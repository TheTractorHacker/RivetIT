<?php

/**
 * Plain-text preview of a knowledge-base article for the card/list views.
 * strip_tags() alone glued headings and paragraphs together ("Change your passwordIf you sign in...") and repeated the title as the first words.
 * Block ends become spaces, entities are decoded once, runs of whitespace collapse, a leading copy of the title is dropped.
 */
function kbExcerpt(?string $html, string $title = '', int $length = 150): string
{
    $text = preg_replace('~</(p|div|li|h[1-6]|tr|blockquote|pre)>|<br\s*/?>~i', ' ', (string) $html);
    $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    $title = trim(preg_replace('/\s+/u', ' ', $title));
    if ($title !== '' && stripos($text, $title) === 0) {
        $text = ltrim(mb_substr($text, mb_strlen($title)), " \t-:\u{2013}\u{2014}");
    }
    return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length)) . '...' : $text;
}
