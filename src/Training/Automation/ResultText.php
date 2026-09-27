<?php

namespace ITFlow\Training\Automation;

/**
 * Plain sentences for the worker results stamped on training_automation (tauto_daily_last_result,
 * tauto_odoo_last_result), for the Training settings cards. The stored values stay the terse log
 * lines cron/training_worker.php and OdooSync\PushService write; this only reads them. Unknown
 * parts are shown as they are, so a newer worker never breaks an older card. Output is plain text
 * (the cards escape it).
 */
final class ResultText
{
    /**
     * The daily run: "ok <part> · <part>" or "FAILED a,b: <part> · <part>".
     *
     * @return array{ok:?bool, text:string} ok null when there is no result
     */
    public static function daily(?string $stored): array
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            return ['ok' => null, 'text' => ''];
        }
        $ok = true;
        $failed = [];
        if (preg_match('/^FAILED ([a-z_,]+): ?(.*)$/sD', $stored, $m) === 1) {
            $ok = false;
            $failed = array_filter(explode(',', $m[1]));
            $stored = $m[2];
        } elseif (str_starts_with($stored, 'ok ')) {
            $stored = substr($stored, 3);
        }
        $sentences = [];
        foreach (explode(' · ', $stored) as $part) {
            $part = trim($part);
            if ($part === '' || str_ends_with($part, ' ERROR')) {
                continue;   // failed steps are named once, below
            }
            $sentences[] = self::dailyPart($part);
        }
        if ($failed !== []) {
            $sentences[] = 'Failed: ' . implode(', ', array_map([self::class, 'stepName'], $failed)) . ' (see the server log).';
        }
        return ['ok' => $ok, 'text' => implode(' ', $sentences)];
    }

    /**
     * An Odoo write-back run: "ok pushed P, failed F, dead D, held H" or "paused <reason>; pushed …".
     *
     * @return array{ok:?bool, text:string} ok null when there is no result, false when paused
     */
    public static function odoo(?string $stored): array
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            return ['ok' => null, 'text' => ''];
        }
        if (preg_match('/^paused ([^;]+)(?:; ?(.*))?$/sD', $stored, $m) === 1) {
            $reason = trim($m[1]);
            $why = class_exists(\ITFlow\Training\OdooSync\PushService::class)
                ? \ITFlow\Training\OdooSync\PushService::describePause($reason) : $reason;
            $text = 'Paused: ' . rtrim($why, '.') . '.';
            if (preg_match('/^pushed (\d+), failed (\d+), dead (\d+), held (\d+)/D', (string) ($m[2] ?? ''), $c) === 1
                && (int) $c[1] + (int) $c[2] + (int) $c[3] + (int) $c[4] > 0) {
                $text .= ' Before the pause: ' . lcfirst(self::odooCounts((int) $c[1], (int) $c[2], (int) $c[3], (int) $c[4]));
            }
            return ['ok' => false, 'text' => $text];
        }
        if (str_starts_with($stored, 'ok ')) {
            $stored = substr($stored, 3);
        }
        if (preg_match('/^pushed (\d+), failed (\d+), dead (\d+), held (\d+)/D', $stored, $m) === 1) {
            return ['ok' => true, 'text' => self::odooCounts((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4])];
        }
        return ['ok' => true, 'text' => $stored];
    }

    /** "3 sent, 1 will be tried again, 12 waiting for an employee link" or "Nothing was due to send." */
    public static function odooCounts(int $pushed, int $failed, int $dead, int $held): string
    {
        $bits = [];
        if ($pushed > 0) {
            $bits[] = $pushed . ' sent';
        }
        if ($failed > 0) {
            $bits[] = $failed . ' will be tried again';
        }
        if ($dead > 0) {
            $bits[] = $dead . ' could not be sent';
        }
        if ($held > 0) {
            $bits[] = $held . ' waiting for an employee link';
        }
        return $bits === [] ? 'Nothing was due to send.' : ucfirst(implode(', ', $bits)) . '.';
    }

    private static function dailyPart(string $part): string
    {
        if ($part === 'nothing to do') {
            return 'Nothing to do.';
        }
        if (preg_match('/^reconcile (\w+)$/D', $part, $m) === 1) {
            return match ($m[1]) {
                'fresh' => 'Assignments were already up to date.',
                'ran' => 'Assignments were brought up to date first.',
                'busy' => 'Assignments were being updated by another run.',
                'unavailable' => 'Assignments could not be brought up to date (not available).',
                default => 'Assignments could not be brought up to date.',
            };
        }
        if (preg_match('/^video checked (\d+), changed (\d+), bad (\d+)$/D', $part, $m) === 1) {
            if ((int) $m[1] === 0) {
                return 'Videos: none to check.';
            }
            return 'Videos: ' . $m[1] . ' checked, ' . self::n((int) $m[2], 'changed') . ', '
                . ((int) $m[3] === 0 ? 'none with a problem' : $m[3] . ' with a problem') . '.';
        }
        if (preg_match('/^reminders sent (\d+), skipped (\d+)$/D', $part, $m) === 1) {
            return 'Reminders: ' . self::plural((int) $m[1], 'digest') . ' sent'
                . ((int) $m[2] > 0 ? ', ' . self::plural((int) $m[2], 'person', 'people') . ' skipped (nothing due, no departments or already sent)' : '') . '.';
        }
        if ($part === 'key-expiry warned') {
            return 'Administrators were warned that the Odoo key expires soon.';
        }
        if (preg_match('/^verify-throttle pruned (\d+)$/D', $part, $m) === 1) {
            return 'Cleared ' . self::plural((int) $m[1], 'old certificate-check counter') . '.';
        }
        return rtrim($part, '.') . '.';
    }

    private static function stepName(string $step): string
    {
        return match ($step) {
            'reconcile' => 'updating assignments',
            'video' => 'video checks',
            'reminders' => 'reminders',
            'key_expiry' => 'the Odoo key warning',
            'verify_prune' => 'clearing certificate-check counters',
            'stamp' => 'saving the result',
            default => str_replace('_', ' ', $step),
        };
    }

    private static function n(int $n, string $word): string
    {
        return ($n === 0 ? 'none' : (string) $n) . ' ' . $word;
    }

    private static function plural(int $n, string $one, ?string $many = null): string
    {
        return $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
    }
}
