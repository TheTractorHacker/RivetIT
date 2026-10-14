<?php

namespace ITFlow\Ops;

/**
 * A small, strict reader for nginx configuration text. It knows just enough of the syntax to find directives and blocks
 * with their exact byte positions (comments, quoted strings and ${var} are handled), which is what the web-route tooling
 * needs to recognise `location` blocks structurally instead of by fragile text matching. It never evaluates anything and
 * never touches nginx. Used by deploy/lib/web_routes_tool.php (root, offline) and the tests.
 *
 * Node shape: ['name' => string, 'args' => list<string> (unquoted), 'children' => ?list<node>, 'start' => int, 'end' => int]
 * where start/end are byte offsets of the whole statement (first word .. just after the ';' or the closing '}').
 */
final class NginxConf
{
    /** @return list<array<string,mixed>> @throws \RuntimeException on unbalanced braces or an unterminated string */
    public static function parse(string $text): array
    {
        $pos = 0;
        $nodes = self::parseBlock($text, $pos, false);
        return $nodes;
    }

    /** @return list<array<string,mixed>> */
    private static function parseBlock(string $t, int &$i, bool $nested): array
    {
        $n = strlen($t);
        $nodes = [];
        $words = [];
        $startPos = null;
        while ($i < $n) {
            $c = $t[$i];
            if (ctype_space($c)) { $i++; continue; }
            if ($c === '#') { $nl = strpos($t, "\n", $i); $i = $nl === false ? $n : $nl + 1; continue; }
            if ($c === ';') {
                if ($words === []) { throw new \RuntimeException("Unexpected ';' at offset $i"); }
                $i++;
                $nodes[] = ['name' => $words[0], 'args' => array_slice($words, 1), 'children' => null, 'start' => $startPos, 'end' => $i];
                $words = []; $startPos = null;
                continue;
            }
            if ($c === '{') {
                if ($words === []) { throw new \RuntimeException("Unexpected '{' at offset $i"); }
                $i++;
                $children = self::parseBlock($t, $i, true);   // consumes the closing brace
                $nodes[] = ['name' => $words[0], 'args' => array_slice($words, 1), 'children' => $children, 'start' => $startPos, 'end' => $i];
                $words = []; $startPos = null;
                continue;
            }
            if ($c === '}') {
                if (!$nested) { throw new \RuntimeException("Unbalanced '}' at offset $i"); }
                if ($words !== []) { throw new \RuntimeException("Missing ';' before '}' at offset $i"); }
                $i++;
                return $nodes;
            }
            $startPos ??= $i;
            $words[] = self::readWord($t, $i);
        }
        if ($nested) { throw new \RuntimeException('Unbalanced braces: a block is never closed'); }
        if ($words !== []) { throw new \RuntimeException('Directive without a terminating ";" at the end of the file'); }
        return $nodes;
    }

    private static function readWord(string $t, int &$i): string
    {
        $n = strlen($t);
        $q = $t[$i];
        if ($q === '"' || $q === "'") {
            $i++;
            $out = '';
            while ($i < $n && $t[$i] !== $q) {
                if ($t[$i] === '\\' && $i + 1 < $n) { $out .= $t[$i] . $t[$i + 1]; $i += 2; continue; }
                $out .= $t[$i++];
            }
            if ($i >= $n) { throw new \RuntimeException('Unterminated quoted string'); }
            $i++;
            return $out;
        }
        $out = '';
        while ($i < $n) {
            $c = $t[$i];
            if (ctype_space($c) || $c === ';' || $c === '{' || $c === '}') {
                // ${name} is one word; a bare '{' or '}' ends it.
                if ($c === '{' && $out !== '' && substr($out, -1) === '$') {
                    $close = strpos($t, '}', $i);
                    if ($close === false) { throw new \RuntimeException('Unterminated ${...}'); }
                    $out .= substr($t, $i, $close - $i + 1);
                    $i = $close + 1;
                    continue;
                }
                break;
            }
            if (($c === '"' || $c === "'") && $out !== '') { $out .= $c; $i++; continue; }
            $out .= $c;
            $i++;
        }
        return $out;
    }

    /** All `server` blocks, wherever they are (top level, or inside http). @return list<array<string,mixed>> */
    public static function servers(array $nodes): array
    {
        $found = [];
        foreach ($nodes as $nd) {
            if ($nd['name'] === 'server' && $nd['children'] !== null) { $found[] = $nd; }
            elseif ($nd['children'] !== null && in_array($nd['name'], ['http', 'events', 'stream'], true)) { array_push($found, ...self::servers($nd['children'])); }
        }
        return $found;
    }

    /** @return array{0:string,1:string}|null [modifier, pattern] of a `location` node */
    public static function locationParts(array $nd): ?array
    {
        if ($nd['name'] !== 'location' || $nd['children'] === null || $nd['args'] === []) { return null; }
        $a = $nd['args'];
        if (in_array($a[0], ['=', '^~', '~', '~*'], true) && isset($a[1])) { return [$a[0], $a[1]]; }
        return ['', $a[0]];
    }

    /** Canonical key of a location: "= /mcp", "^~ /uploads/", "~ \.php$", or "/" for a plain prefix. */
    public static function locationKey(array $nd): ?string
    {
        $p = self::locationParts($nd);
        return $p === null ? null : ($p[0] === '' ? $p[1] : $p[0] . ' ' . $p[1]);
    }

    /**
     * Every directive under a node as one canonical string each, nested blocks prefixed by their header, so two
     * configurations can be compared ignoring comments and whitespace but not meaning. Order is preserved.
     * @return list<string>
     */
    public static function flatten(array $nd, string $prefix = ''): array
    {
        $out = [];
        foreach ($nd['children'] ?? [] as $c) {
            $head = $c['name'] . ($c['args'] ? ' ' . implode(' ', $c['args']) : '');
            if ($c['children'] === null) {
                $out[] = $prefix . $head;
            } else {
                $out[] = $prefix . $head . ' {';
                array_push($out, ...self::flatten($c, $prefix . '  '));
                $out[] = $prefix . '}';
            }
        }
        return $out;
    }

    /** Top-level `location` children of a server block (or of a bare snippet's node list), in order. @return list<array<string,mixed>> */
    public static function locations(array $nodes): array
    {
        return array_values(array_filter($nodes, static fn($n) => $n['name'] === 'location' && $n['children'] !== null));
    }
}
