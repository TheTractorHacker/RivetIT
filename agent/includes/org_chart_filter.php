<?php

/**
 * Keep contacts matching the requested filters and the permitted ancestors
 * needed to explain their reporting chain. The caller supplies only contacts
 * already authorized for the current viewer; this function never fetches or
 * infers an out-of-scope manager.
 *
 * @return array{0: array<int, array<string, mixed>>, 1: int}
 */
function org_chart_filter_contacts(array $contacts_by_id, int $location_filter_id, string $status_filter): array
{
    if ($location_filter_id === 0 && $status_filter === '') {
        foreach ($contacts_by_id as &$contact) {
            $contact['is_context'] = false;
        }
        unset($contact);
        return [$contacts_by_id, count($contacts_by_id)];
    }

    $matching = [];
    $visible = [];
    foreach ($contacts_by_id as $id => $contact) {
        if (($location_filter_id === 0 || $contact['location_id'] === $location_filter_id)
            && ($status_filter === '' || $contact['raw_status'] === $status_filter)) {
            $matching[$id] = true;
            $visible[$id] = true;
        }
    }

    foreach (array_keys($matching) as $id) {
        $seen = [$id => true];
        $manager_id = $contacts_by_id[$id]['manager_id'];
        while ($manager_id > 0 && isset($contacts_by_id[$manager_id]) && !isset($seen[$manager_id])) {
            $seen[$manager_id] = true;
            $visible[$manager_id] = true;
            $manager_id = $contacts_by_id[$manager_id]['manager_id'];
        }
    }

    $filtered = array_intersect_key($contacts_by_id, $visible);
    foreach ($filtered as $id => &$contact) {
        $contact['is_context'] = !isset($matching[$id]);
    }
    unset($contact);
    return [$filtered, count($matching)];
}
