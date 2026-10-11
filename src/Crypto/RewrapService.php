<?php

declare(strict_types=1);

namespace ITFlow\Crypto;

use ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter;
use ITFlow\Core\Adapter\Http\ServerRequestContext;
use RivetCore\Audit\AuditService;
use RivetCore\Crypto\FileRewrapStateStore;
use RivetCore\Crypto\Reencryptor;
use RivetCore\Crypto\RewrapSource;
use RivetCore\Crypto\RewrapOptions;
use RivetCore\Crypto\RewrapReport;
use RivetCore\Crypto\Rewrapper;
use RivetCore\Crypto\RotationPlan;

/**
 * The one place that runs and reports on a rewrap, for scripts/rewrap_cli.php, the Keys panel and the tests: one RewrapSource per
 * encrypted column (ColumnRegistry), Core's Rewrapper (dry run, resumable state file, one audit row per run), and a rotation plan
 * summed over every column.
 *
 * Refuses to run unless the settings stage is on and the ring has an active key: the rewrap writes v3 values, which an older release
 * cannot read, so it must be a deliberate step that follows creating the key file.
 */
final class RewrapService
{
    /** @var list<array{source: RewrapSource, reencryptor: Reencryptor}>|null */
    private ?array $jobs = null;

    public function __construct(private \mysqli $db, private ?int $actorUserId = null, private ?string $stateDir = null)
    {
    }

    public function stateDir(): string
    {
        if ($this->stateDir !== null) {
            return $this->stateDir;
        }
        $cfg = $GLOBALS['config_crypto_state_dir'] ?? null;
        $candidates = [];
        if (is_string($cfg) && $cfg !== '') {
            $candidates[] = $cfg;
        }
        $candidates[] = dirname(__DIR__, 2) . '/backups/crypto-state';
        $candidates[] = sys_get_temp_dir() . '/rivetit-crypto-state-' . substr(hash('sha256', dirname(__DIR__, 2)), 0, 8);
        foreach ($candidates as $dir) {
            if ((is_dir($dir) || @mkdir($dir, 0750, true)) && is_writable($dir)) {
                return $this->stateDir = $dir;
            }
        }

        return $this->stateDir = end($candidates);
    }

    /** @throws \RuntimeException when the rewrap may not run, with the reason */
    public function assertReady(): void
    {
        $state = KeyStore::load();
        if ($state->error !== null) {
            throw new \RuntimeException($state->error);
        }
        if (!$state->ring->hasActive()) {
            throw new \RuntimeException('There is no encryption key: create the key file first (php scripts/keys_cli.php generate).');
        }
        if (!SettingsCrypto::v3Enabled()) {
            throw new \RuntimeException('The v3 settings stage is off ($config_crypto_v3_settings is false and there is no key file); a rewrap would write values that this configuration does not write. Create the key file or switch the stage on first.');
        }
    }

    /** @return list<array{source: RewrapSource, reencryptor: Reencryptor}> the sources whose table and column exist */
    public function jobs(): array
    {
        if ($this->jobs !== null) {
            return $this->jobs;
        }
        $jobs = [];
        foreach (ColumnRegistry::all() as $spec) {
            if (!MysqliRewrapSource::exists($this->db, $spec)) {
                continue;
            }
            $jobs[] = ['source' => new MysqliRewrapSource($this->db, $spec, ColumnRegistry::rowFilter($spec)), 'reencryptor' => FamilyReencryptor::forSpec($spec)];
        }

        return $this->jobs = $jobs;
    }

    /** Add a source that is not a settings column (the vault's, a test's). */
    public function withJob(RewrapSource $source, Reencryptor $reencryptor): void
    {
        $this->jobs();
        $this->jobs[] = ['source' => $source, 'reencryptor' => $reencryptor];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (array $j) => $j['source']->name(), $this->jobs());
    }

    /**
     * @param string|null $only a source name ("settings.config_smtp_password") or a table name; null runs all
     * @return list<RewrapReport>
     */
    public function run(?string $only = null, bool $dryRun = false, int $batchSize = 200, ?int $maxBatches = null, ?float $deadlineSeconds = null): array
    {
        $this->assertReady();
        $rewrapper = new Rewrapper(new FileRewrapStateStore($this->stateDir()), new AuditService(new MysqliDatabaseAdapter($this->db), new ServerRequestContext()));
        $options = new RewrapOptions(batchSize: $batchSize, dryRun: $dryRun, maxBatches: $maxBatches, deadlineSeconds: $deadlineSeconds, actorUserId: $this->actorUserId);
        $reports = [];
        foreach ($this->jobs() as $job) {
            $name = $job['source']->name();
            if ($only !== null && $name !== $only && !str_starts_with($name, $only . '.')) {
                continue;
            }
            if ($job['source']->next(null, 1) === []) {
                continue;   // nothing stored in this column: no report, no audit row
            }
            $reports[] = $rewrapper->run($job['source'], $job['reencryptor'], $options);
        }

        return $reports;
    }

    /**
     * Label counts per source and summed ("v3:k1" => n, "legacy:ENC2" => n, "legacy:plaintext" => n); cleartext in a column that is not
     * wrapped is reported under "cleartext_skipped" instead of as pending work, because a rewrap leaves it alone.
     *
     * @return array{sources: array<string, array<string,int>>, totals: array<string,int>, skipped_cleartext: int}
     */
    public function inventory(): array
    {
        $envelope = SettingsCrypto::vault()->envelope();
        $per = [];
        $totals = [];
        $skipped = 0;
        foreach ($this->jobs() as $job) {
            $source = $job['source'];
            $wrap = $source instanceof MysqliRewrapSource ? $source->spec()->wrapPlaintext : true;
            $counts = [];
            $cursor = null;
            while (($items = $source->next($cursor, 500)) !== []) {
                foreach ($items as $item) {
                    $label = $envelope->label($item->ciphertext);
                    if ($label === 'empty') {
                        continue;
                    }
                    if ($label === 'legacy:plaintext' && !$wrap) {
                        $skipped++;
                        continue;
                    }
                    $counts[$label] = ($counts[$label] ?? 0) + 1;
                    $totals[$label] = ($totals[$label] ?? 0) + 1;
                }
                $cursor = $items[count($items) - 1]->id;
            }
            ksort($counts);
            $per[$source->name()] = $counts;
        }
        ksort($totals);

        return ['sources' => $per, 'totals' => $totals, 'skipped_cleartext' => $skipped];
    }

    public function plan(?array $inventory = null): RotationPlan
    {
        $inventory ??= $this->inventory();

        return RotationPlan::build(KeyStore::load()->ring, $inventory['totals']);
    }
}
