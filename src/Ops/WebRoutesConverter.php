<?php

namespace ITFlow\Ops;

/**
 * Brings one nginx vhost file onto the shared routes file (deploy/templates/rivetit-locations.conf), offline and without ever
 * guessing: it works on the parsed structure (NginxConf), recognises the blocks the routes file now owns by the registry
 * (WebRoutes), and refuses to touch a vhost unless it can show the result is safe. Pure text in, text out; deploy/lib/web_routes_tool.php
 * is the command-line wrapper, deploy/apply-web-routes.sh does the root-side work (backup, nginx -t, reload, rollback).
 *
 * What a conversion does to each server block that has RivetIT routes:
 *   1. removes every top-level `location` whose (modifier, pattern) is in the registry, with the comment lines directly above it;
 *   2. removes an `include` of an older shared snippet (e.g. itflow-locations.conf) whose locations are all owned by the registry;
 *   3. puts ONE managed block where the first removed item was:  set $rivetit_php ...; set $rivetit_hsts ...; include <routes>;
 * and leaves every other directive and location untouched (they are reported as "kept").
 *
 * It declines (status 'manual', text untouched) when any of these holds, and says which:
 *   - the file does not parse (unbalanced braces, unterminated string);
 *   - a block it would remove carries an access rule or redirect the new block lacks (allow/deny/auth_*, satisfy, internal, limit_*,
 *     alias/root/rewrite/proxy_pass, and a `return` unless the registry entry is replaced wholesale), i.e. removing it could OPEN something;
 *   - an older shared snippet holds a location the routes file does not provide;
 *   - the PHP socket cannot be determined uniquely (and none was given);
 *   - $rivetit_php/$rivetit_hsts is already set by hand outside a managed block;
 *   - a location you wrote yourself with a regex (~ or ~*) sits between the ones that would move into the shared file: nginx tries
 *     regex locations in file order, so moving the others past it could change which one answers.
 */
final class WebRoutesConverter
{
    public const BEGIN = '# BEGIN RIVETIT ROUTES';
    public const END = '# END RIVETIT ROUTES';
    public const HSTS_VALUE = 'max-age=63072000; includeSubDomains';
    public const ROUTES_BASENAME = 'rivetit-locations.conf';

    /** Directives whose presence in a block about to be removed means removing it could weaken protection. */
    private const RESTRICTIVE = ['allow', 'deny', 'auth_basic', 'auth_basic_user_file', 'satisfy', 'auth_request', 'internal', 'limit_req',
        'limit_except', 'limit_conn', 'proxy_pass', 'alias', 'root', 'rewrite', 'return'];

    /** Registry entries whose current block fully supersedes any older version (it only ever grew by adding redirects). */
    private const SUPERSEDED = ['kiosk_entry'];

    /** @var array<string, array<string,mixed>> key => registry entry */
    private array $owned = [];
    /** @var array<string, array<string,mixed>> key => parsed template location */
    private array $template = [];

    public function __construct(string $routesTemplateText)
    {
        foreach (WebRoutes::all() as $e) { $this->owned[WebRoutes::key($e)] = $e; }
        foreach (NginxConf::locations(NginxConf::parse($routesTemplateText)) as $l) { $this->template[(string) NginxConf::locationKey($l)] = $l; }
    }

    /**
     * @param array{sock?:?string, hsts?:?string, include?:string, snippets?:array<string,string>} $opts
     *        sock: fastcgi_pass value to use (unix:/path or host:port; "/path" is taken as unix:/path); hsts: 'direct'|'proxy'|null (auto);
     *        include: the include spec to write; snippets: include spec => file text, for includes the caller could read.
     * @return array{status:string, content:string, removed:list<string>, kept:list<string>, notes:list<string>, reasons:list<string>, sock:?string, hsts:?string, includes_routes:bool}
     */
    public function convert(string $text, array $opts = []): array
    {
        $res = ['status' => 'unchanged', 'content' => $text, 'removed' => [], 'kept' => [], 'notes' => [], 'reasons' => [], 'sock' => null, 'hsts' => null, 'includes_routes' => false];
        $include = $opts['include'] ?? 'snippets/' . self::ROUTES_BASENAME;
        try {
            $nodes = NginxConf::parse($text);
        } catch (\RuntimeException $e) {
            $res['status'] = 'manual';
            $res['reasons'][] = 'The file could not be read as nginx configuration (' . $e->getMessage() . ').';
            return $res;
        }

        $edits = [];   // list of [start, end, replacement]
        $any = false;
        foreach (NginxConf::servers($nodes) as $srv) {
            $plan = $this->planServer($text, $srv, $opts, $include);
            if ($plan === null) { continue; }   // not a RivetIT server block
            $any = true;
            array_push($res['reasons'], ...$plan['reasons']);
            array_push($res['removed'], ...$plan['removed']);
            array_push($res['kept'], ...$plan['kept']);
            array_push($res['notes'], ...$plan['notes']);
            $res['sock'] = $plan['sock'] ?? $res['sock'];
            $res['hsts'] = $plan['hsts'] ?? $res['hsts'];
            $res['includes_routes'] = true;
            array_push($edits, ...$plan['edits']);
        }
        if (!$any) {
            $res['status'] = 'manual';
            $res['includes_routes'] = false;
            $res['reasons'][] = 'No RivetIT location rules or include were found in this file, so there is nothing to convert.';
            return $res;
        }
        if ($res['reasons']) {
            $res['status'] = 'manual';
            $res['content'] = $text;
            $res['includes_routes'] = $this->includesRoutes($nodes);
            return $res;
        }

        $out = self::applyEdits($text, $edits);
        // Prove the result before anyone is allowed to write it.
        try {
            $check = NginxConf::parse($out);
            foreach (NginxConf::servers($check) as $srv) {
                $locs = NginxConf::locations($srv['children']);
                $hasInclude = false;
                foreach ($srv['children'] as $c) { if ($c['name'] === 'include' && basename($c['args'][0] ?? '') === self::ROUTES_BASENAME) { $hasInclude = true; } }
                if (!$hasInclude) { continue; }
                foreach ($locs as $l) {
                    if (isset($this->owned[(string) NginxConf::locationKey($l)])) {
                        throw new \RuntimeException('a location owned by the routes file would be defined twice: ' . NginxConf::locationKey($l));
                    }
                }
            }
        } catch (\RuntimeException $e) {
            $res['status'] = 'manual';
            $res['content'] = $text;
            $res['reasons'][] = 'Internal safety check failed after the planned edit (' . $e->getMessage() . '); nothing was changed.';
            return $res;
        }
        $res['content'] = $out;
        $res['status'] = $out === $text ? 'unchanged' : 'changed';
        return $res;
    }

    private function includesRoutes(array $nodes): bool
    {
        foreach (NginxConf::servers($nodes) as $srv) {
            foreach ($srv['children'] as $c) {
                if ($c['name'] === 'include' && basename($c['args'][0] ?? '') === self::ROUTES_BASENAME) { return true; }
            }
        }
        return false;
    }

    /** @return ?array{reasons:list<string>, removed:list<string>, kept:list<string>, notes:list<string>, sock:?string, hsts:?string, edits:list<array{0:int,1:int,2:string}>} */
    private function planServer(string $text, array $srv, array $opts, string $include): ?array
    {
        $children = $srv['children'];
        $reasons = [];
        $notes = [];
        $ownedLocs = [];
        $unknownLocs = [];
        $routesInclude = null;
        $legacyIncludes = [];   // [node, snippet nodes]
        $hstsServerLevel = false;
        $existingPhp = null;
        $existingHsts = null;
        $setOutside = false;

        $blockStart = $blockEnd = null;
        [$bs, $be] = $this->managedBlockSpan($text, $srv);
        $blockStart = $bs; $blockEnd = $be;
        $inBlock = static fn(array $n) => $bs !== null && $n['start'] >= $bs && $n['end'] <= $be;

        foreach ($children as $c) {
            if ($c['name'] === 'location' && $c['children'] !== null) {
                $k = (string) NginxConf::locationKey($c);
                if (isset($this->owned[$k])) { $ownedLocs[] = $c; } else { $unknownLocs[] = $c; }
            } elseif ($c['name'] === 'include' && isset($c['args'][0])) {
                if (basename($c['args'][0]) === self::ROUTES_BASENAME) {
                    $routesInclude = $c;
                } elseif (isset($opts['snippets'][$c['args'][0]])) {
                    try {
                        $snip = NginxConf::parse($opts['snippets'][$c['args'][0]]);
                    } catch (\RuntimeException) { continue; }
                    $locs = NginxConf::locations($snip);
                    $hit = array_filter($locs, fn($l) => isset($this->owned[(string) NginxConf::locationKey($l)]));
                    if ($hit) { $legacyIncludes[] = [$c, $snip, $c['args'][0]]; }
                }
            } elseif ($c['name'] === 'add_header' && ($c['args'][0] ?? '') === 'Strict-Transport-Security') {
                $hstsServerLevel = true;
            } elseif ($c['name'] === 'set' && ($c['args'][0] ?? '') === '$rivetit_php') {
                if ($inBlock($c)) { $existingPhp = $c['args'][1] ?? null; } else { $setOutside = true; }
            } elseif ($c['name'] === 'set' && ($c['args'][0] ?? '') === '$rivetit_hsts') {
                if ($inBlock($c)) { $existingHsts = $c['args'][1] ?? ''; } else { $setOutside = true; }
            }
        }
        if (!$ownedLocs && !$legacyIncludes && !$routesInclude && $blockStart === null) { return null; }

        if ($setOutside) {
            $reasons[] = 'This server block already sets $rivetit_php or $rivetit_hsts by hand, outside the "' . self::BEGIN . '" block. Remove those lines or move them into the block first.';
        }

        // Which socket(s) do the blocks being removed use?
        $socks = [];
        $collect = function (array $loc) use (&$socks, &$reasons): void {
            foreach (self::walk($loc) as $d) {
                if ($d['name'] === 'fastcgi_pass' && isset($d['args'][0])) { $socks[$d['args'][0]] = true; }
            }
        };
        foreach ($ownedLocs as $l) { $collect($l); }
        foreach ($legacyIncludes as [$inc, $snip]) { foreach (NginxConf::locations($snip) as $l) { if (isset($this->owned[(string) NginxConf::locationKey($l)])) { $collect($l); } } }

        // Safety: nothing removed may be stricter than what replaces it.
        foreach ($ownedLocs as $l) { array_push($reasons, ...$this->compare($l, 'in this vhost')); }
        foreach ($legacyIncludes as [$inc, $snip, $spec]) {
            foreach (NginxConf::locations($snip) as $l) {
                $k = (string) NginxConf::locationKey($l);
                if (!isset($this->owned[$k])) {
                    $reasons[] = "The shared snippet '$spec' has a location ($k) that the RivetIT routes file does not provide, so including the routes file instead would drop it.";
                    continue;
                }
                array_push($reasons, ...$this->compare($l, "in the shared snippet '$spec'"));
            }
            foreach ($snip as $d) {
                if ($d['name'] !== 'location') { $reasons[] = "The shared snippet '$spec' contains a '{$d['name']}' directive outside any location; it would be lost."; }
            }
        }

        // Socket and HSTS values for the managed block.
        $sock = isset($opts['sock']) && $opts['sock'] !== '' ? self::normalizeSock($opts['sock']) : null;
        $sock ??= $existingPhp;
        if ($sock === null) {
            $found = array_keys($socks);
            if (count($found) === 1 && !str_contains($found[0], '$')) { $sock = $found[0]; }
            elseif (count($found) > 1) { $reasons[] = 'The blocks to replace use more than one PHP socket (' . implode(', ', $found) . '). Pass --php-sock to say which one this site uses.'; }
            elseif ($ownedLocs || $legacyIncludes) { $reasons[] = 'The PHP-FPM socket of this site could not be found in the file. Pass --php-sock /run/php/phpX.Y-fpm.sock.'; }
            else { $reasons[] = 'There is no $rivetit_php line yet and no PHP socket to copy it from. Pass --php-sock.'; }
        } elseif ($sock !== null && str_contains($sock, '$')) {
            $reasons[] = 'The PHP socket is a variable; pass --php-sock with the real path.';
        }
        $hsts = $opts['hsts'] ?? null;
        $hsts ??= $existingHsts !== null ? ($existingHsts === '' ? 'proxy' : 'direct') : null;
        $hsts ??= $hstsServerLevel ? 'direct' : 'proxy';

        // Regex ordering guard (see class comment).
        $removedItems = array_merge($ownedLocs, array_map(static fn($x) => $x[0], $legacyIncludes));
        if ($removedItems) {
            usort($removedItems, static fn($a, $b) => $a['start'] <=> $b['start']);
            $first = $removedItems[0]['start'];
            $lastRegex = null;
            foreach ($removedItems as $r) {
                $p = $r['name'] === 'location' ? (NginxConf::locationParts($r)[0] ?? '') : '~';
                if ($p === '~' || $p === '~*') { $lastRegex = $r['start']; }
            }
            foreach ($unknownLocs as $u) {
                $m = NginxConf::locationParts($u)[0] ?? '';
                if (($m === '~' || $m === '~*') && $u['start'] > $first && $lastRegex !== null && $u['start'] < $lastRegex) {
                    $reasons[] = 'Your own regex location "' . NginxConf::locationKey($u) . '" sits between locations that would move into the shared file. nginx tries regex locations in file order, so moving the others past it could change which one answers. Move it above or below the whole RivetIT group first.';
                }
            }
        }

        $kept = array_map(static fn($u) => (string) NginxConf::locationKey($u), $unknownLocs);
        $removed = array_map(static fn($l) => (string) NginxConf::locationKey($l), $ownedLocs);
        foreach ($legacyIncludes as [$inc, , $spec]) { $removed[] = "include $spec (every location in it is provided by the routes file)"; }

        $edits = [];
        $insertAt = null;
        if (!$reasons) {
            $removals = array_map(fn($r) => self::removalEdit($text, $r), $removedItems);
            array_push($edits, ...$removals);
            if ($routesInclude !== null && $blockStart === null) {
                // include added by hand without the managed variables: wrap it.
                $line = self::lineSpan($text, $routesInclude);
                $edits[] = [$line[0], $line[1], self::block($sock, $hsts, $routesInclude['args'][0], self::indentOf($text, $routesInclude))];
            } elseif ($blockStart !== null) {
                $blk = substr($text, $blockStart, $blockEnd - $blockStart);
                $new = self::updateBlock($blk, $sock, $hsts, isset($opts['hsts']));
                if ($new !== $blk) { $edits[] = [$blockStart, $blockEnd, $new]; }
            } else {
                $insertAt = min(array_column($removals, 0));   // where the first removed item (and its comment) began
                $edits[] = [$insertAt, $insertAt, self::block($sock, $hsts, $include, self::indentOf($text, $removedItems[0]))];
            }
        }
        if ($removedItems && $legacyIncludes) {
            $notes[] = 'The older shared snippet file is left in place (other sites may still include it); once no site includes it any more you can delete it.';
        }
        return ['reasons' => array_values(array_unique($reasons)), 'removed' => $removed, 'kept' => $kept, 'notes' => $notes, 'sock' => $sock, 'hsts' => $hsts, 'edits' => $edits];
    }

    /** Differences between a block about to be removed and the routes file's block for the same key; only the dangerous ones matter. @return list<string> */
    private function compare(array $old, string $where): array
    {
        $k = (string) NginxConf::locationKey($old);
        $tpl = $this->template[$k] ?? null;
        if ($tpl === null) { return []; }
        $entry = $this->owned[$k] ?? null;
        $superseded = $entry && in_array($entry['id'], self::SUPERSEDED, true);
        $norm = static function (array $lines): array {
            return array_map(static function (string $l): string {
                $l = preg_replace('~^(\s*fastcgi_pass) \S+$~', '$1 $rivetit_php', $l);
                return preg_replace('~^(\s*add_header Strict-Transport-Security) \S.*? always$~', '$1 $rivetit_hsts always', $l);
            }, $lines);
        };
        $oldLines = $norm(NginxConf::flatten($old));
        $newCounts = array_count_values($norm(NginxConf::flatten($tpl)));
        $reasons = [];
        foreach ($oldLines as $l) {
            if (($newCounts[$l] ?? 0) > 0) { $newCounts[$l]--; continue; }
            $name = strtok(ltrim($l), " \t");
            if (in_array($name, self::RESTRICTIVE, true) && !($name === 'return' && $superseded)) {
                $reasons[] = "The location \"$k\" $where has the line \"" . trim($l) . '" which the RivetIT routes file does not have; replacing it could open something up or drop a redirect you rely on.';
            }
        }
        return $reasons;
    }

    /** @return list<array{name:string,args:list<string>}> every directive at any depth under a node */
    private static function walk(array $nd): array
    {
        $out = [];
        foreach ($nd['children'] ?? [] as $c) {
            $out[] = $c;
            if ($c['children'] !== null) { array_push($out, ...self::walk($c)); }
        }
        return $out;
    }

    public static function normalizeSock(string $s): string
    {
        return str_starts_with($s, '/') ? 'unix:' . $s : $s;
    }

    /** @return array{0:?int,1:?int} byte span of the managed block (BEGIN line start .. END line end) inside a server block */
    private function managedBlockSpan(string $text, array $srv): array
    {
        $seg = substr($text, $srv['start'], $srv['end'] - $srv['start']);
        $b = strpos($seg, self::BEGIN);
        if ($b === false) { return [null, null]; }
        $e = strpos($seg, self::END, $b);
        if ($e === false) { return [null, null]; }
        $bStart = $srv['start'] + $b;
        $bLine = strrpos(substr($text, 0, $bStart), "\n");
        $bStart = $bLine === false ? 0 : $bLine + 1;
        $eEnd = strpos($text, "\n", $srv['start'] + $e);
        $eEnd = $eEnd === false ? strlen($text) : $eEnd + 1;
        return [$bStart, $eEnd];
    }

    private static function indentOf(string $text, array $node): string
    {
        $ls = strrpos(substr($text, 0, $node['start']), "\n");
        $ls = $ls === false ? 0 : $ls + 1;
        $lead = substr($text, $ls, $node['start'] - $ls);
        return trim($lead) === '' ? $lead : '    ';
    }

    private static function block(string $sock, string $hsts, string $include, string $ind): string
    {
        $v = $hsts === 'direct' ? '"' . self::HSTS_VALUE . '"' : '""';
        return $ind . self::BEGIN . " (managed by deploy/apply-web-routes.sh; see docs/WEB_ROUTES.md)\n"
            . $ind . "# This site's PHP-FPM socket and HSTS value (\"\" = send none); the shared routes file reads both.\n"
            . $ind . "set \$rivetit_php $sock;\n"
            . $ind . "set \$rivetit_hsts $v;\n"
            . $ind . "include $include;\n"
            . $ind . self::END . "\n";
    }

    private static function updateBlock(string $blk, string $sock, string $hsts, bool $hstsExplicit): string
    {
        $v = $hsts === 'direct' ? '"' . self::HSTS_VALUE . '"' : '""';
        preg_match('~^([ \t]*)' . preg_quote(self::BEGIN, '~') . '~m', $blk, $m);
        $ind = $m[1] ?? '    ';
        if (preg_match('~^[ \t]*set \$rivetit_php (?:"[^"]*"|[^;"]*);~m', $blk)) { $blk = preg_replace('~^([ \t]*)set \$rivetit_php (?:"[^"]*"|[^;"]*);~m', '${1}set $rivetit_php ' . $sock . ';', $blk); }
        else { $blk = preg_replace('~^([ \t]*include [^;]*' . preg_quote(self::ROUTES_BASENAME, '~') . ';)~m', $ind . 'set $rivetit_php ' . $sock . ";\n" . '$1', $blk, 1); }
        $hasHsts = preg_match('~^[ \t]*set \$rivetit_hsts (?:"[^"]*"|[^;"]*);~m', $blk) === 1;
        if (!$hasHsts) { $blk = preg_replace('~^([ \t]*include [^;]*' . preg_quote(self::ROUTES_BASENAME, '~') . ';)~m', $ind . 'set $rivetit_hsts ' . $v . ";\n" . '$1', $blk, 1); }
        elseif ($hstsExplicit) { $blk = preg_replace('~^([ \t]*)set \$rivetit_hsts (?:"[^"]*"|[^;"]*);~m', '${1}set $rivetit_hsts ' . $v . ';', $blk); }
        return $blk;
    }

    /** Whole-line span of a node: from its line start to the end of its line (a trailing # comment included). @return array{0:int,1:int} */
    private static function lineSpan(string $text, array $node): array
    {
        $ls = strrpos(substr($text, 0, $node['start']), "\n");
        $ls = $ls === false ? 0 : $ls + 1;
        if (trim(substr($text, $ls, $node['start'] - $ls)) !== '') { $ls = $node['start']; }
        $nl = strpos($text, "\n", $node['end']);
        $le = $nl === false ? strlen($text) : $nl + 1;
        $rest = trim(substr($text, $node['end'], $le - $node['end']));
        if ($rest !== '' && $rest[0] !== '#') { $le = $node['end']; }
        return [$ls, $le];
    }

    /** Removal of a node, its directly attached comment lines, and the blank line that would otherwise double up. @return array{0:int,1:int,2:string} */
    private static function removalEdit(string $text, array $node): array
    {
        [$s, $e] = self::lineSpan($text, $node);
        // contiguous comment lines directly above
        while ($s > 0) {
            $pe = $s - 1;                                    // the "\n" ending the previous line
            $ps = strrpos(substr($text, 0, $pe), "\n");
            $ps = $ps === false ? 0 : $ps + 1;
            $line = substr($text, $ps, $pe - $ps);
            $t = ltrim($line);
            if ($t === '' || $t[0] !== '#' || str_contains($t, 'RIVETIT ROUTES') || str_contains($t, 'PROXY-MODE ONLY') || str_contains($t, 'DIRECT-TLS ONLY') || str_contains($t, '__PROXY_MODE_BLOCK__')) { break; }
            $s = $ps;
        }
        // a blank line after, when the line before the removed region is blank too
        $prevBlank = $s === 0 || preg_match('~\n[ \t]*\n$~', substr($text, max(0, $s - 200), min(200, $s))) === 1 || trim(substr($text, 0, $s)) === '';
        if ($prevBlank && preg_match('~\G[ \t]*\n~', $text, $m, 0, $e)) { $e += strlen($m[0]); }
        return [$s, $e, ''];
    }

    /** @param list<array{0:int,1:int,2:string}> $edits */
    private static function applyEdits(string $text, array $edits): string
    {
        usort($edits, static fn($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        $prevStart = PHP_INT_MAX;
        foreach ($edits as [$s, $e, $r]) {
            if ($e > $prevStart) { $e = $prevStart; if ($e < $s) { continue; } }   // overlapping removals (adjacent comment blocks)
            $text = substr($text, 0, $s) . $r . substr($text, $e);
            $prevStart = $s;
        }
        return $text;
    }
}
