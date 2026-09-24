<?php

namespace ITFlow\Training\Core;

/**
 * Training's own CSV writer and reader.
 *
 * The app's generic report CSV helper is deliberately not used: it relies on the CSV writer's
 * default backslash escape character, which PHP 8.4 deprecates, and its formula guard is
 * narrower than this one.
 *
 * FORMULA GUARD. A cell that starts with = + - @ TAB or CR (and is not a plain number, so
 * "-5" stays a number) is written with a leading apostrophe, so a spreadsheet shows it as
 * text instead of evaluating it. A value that already starts with an apostrophe followed by
 * something guardable gets one more apostrophe. The reader strips exactly one leading
 * apostrophe when what follows is something the writer would have guarded, so
 * read(send(v)) === v for every value, including "=cmd", "\tcmd" and "-20 °F".
 */
final class Csv
{
    private const GUARD_CHARS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Streams a CSV download and exits. UTF-8 with a BOM (Excel), RFC 4180 quoting,
     * no backslash escaping.
     *
     * @param iterable<array<int|string, mixed>> $rows
     */
    public static function send(string $filename, array $header, iterable $rows): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'export.csv';
        if (!str_ends_with(strtolower($safe), '.csv')) {
            $safe .= '.csv';
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safe . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map([self::class, 'guard'], array_values($header)), ',', '"', escape: '');
        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'guard'], array_values($row)), ',', '"', escape: '');
        }
        fclose($out);
        exit;
    }

    /**
     * Reads a CSV file into rows of strings (the header, if any, is rows[0]).
     *
     * Strips a UTF-8 BOM; converts CP1252 (Excel "CSV" on Windows) to UTF-8 with a warning;
     * detects the delimiter (, ; or TAB) from the first line; handles quoted multi-line cells;
     * drops fully empty lines; strips the formula-guard apostrophe.
     *
     * @return array{rows: list<list<string>>, delimiter: string, encoding: string, warnings: list<string>}
     * @throws \LengthException 'csv_too_large' / 'csv_too_many_rows'
     * @throws \RuntimeException 'csv_unreadable'
     */
    public static function read(string $path, int $maxBytes, int $maxRows): array
    {
        $size = @filesize($path);
        if ($size === false || !is_readable($path)) {
            throw new \RuntimeException('csv_unreadable');
        }
        if ($size > $maxBytes) {
            throw new \LengthException('csv_too_large');
        }
        $raw = (string) file_get_contents($path);
        $warnings = [];
        $encoding = 'UTF-8';
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
            $encoding = 'CP1252';
            $warnings[] = 'converted_from_cp1252';
        }
        $delimiter = self::detectDelimiter($raw);

        $fh = fopen('php://temp', 'w+b');
        fwrite($fh, $raw);
        rewind($fh);
        $rows = [];
        while (($cells = fgetcsv($fh, null, $delimiter, '"', escape: '')) !== false) {
            if ($cells === [null]) {
                continue; // blank line
            }
            $row = [];
            foreach ($cells as $c) {
                $row[] = self::unguard((string) $c);
            }
            if (count(array_filter($row, static fn($c) => trim($c) !== '')) === 0) {
                continue;
            }
            $rows[] = $row;
            if (count($rows) > $maxRows) {
                fclose($fh);
                throw new \LengthException('csv_too_many_rows');
            }
        }
        fclose($fh);
        return ['rows' => $rows, 'delimiter' => $delimiter, 'encoding' => $encoding, 'warnings' => $warnings];
    }

    /** The writer's guard for one cell. */
    public static function guard(mixed $v): string
    {
        $s = $v === null ? '' : (is_bool($v) ? ($v ? '1' : '0') : (string) $v);
        return self::needsGuard($s) ? "'" . $s : $s;
    }

    /** The reader's inverse of guard(). */
    public static function unguard(string $s): string
    {
        if (str_starts_with($s, "'") && self::needsGuard(substr($s, 1))) {
            return substr($s, 1);
        }
        return $s;
    }

    private static function needsGuard(string $s): bool
    {
        if ($s === '') {
            return false;
        }
        if (in_array($s[0], self::GUARD_CHARS, true)) {
            return !is_numeric($s);
        }
        return $s[0] === "'" && self::needsGuard(substr($s, 1));
    }

    private static function detectDelimiter(string $raw): string
    {
        // First physical line outside quotes.
        $line = '';
        $inQuotes = false;
        $len = min(strlen($raw), 65536);
        for ($i = 0; $i < $len; $i++) {
            $ch = $raw[$i];
            if ($ch === '"') {
                $inQuotes = !$inQuotes;
            } elseif (($ch === "\n" || $ch === "\r") && !$inQuotes) {
                break;
            } elseif (!$inQuotes) {
                $line .= $ch;
            }
        }
        $best = ',';
        $bestCount = 0;
        foreach ([',', ';', "\t"] as $d) {
            $n = substr_count($line, $d);
            if ($n > $bestCount) {
                $best = $d;
                $bestCount = $n;
            }
        }
        return $best;
    }
}
