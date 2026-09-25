<?php

namespace ITFlow\Training\Kiosk\Learn;

/**
 * The learner projection of a drawn kiosk attempt and its answer key (P3 spec §3.4). Mirrors
 * Preview\PreviewQuizService::start()'s projection (that file is not edited): the presented
 * questions carry uid, type, text, image_url and options {uid, text} only; the key (type,
 * correct option uids, points, critical, prompt, topic, explanation, per-option feedback in the
 * attempt language) never leaves the server before grading, and after grading only what
 * FeedbackBuilder releases for the quiz's feedback mode.
 */
final class QuizPresenter
{
    /**
     * @param array    $doc       revision document (questions map)
     * @param array    $lessonRev the quiz lesson of the revision
     * @param array    $draw      [['q' => uid, 'o' => [option uids as presented], 'rule' => uid], …]
     * @param callable $mediaUrl  fn(int $mediaId, bool $download): string (KioskLearnerView::mediaUrl)
     * @return array{presented:list<array>, key:array<string, array>}
     */
    public static function present(array $doc, array $lessonRev, array $draw, string $lang, callable $mediaUrl): array
    {
        $default = (string) ($doc['course']['default_language'] ?? 'en');
        $questions = is_array($doc['questions'] ?? null) ? $doc['questions'] : [];
        $tr = static function (?array $map, string $field) use ($lang, $default): ?string {
            if ($map === null) {
                return null;
            }
            $v = $map[$lang][$field] ?? $map[$default][$field] ?? null;
            return $v === null ? null : (string) $v;
        };
        $presented = [];
        $key = [];
        foreach ($draw as $d) {
            $qUid = (string) $d['q'];
            $q = $questions[$qUid] ?? null;
            if (!is_array($q)) {
                throw new \RuntimeException('Kiosk attempt draw names a question that is not in its revision');
            }
            $optByUid = [];
            foreach ($q['options'] ?? [] as $o) {
                $optByUid[(string) $o['uid']] = $o;
            }
            $media = $q['text'][$lang]['media_id'] ?? $q['text'][$default]['media_id'] ?? $q['media_id'] ?? null;
            $opts = [];
            $feedback = [];
            $correct = [];
            foreach ($d['o'] as $ou) {
                $ou = (string) $ou;
                $o = $optByUid[$ou] ?? null;
                if ($o === null) {
                    throw new \RuntimeException('Kiosk attempt draw names an option that is not in its revision');
                }
                $opts[] = ['uid' => $ou, 'text' => (string) ($tr($o['text'] ?? null, 'label') ?? '')];
                $fb = $tr($o['text'] ?? null, 'feedback');
                if ($fb !== null && trim($fb) !== '') {
                    $feedback[$ou] = $fb;
                }
                if (!empty($o['correct'])) {
                    $correct[] = $ou;
                }
            }
            $presented[] = [
                'uid' => $qUid,
                'type' => (string) $q['type'],
                'text' => (string) ($tr($q['text'] ?? null, 'q') ?? ''),
                'image_url' => $media === null ? null : $mediaUrl((int) $media, false),
                'options' => $opts,
            ];
            $key[$qUid] = [
                'type' => (string) $q['type'],
                'correct' => $correct,
                'points' => (int) ($q['points'] ?? 1),
                'critical' => !empty($q['critical']),
                'text' => (string) ($tr($q['text'] ?? null, 'q') ?? ''),
                'topic' => $tr($q['text'] ?? null, 'topic'),
                'explanation' => $tr($q['text'] ?? null, 'explanation'),
                'feedback' => $feedback,
            ];
        }
        return ['presented' => $presented, 'key' => $key];
    }

    /**
     * The attempt language (A3): the run language when every drawn question has a prompt in it,
     * else the course default.
     */
    public static function language(array $doc, array $draw, string $runLang): string
    {
        $default = (string) ($doc['course']['default_language'] ?? 'en');
        if ($runLang === $default) {
            return $default;
        }
        foreach ($draw as $d) {
            $q = $doc['questions'][(string) $d['q']] ?? null;
            if (!is_array($q) || trim((string) ($q['text'][$runLang]['q'] ?? '')) === '') {
                return $default;
            }
        }
        return $runLang;
    }
}
