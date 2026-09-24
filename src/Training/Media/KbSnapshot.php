<?php

namespace ITFlow\Training\Media;

use ITFlow\KB\MediaToken;
use ITFlow\KB\MediaUrlRewriter;
use ITFlow\Training\Core\Access;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;

/**
 * Knowledge Base article -> training article snapshot (spec §1.3 #3, §3.3 "KbSnapshot").
 *
 * The article is copied at AUTHORING time: its HTML is sanitised for Training and every KB
 * image or attachment it shows is copied into the training media store, so the lesson keeps
 * working - and stays exactly what was published - whatever later happens to the KB article.
 * The source sha (sha256 of kb_article_content) is kept so the builder can show "KB changed"
 * (currentSha()).
 *
 * import():
 *   1 the article must be readable by the author under the app-wide KB department rule
 *     (Access::kbScopeSql, archived excluded) - otherwise 404, never "exists but forbidden"
 *   2 stages 0-1 of the sanitiser (the KB purifier config without interactive blocks)
 *   3 MediaUrlRewriter::mapMedia(): an image or attachment of THIS article, or a pool file ->
 *     KbFileLocator::resolve() -> copied into the store (images re-encoded) -> training URL.
 *     Media of another article, missing files and unsupported types are removed with a warning.
 *     Every ingest happens here, before any transaction (the caller opens its own afterwards).
 *   4 ArticleSanitizer::purify() - external images, other links, embeds are dropped/unwrapped
 *     with warnings. External images are never fetched.
 *   body_sha256 = sha256(final html): the lesson service compares it with the stored body to
 *   know whether the author has edited the import since (409 kb_local_edits on re-import).
 */
final class KbSnapshot
{
    private const REMOVED_URL = 'about:blank#tr-kb-removed';

    /** @return list<array{id:int, title:string, department:string, updated_at:?string, excerpt:string}> */
    public static function search(Ctx $c, string $q, int $limit = 20): array
    {
        $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
        if (mb_strlen($q, 'UTF-8') < 2) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        [$scope, $types, $params] = Access::kbScopeSql($c);
        $esc = addcslashes($q, '%_\\');
        $rows = Db::all($c->db, "SELECT a.kb_article_id, a.kb_article_title, a.kb_article_client_id, cl.client_name,
                a.kb_article_updated_at, a.kb_article_created_at, LEFT(a.kb_article_content_raw, 4000) AS raw_text
            FROM kb_articles a
            LEFT JOIN clients cl ON cl.client_id = a.kb_article_client_id
            WHERE $scope AND (a.kb_article_title LIKE ? OR a.kb_article_content_raw LIKE ?)
            ORDER BY (a.kb_article_title LIKE ?) DESC, COALESCE(a.kb_article_updated_at, a.kb_article_created_at) DESC, a.kb_article_id DESC
            LIMIT ?", $types . 'sssi', array_merge($params, ['%' . $esc . '%', '%' . $esc . '%', $esc . '%', $limit]));
        $out = [];
        foreach ($rows as $r) {
            $clientId = (int) $r['kb_article_client_id'];
            $out[] = [
                'id' => (int) $r['kb_article_id'],
                'title' => (string) $r['kb_article_title'],
                'department' => $clientId > 0 ? (string) ($r['client_name'] ?? ('#' . $clientId)) : 'Company-wide',
                'updated_at' => Clock::toIso((string) ($r['kb_article_updated_at'] ?? $r['kb_article_created_at'] ?? ''), false),
                'excerpt' => self::excerpt((string) ($r['raw_text'] ?? ''), (string) $r['kb_article_title'], $q),
            ];
        }
        return $out;
    }

    /**
     * @return array{html:string, title:string, source_sha256:string, body_sha256:string, media_ids:list<int>,
     *               warnings:list<string>, article_id:int}
     * @throws MediaException 404 not_found
     */
    public static function import(Ctx $c, int $articleId, MediaStore $s): array
    {
        MediaStore::assertOutsideTx();
        [$scope, $types, $params] = Access::kbScopeSql($c);
        $art = $articleId < 1 ? null : Db::one($c->db, "SELECT kb_article_id, kb_article_title, kb_article_content FROM kb_articles
            WHERE kb_article_id = ? AND $scope", 'i' . $types, array_merge([$articleId], $params));
        if ($art === null) {
            throw MediaException::notFound('That Knowledge Base article is not available.');
        }
        $raw = (string) ($art['kb_article_content'] ?? '');
        $warnings = [];
        // One HTML limit everywhere (ArticleSanitizer::MAX_HTML_BYTES): an article that could be
        // imported but never saved or previewed again is refused up front, before any copy.
        if (strlen($raw) > ArticleSanitizer::MAX_SOURCE_HTML_BYTES) {
            throw self::tooLong(strlen($raw));
        }

        $stage1 = ArticleSanitizer::purifyMarkup($raw);
        $notes = ['other_article' => 0, 'missing' => 0, 'unsupported' => 0, 'too_large' => 0];
        $cache = [];
        $mapped = MediaUrlRewriter::mapMedia($stage1['html'], function (array $d) use ($c, $s, $articleId, &$notes, &$cache): string {
            $kind = $d['kind'] ?? '';
            if ($kind === MediaToken::KIND_IMAGE) {
                if ((int) $d['ref'] !== $articleId) {
                    $notes['other_article']++;
                    return self::REMOVED_URL;
                }
                $rel = (int) $d['ref'] . '/' . $d['file'];
                $name = (string) $d['file'];
            } elseif ($kind === MediaToken::KIND_ATTACHMENT) {
                $att = Db::one($c->db, 'SELECT kb_article_attachment_name, kb_article_attachment_reference_name FROM kb_article_attachments
                    WHERE kb_article_attachment_id = ? AND kb_article_attachment_kb_article_id = ?', 'ii', [(int) $d['ref'], $articleId]);
                if ($att === null) {
                    $notes['other_article']++;
                    return self::REMOVED_URL;
                }
                $rel = $articleId . '/' . $att['kb_article_attachment_reference_name'];
                $name = (string) $att['kb_article_attachment_name'];
            } else {
                $rel = (string) $d['file'];
                $name = (string) $d['file'];
            }
            if (!array_key_exists($rel, $cache)) {
                $cache[$rel] = self::copyIntoStore($c, $s, $rel, $name, $notes);
            }
            return $cache[$rel] === null ? self::REMOVED_URL : MediaStore::url($cache[$rel], !empty($d['download']));
        });

        $removedImgs = preg_match_all('/\ssrc="' . preg_quote(self::REMOVED_URL, '/') . '"/', $mapped) ?: 0;
        $removedLinks = preg_match_all('/\shref="' . preg_quote(self::REMOVED_URL, '/') . '"/', $mapped) ?: 0;

        $final = ArticleSanitizer::purify($mapped, $s->kindLookup());
        $html = $final['html'];
        if (strlen($html) > ArticleSanitizer::MAX_HTML_BYTES) {
            throw self::tooLong(strlen($html));
        }

        if ($notes['other_article'] > 0) {
            $warnings[] = self::plural($notes['other_article'], 'picture or file belonging to another article was', 'pictures or files belonging to other articles were') . ' removed.';
        }
        if ($notes['missing'] > 0) {
            $warnings[] = self::plural($notes['missing'], 'picture or file in the article is', 'pictures or files in the article are') . ' missing from the server and was left out.';
        }
        if ($notes['unsupported'] > 0) {
            $warnings[] = self::plural($notes['unsupported'], 'attached file has', 'attached files have') . ' a type Training cannot use and was left out.';
        }
        if ($notes['too_large'] > 0) {
            $warnings[] = self::plural($notes['too_large'], 'attached file is', 'attached files are') . ' too large for Training and was left out.';
        }
        $ext = max(0, $final['removed']['external_image'] - $removedImgs);
        if ($ext > 0) {
            $warnings[] = self::plural($ext, 'picture from outside the Knowledge Base was', 'pictures from outside the Knowledge Base were') . ' removed. Upload them in the article instead.';
        }
        $links = max(0, $final['removed']['link'] - $removedLinks);
        if ($links > 0) {
            $warnings[] = self::plural($links, 'link that is not a web (https) or email link was', 'links that are not web (https) or email links were') . ' turned into plain text.';
        }
        if ($stage1['removed']['ikb'] > 0) {
            $warnings[] = self::plural($stage1['removed']['ikb'], 'interactive embed was', 'interactive embeds were') . ' removed: embeds only work in the Knowledge Base.';
        }
        if (stripos($raw, 'data-ikb=') !== false && preg_match('/data-ikb\s*=\s*["\']?(sequence|tree|copy)/i', $raw) === 1) {
            $warnings[] = 'Interactive checklists, steps and decision trees were turned into plain text.';
        }

        return [
            'html' => $html,
            'title' => (string) $art['kb_article_title'],
            'source_sha256' => hash('sha256', $raw),
            'body_sha256' => hash('sha256', $html),
            'media_ids' => ArticleMediaRefs::extract($html),
            'warnings' => $warnings,
            'article_id' => (int) $art['kb_article_id'],
        ];
    }

    /** sha256 of the live article's stored HTML, or null when it is gone or archived (drift badge). */
    public static function currentSha(\mysqli $db, int $articleId): ?string
    {
        if ($articleId < 1) {
            return null;
        }
        $row = Db::one($db, 'SELECT kb_article_content FROM kb_articles WHERE kb_article_id = ? AND kb_article_archived_at IS NULL', 'i', [$articleId]);
        return $row === null ? null : hash('sha256', (string) ($row['kb_article_content'] ?? ''));
    }

    // ---------------------------------------------------------------------------------------

    /** Copies one KB file into the training store. @return int|null the media id, or null (noted) */
    private static function copyIntoStore(Ctx $c, MediaStore $s, string $rel, string $name, array &$notes): ?int
    {
        $abs = KbFileLocator::resolve($rel);
        if ($abs === null) {
            $notes['missing']++;
            return null;
        }
        $size = (int) @filesize($abs);
        if ($size < 1) {
            $notes['missing']++;
            return null;
        }
        if ($size > $c->settings->fileMaxBytes) {
            $notes['too_large']++;
            return null;
        }
        try {
            $info = FileValidator::classify($abs, 'resource_file', $name, $c->settings);
            if ($info['kind'] === 'image') {
                if ($size > $c->settings->imageMaxBytes) {
                    $notes['too_large']++;
                    return null;
                }
                $img = ImageProcessor::reencodeFile($abs);
                $m = $s->ingestBytes($img['bytes'], 'image', $img['mime'], $img['ext'], $name,
                    ['width' => $img['width'], 'height' => $img['height']], 'kb_import');
            } elseif ($info['kind'] === 'pdf') {
                $m = $s->ingestFile($abs, 'pdf', 'application/pdf', 'pdf', $name, ['page_count' => $info['meta']['page_count'] ?? null], 'kb_import');
            } else {
                $m = $s->ingestFile($abs, 'file', $info['mime'], $info['ext'], $name, [], 'kb_import');
            }
            return (int) $m['media_id'];
        } catch (MediaException $e) {
            if ($e->errCode === 'budget_exceeded' || $e->errCode === 'busy') {
                throw $e;   // the whole import cannot finish; say why instead of silently dropping media
            }
            $notes[$e->http === 413 ? 'too_large' : 'unsupported']++;
            return null;
        }
    }

    private static function excerpt(string $raw, string $title, string $q): string
    {
        $text = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($title !== '' && str_starts_with($text, $title)) {
            $text = ltrim(substr($text, strlen($title)));
        }
        $pos = mb_stripos($text, $q, 0, 'UTF-8');
        $start = ($pos === false || $pos < 60) ? 0 : $pos - 60;
        $snippet = mb_substr($text, $start, 180, 'UTF-8');
        return ($start > 0 ? '…' : '') . $snippet . (mb_strlen($text, 'UTF-8') > $start + 180 ? '…' : '');
    }

    private static function tooLong(int $bytes): MediaException
    {
        return new MediaException(413, 'too_large', 'This Knowledge Base article is too long for one lesson (' . (int) ceil($bytes / 1024)
            . ' KB of text and formatting; the limit is ' . intdiv(ArticleSanitizer::MAX_HTML_BYTES, 1024) . ' KB). Split the article, or import part of it.');
    }

    private static function plural(int $n, string $one, string $many): string
    {
        return $n === 1 ? "1 $one" : "$n $many";
    }
}
