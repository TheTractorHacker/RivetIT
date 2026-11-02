<?php

namespace ITFlow\Chat;

/**
 * The chat virtual agent: answers a plain-language message from a linked chat user by searching the knowledge base and the service catalog,
 * and can raise a ticket or a catalog request on their behalf. Rule based on purpose (no model, no outside call): it matches a small set of verbs and
 * otherwise treats the message as a question.
 *
 *   help | status <ticket> | approvals | request <catalog item id> | ticket <subject> / new <subject> | <anything else> = search
 *
 * Visibility follows the web app: a contact (portal user) only gets articles marked client-visible for their own department or for everyone, and
 * active catalog items; an agent gets articles of departments they may access. Archived and inactive things are never returned. Results never include
 * the article body, only a short plain-text snippet and a link.
 */
final class VirtualAgent
{
    public function __construct(private \mysqli $db, private ChatActions $actions)
    {
    }

    /**
     * @param array<string,mixed> $actor
     * @return array{text:string,results:list<array{title:string,url:?string,snippet:string}>,heading:string,ticket_id:?int,intent:string}
     */
    public function answer(array $actor, string $message): array
    {
        $msg = trim(preg_replace('/\s+/', ' ', $message) ?? '');
        $out = ['text' => '', 'results' => [], 'heading' => '', 'ticket_id' => null, 'intent' => 'search'];
        if ($msg === '') {
            return ['text' => $this->help($actor), 'intent' => 'help'] + $out;
        }
        if (preg_match('/^(help|\?|hi|hello|hey)\b/i', $msg)) {
            return ['text' => $this->help($actor), 'intent' => 'help'] + $out;
        }
        if (preg_match('/^status\s+(\S+)/i', $msg, $m)) {
            $r = $this->actions->status($actor, $m[1]);

            return ['text' => $r['text'], 'ticket_id' => $r['ticket_id'] ?? null, 'intent' => 'status'] + $out;
        }
        if (preg_match('/^(my\s+)?approvals?$/i', $msg)) {
            $rows = $this->actions->pendingApprovals($actor);
            if (!$rows) {
                return ['text' => 'You have no approvals waiting.', 'intent' => 'approvals'] + $out;
            }
            $lines = array_map(static fn (array $r) => '- ' . $r['title'] . ($r['detail'] !== '' ? ' (' . $r['detail'] . ')' : ''), $rows);

            return ['text' => "Waiting for you:\n" . implode("\n", $lines) . "\nYou will get a message with Approve / Deny buttons for each when it is your turn.", 'intent' => 'approvals'] + $out;
        }
        if (preg_match('/^request\s+#?(\d{1,9})\b/i', $msg, $m)) {
            $r = $this->actions->requestCatalogItem($actor, (int) $m[1], 'virtual agent');

            return ['text' => $r['text'], 'ticket_id' => $r['ticket_id'] ?? null, 'intent' => 'request'] + $out;
        }
        if (preg_match('/^(?:new\s+ticket|ticket|new|open\s+a\s+ticket|create\s+a?\s*ticket)\s*[:\-]?\s+(.{3,})$/i', $msg, $m)) {
            $r = $this->actions->createTicket($actor, $m[1], 'Created by the chat virtual agent from: ' . $m[1], '', 0, 'virtual agent');

            return ['text' => $r['text'], 'ticket_id' => $r['ticket_id'] ?? null, 'intent' => 'ticket'] + $out;
        }

        $kb = $this->searchKb($actor, $msg);
        $cat = $this->searchCatalog($actor, $msg);
        $results = array_merge($kb, $cat);
        if (!$results) {
            return ['text' => "I could not find anything about that. Say \"ticket <what is wrong>\" and I will open a ticket for you.", 'intent' => 'search'] + $out;
        }
        $text = '';
        if ($kb) {
            $text .= 'Articles that may help: ' . implode('; ', array_map(static fn ($r) => $r['title'], $kb)) . '. ';
        }
        if ($cat) {
            $text .= 'You can request: ' . implode('; ', array_map(static fn ($r) => $r['title'], $cat)) . '. ';
        }
        $text .= 'Not what you need? Say "ticket <what is wrong>".';

        return ['text' => $text, 'results' => $results, 'heading' => 'Here is what I found', 'intent' => 'search'] + $out;
    }

    private function help(array $actor): string
    {
        $h = "Ask me a question and I will search the knowledge base and the service catalog.\n"
            . "ticket <what is wrong> - open a ticket\n"
            . "status <ticket number> - see where a ticket is\n"
            . "request <catalog item id> - request a service\n"
            . "approvals - see what is waiting for you";

        return $h;
    }

    /** @return list<string> distinct search words (3+ characters) */
    public static function words(string $q): array
    {
        $stop = ['the', 'and', 'for', 'how', 'can', 'you', 'what', 'with', 'who', 'does', 'not', 'are', 'was', 'have', 'has', 'need', 'help', 'please', 'my', 'is', 'to', 'a', 'i'];
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($q), $m);
        $w = [];
        foreach ($m[0] as $x) {
            if (!in_array($x, $stop, true) && !in_array($x, $w, true)) {
                $w[] = $x;
            }
        }

        return array_slice($w, 0, 6);
    }

    private static function like(string $w): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $w) . '%';
    }

    /** @return list<array{title:string,url:?string,snippet:string}> */
    public function searchKb(array $actor, string $q, int $limit = 3): array
    {
        $words = self::words($q);
        if (!$words) {
            return [];
        }
        $where = ['kb_article_archived_at IS NULL'];
        $params = [];
        $types = '';
        if ($actor['kind'] === 'contact') {
            $where[] = 'kb_article_client_visible = 1';
            $where[] = 'kb_article_client_id IN (0, ' . (int) $actor['client_id'] . ')';
        }
        $or = [];
        $score = [];
        foreach ($words as $w) {
            $or[] = '(kb_article_title LIKE ? OR kb_article_content_raw LIKE ?)';
            $score[] = '(kb_article_title LIKE ?) * 3 + (kb_article_content_raw LIKE ?)';
            $params[] = self::like($w);
            $params[] = self::like($w);
            $types .= 'ss';
        }
        $sql = 'SELECT kb_article_id, kb_article_title, kb_article_client_id, kb_article_content_raw, (' . implode(' + ', $score) . ') AS score FROM kb_articles WHERE ' . implode(' AND ', $where) . ' AND (' . implode(' OR ', $or) . ') ORDER BY score DESC, kb_article_id DESC LIMIT 20';
        $stmt = mysqli_prepare($this->db, $sql);
        $all = array_merge($params, $params);
        mysqli_stmt_bind_param($stmt, $types . $types, ...$all);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $base = ChatSettings::baseUrl();
        $out = [];
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            if ($actor['kind'] === 'agent' && (int) $r['kb_article_client_id'] > 0 && !$this->actions->mayRead($actor, (int) $r['kb_article_client_id'])) {
                continue;
            }
            $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $r['kb_article_content_raw']), ENT_QUOTES, 'UTF-8')) ?? '');
            $path = $actor['kind'] === 'contact' ? '/client/kb_article.php?id=' : '/agent/kb_article.php?id=';
            $out[] = ['title' => (string) $r['kb_article_title'], 'url' => $base !== '' ? $base . $path . (int) $r['kb_article_id'] : null, 'snippet' => ChatBlocks::clip($text, 140)];
            if (count($out) >= $limit) {
                break;
            }
        }
        mysqli_stmt_close($stmt);

        return $out;
    }

    /** @return list<array{title:string,url:?string,snippet:string}> */
    public function searchCatalog(array $actor, string $q, int $limit = 3): array
    {
        $words = self::words($q);
        if (!$words) {
            return [];
        }
        $or = [];
        $params = [];
        foreach ($words as $w) {
            $or[] = '(name LIKE ? OR description LIKE ?)';
            $params[] = self::like($w);
            $params[] = self::like($w);
        }
        $stmt = mysqli_prepare($this->db, 'SELECT catalog_item_id, name, description FROM service_catalog_items WHERE is_active = 1 AND (' . implode(' OR ', $or) . ') ORDER BY sort_order ASC, name ASC LIMIT ' . (int) $limit);
        mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $base = ChatSettings::baseUrl();
        $out = [];
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $out[] = [
                'title' => $r['name'] . ' (say "request ' . (int) $r['catalog_item_id'] . '")',
                'url' => $base !== '' ? $base . ($actor['kind'] === 'contact' ? '/client/ticket_add.php?catalog_item_id=' : '/agent/service_catalog.php?catalog_item_id=') . (int) $r['catalog_item_id'] : null,
                'snippet' => ChatBlocks::clip(trim(strip_tags((string) $r['description'])), 140),
            ];
        }
        mysqli_stmt_close($stmt);

        return $out;
    }
}
