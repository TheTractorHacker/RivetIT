<?php

namespace ITFlow\Training\Kiosk\Pin;

use ITFlow\Integrations\Odoo\OdooAuthException;
use ITFlow\Integrations\Odoo\OdooConnectorFactory;
use ITFlow\Integrations\Odoo\OdooConnectorInterface;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\Core\KioskSettings;
use ITFlow\Training\Kiosk\Core\KTime;

/**
 * Odoo-PIN checks through the app's Odoo connector (plan A1, option B; P3 spec §3.2) [S★].
 *
 * OFF BY DEFAULT: config_training_odoo_pin_enabled = 0 while the integration points at the stale
 * staging copy (spec §2.3); every method then answers 'disabled' / null without a network call.
 *
 * check() answers 'match' | 'nomatch' | 'unavailable' | 'auth_error' | 'disabled' and NEVER
 * throws. Only an answer from Odoo (the connector returned, i.e. HTTP 2xx) of integer 0 is a
 * 'nomatch'; 401/403/key problems are 'auth_error'; 5xx, timeouts, transport errors and malformed
 * answers ('x', [], 2) are 'unavailable'. The caller refunds the charge for every non-answer.
 *
 * Hygiene (§0.12): every connector call sits in catch (\Throwable); only get_class(), getCode()
 * (the HTTP status where the connector sets it) are logged - never the exception message, which can carry
 * Odoo's own text. The PIN is a PHP string matching ^[0-9]{4,12}$ (asserted here again) and goes
 * only into the domain. base_url must be https://. The integration row is the app's current one
 * (newest enabled) and its stored api_protocol is honoured (OdooConnectorFactory::fromRow).
 *
 * Circuit breaker (settings row, shared by every PHP worker): while
 * config_training_odoo_breaker_until_utc is in the future, no call is made ('unavailable').
 * Three consecutive errors (a malformed answer is an error) open it for 60 s (breakerOpened()
 * then reports true so the caller can alert 'odoo_down'); any well-formed answer resets the count.
 */
final class OdooPinVerifier
{
    public const BREAKER_ERRORS = 3;
    public const BREAKER_S = 60;
    public const CHECK_OPTS = ['connect_timeout' => 2, 'timeout' => 5];
    public const SYNC_OPTS = ['connect_timeout' => 5, 'timeout' => 30];

    private bool $breakerOpened = false;
    private ?string $lastOutcome = null;

    public function __construct(
        private readonly \mysqli $db,
        private readonly KioskSettings $ks,
        private readonly ?OdooConnectorInterface $connector = null,
    ) {
    }

    /** True when the last call on this object opened the breaker (alert 'odoo_down' after commit). */
    public function breakerOpened(): bool
    {
        return $this->breakerOpened;
    }

    public function lastOutcome(): ?string
    {
        return $this->lastOutcome;
    }

    public function check(int $employeeId, string $pin): string
    {
        $this->breakerOpened = false;
        if (!$this->ks->odooPinEnabled) {
            return $this->lastOutcome = 'disabled';
        }
        $row = OdooIntegration::current($this->db);
        if ($row === null) {
            return $this->lastOutcome = 'disabled';
        }
        if ($employeeId < 1 || preg_match('/^[0-9]{4,12}$/D', $pin) !== 1) {
            return $this->lastOutcome = 'unavailable';
        }
        $result = $this->call($row, 'search_count', [
            'domain' => [['id', '=', $employeeId], ['pin', '!=', false], ['pin', '=', $pin]],
        ], self::CHECK_OPTS);
        unset($pin);
        if ($result['outcome'] !== 'answer') {
            return $this->lastOutcome = $result['outcome'];
        }
        $v = $result['value'];
        if ($v === 1 || $v === 0) {
            $this->recordAnswer();
            return $this->lastOutcome = $v === 1 ? 'match' : 'nomatch';
        }
        $this->recordError();
        return $this->lastOutcome = 'unavailable';
    }

    /** Whether the employee has an Odoo PIN of at least 4 characters; null = unknown (disabled, down, malformed). */
    public function hasUsablePin(int $employeeId): ?bool
    {
        $this->breakerOpened = false;
        if (!$this->ks->odooPinEnabled || $employeeId < 1) {
            return null;
        }
        $row = OdooIntegration::current($this->db);
        if ($row === null) {
            return null;
        }
        $result = $this->call($row, 'search_count', [
            'domain' => [['id', '=', $employeeId], ['pin', '!=', false], ['pin', '=like', '____%']],
        ], self::CHECK_OPTS);
        if ($result['outcome'] !== 'answer') {
            return null;
        }
        if ($result['value'] === 1 || $result['value'] === 0) {
            $this->recordAnswer();
            return $result['value'] === 1;
        }
        $this->recordError();
        return null;
    }

    /**
     * Ids of every employee with a usable Odoo PIN - ids only, PIN values are never read
     * (PinSourceSync, once a night and on "Refresh PIN sources"). null = unknown.
     *
     * @return list<int>|null
     */
    public function idsWithUsablePin(): ?array
    {
        $this->breakerOpened = false;
        if (!$this->ks->odooPinEnabled) {
            return null;
        }
        $row = OdooIntegration::current($this->db);
        if ($row === null) {
            return null;
        }
        $result = $this->call($row, 'search', [
            'domain' => [['pin', '!=', false], ['pin', '=like', '____%']],
        ], self::SYNC_OPTS);
        if ($result['outcome'] !== 'answer') {
            return null;
        }
        if (!is_array($result['value']) || !array_is_list($result['value'])) {
            $this->recordError();
            return null;
        }
        $ids = [];
        foreach ($result['value'] as $id) {
            if (!is_int($id) || $id < 1) {
                $this->recordError();
                return null;
            }
            $ids[$id] = $id;
        }
        $this->recordAnswer();
        return array_values($ids);
    }

    /**
     * One connector call behind the breaker and the https rule.
     *
     * @return array{outcome:string, value?:mixed} outcome 'answer' | 'unavailable' | 'auth_error'
     */
    private function call(array $row, string $method, array $kwargs, array $opts): array
    {
        if (!str_starts_with(strtolower(trim((string) ($row['base_url'] ?? ''))), 'https://')) {
            return ['outcome' => 'unavailable'];
        }
        if ($this->breakerIsOpen()) {
            return ['outcome' => 'unavailable'];
        }
        try {
            $conn = $this->connector ?? OdooConnectorFactory::fromRow($row);
            $value = $conn->call('hr.employee', $method, [], $kwargs, $opts);
        } catch (OdooAuthException $e) {
            error_log('Kiosk Odoo PIN check: ' . get_class($e) . ' code ' . (int) $e->getCode());
            $this->recordError();
            return ['outcome' => 'auth_error'];
        } catch (\Throwable $e) {
            error_log('Kiosk Odoo PIN check: ' . get_class($e) . ' code ' . (int) $e->getCode());
            $this->recordError();
            return ['outcome' => 'unavailable'];
        }
        // The caller records the answer (recordAnswer) only when it is well-formed; a malformed
        // 2xx answer counts as an error, so it can open the breaker too.
        return ['outcome' => 'answer', 'value' => $value];
    }

    private function breakerIsOpen(): bool
    {
        try {
            $r = Db::one($this->db, 'SELECT config_training_odoo_breaker_until_utc AS u FROM settings WHERE company_id = 1');
        } catch (\Throwable $e) {
            error_log('Kiosk Odoo breaker read: ' . get_class($e));
            return true;
        }
        return KTime::isFuture($r['u'] ?? null);
    }

    private function recordAnswer(): void
    {
        try {
            Db::exec($this->db, 'UPDATE settings SET config_training_odoo_breaker_errors = 0 WHERE company_id = 1 AND config_training_odoo_breaker_errors <> 0');
        } catch (\Throwable $e) {
            error_log('Kiosk Odoo breaker reset: ' . get_class($e));
        }
    }

    /** Autocommit updates (never inside a caller's transaction: the verifier runs between reserve and settle). */
    private function recordError(): void
    {
        try {
            Db::exec($this->db, 'UPDATE settings SET config_training_odoo_breaker_errors = LEAST(config_training_odoo_breaker_errors + 1, 255) WHERE company_id = 1');
            $opened = Db::exec($this->db, 'UPDATE settings SET config_training_odoo_breaker_until_utc = ?, config_training_odoo_breaker_errors = 0
                WHERE company_id = 1 AND config_training_odoo_breaker_errors >= ?', 'si', [KTime::plus(self::BREAKER_S), self::BREAKER_ERRORS]);
            if ($opened > 0) {
                $this->breakerOpened = true;
            }
        } catch (\Throwable $e) {
            error_log('Kiosk Odoo breaker write: ' . get_class($e));
        }
    }
}
