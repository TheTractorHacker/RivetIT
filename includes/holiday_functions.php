<?php

/*
 * Computes a country's well-known public/federal holidays for a given
 * Gregorian year, for the SLA Business Hours "Load Federal Holidays"
 * feature (admin/modals/sla/calendar_edit.php). Deliberately limited to
 * countries whose holidays are fully defined by fixed dates, simple
 * weekday-of-month rules, or an Easter offset (PHP's built-in
 * easter_date(), Gregorian computus - no lunar/Islamic/other non-Gregorian
 * calendar support). getFederalHolidayCountries() reports which countries
 * are covered; getFederalHolidaysForCountry() returns [] for anything else
 * rather than guessing.
 */

// $weekday: 0=Sunday..6=Saturday, matching date('w'). Returns 'Y-m-d'.
function nthWeekdayOfMonth(int $year, int $month, int $weekday, int $n): string {
    $date = new DateTime(sprintf('%04d-%02d-01', $year, $month));
    $first_weekday = intval($date->format('w'));
    $day = 1 + (($weekday - $first_weekday + 7) % 7) + ($n - 1) * 7;
    $date->setDate($year, $month, $day);
    return $date->format('Y-m-d');
}

function lastWeekdayOfMonth(int $year, int $month, int $weekday): string {
    $date = new DateTime(sprintf('%04d-%02d-01', $year, $month));
    $date->modify('last day of this month');
    $last_weekday = intval($date->format('w'));
    $day = intval($date->format('d')) - (($last_weekday - $weekday + 7) % 7);
    $date->setDate($year, $month, $day);
    return $date->format('Y-m-d');
}

// US federal holidays observe a weekend-adjacent date on the nearest
// weekday (Saturday -> observed Friday, Sunday -> observed Monday) - that's
// the date offices actually close on, so it's what an SLA business-hours
// calendar should reflect, not the nominal calendar date.
function usObservedDate(string $ymd): string {
    $date = new DateTime($ymd);
    $dow = intval($date->format('w'));
    if ($dow === 6) { $date->modify('-1 day'); }
    elseif ($dow === 0) { $date->modify('+1 day'); }
    return $date->format('Y-m-d');
}

function easterYmd(int $year): string {
    return date('Y-m-d', easter_date($year));
}

function getFederalHolidayCountries(): array {
    return ['United States', 'United Kingdom', 'Canada', 'Australia'];
}

// The generic term "Federal Holidays" is US-specific - other countries call
// this list something else officially (the UK/Australia don't have a
// federal system of government at all). Used everywhere the holiday
// catalog/loader talks about "this country's list of holidays" so the
// wording matches the configured country instead of defaulting to the US term.
function getHolidayTermForCountry(string $country): string {
    switch ($country) {
        case 'United States':
            return 'Federal Holidays';
        case 'United Kingdom':
            return 'Bank Holidays';
        case 'Canada':
            return 'Statutory Holidays';
        case 'Australia':
            return 'Public Holidays';
        default:
            return 'Public Holidays';
    }
}

// Returns a list of ['date' => 'Y-m-d', 'name' => string], sorted by date.
// UK/Canada/Australia are the nominal calendar date (no weekend
// substitution) - only the US list is "observed" - so treat those three as
// a solid starting point to hand-adjust, not a guaranteed-accurate
// office-closure calendar.
function getFederalHolidaysForCountry(string $country, int $year): array {
    $holidays = [];

    switch ($country) {
        case 'United States':
            $holidays[] = ['date' => usObservedDate("$year-01-01"), 'name' => "New Year's Day"];
            $holidays[] = ['date' => nthWeekdayOfMonth($year, 1, 1, 3), 'name' => "Martin Luther King, Jr. Day"];
            $holidays[] = ['date' => nthWeekdayOfMonth($year, 2, 1, 3), 'name' => "Washington's Birthday (Presidents Day)"];
            $holidays[] = ['date' => lastWeekdayOfMonth($year, 5, 1), 'name' => "Memorial Day"];
            $holidays[] = ['date' => usObservedDate("$year-06-19"), 'name' => "Juneteenth National Independence Day"];
            $holidays[] = ['date' => usObservedDate("$year-07-04"), 'name' => "Independence Day"];
            $holidays[] = ['date' => nthWeekdayOfMonth($year, 9, 1, 1), 'name' => "Labor Day"];
            $holidays[] = ['date' => nthWeekdayOfMonth($year, 10, 1, 2), 'name' => "Columbus Day"];
            $holidays[] = ['date' => usObservedDate("$year-11-11"), 'name' => "Veterans Day"];
            $holidays[] = ['date' => nthWeekdayOfMonth($year, 11, 4, 4), 'name' => "Thanksgiving Day"];
            $holidays[] = ['date' => usObservedDate("$year-12-25"), 'name' => "Christmas Day"];
            break;

        case 'United Kingdom':
            $easter = easterYmd($year);
            $holidays[] = ['date' => "$year-01-01", 'name' => "New Year's Day"];
            $holidays[] = ['date' => date('Y-m-d', strtotime("$easter -2 days")), 'name' => "Good Friday"];
            $holidays[] = ['date' => date('Y-m-d', strtotime("$easter +1 day")), 'name' => "Easter Monday"];
            $holidays[] = ['date' => nthWeekdayOfMonth($year, 5, 1, 1), 'name' => "Early May Bank Holiday"];
            $holidays[] = ['date' => lastWeekdayOfMonth($year, 5, 1), 'name' => "Spring Bank Holiday"];
            $holidays[] = ['date' => lastWeekdayOfMonth($year, 8, 1), 'name' => "Summer Bank Holiday"];
            $holidays[] = ['date' => "$year-12-25", 'name' => "Christmas Day"];
            $holidays[] = ['date' => "$year-12-26", 'name' => "Boxing Day"];
            break;

        case 'Canada':
            $easter = easterYmd($year);
            // Victoria Day: the Monday on or before May 24.
            $victoria = new DateTime("$year-05-24");
            $victoria->modify('-' . ((intval($victoria->format('w')) - 1 + 7) % 7) . ' days');
            $holidays[] = ['date' => "$year-01-01", 'name' => "New Year's Day"];
            $holidays[] = ['date' => date('Y-m-d', strtotime("$easter -2 days")), 'name' => "Good Friday"];
            $holidays[] = ['date' => $victoria->format('Y-m-d'), 'name' => "Victoria Day"];
            $holidays[] = ['date' => "$year-07-01", 'name' => "Canada Day"];
            $holidays[] = ['date' => nthWeekdayOfMonth($year, 9, 1, 1), 'name' => "Labour Day"];
            $holidays[] = ['date' => "$year-09-30", 'name' => "National Day for Truth and Reconciliation"];
            $holidays[] = ['date' => nthWeekdayOfMonth($year, 10, 1, 2), 'name' => "Thanksgiving"];
            $holidays[] = ['date' => "$year-11-11", 'name' => "Remembrance Day"];
            $holidays[] = ['date' => "$year-12-25", 'name' => "Christmas Day"];
            $holidays[] = ['date' => "$year-12-26", 'name' => "Boxing Day"];
            break;

        case 'Australia':
            $easter = easterYmd($year);
            $holidays[] = ['date' => "$year-01-01", 'name' => "New Year's Day"];
            $holidays[] = ['date' => "$year-01-26", 'name' => "Australia Day"];
            $holidays[] = ['date' => date('Y-m-d', strtotime("$easter -2 days")), 'name' => "Good Friday"];
            $holidays[] = ['date' => date('Y-m-d', strtotime("$easter +1 day")), 'name' => "Easter Monday"];
            $holidays[] = ['date' => "$year-04-25", 'name' => "Anzac Day"];
            $holidays[] = ['date' => "$year-12-25", 'name' => "Christmas Day"];
            $holidays[] = ['date' => "$year-12-26", 'name' => "Boxing Day"];
            break;
    }

    usort($holidays, fn($a, $b) => strcmp($a['date'], $b['date']));
    return $holidays;
}
