<?php

namespace ITFlow\Training\Records;

/**
 * How strongly a record is proven (Phase 2 spec §1.4 #5; the Admin-Transcript legend):
 *
 *   A  online + self_pin_signature                                         "PIN + signature"
 *   B  session/blended/evaluation + self_pin_signature|evaluator_signed|self_pin,
 *      or online + self_pin (and portal_login, v0 §10)                     "Trainer session"
 *   C  trainer_attested                                                    "Trainer attests"
 *   D  document                                                            "Scan on file" / "External card"
 *   E  agent_recorded                                                      "Recorded by office"
 *
 * A blended record already carries its weakest component's proof (CompletionService picks it
 * with weakest()), so the grade is a pure function of (method, proof). Reports\Labels mirrors
 * this table as its fallback; the two must stay identical.
 */
final class EvidenceStrength
{
    public const LABELS = [
        'A' => 'PIN + signature',
        'B' => 'Trainer session',
        'C' => 'Trainer attests',
        'D' => 'Scan on file',
        'E' => 'Recorded by office',
    ];

    /** D reads "External card" for an outside card. */
    public const EXTERNAL_LABEL = 'External card';

    /** Proofs from strongest to weakest (for "a blended record carries its weakest component"). */
    public const PROOF_ORDER = ['self_pin_signature', 'evaluator_signed', 'self_pin', 'portal_login', 'trainer_attested', 'document', 'agent_recorded'];

    public static function grade(string $method, string $proof): string
    {
        if ($proof === 'agent_recorded') {
            return 'E';
        }
        if ($proof === 'document') {
            return 'D';
        }
        if ($proof === 'trainer_attested') {
            return 'C';
        }
        if ($method === 'online' && $proof === 'self_pin_signature') {
            return 'A';
        }
        return 'B';
    }

    public static function label(string $grade, string $method): string
    {
        if ($grade === 'D' && $method === 'external') {
            return self::EXTERNAL_LABEL;
        }
        return self::LABELS[$grade] ?? $grade;
    }

    /** The weakest of several proofs (unknown values count as the weakest). */
    public static function weakest(string ...$proofs): string
    {
        $worst = null;
        $worstRank = -1;
        foreach ($proofs as $p) {
            $rank = array_search($p, self::PROOF_ORDER, true);
            $rank = $rank === false ? PHP_INT_MAX : $rank;
            if ($rank > $worstRank) {
                $worstRank = $rank;
                $worst = $p;
            }
        }
        if ($worst === null) {
            throw new \InvalidArgumentException('EvidenceStrength::weakest: no proofs');
        }
        return $worst;
    }

    /**
     * SQL CASE over the completion columns that computes the same letter (records log filter).
     * Column names are fixed identifiers, never input.
     */
    public static function sqlCase(string $methodCol = 'tc.completion_method', string $proofCol = 'tc.completion_proof'): string
    {
        foreach ([$methodCol, $proofCol] as $col) {
            if (preg_match('/^[a-z_.]+$/', $col) !== 1) {
                throw new \InvalidArgumentException('EvidenceStrength::sqlCase: bad column');
            }
        }
        return "(CASE WHEN $proofCol = 'agent_recorded' THEN 'E' WHEN $proofCol = 'document' THEN 'D'"
            . " WHEN $proofCol = 'trainer_attested' THEN 'C'"
            . " WHEN $methodCol = 'online' AND $proofCol = 'self_pin_signature' THEN 'A' ELSE 'B' END)";
    }
}
