<?php

namespace ITFlow\Training\Upstream;

use ITFlow\Training\Records\CertificateView;
use ITFlow\Training\Reports\Labels as ReportLabels;

/**
 * Wording Phase 5 prints (spec §3.1): P2's strings when P2 has them, the v0 defaults otherwise.
 */
final class Labels
{
    /** v0 method wording (P2 has no generic method label on certificates; its badges are reused for external records). */
    private const METHOD = [
        'online' => 'Online course',
        'session' => 'Instructor-led session',
        'blended' => 'Online course + practical evaluation',
        'evaluation' => 'Practical evaluation',
        'external' => 'External card recorded',
        'legacy_paper' => 'Paper record on file',
    ];

    /** Admin-Transcript mockup fallback, identical to P2's Reports\Labels::STRENGTH today. */
    private const LEGEND = [
        'A' => ['PIN + signature', 'employee signed at the kiosk'],
        'B' => ['Trainer session', 'trainer and employee signed, or the employee confirmed with a PIN'],
        'C' => ['Trainer attests', 'employee did not sign'],
        'D' => ['Scan on file', 'scan of a card or certificate on file'],
        'E' => ['Recorded by office', 'nothing signed'],
    ];

    public static function method(string $method): string
    {
        if (class_exists(CertificateView::class)) {
            if ($method === 'external') {
                return CertificateView::BADGE_EXTERNAL;
            }
            if ($method === 'legacy_paper') {
                return CertificateView::BADGE_PAPER;
            }
        }
        return self::METHOD[$method] ?? ucfirst(str_replace('_', ' ', $method));
    }

    /**
     * The English regulation line for a course's regulation reference, exactly as P2 words it on the
     * on-screen certificate (Reports\Labels::regulation): an OSHA 19xx section reads "Meets the training
     * requirements of OSHA 29 CFR …", anything else "Reference: …". Null when there is no reference.
     */
    public static function regulation(?string $ref): ?string
    {
        if (class_exists(ReportLabels::class) && method_exists(ReportLabels::class, 'regulation')) {
            return ReportLabels::regulation($ref);
        }
        $ref = trim((string) $ref);
        if ($ref === '') {
            return null;
        }
        return preg_match('/^19\d\d\./', $ref) === 1 ? 'Meets the training requirements of OSHA 29 CFR ' . $ref : 'Reference: ' . $ref;
    }

    /** @return array<string, array{0:string, 1:string}> letter => [label, detail] (P2's Reports\Labels::STRENGTH when present) */
    public static function legend(): array
    {
        if (class_exists(ReportLabels::class) && defined(ReportLabels::class . '::STRENGTH')) {
            $out = [];
            foreach (ReportLabels::STRENGTH as $letter => $v) {
                if (is_array($v) && isset($v['label'], $v['detail'])) {
                    $out[(string) $letter] = [(string) $v['label'], (string) $v['detail']];
                }
            }
            if (count($out) === 5) {
                return $out;
            }
        }
        return self::LEGEND;
    }
}
