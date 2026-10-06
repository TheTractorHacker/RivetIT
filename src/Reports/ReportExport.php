<?php

namespace ITFlow\Reports;

/**
 * One CSV writer for every report.
 *
 *  - cell():        formula-injection-safe value. Text that starts with = + - @ TAB or CR gets a leading apostrophe so a
 *                   spreadsheet shows it as text; genuine numbers (ints, floats, numeric strings such as "-5") are untouched.
 *  - toCsv():       header + rows to a CSV string (RFC 4180 quoting through fputcsv), UTF-8 BOM optional.
 *  - fromHtml():    reads the <table>s of a rendered report page, so a report page that has no hand-written export still
 *                   exports exactly what it shows (section caption row, header row, data rows, blank row between tables).
 */
final class ReportExport
{
    public static function cell($v)
    {
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        $v = (string) ($v ?? '');
        if ($v !== '' && !is_numeric($v) && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $v;
        }
        return $v;
    }

    /** @param array<int, array> $rows */
    public static function toCsv(array $header, array $rows, bool $bom = true): string
    {
        $fh = fopen('php://temp', 'w+');
        if ($bom) {
            fwrite($fh, "\xEF\xBB\xBF");
        }
        if ($header !== []) {
            fputcsv($fh, array_map([self::class, 'cell'], array_values($header)), ',', '"', '');
        }
        foreach ($rows as $row) {
            fputcsv($fh, array_map([self::class, 'cell'], array_values((array) $row)), ',', '"', '');
        }
        rewind($fh);
        $out = stream_get_contents($fh);
        fclose($fh);
        return (string) $out;
    }

    public static function filename(string $name, string $ext = 'csv'): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? '';
        $name = trim($name, '._-');
        if ($name === '') {
            $name = 'report';
        }
        return substr($name, 0, 100) . '.' . $ext;
    }

    /**
     * Pull every table out of an HTML fragment as CSV rows. Cells are the visible text (tags dropped, whitespace
     * collapsed). Returns a flat list of rows; tables are separated by an empty row and introduced by their caption.
     */
    public static function fromHtml(string $html): array
    {
        if (trim($html) === '' || stripos($html, '<table') === false) {
            return [];
        }
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new \DOMXPath($dom);

        $text = static function (\DOMNode $n): string {
            $t = preg_replace('/\s+/u', ' ', $n->textContent) ?? '';
            return trim(str_replace("\xC2\xA0", ' ', $t));
        };

        $rows = [];
        foreach ($xp->query('//table') as $table) {
            // Skip a table nested inside a cell of another table (the outer one already carries its text).
            if ($xp->query('ancestor::table', $table)->length > 0) {
                continue;
            }
            $tableRows = [];
            foreach ($xp->query('.//tr', $table) as $tr) {
                if ($xp->query('ancestor::table[1]', $tr)->item(0) !== $table) {
                    continue;
                }
                $cells = [];
                foreach ($xp->query('./th|./td', $tr) as $cell) {
                    $cells[] = $text($cell);
                    $span = (int) $cell->getAttribute('colspan');
                    for ($i = 1; $i < $span && $i < 20; $i++) {
                        $cells[] = '';
                    }
                }
                if (array_filter($cells, static fn ($c) => $c !== '') !== []) {
                    $tableRows[] = $cells;
                }
            }
            if ($tableRows === []) {
                continue;
            }
            // Caption: nearest preceding card title / heading.
            $caption = '';
            $h = $xp->query('(preceding::*[contains(concat(" ", normalize-space(@class), " "), " card-title ") or self::h1 or self::h2 or self::h3 or self::h4])[last()]', $table);
            if ($h->length) {
                $caption = $text($h->item(0));
            }
            if ($rows !== []) {
                $rows[] = [];
            }
            if ($caption !== '') {
                $rows[] = [$caption];
            }
            foreach ($tableRows as $r) {
                $rows[] = $r;
            }
        }
        return $rows;
    }

    /** CSV text of the tables in $html (empty string when there are none). */
    public static function csvFromHtml(string $html, bool $bom = true): string
    {
        $rows = self::fromHtml($html);
        return $rows === [] ? '' : self::toCsv([], $rows, $bom);
    }

    /**
     * Email-safe HTML of the tables in a report page (inline styles, no scripts, all text escaped).
     */
    public static function emailHtmlFromRows(string $title, array $rows): string
    {
        $h = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#333"><h2 style="color:#2c3e50">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>';
        $open = false;
        $first = true;
        foreach ($rows as $r) {
            if ($r === []) {
                if ($open) { $h .= '</table><br>'; $open = false; }
                $first = true;
                continue;
            }
            if (count($r) === 1 && !$open) {
                $h .= '<h3 style="margin:12px 0 4px">' . htmlspecialchars((string) $r[0], ENT_QUOTES, 'UTF-8') . '</h3>';
                continue;
            }
            if (!$open) {
                $h .= '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse">';
                $open = true;
                $first = true;
            }
            $tag = $first ? 'th' : 'td';
            $h .= '<tr>';
            foreach ($r as $c) {
                $h .= "<$tag style=\"border:1px solid #ddd;text-align:left;" . ($first ? 'background:#f0f0f0' : '') . '">' . htmlspecialchars((string) $c, ENT_QUOTES, 'UTF-8') . "</$tag>";
            }
            $h .= '</tr>';
            $first = false;
        }
        if ($open) {
            $h .= '</table>';
        }
        return $h . '</div>';
    }
}
