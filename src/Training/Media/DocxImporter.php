<?php

namespace ITFlow\Training\Media;

use ITFlow\KB\DocxConversionException;
use ITFlow\KB\DocxConverter;

/**
 * Word document (.docx) -> training article HTML (spec §4.1 docx_import, §5.4 "Import Word").
 *
 *   DocxConverter::convert()  the KB's hardened converter (XXE, zip-bomb and zip-slip safe; a
 *                             small fixed tag set; embedded images returned as bytes)
 *   each image                re-encoded (ImageProcessor) and ingested as `image` media, its
 *                             placeholder token replaced with the training media URL
 *   ArticleSanitizer::purify  the same sanitiser every authored HTML field goes through
 *
 * Nothing is written to any lesson: the author reviews the HTML in the editor and the normal
 * autosave stores it. The caller has already validated the upload (FileValidator: a real .docx
 * without macros); ingests run here at depth 0.
 */
final class DocxImporter
{
    /**
     * @return array{html:string, media_ids:list<int>, warnings:list<string>}
     * @throws MediaException 415 unsupported_type (not convertible), 413 budget_exceeded
     */
    public static function import(string $tmpPath, MediaStore $s): array
    {
        MediaStore::assertOutsideTx();
        try {
            $doc = DocxConverter::convert($tmpPath);
        } catch (DocxConversionException $e) {
            throw MediaException::unsupported($e->getMessage());
        }
        $warnings = array_values(array_map('strval', $doc['warnings'] ?? []));
        $html = (string) $doc['html'];
        // Refused before any picture is stored: the article must fit the one HTML limit
        // (ArticleSanitizer::MAX_HTML_BYTES) to be saved, edited and previewed afterwards.
        if (strlen($html) > ArticleSanitizer::MAX_SOURCE_HTML_BYTES) {
            throw self::tooLong(strlen($html));
        }
        $dropped = 0;
        $tooBig = 0;
        $replace = [];
        foreach ($doc['media'] ?? [] as $i => $m) {
            $token = (string) ($m['token'] ?? '');
            if ($token === '') {
                continue;
            }
            try {
                $img = ImageProcessor::reencodeBytes((string) $m['bytes']);
                $row = $s->ingestBytes($img['bytes'], 'image', $img['mime'], $img['ext'], 'word-image-' . ($i + 1) . '.' . $img['ext'],
                    ['width' => $img['width'], 'height' => $img['height']], 'docx_import');
                $replace[$token] = MediaStore::url((int) $row['media_id']);
            } catch (MediaException $e) {
                if ($e->errCode === 'budget_exceeded' || $e->errCode === 'busy') {
                    throw $e;
                }
                if ($e->http === 413) {
                    $tooBig++;
                } else {
                    $dropped++;
                }
                $replace[$token] = 'about:blank#tr-docx-removed';
            }
        }
        if ($replace !== []) {
            $html = strtr($html, $replace);
        }
        $clean = ArticleSanitizer::purify($html, $s->kindLookup());
        if (strlen($clean['html']) > ArticleSanitizer::MAX_HTML_BYTES) {
            throw self::tooLong(strlen($clean['html']));
        }

        if ($tooBig > 0) {
            $warnings[] = $tooBig === 1 ? '1 picture was larger than 16 megapixels and was left out.' : "$tooBig pictures were larger than 16 megapixels and were left out.";
        }
        if ($dropped > 0) {
            $warnings[] = $dropped === 1 ? '1 picture could not be read and was left out.' : "$dropped pictures could not be read and were left out.";
        }
        $links = $clean['removed']['link'];
        if ($links > 0) {
            $warnings[] = $links === 1 ? '1 link that is not a web (https) or email link was turned into plain text.'
                : "$links links that are not web (https) or email links were turned into plain text.";
        }
        return ['html' => $clean['html'], 'media_ids' => ArticleMediaRefs::extract($clean['html']), 'warnings' => $warnings];
    }

    private static function tooLong(int $bytes): MediaException
    {
        return new MediaException(413, 'too_large', 'This document is too long for one article (' . (int) ceil($bytes / 1024) . ' KB of text and formatting; the limit is '
            . intdiv(ArticleSanitizer::MAX_HTML_BYTES, 1024) . ' KB). Split it into smaller documents.');
    }
}
