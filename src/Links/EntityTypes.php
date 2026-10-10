<?php

declare(strict_types=1);

namespace ITFlow\Links;

/**
 * The record types an entity link can join, and everything the link service needs to know about each: which table holds the
 * record, which column names it and scopes it to a department, which role modules may read and change it, and where its page is.
 *
 * Also the DERIVED mappings: the older hard-wired link tables (asset_documents, software_assets, service_assets, ...) and a few
 * foreign-key columns, read at query time and shown beside the real links as read-only rows. Nothing is copied.
 */
final class EntityTypes
{
    public const LINK_TYPES = ['depends_on', 'runs_on', 'supported_by', 'documented_by', 'related'];

    /** Link types that carry an outage downstream: "A depends_on B" means B going down affects A. */
    public const IMPACT_TYPES = ['depends_on', 'runs_on', 'supported_by'];

    public const MAX_DEPTH = 3;

    public const VENDOR_ROLES = ['support', 'reseller', 'manufacturer'];

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $specs = null;

    /**
     * 'modules' is an any-of list (a role needs the level on at least one); 'write' is the list for changing the record.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        return self::$specs ??= [
            'asset' => ['label' => 'Asset', 'icon' => 'fa-laptop', 'table' => 'assets', 'pk' => 'asset_id', 'name' => 'asset_name', 'client' => 'asset_client_id', 'archived' => 'asset_archived_at',
                'modules' => ['module_assets', 'module_support'], 'page' => 'asset_details.php?client_id={c}&asset_id={id}'],
            'software' => ['label' => 'Software', 'icon' => 'fa-cube', 'table' => 'software', 'pk' => 'software_id', 'name' => 'software_name', 'client' => 'software_client_id', 'archived' => 'software_archived_at',
                'modules' => ['module_support'], 'page' => 'software.php?client_id={c}&q={name}'],
            'vendor' => ['label' => 'Vendor', 'icon' => 'fa-building', 'table' => 'vendors', 'pk' => 'vendor_id', 'name' => 'vendor_name', 'client' => 'vendor_client_id', 'archived' => 'vendor_archived_at',
                'modules' => ['module_client', 'module_support', 'module_financial'], 'page' => 'vendors.php?client_id={c}&q={name}'],
            'document' => ['label' => 'Document', 'icon' => 'fa-file-alt', 'table' => 'documents', 'pk' => 'document_id', 'name' => 'document_name', 'client' => 'document_client_id', 'archived' => 'document_archived_at',
                'modules' => ['module_support'], 'page' => 'document_details.php?client_id={c}&document_id={id}'],
            'kb_article' => ['label' => 'KB article', 'icon' => 'fa-book', 'table' => 'kb_articles', 'pk' => 'kb_article_id', 'name' => 'kb_article_title', 'client' => 'kb_article_client_id', 'archived' => 'kb_article_archived_at',
                'modules' => ['module_kb'], 'page' => 'kb_article.php?id={id}'],
            'service' => ['label' => 'Service', 'icon' => 'fa-concierge-bell', 'table' => 'services', 'pk' => 'service_id', 'name' => 'service_name', 'client' => 'service_client_id', 'archived' => null,
                'modules' => ['module_support'], 'page' => 'services.php?client_id={c}'],
            'network' => ['label' => 'Network', 'icon' => 'fa-network-wired', 'table' => 'networks', 'pk' => 'network_id', 'name' => 'network_name', 'client' => 'network_client_id', 'archived' => 'network_archived_at',
                'modules' => ['module_support'], 'page' => 'networks.php?client_id={c}&q={name}'],
            'domain' => ['label' => 'Domain', 'icon' => 'fa-globe', 'table' => 'domains', 'pk' => 'domain_id', 'name' => 'domain_name', 'client' => 'domain_client_id', 'archived' => 'domain_archived_at',
                'modules' => ['module_support'], 'page' => 'domain_details.php?client_id={c}&id={id}'],
            'certificate' => ['label' => 'Certificate', 'icon' => 'fa-lock', 'table' => 'certificates', 'pk' => 'certificate_id', 'name' => 'certificate_name', 'client' => 'certificate_client_id', 'archived' => 'certificate_archived_at',
                'modules' => ['module_support'], 'page' => 'certificate_details.php?client_id={c}&id={id}'],
            'credential' => ['label' => 'Credential', 'icon' => 'fa-key', 'table' => 'credentials', 'pk' => 'credential_id', 'name' => 'credential_name', 'client' => 'credential_client_id', 'archived' => 'credential_archived_at',
                'modules' => ['module_credential'], 'page' => 'credentials.php?client_id={c}&q={name}'],
            'contact' => ['label' => 'Contact', 'icon' => 'fa-user', 'table' => 'contacts', 'pk' => 'contact_id', 'name' => 'contact_name', 'client' => 'contact_client_id', 'archived' => 'contact_archived_at',
                'modules' => ['module_client'], 'page' => 'contact_details.php?client_id={c}&contact_id={id}'],
            'location' => ['label' => 'Location', 'icon' => 'fa-map-marker-alt', 'table' => 'locations', 'pk' => 'location_id', 'name' => 'location_name', 'client' => 'location_client_id', 'archived' => 'location_archived_at',
                'modules' => ['module_client', 'module_support'], 'page' => 'locations.php?client_id={c}&q={name}'],
            'ticket' => ['label' => 'Ticket', 'icon' => 'fa-life-ring', 'table' => 'tickets', 'pk' => 'ticket_id', 'name' => "CONCAT(ticket_prefix, ticket_number, ' ', ticket_subject)", 'client' => 'ticket_client_id', 'archived' => 'ticket_archived_at',
                'modules' => ['module_support'], 'page' => 'ticket.php?ticket_id={id}'],
        ];
    }

    /** @return array<string,mixed>|null */
    public static function spec(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    public static function isType(string $type): bool
    {
        return isset(self::all()[$type]);
    }

    public static function isLinkType(string $linkType): bool
    {
        return in_array($linkType, self::LINK_TYPES, true);
    }

    public static function linkTypeLabel(string $linkType): string
    {
        return ['depends_on' => 'depends on', 'runs_on' => 'runs on', 'supported_by' => 'supported by', 'documented_by' => 'documented by', 'related' => 'related to'][$linkType] ?? $linkType;
    }

    /** The label read from the other end: "A depends on B" is "B is depended on by A". */
    public static function reverseLabel(string $linkType): string
    {
        return ['depends_on' => 'depended on by', 'runs_on' => 'hosts', 'supported_by' => 'supports', 'documented_by' => 'documents', 'related' => 'related to'][$linkType] ?? $linkType;
    }

    public static function pageUrl(string $type, int $id, int $clientId, string $name = ''): string
    {
        $spec = self::spec($type);
        if ($spec === null) {
            return '#';
        }
        $url = str_replace(['{id}', '{c}', '{name}'], [(string) $id, (string) $clientId, rawurlencode($name)], (string) $spec['page']);
        // A global record (department 0) has no department page; the pages that need one fall back to the department-less form.
        if ($clientId === 0) {
            $url = (string) preg_replace('/client_id=0&?/', '', $url);
            $url = rtrim($url, '?&');
        }

        return $url;
    }

    /**
     * Read-only rows drawn from the older link tables and foreign keys. 'kind' is 'pair' (a two-column table) or 'fk' (a column on the
     * left record that points at the right one). left -> right is the stored direction ("left <link_type> right").
     *
     * @return list<array{kind:string,table:string,left:string,lcol:string,right:string,rcol:string,link_type:string,label:string,where?:string}>
     */
    public static function derivedMaps(): array
    {
        $p = static fn (string $table, string $left, string $lcol, string $right, string $rcol, string $linkType, string $label): array => ['kind' => 'pair', 'table' => $table, 'left' => $left, 'lcol' => $lcol, 'right' => $right, 'rcol' => $rcol, 'link_type' => $linkType, 'label' => $label];
        $fk = static fn (string $table, string $pk, string $left, string $col, string $right, string $linkType, string $label, string $where = ''): array => ['kind' => 'fk', 'table' => $table, 'left' => $left, 'lcol' => $pk, 'right' => $right, 'rcol' => $col, 'link_type' => $linkType, 'label' => $label] + ($where !== '' ? ['where' => $where] : []);

        return [
            $p('asset_documents', 'asset', 'asset_id', 'document', 'document_id', 'documented_by', 'Asset documents'),
            $p('asset_credentials', 'asset', 'asset_id', 'credential', 'credential_id', 'related', 'Asset credentials'),
            $p('software_assets', 'software', 'software_id', 'asset', 'asset_id', 'runs_on', 'Software licences'),
            $p('software_documents', 'software', 'software_id', 'document', 'document_id', 'documented_by', 'Software documents'),
            $p('software_credentials', 'software', 'software_id', 'credential', 'credential_id', 'related', 'Software credentials'),
            $p('software_contacts', 'software', 'software_id', 'contact', 'contact_id', 'related', 'Software contacts'),
            $p('service_assets', 'service', 'service_id', 'asset', 'asset_id', 'depends_on', 'Service assets'),
            $p('service_vendors', 'service', 'service_id', 'vendor', 'vendor_id', 'supported_by', 'Service vendors'),
            $p('service_documents', 'service', 'service_id', 'document', 'document_id', 'documented_by', 'Service documents'),
            $p('service_domains', 'service', 'service_id', 'domain', 'domain_id', 'depends_on', 'Service domains'),
            $p('service_certificates', 'service', 'service_id', 'certificate', 'certificate_id', 'depends_on', 'Service certificates'),
            $p('service_contacts', 'service', 'service_id', 'contact', 'contact_id', 'related', 'Service contacts'),
            $p('service_credentials', 'service', 'service_id', 'credential', 'credential_id', 'depends_on', 'Service credentials'),
            $p('contact_assets', 'contact', 'contact_id', 'asset', 'asset_id', 'related', 'Contact assets'),
            $p('contact_credentials', 'contact', 'contact_id', 'credential', 'credential_id', 'related', 'Contact credentials'),
            $p('contact_documents', 'contact', 'contact_id', 'document', 'document_id', 'related', 'Contact documents'),
            $p('vendor_credentials', 'vendor', 'vendor_id', 'credential', 'credential_id', 'related', 'Vendor credentials'),
            $p('vendor_documents', 'vendor', 'vendor_id', 'document', 'document_id', 'documented_by', 'Vendor documents'),
            $p('ticket_assets', 'ticket', 'ticket_id', 'asset', 'asset_id', 'related', 'Ticket assets'),
            $fk('assets', 'asset_id', 'asset', 'asset_location_id', 'location', 'runs_on', 'Asset location', 'asset_location_id > 0'),
            $fk('assets', 'asset_id', 'asset', 'asset_contact_id', 'contact', 'related', 'Asset assigned contact', 'asset_contact_id > 0'),
            $fk('tickets', 'ticket_id', 'ticket', 'ticket_asset_id', 'asset', 'related', 'Ticket asset', 'ticket_asset_id > 0'),
            $fk('certificates', 'certificate_id', 'certificate', 'certificate_domain_id', 'domain', 'depends_on', 'Certificate domain', 'certificate_domain_id > 0'),
            $fk('credentials', 'credential_id', 'credential', 'credential_asset_id', 'asset', 'related', 'Credential asset', 'credential_asset_id > 0'),
            $fk('credentials', 'credential_id', 'credential', 'credential_software_id', 'software', 'related', 'Credential software', 'credential_software_id > 0'),
            $fk('credentials', 'credential_id', 'credential', 'credential_vendor_id', 'vendor', 'related', 'Credential vendor', 'credential_vendor_id > 0'),
            $fk('credentials', 'credential_id', 'credential', 'credential_contact_id', 'contact', 'related', 'Credential contact', 'credential_contact_id > 0'),
            $fk('asset_interfaces', 'interface_asset_id', 'asset', 'interface_network_id', 'network', 'runs_on', 'Interface network', 'interface_network_id > 0 AND interface_archived_at IS NULL'),
            // The multi-vendor roles (the primary vendor on the record is merged in by VendorRoles; these are the extra rows).
            ['kind' => 'pair', 'table' => 'asset_vendors', 'left' => 'asset', 'lcol' => 'asset_id', 'right' => 'vendor', 'rcol' => 'vendor_id', 'link_type' => 'supported_by', 'label' => 'Asset vendors'],
            ['kind' => 'pair', 'table' => 'software_vendors', 'left' => 'software', 'lcol' => 'software_id', 'right' => 'vendor', 'rcol' => 'vendor_id', 'link_type' => 'supported_by', 'label' => 'Software vendors'],
            $fk('assets', 'asset_id', 'asset', 'asset_vendor_id', 'vendor', 'supported_by', 'Asset vendor', 'asset_vendor_id > 0'),
            $fk('software', 'software_id', 'software', 'software_vendor_id', 'vendor', 'supported_by', 'Software vendor', 'software_vendor_id > 0'),
        ];
    }
}
