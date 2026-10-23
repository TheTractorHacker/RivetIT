<?php

declare(strict_types=1);

require_once __DIR__ . '/EndpointKit.php';

use RivetCore\Panel\Contracts\PanelTicketGatewayInterface;
use RivetCore\Testing\PanelTicketGatewayConformanceTestCase;

/**
 * EndpointPanelGateway (RivetPanel's PanelTicketGatewayInterface adapter) over tickets, ticket_replies, categories and contacts.
 * Needs the 2.6.195 schema (endpoint_agent_devices/enrollment_tokens.kind+contact_id, panel_client_settings, panel_ticket_owner) -
 * a plain db.sql import already has it. Seeds the one settings row and one active ticket status every ticket creation needs.
 */
final class PanelTicketGatewayConformanceTest extends PanelTicketGatewayConformanceTestCase
{
    private static ?string $uploadsTestDir = null;

    public static function setUpBeforeClass(): void
    {
        $db = EndpointKit::db();
        if ($db->fetchOne('SELECT company_id FROM settings WHERE company_id = 1') === null) {
            $db->execute("INSERT INTO settings (company_id, config_current_database_version) VALUES (1, '2.6.195')");
        }
        if ($db->fetchOne("SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_name = 'New'") === null) {
            $db->execute("INSERT INTO ticket_statuses SET ticket_status_name = 'New', ticket_status_color = '#000', ticket_status_active = 1, ticket_status_order = 1");
        }

        // Safe by default, not by whoever invokes phpunit remembering to export a variable: EndpointPanelGateway
        // writes attachments under $root/tickets/{ticket_id} (RIVETIT_PANEL_TEST_UPLOADS_DIR overrides $root), and
        // the live uploads/ tree is keyed by the SAME ticket_id space real production tickets use - running this
        // kit without redirecting it would write scratch attachment files into (or next to) real tickets' folders.
        // Set here so every run is isolated whether or not the caller knows about the override.
        self::$uploadsTestDir = sys_get_temp_dir() . '/rivetit_panel_conformance_' . bin2hex(random_bytes(8));
        mkdir(self::$uploadsTestDir, 0755, true);
        putenv('RIVETIT_PANEL_TEST_UPLOADS_DIR=' . self::$uploadsTestDir);
    }

    public static function tearDownAfterClass(): void
    {
        putenv('RIVETIT_PANEL_TEST_UPLOADS_DIR');
        if (self::$uploadsTestDir !== null && is_dir(self::$uploadsTestDir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::$uploadsTestDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) { $f->isDir() ? rmdir((string) $f) : unlink((string) $f); }
            rmdir(self::$uploadsTestDir);
        }
    }

    protected function gateway(): PanelTicketGatewayInterface
    {
        return new \ITFlow\Core\Adapter\Endpoint\EndpointPanelGateway(EndpointKit::mysqli());
    }

    protected function createClient(string $name): int
    {
        return EndpointKit::client($name);
    }

    protected function createContact(int $clientId, string $name): int
    {
        return (int) EndpointKit::db()->execute('INSERT INTO contacts SET contact_name = ?, contact_client_id = ?, contact_created_at = NOW()', [$name, $clientId])->insertId;
    }

    protected function archiveContact(int $contactId): void
    {
        EndpointKit::db()->execute('UPDATE contacts SET contact_archived_at = NOW() WHERE contact_id = ?', [$contactId]);
    }

    protected function createCategory(int $clientId, string $name): int
    {
        // RivetIT's ticket categories are a single global list (no client_id column at all) - $clientId is accepted only to match
        // the kit's signature, exactly like EndpointPanelGateway::categories() itself ignores it beyond checking the client exists.
        return (int) EndpointKit::db()->execute("INSERT INTO categories SET category_name = ?, category_type = 'Ticket', category_created_at = NOW()", [$name])->insertId;
    }

    protected function addStaffReply(int $ticketId, string $body): void
    {
        EndpointKit::db()->execute("INSERT INTO ticket_replies SET ticket_reply = ?, ticket_reply_type = 'Public', ticket_reply_by = 1, ticket_reply_ticket_id = ?", [$body, $ticketId]);
    }

    protected function addInternalNote(int $ticketId, string $body): void
    {
        EndpointKit::db()->execute("INSERT INTO ticket_replies SET ticket_reply = ?, ticket_reply_type = 'Internal', ticket_reply_by = 1, ticket_reply_ticket_id = ?", [$body, $ticketId]);
    }
}
