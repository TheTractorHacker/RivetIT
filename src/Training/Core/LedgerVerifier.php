<?php

namespace ITFlow\Training\Core;

/**
 * Walks the training ledger and every hashed table, both directions (plan A8).
 *
 *   events  -> chain:    seq continuity (gap), prev_hash = previous event's hash (chain),
 *                        each event's own hash re-computed (hash), head = last event (head)
 *   events  -> entities: the row an event names exists (entity_missing), its row hash
 *                        re-computes and equals the event's entity_sha256 (entity_hash);
 *                        revisions also: sha256(revision_json) = revision_sha256
 *                        (revision_json), the stored JSON is canonical (revision_noncanonical),
 *                        and the rmedia rows equal the JSON's media manifest (rmedia_mismatch)
 *   entities -> events:  every media, media page and revision row is covered by an event
 *                        (entity_unevented)
 *   Phase 2 (spec §3.1): events named in HashSpecs::EVENT_ROWS (completion.recorded,
 *                        completion.voided, evaluation.recorded) go through the generic path
 *                        above, both ways; session.finalized requires the session to be
 *                        finalized and its stored tsession_sha256 = SessionDigest::computeFromDb()
 *                        = the event's entity_sha256, and every finalized session needs its event.
 *                        One information_schema probe per run: the Phase 2 checks are skipped
 *                        while their tables are absent (a 2.6.91 database), and an event that
 *                        names a missing table is reported, never a 500.
 *   deep:                every media file exists and re-hashes to media_sha256 (media_file); a
 *                        missing file is fine only when the latest file event for that media id
 *                        is media.file_purged
 *
 * READING. Every query is a plain, BUFFERED mysqli_query(): the text protocol returns exactly
 * the strings the writer hashed (prepared-statement results carry native PHP types and must
 * not be used for hashing). Events are read in keyset pages of 2000 (`WHERE tevent_seq > ?
 * ORDER BY tevent_seq LIMIT 2000`), and entity rows for a page are read on the same
 * connection after the page's result is freed. Unbuffered result mode is never used: a second
 * query while an unbuffered result is open fails with "Commands out of sync".
 *
 * Read-only: nothing here writes, except recordResult(), which stores the one-line summary in
 * the settings row for Admin › Training and the nightly cron.
 */
final class LedgerVerifier
{
    public const PAGE = 2000;

    /** Mirrors Media\MediaStore::PATH_RE (spec §3.3); kept here so Core has no dependency on the media lane. */
    public const MEDIA_PATH_RE = '#^(content|evidence)/[0-9a-f]{2}/[0-9a-f]{64}\.(pdf|jpg|png|webp|gif|mp4|docx|xlsx|pptx|txt|csv)$#';

    private const MEDIA_EVENTS = ['media.stored', 'media.file_purged', 'media.file_restored'];

    /** @var list<array{seq:?int, kind:string, detail:string}> */
    private array $breaks = [];
    private int $maxBreaks;
    private bool $truncated = false;
    private float $deadline;
    private bool $outOfTime = false;

    /** @var array<int, true> */ private array $storedMedia = [];
    /** @var array<string, true> */ private array $linkedPages = [];
    /** @var array<int, true> */ private array $publishedRevisions = [];
    /** @var array<int, true> */ private array $issuedTokens = [];
    /** @var array<string, array<int, true>> table => ids named by an EVENT_ROWS event */ private array $seenRows = [];
    /** @var array<int, true> */ private array $seenSessions = [];
    /** @var array<string, true> Phase 2 tables present in this database (schema probe) */ private array $tables = [];

    /** Tables the Phase 2 checks read; probed once per run so a 2.6.91 database verifies clean. */
    private const PHASE2_TABLES = ['training_completions', 'training_completion_voids', 'training_evaluations',
                                   'training_sessions', 'training_session_attendees'];

    private function __construct(private readonly \mysqli $db, array $opts)
    {
        $this->maxBreaks = max(1, (int) ($opts['max_breaks'] ?? 20));
        $budget = $opts['time_budget_s'] ?? null;
        $this->deadline = $budget === null ? INF : microtime(true) + max(1, (float) $budget);
    }

    /**
     * @param array{deep?:bool, max_breaks?:int, time_budget_s?:?int, media_root?:string} $opts
     * @return array{ok:bool, head:array, checked:int, breaks:list<array{seq:?int,kind:string,detail:string}>,
     *               complete:bool, deep:bool, last_seq:int, elapsed_ms:int}
     */
    public static function verify(\mysqli $db, array $opts = []): array
    {
        $start = microtime(true);
        $v = new self($db, $opts);
        $deep = !empty($opts['deep']);
        $root = rtrim((string) ($opts['media_root'] ?? dirname(__DIR__, 3) . '/uploads/training'), '/');

        // One read view for the head, the chain and the entity tables, so an append that commits
        // mid-walk cannot show up as a false "head" or "entity_unevented" break. A consistent-
        // snapshot read takes no locks; writers are never blocked by a verify.
        Db::ensureUtf8mb4($db);
        $ownSnapshot = Db::depth() === 0;
        if ($ownSnapshot) {
            $db->query("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ");
            $db->query("START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY");
        }
        try {
            $v->probeSchema();
            $head = null;
            try {
                $head = Ledger::head($db);
            } catch (\RuntimeException) {
                $v->addBreak(null, 'head', 'ledger head row (lhead_id = 1) is missing');
            }

            [$checked, $lastSeq, $lastHash, $walkComplete] = $head === null ? [0, 0, Ledger::ZERO_HASH, false] : $v->walkEvents();

            if ($head !== null && $walkComplete) {
                if ($head['seq'] !== $lastSeq || $head['hash'] !== $lastHash) {
                    $v->addBreak($head['seq'], 'head', "head is #{$head['seq']}/" . substr($head['hash'], 0, 16)
                        . ", last event is #$lastSeq/" . substr($lastHash, 0, 16));
                }
                $v->checkUnevented();
            }
        } finally {
            if ($ownSnapshot) {
                try {
                    $db->query("COMMIT");
                } catch (\Throwable) {
                    // read-only snapshot: nothing to lose
                }
            }
        }

        // Files are checked outside the snapshot: a missing file is looked up against the
        // CURRENT latest file event, so a purge that commits during the run is not a break.
        if ($deep && $head !== null && $walkComplete && !$v->stopped()) {
            $v->checkFiles($root);
        }

        $complete = $walkComplete && !$v->stopped();
        return [
            'ok' => $v->breaks === [] && $complete,
            'head' => $head ?? ['seq' => 0, 'hash' => '', 'updated_at_utc' => null],
            'checked' => $checked,
            'breaks' => $v->breaks,
            'complete' => $complete,
            'deep' => $deep,
            'last_seq' => $lastSeq,
            'elapsed_ms' => (int) round((microtime(true) - $start) * 1000),
        ];
    }

    /** One-line summary: "ok #<seq>/<hash16>", "BREAK #<seq> <kind>" or "INCOMPLETE #<seq> …". */
    public static function resultLine(array $r): string
    {
        if (!empty($r['breaks'])) {
            $b = $r['breaks'][0];
            $more = count($r['breaks']) > 1 ? ' (+' . (count($r['breaks']) - 1) . ' more)' : '';
            return mb_substr('BREAK #' . ($b['seq'] ?? '-') . ' ' . $b['kind'] . $more, 0, 255);
        }
        if (empty($r['complete'])) {
            return 'INCOMPLETE #' . (int) ($r['last_seq'] ?? 0) . ' (time budget reached; run a full verify)';
        }
        return 'ok #' . (int) $r['head']['seq'] . '/' . substr((string) $r['head']['hash'], 0, 16);
    }

    /**
     * Stores the result line and time in the settings row. Returns the new and previous line and
     * whether this is a break whose signature differs from the last stored result (alert once).
     *
     * @return array{line:string, previous:?string, new_break:bool}
     */
    public static function recordResult(\mysqli $db, array $r): array
    {
        $line = self::resultLine($r);
        $prev = null;
        $res = $db->query("SELECT config_training_ledger_verify_result FROM settings WHERE company_id = 1");
        if ($res) {
            $row = $res->fetch_assoc();
            $prev = $row['config_training_ledger_verify_result'] ?? null;
            $res->free();
        }
        Db::exec($db, "UPDATE settings SET config_training_ledger_verified_at_utc = ?, config_training_ledger_verify_result = ? WHERE company_id = 1",
            'ss', [Clock::nowUtc(), $line]);
        return ['line' => $line, 'previous' => $prev, 'new_break' => str_starts_with($line, 'BREAK') && $line !== $prev];
    }

    // ---------------------------------------------------------------------------------------

    /** @return array{0:int, 1:int, 2:string, 3:bool} checked, last seq, last hash, complete */
    private function walkEvents(): array
    {
        $cols = implode(', ', HashSpecs::selectColumns('training_events'));
        $after = 0;
        $expected = 1;
        $prevHash = Ledger::ZERO_HASH;
        $lastSeq = 0;
        $lastHash = Ledger::ZERO_HASH;
        $checked = 0;

        while (true) {
            $res = $this->db->query("SELECT $cols FROM training_events WHERE tevent_seq > $after ORDER BY tevent_seq LIMIT " . self::PAGE);
            $rows = $res->fetch_all(MYSQLI_ASSOC);
            $res->free();
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $seq = (int) $row['tevent_seq'];
                if ($seq !== $expected) {
                    $this->addBreak($seq, 'gap', "expected #$expected, found #$seq");
                }
                if ($row['tevent_prev_hash'] !== $prevHash) {
                    $this->addBreak($seq, 'chain', 'prev_hash ' . substr((string) $row['tevent_prev_hash'], 0, 16)
                        . ' != previous event hash ' . substr($prevHash, 0, 16));
                }
                try {
                    $calc = RowHasher::hash('training_events', $row);
                    if (!hash_equals($calc, (string) $row['tevent_hash'])) {
                        $this->addBreak($seq, 'hash', 'stored ' . substr((string) $row['tevent_hash'], 0, 16) . ', computed ' . substr($calc, 0, 16));
                    }
                } catch (\InvalidArgumentException $e) {
                    $this->addBreak($seq, 'hash', 'cannot re-hash: ' . $e->getMessage());
                }
                $prevHash = (string) $row['tevent_hash'];
                $expected = $seq + 1;
                $lastSeq = $seq;
                $lastHash = $prevHash;
                $checked++;
            }
            $this->checkEntities($rows);
            $after = $lastSeq;
            if ($this->stopped()) {
                return [$checked, $lastSeq, $lastHash, false];
            }
            if (count($rows) < self::PAGE) {
                break;
            }
        }
        return [$checked, $lastSeq, $lastHash, true];
    }

    /** Checks the rows named by one page of events. */
    private function checkEntities(array $events): void
    {
        $mediaIds = [];
        $pdfIds = [];
        foreach ($events as $ev) {
            $type = $ev['tevent_type'];
            if ($ev['tevent_entity_id'] === null) {
                continue;
            }
            if (in_array($type, self::MEDIA_EVENTS, true)) {
                $mediaIds[(int) $ev['tevent_entity_id']] = true;
            } elseif ($type === 'media.pages_linked') {
                $pdfIds[(int) $ev['tevent_entity_id']] = true;
            }
        }
        $rowIds = [];
        foreach ($events as $ev) {
            if ($ev['tevent_entity_id'] !== null && isset(HashSpecs::EVENT_ROWS[$ev['tevent_type']])) {
                [$t, $idCol] = HashSpecs::EVENT_ROWS[$ev['tevent_type']];
                $rowIds[$t][$idCol][(int) $ev['tevent_entity_id']] = true;
            }
        }
        $rows = [];
        foreach ($rowIds as $t => $byCol) {
            foreach ($byCol as $idCol => $ids) {
                $rows[$t] = ($rows[$t] ?? []) + ($this->tableExists($t) ? $this->fetchByIds($t, $idCol, array_keys($ids)) : []);
            }
        }
        $media = $this->fetchByIds('training_media', 'media_id', array_keys($mediaIds));
        $pages = [];
        if ($pdfIds !== []) {
            $cols = implode(', ', HashSpecs::selectColumns('training_media_pages'));
            $in = implode(',', array_map('intval', array_keys($pdfIds)));
            $res = $this->db->query("SELECT $cols FROM training_media_pages WHERE mpage_pdf_media_id IN ($in)");
            foreach ($res->fetch_all(MYSQLI_ASSOC) as $p) {
                $pages[(int) $p['mpage_pdf_media_id'] . ':' . (int) $p['mpage_number']] = $p;
            }
            $res->free();
        }

        foreach ($events as $ev) {
            $seq = (int) $ev['tevent_seq'];
            $type = $ev['tevent_type'];
            $id = $ev['tevent_entity_id'] === null ? null : (int) $ev['tevent_entity_id'];
            $payload = $this->payload($ev);

            if (in_array($type, self::MEDIA_EVENTS, true)) {
                if ($id === null || !isset($media[$id])) {
                    $this->addBreak($seq, 'entity_missing', "training_media #$id ($type)");
                    continue;
                }
                $row = $media[$id];
                $this->checkRowHash($seq, 'training_media', $row, "training_media #$id");
                if ($ev['tevent_entity_sha256'] !== $row['media_row_sha256']) {
                    $this->addBreak($seq, 'entity_hash', "$type sha differs from training_media #$id row hash");
                }
                if (isset($payload['content_sha256']) && $payload['content_sha256'] !== $row['media_sha256']) {
                    $this->addBreak($seq, 'entity_hash', "$type content_sha256 differs from training_media #$id");
                }
                if ($type === 'media.stored') {
                    $this->storedMedia[$id] = true;
                }
            } elseif ($type === 'media.pages_linked') {
                $list = is_array($payload['pages'] ?? null) ? $payload['pages'] : [];
                $shas = [];
                foreach ($list as $pg) {
                    $n = (int) ($pg['n'] ?? 0);
                    $key = $id . ':' . $n;
                    $shas[] = (string) ($pg['row_sha256'] ?? '');
                    if (!isset($pages[$key])) {
                        $this->addBreak($seq, 'entity_missing', "training_media_pages pdf #$id page $n");
                        continue;
                    }
                    $p = $pages[$key];
                    $this->checkRowHash($seq, 'training_media_pages', $p, "training_media_pages pdf #$id page $n");
                    if (($pg['row_sha256'] ?? null) !== $p['mpage_row_sha256'] || (int) ($pg['media_id'] ?? 0) !== (int) $p['mpage_media_id']) {
                        $this->addBreak($seq, 'entity_hash', "pages_linked payload differs from pdf #$id page $n");
                    }
                    $this->linkedPages[$key] = true;
                }
                if ($ev['tevent_entity_sha256'] !== Ledger::pagesEntitySha($shas)) {
                    $this->addBreak($seq, 'entity_hash', "pages_linked entity sha differs for pdf #$id");
                }
            } elseif (isset(HashSpecs::EVENT_ROWS[$type])) {
                $this->checkEventRow($seq, $type, $id, $ev, $rows);
            } elseif ($type === 'session.finalized') {
                $this->checkFinalizedSession($seq, $id, $ev);
            } elseif ($type === 'revision.published') {
                $this->checkRevision($seq, $id, $ev, $payload);
            } elseif ($type === 'cert.token_issued') {
                // Reserved for Phase 2 (spec §2.6); verified here so the contract holds from day one.
                $tok = $id === null ? [] : $this->fetchByIds('training_cert_tokens', 'certtok_id', [$id]);
                if (!isset($tok[$id])) {
                    $this->addBreak($seq, 'entity_missing', "training_cert_tokens #$id");
                } else {
                    $this->checkRowHash($seq, 'training_cert_tokens', $tok[$id], "training_cert_tokens #$id");
                    if ($ev['tevent_entity_sha256'] !== $tok[$id]['certtok_row_sha256']) {
                        $this->addBreak($seq, 'entity_hash', "cert.token_issued sha differs from training_cert_tokens #$id");
                    }
                    $this->issuedTokens[$id] = true;
                }
            }
            if ($this->stopped()) {
                return;
            }
        }
    }

    /** Generic Phase 2 path: an EVENT_ROWS event names an existing row whose hash re-computes and equals entity_sha256. */
    private function checkEventRow(int $seq, string $type, ?int $id, array $ev, array $rows): void
    {
        [$table] = HashSpecs::EVENT_ROWS[$type];
        if (!$this->tableExists($table)) {
            $this->addBreak($seq, 'entity_missing', "$type names $table #$id but the table does not exist");
            return;
        }
        if ($id === null || !isset($rows[$table][$id])) {
            $this->addBreak($seq, 'entity_missing', "$table #$id ($type)");
            return;
        }
        $row = $rows[$table][$id];
        $meta = HashSpecs::meta($table);
        $this->checkRowHash($seq, $table, $row, "$table #$id");
        if ($ev['tevent_entity_sha256'] !== $row[$meta['hash']]) {
            $this->addBreak($seq, 'entity_hash', "$type sha differs from $table #$id row hash");
        }
        $this->seenRows[$table][$id] = true;
    }

    /** session.finalized: the session is finalized and its digest re-computes to both stored values. */
    private function checkFinalizedSession(int $seq, ?int $id, array $ev): void
    {
        if (!$this->tableExists('training_sessions') || !$this->tableExists('training_session_attendees')) {
            $this->addBreak($seq, 'entity_missing', "session.finalized names session #$id but the session tables do not exist");
            return;
        }
        if ($id === null) {
            $this->addBreak($seq, 'entity_missing', 'session.finalized without a session id');
            return;
        }
        $res = $this->db->query("SELECT tsession_status, tsession_sha256, tsession_digest_v FROM training_sessions WHERE tsession_id = " . intval($id));
        $row = $res->fetch_assoc();
        $res->free();
        if (!$row) {
            $this->addBreak($seq, 'entity_missing', "training_sessions #$id (session.finalized)");
            return;
        }
        $this->seenSessions[$id] = true;
        $digest = null;
        try {
            $v = (int) ($row['tsession_digest_v'] ?? 0);
            $digest = SessionDigest::computeFromDb($this->db, $id, $v > 0 ? $v : 1);
        } catch (\InvalidArgumentException | \RuntimeException) {
            $digest = null;
        }
        if ($row['tsession_status'] !== 'finalized' || $digest === null
            || !hash_equals((string) $row['tsession_sha256'], $digest)
            || $ev['tevent_entity_sha256'] !== $digest) {
            $this->addBreak($seq, 'entity_hash', "session #$id digest does not re-compute");
        }
    }

    private function checkRevision(int $seq, ?int $id, array $ev, array $payload): void
    {
        if ($id === null) {
            $this->addBreak($seq, 'entity_missing', 'revision.published without a revision id');
            return;
        }
        $cols = implode(', ', HashSpecs::selectColumns('training_revisions'));
        $res = $this->db->query("SELECT $cols, revision_json FROM training_revisions WHERE revision_id = " . intval($id));
        $row = $res->fetch_assoc();
        $res->free();
        if (!$row) {
            $this->addBreak($seq, 'entity_missing', "training_revisions #$id");
            return;
        }
        $this->publishedRevisions[$id] = true;
        $this->checkRowHash($seq, 'training_revisions', $row, "training_revisions #$id");
        if ($ev['tevent_entity_sha256'] !== $row['revision_row_sha256']) {
            $this->addBreak($seq, 'entity_hash', "revision.published sha differs from training_revisions #$id row hash");
        }
        if (isset($payload['revision_sha256']) && $payload['revision_sha256'] !== $row['revision_sha256']) {
            $this->addBreak($seq, 'entity_hash', "revision.published payload sha differs from training_revisions #$id");
        }

        $json = (string) $row['revision_json'];
        if (!hash_equals((string) $row['revision_sha256'], Canonical::sha256($json))) {
            $this->addBreak($seq, 'revision_json', "sha256(revision_json) != revision_sha256 for revision #$id");
        }
        $doc = null;
        try {
            $doc = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($doc) || Canonical::doc($doc) !== $json) {
                $this->addBreak($seq, 'revision_noncanonical', "revision #$id JSON is not in canonical form");
            }
        } catch (\JsonException | \InvalidArgumentException $e) {
            $this->addBreak($seq, 'revision_noncanonical', "revision #$id JSON: " . $e->getMessage());
        }

        // rmedia set must equal the manifest exactly, including the downloadable flag.
        $manifest = [];
        foreach ((is_array($doc) && is_array($doc['media'] ?? null)) ? $doc['media'] : [] as $m) {
            $manifest[] = (int) ($m['id'] ?? 0) . '|' . ($m['sha256'] ?? '') . '|' . (!empty($m['dl']) ? '1' : '0');
        }
        $actual = [];
        $res = $this->db->query("SELECT rmedia_media_id, rmedia_media_sha256, rmedia_downloadable FROM training_revision_media WHERE rmedia_revision_id = " . intval($id));
        foreach ($res->fetch_all(MYSQLI_ASSOC) as $rm) {
            $actual[] = (int) $rm['rmedia_media_id'] . '|' . $rm['rmedia_media_sha256'] . '|' . ((int) $rm['rmedia_downloadable'] === 1 ? '1' : '0');
        }
        $res->free();
        sort($manifest, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($manifest !== $actual) {
            $missing = count(array_diff($manifest, $actual));
            $extra = count(array_diff($actual, $manifest));
            $this->addBreak($seq, 'rmedia_mismatch', "revision #$id: $missing manifest entries without an rmedia row, $extra rmedia rows not in the manifest");
        }
    }

    /** Every row of a hashed table must be named by an event. Runs only after a complete walk. */
    private function checkUnevented(): void
    {
        $after = 0;
        do {
            $res = $this->db->query("SELECT media_id FROM training_media WHERE media_id > $after ORDER BY media_id LIMIT " . self::PAGE);
            $ids = array_map('intval', array_column($res->fetch_all(MYSQLI_ASSOC), 'media_id'));
            $res->free();
            foreach ($ids as $mid) {
                if (!isset($this->storedMedia[$mid])) {
                    $this->addBreak(null, 'entity_unevented', "training_media #$mid has no media.stored event");
                }
                $after = $mid;
            }
        } while (count($ids) === self::PAGE && !$this->stopped());

        $afterPdf = 0;
        $afterN = 0;
        do {
            $res = $this->db->query("SELECT mpage_pdf_media_id, mpage_number FROM training_media_pages
                WHERE (mpage_pdf_media_id, mpage_number) > ($afterPdf, $afterN) ORDER BY mpage_pdf_media_id, mpage_number LIMIT " . self::PAGE);
            $rows = $res->fetch_all(MYSQLI_ASSOC);
            $res->free();
            foreach ($rows as $p) {
                $afterPdf = (int) $p['mpage_pdf_media_id'];
                $afterN = (int) $p['mpage_number'];
                if (!isset($this->linkedPages[$afterPdf . ':' . $afterN])) {
                    $this->addBreak(null, 'entity_unevented', "training_media_pages pdf #$afterPdf page $afterN has no media.pages_linked event");
                }
            }
        } while (count($rows) === self::PAGE && !$this->stopped());

        foreach (['training_revisions' => ['revision_id', $this->publishedRevisions, 'revision.published'],
                  'training_cert_tokens' => ['certtok_id', $this->issuedTokens, 'cert.token_issued']] as $table => [$col, $seen, $evt]) {
            $after = 0;
            do {
                $res = $this->db->query("SELECT $col FROM $table WHERE $col > $after ORDER BY $col LIMIT " . self::PAGE);
                $ids = array_map('intval', array_column($res->fetch_all(MYSQLI_ASSOC), $col));
                $res->free();
                foreach ($ids as $rid) {
                    if (!isset($seen[$rid])) {
                        $this->addBreak(null, 'entity_unevented', "$table #$rid has no $evt event");
                    }
                    $after = $rid;
                }
            } while (count($ids) === self::PAGE && !$this->stopped());
        }

        // Phase 2: every row of an EVENT_ROWS table, and every finalized session, needs its event.
        $byTable = [];
        foreach (HashSpecs::EVENT_ROWS as $evt => [$table, $col]) {
            $byTable[$table] ??= [$col, $evt];
        }
        foreach ($byTable as $table => [$col, $evt]) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $seen = $this->seenRows[$table] ?? [];
            $after = 0;
            do {
                $res = $this->db->query("SELECT $col FROM $table WHERE $col > $after ORDER BY $col LIMIT " . self::PAGE);
                $ids = array_map('intval', array_column($res->fetch_all(MYSQLI_ASSOC), $col));
                $res->free();
                foreach ($ids as $rid) {
                    if (!isset($seen[$rid])) {
                        $this->addBreak(null, 'entity_unevented', "$table #$rid has no $evt event");
                    }
                    $after = $rid;
                }
            } while (count($ids) === self::PAGE && !$this->stopped());
        }
        if ($this->tableExists('training_sessions')) {
            $after = 0;
            do {
                $res = $this->db->query("SELECT tsession_id FROM training_sessions WHERE tsession_status = 'finalized' AND tsession_id > $after
                    ORDER BY tsession_id LIMIT " . self::PAGE);
                $ids = array_map('intval', array_column($res->fetch_all(MYSQLI_ASSOC), 'tsession_id'));
                $res->free();
                foreach ($ids as $sid) {
                    if (!isset($this->seenSessions[$sid])) {
                        $this->addBreak(null, 'entity_unevented', "training_sessions #$sid is finalized but has no session.finalized event");
                    }
                    $after = $sid;
                }
            } while (count($ids) === self::PAGE && !$this->stopped());
        }
    }

    /** One information_schema query per run: which Phase 2 tables exist in this database. */
    private function probeSchema(): void
    {
        $in = "'" . implode("','", self::PHASE2_TABLES) . "'";
        $res = $this->db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)");
        foreach ($res->fetch_all(MYSQLI_NUM) as $r) {
            $this->tables[(string) $r[0]] = true;
        }
        $res->free();
    }

    private function tableExists(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    /** Deep: every media file present and intact (or purged by a recorded event). */
    private function checkFiles(string $root): void
    {
        $after = 0;
        do {
            $res = $this->db->query("SELECT media_id, media_sha256, media_bytes, media_path FROM training_media WHERE media_id > $after ORDER BY media_id LIMIT 500");
            $rows = $res->fetch_all(MYSQLI_ASSOC);
            $res->free();
            foreach ($rows as $m) {
                $mid = (int) $m['media_id'];
                $after = $mid;
                $path = (string) $m['media_path'];
                if (preg_match(self::MEDIA_PATH_RE, $path) !== 1) {
                    $this->addBreak(null, 'media_file', "training_media #$mid has an invalid media_path");
                    continue;
                }
                $file = $root . '/' . $path;
                if (!is_file($file)) {
                    if ($this->latestFileEvent($mid) !== 'media.file_purged') {
                        $this->addBreak(null, 'media_file', "training_media #$mid file is missing (not purged)");
                    }
                    continue;
                }
                $size = @filesize($file);
                $sha = @hash_file('sha256', $file);
                if ($size === false || $sha === false) {
                    $this->addBreak(null, 'media_file', "training_media #$mid file is unreadable");
                } elseif ((string) $size !== (string) $m['media_bytes'] || !hash_equals((string) $m['media_sha256'], $sha)) {
                    $this->addBreak(null, 'media_file', "training_media #$mid file content does not match media_sha256");
                }
                if ($this->stopped()) {
                    return;
                }
            }
        } while (count($rows) === 500);
    }

    // ---------------------------------------------------------------------------------------

    /** Type of the latest media.stored / media.file_purged / media.file_restored event for a media id. */
    private function latestFileEvent(int $mediaId): ?string
    {
        $res = $this->db->query("SELECT tevent_type FROM training_events
            WHERE tevent_entity_type = 'media' AND tevent_entity_id = " . intval($mediaId) . "
              AND tevent_type IN ('media.stored', 'media.file_purged', 'media.file_restored')
            ORDER BY tevent_seq DESC LIMIT 1");
        $row = $res->fetch_assoc();
        $res->free();
        return $row['tevent_type'] ?? null;
    }

    /** @return array<int, array<string, ?string>> */
    private function fetchByIds(string $table, string $idCol, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $cols = implode(', ', HashSpecs::selectColumns($table));
        $out = [];
        foreach (array_chunk(array_map('intval', $ids), 500) as $chunk) {
            $res = $this->db->query("SELECT $cols FROM $table WHERE $idCol IN (" . implode(',', $chunk) . ")");
            foreach ($res->fetch_all(MYSQLI_ASSOC) as $r) {
                $out[(int) $r[$idCol]] = $r;
            }
            $res->free();
        }
        return $out;
    }

    private function checkRowHash(int $seq, string $table, array $row, string $label): void
    {
        $meta = HashSpecs::meta($table);
        try {
            $calc = RowHasher::hash($table, $row);
        } catch (\InvalidArgumentException $e) {
            $this->addBreak($seq, 'entity_hash', "$label cannot be re-hashed: " . $e->getMessage());
            return;
        }
        if (!hash_equals((string) $row[$meta['hash']], $calc)) {
            $this->addBreak($seq, 'entity_hash', "$label row hash does not re-compute");
        }
    }

    private function payload(array $ev): array
    {
        try {
            $p = json_decode((string) $ev['tevent_payload_json'], true, 512, JSON_THROW_ON_ERROR);
            return is_array($p) ? $p : [];
        } catch (\JsonException) {
            return [];
        }
    }

    private function addBreak(?int $seq, string $kind, string $detail): void
    {
        if (count($this->breaks) >= $this->maxBreaks) {
            $this->truncated = true;
            return;
        }
        $this->breaks[] = ['seq' => $seq, 'kind' => $kind, 'detail' => $detail];
        if (count($this->breaks) >= $this->maxBreaks) {
            $this->truncated = true;
        }
    }

    private function stopped(): bool
    {
        if (!$this->outOfTime && microtime(true) > $this->deadline) {
            $this->outOfTime = true;
        }
        return $this->truncated || $this->outOfTime;
    }
}
