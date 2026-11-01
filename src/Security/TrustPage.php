<?php

declare(strict_types=1);

namespace ITFlow\Security;

/**
 * Data and markup for the "Security and trust" page (public /trust.php and the administrator copy under Settings).
 *
 * Content rules (vendor/rivet/rivet-core/docs/security/CLAIMS.md): the approved claims are copied from that file's "Approved wording" table AS WRITTEN and
 * nothing is invented here; the edition's own status counts come from docs/security/EDITION_BASELINE.json when the page is rendered (never typed by hand);
 * the known gaps are listed; no badge, logo or seal that implies certification. {@see self::FORBIDDEN} is the phrase list the test scans the output for.
 */
final class TrustPage
{
    /** Phrases CLAIMS.md forbids. The page must never contain any of them (tests/trust_page.php scans the rendered output). */
    public const FORBIDDEN = [
        'SOC 2 compliant', 'SOC 2 certified', 'ISO 27001 certified', 'HIPAA compliant', 'HIPAA certified',
        'guaranteed secure', 'unhackable', 'military-grade', 'bank-grade',
    ];

    public function __construct(private string $root, private ?string $coreRoot = null)
    {
        $this->coreRoot ??= $root . '/vendor/rivet/rivet-core';
    }

    /** @return list<string> the approved claims, verbatim (the first column of CLAIMS.md "Approved wording"); empty if the file is not there */
    public function approvedClaims(): array
    {
        $text = @file_get_contents($this->coreRoot . '/docs/security/CLAIMS.md');
        if ($text === false || !preg_match('/^## Approved wording[^\n]*\n(.*?)(?=^## )/ms', $text, $m)) {
            return [];
        }
        $claims = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^\|\s*"(.+?)"\s*\|/', $line, $c) === 1) {
                $claims[] = $c[1];
            }
        }

        return $claims;
    }

    /** @return array{edition: string, counts: array<string,int>, controls: list<array<string,mixed>>}|null */
    public function editionBaseline(): ?array
    {
        $raw = @file_get_contents($this->root . '/docs/security/EDITION_BASELINE.json');
        $d = $raw === false ? null : json_decode($raw, true);
        if (!is_array($d) || !is_array($d['controls'] ?? null)) {
            return null;
        }
        $counts = ['enforced' => 0, 'partial' => 0, 'gap' => 0];
        foreach ($d['controls'] as $c) {
            $s = (string) ($c['status'] ?? '');
            if (isset($counts[$s])) {
                $counts[$s]++;
            }
        }

        return ['edition' => (string) ($d['edition'] ?? 'RivetIT'), 'counts' => $counts, 'controls' => $d['controls']];
    }

    /** @return array{version:string, counts:array<string,int>, gaps:list<array{id:string,title:string}>}|null */
    public function coreBaseline(): ?array
    {
        $raw = @file_get_contents($this->coreRoot . '/docs/security/baseline.json');
        $d = $raw === false ? null : json_decode($raw, true);
        if (!is_array($d) || !is_array($d['controls'] ?? null)) {
            return null;
        }
        $counts = [];
        foreach ($d['controls'] as $c) {
            $s = (string) ($c['status'] ?? '');
            $counts[$s] = ($counts[$s] ?? 0) + 1;
        }
        $gaps = [];
        foreach ((array) ($d['gaps'] ?? []) as $g) {
            $gaps[] = ['id' => (string) ($g['id'] ?? ''), 'title' => (string) ($g['title'] ?? '')];
        }

        return ['version' => (string) ($d['version'] ?? ''), 'counts' => $counts, 'gaps' => $gaps];
    }

    /** The pinned RivetCore tag, from composer.lock (e.g. v1.0.0-rc.55); '' when unknown. */
    public function coreVersion(): string
    {
        $lock = json_decode((string) @file_get_contents($this->root . '/composer.lock'), true);
        foreach ((array) ($lock['packages'] ?? []) as $p) {
            if (($p['name'] ?? '') === 'rivet/rivet-core') {
                return (string) ($p['version'] ?? '');
            }
        }

        return '';
    }

    /** The rendered body (no <html> wrapper). $admin adds the per-control table with the notes. */
    public function html(string $editionVersion, bool $admin = false): string
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $core = $this->coreVersion();
        $tag = $core !== '' ? $core : 'main';
        $base = 'https://github.com/TheTractorHacker/rivet-core/blob/' . rawurlencode($tag) . '/docs/security/';
        $edition = $this->editionBaseline();
        $coreBaseline = $this->coreBaseline();
        $claims = $this->approvedClaims();

        $out = '<p class="text-muted">' . $h(defined('APP_NAME') ? APP_NAME : 'RivetIT') . ' ' . $h($editionVersion)
            . ($core !== '' ? ', built on RivetCore ' . $h($core) : '') . '. This page is a self-assessment, not a certificate.</p>';

        $out .= '<h2 class="h4 mt-4">What we say</h2><ul>';
        foreach ($claims as $c) {
            $out .= '<li>' . $h($c) . '</li>';
        }
        $out .= '</ul>';

        $out .= '<h2 class="h4 mt-4">Controls and status</h2>';
        if ($edition !== null) {
            $n = $edition['counts'];
            $out .= '<p>' . $h($edition['edition']) . ' implements the controls RivetCore leaves to the edition. Current self-assessed status, generated from '
                . '<code>docs/security/EDITION_BASELINE.json</code>: <strong>' . $n['enforced'] . '</strong> enforced and covered by a named test, <strong>'
                . $n['partial'] . '</strong> partial, <strong>' . $n['gap'] . '</strong> gap.</p>';
        }
        if ($coreBaseline !== null) {
            $parts = [];
            foreach ($coreBaseline['counts'] as $s => $k) {
                $parts[] = $k . ' ' . $s;
            }
            $out .= '<p>RivetCore control list at ' . $h($tag) . ': ' . $h(implode(', ', $parts)) . '. '
                . '<a href="' . $h($base . 'BASELINE.md') . '" rel="noopener noreferrer">Published control list</a> &middot; '
                . '<a href="' . $h($base . 'CONTROL_MAPPING.md') . '" rel="noopener noreferrer">SOC 2 / ISO 27001 mapping</a> (mapped, not met).</p>';
        }

        $out .= '<h2 class="h4 mt-4">Known gaps</h2><ul>';
        if ($edition !== null) {
            foreach ($edition['controls'] as $c) {
                if (($c['status'] ?? '') === 'gap' || (($c['status'] ?? '') === 'partial' && trim((string) ($c['note'] ?? '')) !== '')) {
                    $out .= '<li><strong>' . $h((string) $c['core_control']) . '</strong> (' . $h((string) $c['status']) . '): ' . $h((string) $c['note']) . '</li>';
                }
            }
        }
        if ($coreBaseline !== null) {
            foreach ($coreBaseline['gaps'] as $g) {
                $out .= '<li>RivetCore ' . $h($g['id']) . ': ' . $h($g['title']) . '</li>';
            }
        }
        $out .= '</ul>';

        $out .= '<h2 class="h4 mt-4">Independent review</h2><p>Independent security review: none yet. Self-audited 2026-10; findings and fixes published. '
            . 'We hold no certifications.</p>';
        $out .= '<h2 class="h4 mt-4">Reporting a vulnerability</h2><p>Please do not report security concerns publicly. Use '
            . '<a href="https://github.com/TheTractorHacker/RivetIT/security/advisories/new" rel="noopener noreferrer">GitHub Security Advisories</a> '
            . '(<code>SECURITY.md</code>); you should expect an acknowledgement within 72 hours.</p>';

        if ($admin && $edition !== null) {
            $out .= '<h2 class="h4 mt-4">Per-control detail (administrators)</h2><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Control</th><th>Status</th><th>How</th><th>Tests</th></tr></thead><tbody>';
            foreach ($edition['controls'] as $c) {
                $out .= '<tr><td>' . $h((string) $c['core_control']) . '</td><td>' . $h((string) $c['status']) . '</td><td>' . $h((string) $c['how'])
                    . (($c['note'] ?? '') !== '' ? '<div class="small text-muted">' . $h((string) $c['note']) . '</div>' : '') . '</td><td>'
                    . $h(implode(', ', (array) ($c['tests'] ?? []))) . '</td></tr>';
            }
            $out .= '</tbody></table></div>';
        }

        return $out;
    }
}
