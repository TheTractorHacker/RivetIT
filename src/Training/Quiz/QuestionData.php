<?php

namespace ITFlow\Training\Quiz;

use ITFlow\Training\Core\Db;

/**
 * Batch loader for questions with their per-language texts and options.
 *
 * One normalised in-memory shape is shared by the builder API (QuestionService), pool
 * resolution, the revision builder, the CSV exporter and bank hashing, so they can never
 * disagree about what a question contains:
 *
 *   [ 'id','uid','bank_id','type','media_id'(?int),'points','critical'(bool),'sort','version','archived'(bool),
 *     'texts'   => [lang => ['text','explanation'(?),'topic'(?),'media_id'(?int)]],   lang-sorted
 *     'options' => [ ['id','uid','sort','correct'(bool),'pinned'(bool),'texts' => [lang => ['text','feedback'(?)]]], … ]
 *   ]                                                                               options in (sort, id) order
 */
final class QuestionData
{
    private const Q_COLS = 'question_id, question_uid, question_bank_id, question_type, question_media_id, question_points,
        question_critical, question_sort, question_version, question_archived_at';

    /** @return array<int, array> question id => question, in the order of $ids */
    public static function load(\mysqli $db, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $byId = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            [$ph, $t, $p] = InList::ints($chunk);
            foreach (Db::all($db, 'SELECT ' . self::Q_COLS . " FROM training_questions WHERE question_id IN ($ph)", $t, $p) as $r) {
                $id = (int) $r['question_id'];
                $byId[$id] = [
                    'id' => $id,
                    'uid' => (string) $r['question_uid'],
                    'bank_id' => (int) $r['question_bank_id'],
                    'type' => (string) $r['question_type'],
                    'media_id' => $r['question_media_id'] === null ? null : (int) $r['question_media_id'],
                    'points' => (int) $r['question_points'],
                    'critical' => (int) $r['question_critical'] === 1,
                    'sort' => (int) $r['question_sort'],
                    'version' => (int) $r['question_version'],
                    'archived' => $r['question_archived_at'] !== null,
                    'texts' => [],
                    'options' => [],
                ];
            }
            foreach (Db::all($db, "SELECT qtext_question_id, qtext_lang, qtext_text, qtext_explanation, qtext_topic, qtext_media_id
                    FROM training_question_texts WHERE qtext_question_id IN ($ph) ORDER BY qtext_question_id, qtext_lang", $t, $p) as $r) {
                $qid = (int) $r['qtext_question_id'];
                if (isset($byId[$qid])) {
                    $byId[$qid]['texts'][(string) $r['qtext_lang']] = [
                        'text' => (string) $r['qtext_text'],
                        'explanation' => $r['qtext_explanation'],
                        'topic' => $r['qtext_topic'],
                        'media_id' => $r['qtext_media_id'] === null ? null : (int) $r['qtext_media_id'],
                    ];
                }
            }
            $optById = [];
            foreach (Db::all($db, "SELECT option_id, option_uid, option_question_id, option_sort, option_is_correct, option_pinned
                    FROM training_question_options WHERE option_question_id IN ($ph) ORDER BY option_question_id, option_sort, option_id", $t, $p) as $r) {
                $oid = (int) $r['option_id'];
                $optById[$oid] = [(int) $r['option_question_id'], [
                    'id' => $oid,
                    'uid' => (string) $r['option_uid'],
                    'sort' => (int) $r['option_sort'],
                    'correct' => (int) $r['option_is_correct'] === 1,
                    'pinned' => (int) $r['option_pinned'] === 1,
                    'texts' => [],
                ]];
            }
            if ($optById !== []) {
                foreach (array_chunk(array_keys($optById), 500) as $oChunk) {
                    [$oph, $ot, $op] = InList::ints($oChunk);
                    foreach (Db::all($db, "SELECT otext_option_id, otext_lang, otext_text, otext_feedback
                            FROM training_option_texts WHERE otext_option_id IN ($oph) ORDER BY otext_option_id, otext_lang", $ot, $op) as $r) {
                        $oid = (int) $r['otext_option_id'];
                        $optById[$oid][1]['texts'][(string) $r['otext_lang']] = [
                            'text' => (string) $r['otext_text'],
                            'feedback' => $r['otext_feedback'],
                        ];
                    }
                }
            }
            foreach ($optById as [$qid, $opt]) {
                if (isset($byId[$qid])) {
                    $byId[$qid]['options'][] = $opt;
                }
            }
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[$id] = $byId[$id];
            }
        }
        return $out;
    }

    /**
     * Live question ids of the given banks, in pool order: banks in the order given, then
     * (question_sort, question_id) within each bank.
     *
     * @return list<int>
     */
    public static function idsForBanks(\mysqli $db, array $bankIds): array
    {
        $bankIds = array_values(array_unique(array_map('intval', $bankIds)));
        if ($bankIds === []) {
            return [];
        }
        [$ph, $t, $p] = InList::ints($bankIds);
        $rows = Db::all($db, "SELECT question_id, question_bank_id FROM training_questions
            WHERE question_bank_id IN ($ph) AND question_archived_at IS NULL ORDER BY question_sort, question_id", $t, $p);
        $byBank = [];
        foreach ($rows as $r) {
            $byBank[(int) $r['question_bank_id']][] = (int) $r['question_id'];
        }
        $out = [];
        foreach ($bankIds as $b) {
            foreach ($byBank[$b] ?? [] as $qid) {
                $out[] = $qid;
            }
        }
        return $out;
    }
}
