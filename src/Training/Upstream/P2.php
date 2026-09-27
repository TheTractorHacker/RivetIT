<?php

namespace ITFlow\Training\Upstream;

/**
 * Phase 2 feature probes (spec §3.1): a feature is available when every class it names autoloads
 * AND the Phase 2 tables exist (Schema::has(Schema::P2)). Memoised per request. A missing class or
 * table means the dependent Phase 5 feature shows "not available" - it never re-implements P2.
 */
final class P2
{
    public const FEATURES = [
        'verify'     => ['ITFlow\Training\Records\PublicVerify'],
        'cert'       => ['ITFlow\Training\Records\CertificateView', 'ITFlow\Training\Records\CertSecret', 'ITFlow\Training\Records\CertIssuer'],
        'transcript' => ['ITFlow\Training\Reports\TranscriptService'],
        'scope'      => ['ITFlow\Training\People\Scope'],
        'compliance' => ['ITFlow\Training\Compliance\ComplianceService', 'ITFlow\Training\Core\RecordsSettings'],
        'reconcile'  => ['ITFlow\Training\Assign\AssignmentService'],
    ];

    /** @var array<string, bool> */
    private static array $classes = [];

    public static function has(\mysqli $db, string $feature): bool
    {
        if (!isset(self::FEATURES[$feature])) {
            throw new \InvalidArgumentException("Upstream\\P2: unknown feature '$feature'");
        }
        if (!array_key_exists($feature, self::$classes)) {
            $ok = true;
            foreach (self::FEATURES[$feature] as $class) {
                try {
                    if (!class_exists($class)) {
                        $ok = false;
                        break;
                    }
                } catch (\Throwable) {
                    $ok = false;
                    break;
                }
            }
            self::$classes[$feature] = $ok;
        }
        return self::$classes[$feature] && Schema::has($db, Schema::P2);
    }
}
