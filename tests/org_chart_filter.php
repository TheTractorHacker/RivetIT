<?php

require_once __DIR__ . '/../agent/includes/org_chart_filter.php';

function contact(int $manager, int $location, string $status = 'active'): array
{
    return ['manager_id' => $manager, 'location_id' => $location, 'raw_status' => $status];
}

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$contacts = [
    1 => contact(0, 10),
    2 => contact(1, 20),
    3 => contact(2, 20, 'terminated'),
    4 => contact(99, 20),
    5 => contact(6, 20),
    6 => contact(5, 10),
    7 => contact(1, 10),
];

[$filtered, $count] = org_chart_filter_contacts($contacts, 20, 'active');
verify($count === 3, 'Only matching contacts should count as matches');
verify(array_keys($filtered) === [1, 2, 4, 5, 6], 'Keep matching contacts and authorized ancestors only');
verify($filtered[1]['is_context'] && $filtered[6]['is_context'], 'Nonmatching ancestors need context labels');
verify(!$filtered[2]['is_context'] && !$filtered[4]['is_context'], 'Matching contacts are not context');
verify(!isset($filtered[99]), 'An unavailable manager must not be created or disclosed');

$restricted = $contacts;
unset($restricted[1]);
[$filtered] = org_chart_filter_contacts($restricted, 20, 'active');
verify(!isset($filtered[1]), 'Filtering must not restore an out-of-scope manager');

[$unfiltered, $count] = org_chart_filter_contacts($contacts, 0, '');
verify($count === count($contacts), 'The unfiltered count should include the entire authorized set');
verify(!array_filter($unfiltered, fn($row) => $row['is_context']), 'The unfiltered view has no context nodes');

[$empty, $count] = org_chart_filter_contacts($contacts, 10, 'terminated');
verify($count === 0 && $empty === [], 'An empty intersection should show no contacts');

echo "Org chart filter checks passed\n";
