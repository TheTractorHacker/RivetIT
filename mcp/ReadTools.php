<?php

use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;

/** Small, read-only MCP surface; every tool rechecks the caller's RivetIT role. */
final class RivetITMcpReadTools
{
    public function __construct(private mysqli $db) {}

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

    #[McpTool(name: 'rivetit_my_profile', description: 'Read the signed-in RivetIT agent profile.')]
    public function myProfile(RequestContext $context): array
    {
        $id = $this->userId($context);
        $stmt = $this->db->prepare('SELECT user_id, user_name, user_email FROM users
            WHERE user_id = ? AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) throw new RuntimeException('Agent is unavailable.');
        return ['id' => (int) $row['user_id'], 'name' => $row['user_name'], 'email' => $row['user_email']];
    }

    #[McpTool(name: 'rivetit_recent_tickets', description: 'Read up to 20 recent open tickets within the signed-in agent’s department access.')]
    public function recentTickets(RequestContext $context, int $limit = 10): array
    {
        $id = $this->userId($context);
        require_once __DIR__ . '/../includes/module_access.php';
        if (itflow_profile_level(itflow_user_access_profile($id), 'module_support') < 1) {
            throw new RuntimeException('Tickets permission is required.');
        }
        $limit = max(1, min(20, $limit));
        $stmt = $this->db->prepare('SELECT t.ticket_id, t.ticket_number, t.ticket_subject,
                t.ticket_priority, t.ticket_created_at
            FROM tickets t WHERE t.ticket_archived_at IS NULL AND t.ticket_resolved_at IS NULL
              AND (NOT EXISTS (SELECT 1 FROM user_client_permissions p WHERE p.user_id = ?)
                   OR EXISTS (SELECT 1 FROM user_client_permissions p
                              WHERE p.user_id = ? AND p.client_id = t.ticket_client_id))
            ORDER BY t.ticket_created_at DESC LIMIT ?');
        $stmt->bind_param('iii', $id, $id, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
