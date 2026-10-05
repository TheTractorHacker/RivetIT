<?php

use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;

require_once __DIR__ . '/../includes/module_access.php';

/** Raised inside a tool body when the requested record does not exist or is outside the caller's scope. */
final class McpNotFound extends \RivetCore\Mcp\NotFoundException {}

/**
 * Read-only MCP tools. Every tool goes through run(): token -> rate limit -> RivetIT role check -> the tool's
 * own client-scope SQL -> audit row -> standard envelope {success, request_id, data, errors}. No tool writes,
 * and none returns secrets (asset PINs, contact PINs, credentials, vault data are never selected).
 */
final class RivetITMcpReadTools
{
    private const RATE_LIMIT = 60;       // calls per user per window
    private const RATE_WINDOW = 60;
    private const MAX_ROWS = 25;

    public function __construct(private mysqli $db) {}

    /**
     * Register every #[McpTool] method. The SDK only reads those attributes in discovery mode, so the name
     * and description are passed explicitly; without this clients would see method names like "getAsset".
     * Every tool here is read-only, which is declared to clients via readOnlyHint.
     */
    public function register(Mcp\Server\Builder $builder): Mcp\Server\Builder
    {
        foreach ((new ReflectionClass($this))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(McpTool::class) as $attribute) {
                $tool = $attribute->newInstance();
                $builder->addTool([$this, $method->getName()], $tool->name, null, $tool->description,
                    new Mcp\Schema\ToolAnnotations(readOnlyHint: true, destructiveHint: false, openWorldHint: false));
            }
        }
        return $builder;
    }

    /* ---------------------------------------------------------------- pipeline */

    private function userId(RequestContext $context): int
    {
        $meta = $context->getRequest()->getMeta() ?? [];
        $id = $meta['oauth']['oauth.user_id'] ?? null;
        $scopes = $meta['oauth']['oauth.scopes'] ?? null;
        if (!is_int($id) || $id < 1 || !is_array($scopes) || !in_array('mcp:read', $scopes, true)) {
            throw new RuntimeException('MCP authorization failed.');
        }
        return $id;
    }

    private ?\RivetCore\Mcp\ToolPipeline $pipeline = null;

    /** The shared pipeline (rate limit -> permission -> scoped read -> audit -> envelope) lives in RivetCore. */
    private function pipeline(): \RivetCore\Mcp\ToolPipeline
    {
        $request = new \ITFlow\Core\Adapter\Http\ServerRequestContext();

        return $this->pipeline ??= new \RivetCore\Mcp\ToolPipeline(
            new \RivetCore\Redis\RateLimiter(new \ITFlow\Core\Adapter\Redis\GlobalRedisClientProvider(), 'rivetit:'),
            new \RivetCore\Audit\AuditService(new \ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter($this->db), $request),
            $request,
            self::RATE_LIMIT,
            self::RATE_WINDOW
        );
    }

    /** @param callable(array):bool $allow gets the caller's access profile; @param callable(int):array $body */
    private function run(RequestContext $context, string $tool, array $args, callable $allow, callable $body): array
    {
        try {
            $uid = $this->userId($context);
        } catch (Throwable) {
            $uid = null;
        }

        return $this->pipeline()->run(
            $uid, $tool, $args,
            static fn(int $u): bool => $allow(itflow_user_access_profile($u)),
            $body,
            'RivetIT role'
        );
    }

    /* ---------------------------------------------------------------- helpers */

    private static function can(array $profile, string $module, int $level = 1): bool
    {
        return itflow_profile_level($profile, $module) >= $level;
    }

    /** Same client restriction the agent UI applies: no rows in user_client_permissions means all clients. */
    private static function scope(string $clientColumn): string
    {
        return "(NOT EXISTS (SELECT 1 FROM user_client_permissions ucp WHERE ucp.user_id = ?)
                 OR EXISTS (SELECT 1 FROM user_client_permissions ucp WHERE ucp.user_id = ? AND ucp.client_id = $clientColumn))";
    }

    private static function like(string $q): string
    {
        return '%' . addcslashes(mb_substr(trim($q), 0, 100), '%_\\') . '%';
    }

    private static function limit(int $n): int
    {
        return max(1, min(self::MAX_ROWS, $n));
    }

    private static function text(?string $html, int $max, bool $keepLines = false): string
    {
        $t = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = trim($keepLines ? preg_replace("/[ \t]+/", ' ', preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $t))) : preg_replace('/\s+/', ' ', $t));
        return mb_strlen($t) > $max ? mb_substr($t, 0, $max) . '…' : $t;
    }

    /** Prepared SELECT; $types/$params bind in order. */
    private function rows(string $sql, string $types, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function one(string $sql, string $types, array $params): array
    {
        return $this->rows($sql, $types, $params)[0] ?? throw new McpNotFound();
    }

    /* ---------------------------------------------------------------- profile */

    #[McpTool(name: 'rivetit_my_profile', description: 'Read the signed-in RivetIT agent profile.')]
    public function myProfile(RequestContext $context): array
    {
        return $this->run($context, 'rivetit_my_profile', [], fn() => true, function (int $uid) {
            $row = $this->one('SELECT user_id, user_name, user_email FROM users
                WHERE user_id = ? AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL', 'i', [$uid]);
            return ['id' => (int) $row['user_id'], 'name' => $row['user_name'], 'email' => $row['user_email']];
        });
    }

    /* ---------------------------------------------------------------- tickets */

    #[McpTool(name: 'rivetit_recent_tickets', description: 'Read up to 25 recent open tickets within the signed-in agent’s client access.')]
    public function recentTickets(RequestContext $context, int $limit = 10): array
    {
        return $this->searchTickets($context, '', true, $limit, 'rivetit_recent_tickets');
    }

    #[McpTool(name: 'rivetit_search_tickets', description: 'Search tickets by subject, number, or details. open_only defaults to true. Returns up to 25.')]
    public function searchTicketsTool(RequestContext $context, string $query = '', bool $open_only = true, int $limit = 10): array
    {
        return $this->searchTickets($context, $query, $open_only, $limit, 'rivetit_search_tickets');
    }

    private function searchTickets(RequestContext $context, string $query, bool $openOnly, int $limit, string $tool): array
    {
        $args = ['query' => $query, 'open_only' => $openOnly, 'limit' => $limit];
        return $this->run($context, $tool, $args, fn($p) => self::can($p, 'module_support'), function (int $uid) use ($query, $openOnly, $limit) {
            $where = ['t.ticket_archived_at IS NULL', self::scope('t.ticket_client_id')];
            $types = 'ii';
            $params = [$uid, $uid];
            if ($openOnly) $where[] = 't.ticket_resolved_at IS NULL';
            if (trim($query) !== '') {
                $where[] = "(t.ticket_subject LIKE ? OR t.ticket_details LIKE ? OR CONCAT(IFNULL(t.ticket_prefix,''), t.ticket_number) LIKE ?)";
                $like = self::like($query);
                $types .= 'sss';
                array_push($params, $like, $like, $like);
            }
            $n = self::limit($limit);
            $types .= 'i';
            $params[] = $n;
            return $this->rows("SELECT t.ticket_id, CONCAT(IFNULL(t.ticket_prefix,''), t.ticket_number) AS ticket_number,
                    t.ticket_subject, t.ticket_priority, s.ticket_status_name AS status, c.client_name,
                    t.ticket_assigned_to, t.ticket_created_at, t.ticket_updated_at
                FROM tickets t LEFT JOIN ticket_statuses s ON s.ticket_status_id = t.ticket_status
                LEFT JOIN clients c ON c.client_id = t.ticket_client_id
                WHERE " . implode(' AND ', $where) . ' ORDER BY t.ticket_created_at DESC LIMIT ?', $types, $params);
        });
    }

    #[McpTool(name: 'rivetit_get_ticket', description: 'Read one ticket with its description and the 10 most recent replies (public and internal notes).')]
    public function getTicket(RequestContext $context, int $ticket_id): array
    {
        return $this->run($context, 'rivetit_get_ticket', ['ticket_id' => $ticket_id], fn($p) => self::can($p, 'module_support'), function (int $uid) use ($ticket_id) {
            $t = $this->one("SELECT t.ticket_id, CONCAT(IFNULL(t.ticket_prefix,''), t.ticket_number) AS ticket_number,
                    t.ticket_subject, t.ticket_details, t.ticket_priority, s.ticket_status_name AS status,
                    t.ticket_client_id, c.client_name, t.ticket_asset_id, t.ticket_assigned_to,
                    t.ticket_created_at, t.ticket_updated_at, t.ticket_resolved_at, t.ticket_due_at
                FROM tickets t LEFT JOIN ticket_statuses s ON s.ticket_status_id = t.ticket_status
                LEFT JOIN clients c ON c.client_id = t.ticket_client_id
                WHERE t.ticket_id = ? AND t.ticket_archived_at IS NULL AND " . self::scope('t.ticket_client_id'),
                'iii', [$ticket_id, $uid, $uid]);
            $t['ticket_details'] = self::text($t['ticket_details'], 6000);
            $replies = $this->rows('SELECT ticket_reply_id, ticket_reply_type, ticket_reply, ticket_reply_by, ticket_reply_created_at
                FROM ticket_replies WHERE ticket_reply_ticket_id = ? AND ticket_reply_archived_at IS NULL
                ORDER BY ticket_reply_id DESC LIMIT 10', 'i', [$ticket_id]);
            foreach ($replies as &$r) $r['ticket_reply'] = self::text($r['ticket_reply'], 3000);
            $t['recent_replies'] = array_reverse($replies);
            return $t;
        });
    }

    /* ---------------------------------------------------------------- assets */

    #[McpTool(name: 'rivetit_search_assets', description: 'Search assets by name, tag, serial, make, model, or OS. Optional client_id filter. Returns up to 25.')]
    public function searchAssets(RequestContext $context, string $query = '', int $client_id = 0, int $limit = 10): array
    {
        $args = ['query' => $query, 'client_id' => $client_id, 'limit' => $limit];
        return $this->run($context, 'rivetit_search_assets', $args, fn($p) => itflow_profile_can_assets($p), function (int $uid) use ($query, $client_id, $limit) {
            $where = ['a.asset_archived_at IS NULL', self::scope('a.asset_client_id')];
            $types = 'ii';
            $params = [$uid, $uid];
            if ($client_id > 0) { $where[] = 'a.asset_client_id = ?'; $types .= 'i'; $params[] = $client_id; }
            if (trim($query) !== '') {
                $where[] = '(a.asset_name LIKE ? OR a.asset_tag LIKE ? OR a.asset_serial LIKE ? OR a.asset_make LIKE ? OR a.asset_model LIKE ? OR a.asset_os LIKE ?)';
                $like = self::like($query);
                $types .= 'ssssss';
                array_push($params, $like, $like, $like, $like, $like, $like);
            }
            $types .= 'i';
            $params[] = self::limit($limit);
            return $this->rows('SELECT a.asset_id, a.asset_name, a.asset_type, a.asset_make, a.asset_model, a.asset_serial,
                    a.asset_tag, a.asset_os, a.asset_status, a.asset_client_id, c.client_name
                FROM assets a LEFT JOIN clients c ON c.client_id = a.asset_client_id
                WHERE ' . implode(' AND ', $where) . ' ORDER BY a.asset_name LIMIT ?', $types, $params);
        });
    }

    #[McpTool(name: 'rivetit_get_asset', description: 'Read one asset: hardware identity, OS, status, dates, location, assigned contact, and notes. Never returns PINs or credentials.')]
    public function getAsset(RequestContext $context, int $asset_id): array
    {
        return $this->run($context, 'rivetit_get_asset', ['asset_id' => $asset_id], fn($p) => itflow_profile_can_assets($p), function (int $uid) use ($asset_id) {
            $a = $this->one('SELECT a.asset_id, a.asset_name, a.asset_type, a.asset_description, a.asset_make, a.asset_model,
                    a.asset_serial, a.asset_tag, a.asset_os, a.asset_status, a.asset_purchase_date, a.asset_warranty_expire,
                    a.asset_install_date, a.asset_physical_location, a.asset_notes, a.asset_created_at, a.asset_updated_at,
                    a.asset_client_id, c.client_name, a.asset_contact_id, ct.contact_name AS assigned_contact
                FROM assets a LEFT JOIN clients c ON c.client_id = a.asset_client_id
                LEFT JOIN contacts ct ON ct.contact_id = a.asset_contact_id
                WHERE a.asset_id = ? AND a.asset_archived_at IS NULL AND ' . self::scope('a.asset_client_id'),
                'iii', [$asset_id, $uid, $uid]);
            $a['asset_notes'] = self::text($a['asset_notes'], 4000);
            return $a;
        });
    }

    /* ---------------------------------------------------------------- clients and contacts */

    #[McpTool(name: 'rivetit_list_clients', description: 'List active clients/departments, optionally filtered by name. Returns up to 25.')]
    public function listClients(RequestContext $context, string $query = '', int $limit = 25): array
    {
        return $this->run($context, 'rivetit_list_clients', ['query' => $query, 'limit' => $limit], fn($p) => self::can($p, 'module_client'), function (int $uid) use ($query, $limit) {
            $where = ['c.client_archived_at IS NULL', self::scope('c.client_id')];
            $types = 'ii';
            $params = [$uid, $uid];
            if (trim($query) !== '') { $where[] = 'c.client_name LIKE ?'; $types .= 's'; $params[] = self::like($query); }
            $types .= 'i';
            $params[] = self::limit($limit);
            return $this->rows('SELECT c.client_id, c.client_name, c.client_type, c.client_status, c.client_website
                FROM clients c WHERE ' . implode(' AND ', $where) . ' ORDER BY c.client_name LIMIT ?', $types, $params);
        });
    }

    #[McpTool(name: 'rivetit_search_contacts', description: 'Search people (contacts/users) by name, email, or title. Optional client_id filter. Returns up to 25.')]
    public function searchContacts(RequestContext $context, string $query = '', int $client_id = 0, int $limit = 10): array
    {
        $args = ['query' => $query, 'client_id' => $client_id, 'limit' => $limit];
        return $this->run($context, 'rivetit_search_contacts', $args, fn($p) => self::can($p, 'module_client'), function (int $uid) use ($query, $client_id, $limit) {
            $where = ['ct.contact_archived_at IS NULL', self::scope('ct.contact_client_id')];
            $types = 'ii';
            $params = [$uid, $uid];
            if ($client_id > 0) { $where[] = 'ct.contact_client_id = ?'; $types .= 'i'; $params[] = $client_id; }
            if (trim($query) !== '') {
                $where[] = '(ct.contact_name LIKE ? OR ct.contact_email LIKE ? OR ct.contact_title LIKE ?)';
                $like = self::like($query);
                $types .= 'sss';
                array_push($params, $like, $like, $like);
            }
            $types .= 'i';
            $params[] = self::limit($limit);
            return $this->rows('SELECT ct.contact_id, ct.contact_name, ct.contact_title, ct.contact_email, ct.contact_phone,
                    ct.contact_mobile, ct.contact_department, ct.contact_client_id, c.client_name
                FROM contacts ct LEFT JOIN clients c ON c.client_id = ct.contact_client_id
                WHERE ' . implode(' AND ', $where) . ' ORDER BY ct.contact_name LIMIT ?', $types, $params);
        });
    }

    /* ---------------------------------------------------------------- knowledge base */

    #[McpTool(name: 'rivetit_search_kb', description: 'Search knowledge base articles by title or content. Returns up to 25 with a short excerpt.')]
    public function searchKb(RequestContext $context, string $query, int $limit = 10): array
    {
        return $this->run($context, 'rivetit_search_kb', ['query' => $query, 'limit' => $limit], fn($p) => self::can($p, 'module_kb'), function (int $uid) use ($query, $limit) {
            $like = self::like($query);
            $rows = $this->rows('SELECT a.kb_article_id, a.kb_article_title, a.kb_article_client_id, a.kb_article_updated_at,
                    a.kb_article_content_raw
                FROM kb_articles a WHERE a.kb_article_archived_at IS NULL
                  AND (a.kb_article_client_id = 0 OR ' . self::scope('a.kb_article_client_id') . ')
                  AND (a.kb_article_title LIKE ? OR a.kb_article_content_raw LIKE ?)
                ORDER BY (a.kb_article_title LIKE ?) DESC, a.kb_article_updated_at DESC LIMIT ?',
                'iisssi', [$uid, $uid, $like, $like, $like, self::limit($limit)]);
            foreach ($rows as &$r) {
                $r['excerpt'] = self::text($r['kb_article_content_raw'], 240);
                unset($r['kb_article_content_raw']);
            }
            return $rows;
        });
    }

    #[McpTool(name: 'rivetit_get_kb_article', description: 'Read one knowledge base article as plain text (truncated to 20,000 characters).')]
    public function getKbArticle(RequestContext $context, int $article_id): array
    {
        return $this->run($context, 'rivetit_get_kb_article', ['article_id' => $article_id], fn($p) => self::can($p, 'module_kb'), function (int $uid) use ($article_id) {
            $a = $this->one('SELECT a.kb_article_id, a.kb_article_title, a.kb_article_content_raw, a.kb_article_client_id,
                    a.kb_article_created_at, a.kb_article_updated_at
                FROM kb_articles a WHERE a.kb_article_id = ? AND a.kb_article_archived_at IS NULL
                  AND (a.kb_article_client_id = 0 OR ' . self::scope('a.kb_article_client_id') . ')',
                'iii', [$article_id, $uid, $uid]);
            $a['content'] = self::text($a['kb_article_content_raw'], 20000, true);
            unset($a['kb_article_content_raw']);
            return $a;
        });
    }
}
