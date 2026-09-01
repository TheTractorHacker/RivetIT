<?php
/*
 * CRM Engagement - shared helper functions (pure, no output)
 *
 * Segment criteria resolution used by:
 *   - agent/segments.php        (list + saved-count)
 *   - agent/campaigns.php       (recipient preview)
 *   - agent/crm_ajax.php        (live segment preview)
 *   - agent/post/segment.php    (validate/store criteria)
 *   - agent/post/campaign.php   (materialize recipients on send)
 *
 * Criteria is stored as JSON on crm_segments.segment_criteria_json.
 * Canonical keys (all optional): tag_id (int), client_id (int),
 * lead_status ('lead'|'customer'), has_open_opportunity (1).
 */

// Direct-access guard: these are pure functions and rely on the app bootstrap.
if (!function_exists('sanitizeInput')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!function_exists('crmActivityTypes')) {
    function crmActivityTypes() {
        return [
            'call'    => ['label' => 'Call',    'icon' => 'fa-phone-alt'],
            'email'   => ['label' => 'Email',   'icon' => 'fa-envelope'],
            'meeting' => ['label' => 'Meeting', 'icon' => 'fa-handshake'],
            'note'    => ['label' => 'Note',    'icon' => 'fa-sticky-note'],
        ];
    }
}

if (!function_exists('crmSanitizeCriteria')) {
    // Normalise raw request/decoded input into the canonical criteria array.
    function crmSanitizeCriteria($raw) {
        $c = [];
        if (!is_array($raw)) {
            return $c;
        }
        if (!empty($raw['tag_id'])) {
            $c['tag_id'] = intval($raw['tag_id']);
        }
        if (!empty($raw['client_id'])) {
            $c['client_id'] = intval($raw['client_id']);
        }
        if (isset($raw['lead_status']) && in_array($raw['lead_status'], ['lead', 'customer'], true)) {
            $c['lead_status'] = $raw['lead_status'];
        }
        if (!empty($raw['has_open_opportunity'])) {
            $c['has_open_opportunity'] = 1;
        }
        return $c;
    }
}

if (!function_exists('crmCriteriaFromJson')) {
    function crmCriteriaFromJson($json) {
        $decoded = json_decode((string)$json, true);
        return crmSanitizeCriteria(is_array($decoded) ? $decoded : []);
    }
}

if (!function_exists('crmContactBaseWhere')) {
    // Builds a safe WHERE fragment (values are ints / whitelisted strings only).
    function crmContactBaseWhere(array $c, $client_access_string, $is_admin) {
        $where = "contacts.contact_archived_at IS NULL";

        // Restricted (non-admin) users only see their permitted clients.
        if (empty($is_admin) && !empty($client_access_string)) {
            $where .= " AND contacts.contact_client_id IN ($client_access_string)";
        }

        if (!empty($c['client_id'])) {
            $cid = intval($c['client_id']);
            $where .= " AND contacts.contact_client_id = $cid";
        }

        if (!empty($c['tag_id'])) {
            $tid = intval($c['tag_id']);
            $where .= " AND EXISTS (SELECT 1 FROM contact_tags ct WHERE ct.contact_id = contacts.contact_id AND ct.tag_id = $tid)";
        }

        if (isset($c['lead_status']) && $c['lead_status'] === 'lead') {
            $where .= " AND clients.client_lead = 1";
        } elseif (isset($c['lead_status']) && $c['lead_status'] === 'customer') {
            $where .= " AND (clients.client_lead = 0 OR clients.client_lead IS NULL)";
        }

        if (!empty($c['has_open_opportunity'])) {
            $where .= " AND EXISTS (SELECT 1 FROM opportunities o WHERE o.opportunity_contact_id = contacts.contact_id AND o.opportunity_status = 'open')";
        }

        return $where;
    }
}

if (!function_exists('crmResolveContacts')) {
    function crmResolveContacts($mysqli, array $c, $client_access_string, $is_admin, $require_email = false, $limit = 0) {
        $where = crmContactBaseWhere($c, $client_access_string, $is_admin);
        if ($require_email) {
            $where .= " AND contacts.contact_email IS NOT NULL AND contacts.contact_email <> ''";
        }
        $limit_sql = ($limit > 0) ? (" LIMIT " . intval($limit)) : "";
        $sql = "SELECT contacts.contact_id, contacts.contact_name, contacts.contact_email, contacts.contact_client_id, clients.client_name
                FROM contacts
                LEFT JOIN clients ON contacts.contact_client_id = clients.client_id
                WHERE $where
                ORDER BY contacts.contact_name ASC$limit_sql";
        $res = mysqli_query($mysqli, $sql);
        $rows = [];
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $rows[] = $r;
            }
        }
        return $rows;
    }
}

if (!function_exists('crmContactCount')) {
    function crmContactCount($mysqli, array $c, $client_access_string, $is_admin, $require_email = false) {
        $where = crmContactBaseWhere($c, $client_access_string, $is_admin);
        if ($require_email) {
            $where .= " AND contacts.contact_email IS NOT NULL AND contacts.contact_email <> ''";
        }
        $sql = "SELECT COUNT(*) AS cnt
                FROM contacts
                LEFT JOIN clients ON contacts.contact_client_id = clients.client_id
                WHERE $where";
        $res = mysqli_query($mysqli, $sql);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        return intval($row['cnt'] ?? 0);
    }
}

if (!function_exists('crmCriteriaSummary')) {
    // Human-readable, HTML-escaped summary of a criteria array for display.
    function crmCriteriaSummary($mysqli, array $c) {
        $parts = [];
        if (!empty($c['client_id'])) {
            $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_name FROM clients WHERE client_id = " . intval($c['client_id'])));
            $parts[] = "Client: " . nullable_htmlentities($row['client_name'] ?? ('#' . intval($c['client_id'])));
        }
        if (!empty($c['tag_id'])) {
            $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT tag_name FROM tags WHERE tag_id = " . intval($c['tag_id'])));
            $parts[] = "Tag: " . nullable_htmlentities($row['tag_name'] ?? ('#' . intval($c['tag_id'])));
        }
        if (!empty($c['lead_status'])) {
            $parts[] = "Status: " . ucfirst(nullable_htmlentities($c['lead_status']));
        }
        if (!empty($c['has_open_opportunity'])) {
            $parts[] = "Has open opportunity";
        }
        return $parts ? implode(' &middot; ', $parts) : 'All contacts';
    }
}
