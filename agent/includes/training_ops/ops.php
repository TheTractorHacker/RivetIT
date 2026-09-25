<?php

defined('TRAINING_PAGE') || exit;

/*
 * Training operations pages (Phase 2 spec §5.2, Lane E): shared page-side helpers.
 *
 * Included by agent/training_{assignments,rule,people,records,record,session}.php after the
 * page guard. Nothing here writes: mutations go through the JSON actions from the page JS.
 *
 * Server-rendered first data (tro_action): the page runs the SAME route handler the JSON
 * endpoint would run (Router::routes() lookup, the route's own level, ApiContext over the
 * query), in-process, so the first paint needs no second request and the data has exactly
 * the §4 shape the JS already renders for refetches. When a route is not installed yet (the
 * engines ship in other lanes) or fails, the page JS fetches the action itself and shows the
 * matching state; a refusal (not_found) renders the not-found state.
 */

use ITFlow\Training\Api\ApiContext;
use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Api\Router;
use ITFlow\Training\Core\Access;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;

/**
 * Runs a GET route in-process.
 *
 * @return array{action:string, params:array, state:'ok'|'missing'|'error', data?:mixed, error?:array{code:string,message:string,status:int}}
 */
function tro_action(\mysqli $db, string $action, array $params = []): array
{
    $out = ['action' => $action, 'params' => $params, 'state' => 'missing'];
    try {
        $routes = Router::routes();
    } catch (\Throwable $e) {
        error_log('Training ops page: route table: ' . $e->getMessage());
        return $out;
    }
    $spec = $routes[$action] ?? null;
    if ($spec === null || $spec['method'] !== 'GET' || !empty($spec['raw'])) {
        return $out;
    }
    if (Access::level() < (int) $spec['level']) {
        return $out + ['state' => 'error', 'error' => ['code' => 'forbidden', 'message' => "You don't have access to this part of Training.", 'status' => 403]];
    }
    try {
        $ctx = Access::ctx($db);
        $api = new ApiContext('GET', $params, $ctx->settings);
        [$class, $fn] = explode('::', (string) $spec['handler'], 2);
        $data = $class::$fn($ctx, $api);
        $out['state'] = 'ok';
        $out['data'] = $data;
    } catch (ApiException $e) {
        $out['state'] = 'error';
        $out['error'] = ['code' => $e->errCode, 'message' => $e->getMessage(), 'status' => $e->http];
    } catch (\Throwable $e) {
        error_log('Training ops page ' . $action . ': ' . get_class($e) . ': ' . $e->getMessage());
        $out['state'] = 'missing';   // let the client retry through the endpoint (same error handling as refetches)
    }
    if (Db::depth() > 0) {
        error_log('Training ops page ' . $action . ': handler left a transaction open');
    }
    return $out;
}

/** True when a route with this name is installed (buttons for actions of unmerged lanes stay hidden). */
function tro_has_route(string $action): bool
{
    try {
        return isset(Router::routes()[$action]);
    } catch (\Throwable) {
        return false;
    }
}

/**
 * The caller's people scope, fail-closed (spec §0 #3): 'all' | 'some' | 'none', plus the
 * department ids for 'some'. Uses People\Scope when installed, else the same rule directly.
 *
 * @return array{state:string, client_ids:list<int>}
 */
function tro_scope(\mysqli $db, Ctx $ctx): array
{
    if (class_exists(\ITFlow\Training\People\Scope::class)) {
        try {
            $s = \ITFlow\Training\People\Scope::forCtx($ctx);
            if ($s->isAll()) {
                return ['state' => 'all', 'client_ids' => []];
            }
            if ($s->isNone()) {
                return ['state' => 'none', 'client_ids' => []];
            }
            return ['state' => 'some', 'client_ids' => array_values(array_map('intval', $s->clientIds()))];
        } catch (\Throwable $e) {
            error_log('Training ops page scope: ' . $e->getMessage());
        }
    }
    if ($ctx->isAdmin || $ctx->level >= 3) {
        return ['state' => 'all', 'client_ids' => []];
    }
    $ids = [];
    foreach (Db::all($db, 'SELECT client_id FROM user_client_permissions WHERE user_id = ? ORDER BY client_id', 'i', [$ctx->userId]) as $r) {
        $ids[] = (int) $r['client_id'];
    }
    return $ids === [] ? ['state' => 'none', 'client_ids' => []] : ['state' => 'some', 'client_ids' => $ids];
}

/**
 * Departments (clients) for pickers and filters: every active department for 'all' (plus the
 * "No department" pseudo entry id 0 when $withNone), otherwise only the in-scope ones.
 * people = active (non-archived) contacts in the department.
 *
 * @return list<array{id:int, name:string, people:int}>
 */
function tro_departments(\mysqli $db, array $scope, bool $withNone = false): array
{
    if ($scope['state'] === 'none') {
        return [];
    }
    $where = 'cl.client_archived_at IS NULL';
    $types = '';
    $params = [];
    if ($scope['state'] === 'some') {
        $where .= ' AND cl.client_id IN (' . implode(',', array_fill(0, count($scope['client_ids']), '?')) . ')';
        $types = str_repeat('i', count($scope['client_ids']));
        $params = $scope['client_ids'];
    }
    $rows = Db::all($db, "SELECT cl.client_id, cl.client_name,
            (SELECT COUNT(*) FROM contacts c WHERE c.contact_client_id = cl.client_id AND c.contact_archived_at IS NULL) AS people
        FROM clients cl WHERE $where ORDER BY cl.client_name ASC LIMIT 500", $types, $params);
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['id' => (int) $r['client_id'], 'name' => (string) $r['client_name'], 'people' => (int) $r['people']];
    }
    if ($withNone && $scope['state'] === 'all') {
        $n = Db::one($db, 'SELECT COUNT(*) AS n FROM contacts WHERE contact_client_id = 0 AND contact_archived_at IS NULL');
        $out[] = ['id' => 0, 'name' => 'No department', 'people' => (int) ($n['n'] ?? 0)];
    }
    return $out;
}

/**
 * Published, non-archived courses as §4.1 CourseCards (plus is_qualification, regulation_ref
 * and the practical checklist), read from each current revision's JSON. Only the course
 * settings leave this function: the revision JSON carries answer keys and is never embedded.
 *
 * @return list<array>
 */
function tro_course_cards(\mysqli $db): array
{
    // Only the course settings and the quiz headers are extracted in SQL: the full revision JSON
    // (lessons, questions, answer keys) never leaves the database.
    $rows = Db::all($db, "SELECT c.course_id, c.course_kind, c.course_name, c.course_code, r.revision_id, r.revision_number,
            JSON_EXTRACT(r.revision_json, '$.course') AS rj_course, JSON_EXTRACT(r.revision_json, '$.lessons[*].quiz') AS rj_quizzes
        FROM training_courses c
        JOIN training_revisions r ON r.revision_id = c.course_current_revision_id
        WHERE c.course_archived_at IS NULL AND c.course_current_revision_id IS NOT NULL
        ORDER BY c.course_kind DESC, c.course_name ASC LIMIT 500");
    $out = [];
    foreach ($rows as $r) {
        $course = json_decode((string) $r['rj_course'], true);
        $course = is_array($course) ? $course : [];
        $quizzes = json_decode((string) $r['rj_quizzes'], true);
        $lang = (string) ($course['default_language'] ?? 'en');
        $name = $course['text'][$lang]['name'] ?? $r['course_name'];
        $comp = is_array($course['components'] ?? null) ? $course['components'] : [];
        $passPct = null;
        foreach ((is_array($quizzes) ? $quizzes : []) as $q) {
            if (is_array($q) && ($q['role'] ?? '') === 'exam') {
                $passPct = isset($q['pass_pct']) ? (int) $q['pass_pct'] : null;
                break;
            }
        }
        $checklist = [];
        foreach ((is_array($course['eval_checklist'] ?? null) ? $course['eval_checklist'] : []) as $item) {
            if (is_array($item) && isset($item['item'])) {
                $checklist[] = ['item' => (string) $item['item'], 'critical' => !empty($item['critical'])];
            }
        }
        $out[] = [
            'id' => (int) $r['course_id'],
            'name' => (string) $name,
            'code' => $course['code'] ?? $r['course_code'],
            'kind' => (string) $r['course_kind'],
            'published' => true,
            'revision_id' => (int) $r['revision_id'],
            'revision_number' => (int) $r['revision_number'],
            'est_minutes' => isset($course['est_minutes']) ? (int) $course['est_minutes'] : null,
            'has_exam' => $passPct !== null,
            'pass_pct' => $passPct,
            'validity_months' => isset($course['validity_months']) ? (int) $course['validity_months'] : null,
            'renewal_lead_days' => isset($course['renewal_lead_days']) ? (int) $course['renewal_lead_days'] : 0,
            'needs' => [
                'online' => !empty($comp['online']),
                'session' => !empty($comp['session']),
                'practical' => !empty($comp['practical']),
                'external_only' => !empty($comp['external_only']),
            ],
            'is_qualification' => !empty($course['is_qualification']),
            'regulation_ref' => isset($course['regulation_ref']) ? (string) $course['regulation_ref'] : null,
            'checklist' => $checklist,
        ];
    }
    return $out;
}

/**
 * The Phase 2 records settings the UI shows (due-soon days, reissue days, evidence cap, link
 * check time). Column defaults until the 2.6.92 schema and Core\RecordsSettings are present.
 */
function tro_records_settings(\mysqli $db): array
{
    $out = ['due_soon_days' => 30, 'reissue_days' => 14, 'evidence_max_bytes' => 20 * 1048576, 'link_checked_at' => null,
            'reconciled_at' => null, 'schema_ready' => false];
    if (class_exists(\ITFlow\Training\Core\RecordsSettings::class)) {
        try {
            $s = \ITFlow\Training\Core\RecordsSettings::fromDb($db);
            $out['due_soon_days'] = (int) $s->dueSoonDays;
            $out['reissue_days'] = (int) $s->reissueDays;
            $out['evidence_max_bytes'] = (int) $s->evidenceMaxBytes;
            $out['schema_ready'] = (bool) $s->schemaReady;
            $out['link_checked_at'] = \ITFlow\Training\Core\Clock::toIso($s->linkCheckedAtUtc, true);
            $out['reconciled_at'] = \ITFlow\Training\Core\Clock::toIso($s->reconciledAtUtc, true);
        } catch (\Throwable $e) {
            error_log('Training ops page settings: ' . $e->getMessage());
        }
    }
    return $out;
}

/** Local business date (spec §0 #5). */
function tro_today(): string
{
    if (method_exists(\ITFlow\Training\Core\Clock::class, 'todayLocal')) {
        return \ITFlow\Training\Core\Clock::todayLocal();
    }
    return (new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get())))->format('Y-m-d');
}

/** A whitelisted GET string (trimmed, valid UTF-8, clipped), or null. */
function tro_get_str(string $k, int $max = 100): ?string
{
    $v = $_GET[$k] ?? null;
    if (!is_string($v)) {
        return null;
    }
    $v = trim($v);
    if ($v === '' || !mb_check_encoding($v, 'UTF-8')) {
        return null;
    }
    return mb_substr($v, 0, $max, 'UTF-8');
}

/** A positive GET id, or null. $allowZero accepts 0 (the "No department" filter). */
function tro_get_id(string $k, bool $allowZero = false): ?int
{
    $v = $_GET[$k] ?? null;
    if (!is_string($v) || !ctype_digit($v) || strlen($v) > 10) {
        return null;
    }
    $i = (int) $v;
    return ($i > 0 || ($allowZero && $i === 0)) ? $i : null;
}

/** A GET value from a whitelist, or $default. */
function tro_get_enum(string $k, array $allowed, ?string $default = null): ?string
{
    $v = $_GET[$k] ?? null;
    return is_string($v) && in_array($v, $allowed, true) ? $v : $default;
}

/** A strict Y-m-d GET date, or null. */
function tro_get_date(string $k): ?string
{
    $v = $_GET[$k] ?? null;
    if (!is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) {
        return null;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $v));
    return checkdate($m, $d, $y) ? $v : null;
}

/** Encodes the #tr-page-data block (spec Phase 1 §0 "CSP and page skeleton"). */
function tro_page_data(array $data): void
{
    echo '<script type="application/json" id="tr-page-data">'
        . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
        . '</script>' . "\n";
}

/** Deferred script tags, cache-busted by mtime: training_common.js, the ops forms, then the page's own. */
function tro_scripts(string ...$pageScripts): void
{
    $root = dirname(__DIR__, 3);
    $files = array_merge(['/js/training_common.js', '/agent/js/training_ops_forms.js'], $pageScripts);
    foreach ($files as $f) {
        $path = $root . $f;
        $v = is_file($path) ? (string) filemtime($path) : '0';
        echo '<script src="' . nullable_htmlentities($f) . '?v=' . $v . '" defer></script>' . "\n";
    }
}

/** Underlined tabs (`.tro-tabs`), each a real link so the tab survives a reload. */
function tro_tabs(array $tabs, string $active): void
{
    echo '<nav class="tro-tabs" aria-label="Sections"><ul class="nav">';
    foreach ($tabs as $key => $t) {
        $is = $key === $active;
        echo '<li class="nav-item"><a class="nav-link' . ($is ? ' active' : '') . '" href="' . nullable_htmlentities($t['url']) . '"'
            . ($is ? ' aria-current="page"' : '') . '>' . nullable_htmlentities($t['label'])
            . (isset($t['count']) && $t['count'] !== null ? ' <span class="tro-tabs__count" data-tro-count="' . nullable_htmlentities((string) $key) . '">' . intval($t['count']) . '</span>' : '')
            . '</a></li>';
    }
    echo '</ul></nav>';
}

/** The fail-closed scope banner (spec §5 common rules). */
function tro_scope_banner(array $scope): void
{
    if ($scope['state'] === 'none') {
        echo '<div class="tr-banner alert alert-info d-flex align-items-center gap-2" role="status" id="tro-scope-banner">'
            . '<i class="fas fa-user-lock" aria-hidden="true"></i><span>Ask an administrator to grant department access to see people.</span></div>';
    }
}
