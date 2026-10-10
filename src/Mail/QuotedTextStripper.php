<?php

declare(strict_types=1);

namespace ITFlow\Mail;

/**
 * Removes the quoted history from an inbound reply so the ticket thread shows only what the person just wrote.
 *
 * Plain text: "On ... wrote:" attribution lines (and localised forms), "-----Original Message-----" separators,
 * From:/Sent:/To:/Subject: header blocks (Outlook), an Outlook underscore rule before such a block, and ">" quoted
 * lines. HTML: Gmail / Outlook / Apple Mail / Thunderbird quote containers. The existing "##- Please type your
 * reply above this line -##" marker always wins. Nothing here may ever return an empty result for a non-empty input:
 * when stripping would leave nothing (the whole message is a quote, or the reply is only a forward) the original text
 * is returned unchanged.
 */
final class QuotedTextStripper
{
    public const REPLY_MARKER = '/##-\s*Please\s+type\s+your\s+reply\s+above\s+this\s+line\s*-##/i';

    private const ATTR_START = '(?:On|Am|Le|El|Il|Em|Op|Den|På|Pa|W dniu|Il giorno|Dne)';
    private const ATTR_END = '(?:wrote|schrieb|a\s+écrit|a\s+ecrit|escribió|escribio|ha\s+scritto|escreveu|schreef|skrev|napisał|napisala|napsal)';

    private const HDR_FROM = '(?:From|Von|De|Da|Van|Från|Fra|Od)';
    private const HDR_OTHER = '(?:Sent|Date|To|Cc|Subject|Gesendet|Datum|An|Betreff|Envoyé|Envoye|À|A|Objet|Enviado|Para|Asunto|Inviato|Oggetto|Verzonden|Aan|Onderwerp|Skickat|Till|Ämne|Wysłano|Temat)';

    private const ORIGINAL = '/^\s*[-_=*>\s]*(?:original\s+message|ursprüngliche\s+nachricht|urspruengliche\s+nachricht|message\s+d.origine|mensaje\s+original|messaggio\s+originale|mensagem\s+original|oorspronkelijk\s+bericht|opprinnelig\s+melding|ursprungligt\s+meddelande)[-_=*\s]*$/iu';

    /** Strip plain text. Never returns '' for a non-blank input. */
    public static function stripText(string $text): string
    {
        if (trim($text) === '') {
            return $text;
        }
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $normalized);

        $cut = self::findCutLine($lines);
        $kept = $cut === null ? $lines : array_slice($lines, 0, $cut);

        // Quoted lines left in the part we kept (inline replies): drop them, keep the author's own lines.
        $kept = array_values(array_filter($kept, static fn (string $l): bool => !preg_match('/^\s*>/', $l)));

        $result = rtrim(implode("\n", $kept));
        // Trim trailing blank lines and a dangling signature separator-less result; leading blank lines too.
        $result = trim($result, "\n");

        return trim($result) === '' ? $text : $result;
    }

    /** First line index at which the quoted history starts, or null. */
    private static function findCutLine(array $lines): ?int
    {
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            $line = $lines[$i];
            if (preg_match(self::REPLY_MARKER, $line)) {
                return $i;
            }
            if (preg_match(self::ORIGINAL, $line)) {
                return $i;
            }
            // Attribution: "On <date>, <name> <email> wrote:" - Gmail/Apple wrap it over up to three lines. Needs a digit,
            // "@" or "<" so a sentence that merely starts with "On" and ends in "wrote:" is left alone.
            if (preg_match('/^\s*' . self::ATTR_START . '\s+.{3,}/iu', $line)) {
                $joined = trim($line);
                for ($j = 0; $j < 4; $j++) {
                    if (strlen($joined) < 400 && (preg_match('/' . self::ATTR_END . '\s*[:：]?\s*$/iu', $joined) || preg_match('/' . self::ATTR_END . '\s.{0,200}[:：]\s*$/iu', $joined)) && preg_match('/[\d@<]/', $joined)) {
                        return $i;
                    }
                    $next = $lines[$i + $j + 1] ?? null;
                    if ($next === null || trim($next) === '') {
                        break;
                    }
                    $joined .= ' ' . trim($next);
                }
            }
            // Outlook header block: From: + at least one more header-ish line right after it.
            if (preg_match('/^\s*\*?\s*' . self::HDR_FROM . '\s*\*?\s*:\s*\*?\s*\S/iu', $line)) {
                $hits = 0;
                for ($j = 1; $j <= 5 && isset($lines[$i + $j]); $j++) {
                    if (preg_match('/^\s*\*?\s*' . self::HDR_OTHER . '\s*\*?\s*:\s*/iu', $lines[$i + $j])) {
                        $hits++;
                    } elseif (trim($lines[$i + $j]) === '') {
                        break;
                    }
                }
                if ($hits >= 2 || ($hits >= 1 && preg_match('/^\s*\*?\s*' . self::HDR_FROM . '\s*\*?\s*:.*[<@]/iu', $line))) {
                    // Include an Outlook rule / blank line sitting directly above the block.
                    $start = $i;
                    while ($start > 0 && (trim($lines[$start - 1]) === '' || preg_match('/^\s*[_\-=]{8,}\s*$/', $lines[$start - 1]))) {
                        $start--;
                    }
                    return $start;
                }
            }
        }
        return null;
    }

    /**
     * Strip an HTML body. Removes the first quote container (Gmail, Outlook, Apple Mail, Thunderbird) and everything
     * after it, and the reply-above marker with everything after it. Returns the original when nothing would be left.
     */
    public static function stripHtml(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }
        // The marker the app puts in its own notifications: cut it and everything after it.
        $cut = preg_replace('/<i[^>]*>\s*##-\s*Please\s+type\s+your\s+reply\s+above\s+this\s+line\s*-##\s*<\/i>.*$/is', '', $html);
        $cut = preg_replace('/##-\s*Please\s+type\s+your\s+reply\s+above\s+this\s+line\s*-##.*$/is', '', $cut ?? $html);
        $result = self::removeQuoteContainers($cut ?? $html);
        return self::visibleText($result) === '' ? $html : $result;
    }

    private static function visibleText(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\xC2\xA0");
    }

    private static function removeQuoteContainers(string $html): string
    {
        if (!class_exists(\DOMDocument::class)) {
            return $html;
        }
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8"><div id="__rivet_root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return $html;
        }
        $xp = new \DOMXPath($doc);
        $queries = [
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' gmail_quote ')]",
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' gmail_extra ')]",
            "//div[@id='divRplyFwdMsg']",
            "//div[@id='appendonsend']",
            "//div[@id='mail-editor-reference-message-container']",
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' yahoo_quoted ')]",
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' moz-cite-prefix ')]",
            "//blockquote[@type='cite']",
            "//div[contains(@style,'border-top:solid #E1E1E1') or contains(@style,'border-top: solid #E1E1E1') or contains(@style,'border-top:solid #B5C4DF')]",
        ];
        $matched = new \SplObjectStorage();
        foreach ($queries as $q) {
            $nodes = $xp->query($q);
            if ($nodes === false) {
                continue;
            }
            foreach ($nodes as $node) {
                $matched->attach($node);
            }
        }
        $first = null;
        foreach ($doc->getElementsByTagName('*') as $el) { // document order
            if ($matched->contains($el)) {
                $first = $el;
                break;
            }
        }
        if ($first === null) {
            return $html;
        }
        // An <hr> directly above an Outlook block belongs to the quote.
        $prevSibling = $first->previousSibling;
        while ($prevSibling !== null && $prevSibling->nodeType === XML_TEXT_NODE && trim($prevSibling->textContent) === '') {
            $prevSibling = $prevSibling->previousSibling;
        }
        if ($prevSibling !== null && strtolower($prevSibling->nodeName) === 'hr') {
            $first = $prevSibling;
        }
        // Remove the node and everything after it, climbing to the wrapper.
        $node = $first;
        $root = $doc->getElementById('__rivet_root') ?? null;
        if ($root === null) {
            foreach ($doc->getElementsByTagName('div') as $d) {
                if ($d->getAttribute('id') === '__rivet_root') {
                    $root = $d;
                    break;
                }
            }
        }
        if ($root === null) {
            return $html;
        }
        while ($node !== null && $node !== $root) {
            while ($node->nextSibling !== null) {
                $node->parentNode->removeChild($node->nextSibling);
            }
            $parent = $node->parentNode;
            if ($node === $first) {
                $parent->removeChild($node);
            }
            $node = $parent;
        }
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    /**
     * Strip a reply for storage. Prefers the plain-text part (quote markers are reliable there); when text shows no
     * quote the HTML body is tried; when neither finds one, the body is returned as received.
     *
     * @return array{body: string, stripped: bool}
     */
    public static function stripReply(string $htmlBody, string $textBody): array
    {
        $textBody = trim($textBody);
        if ($textBody !== '') {
            $stripped = self::stripText($textBody);
            if ($stripped !== $textBody) {
                return ['body' => nl2br(htmlspecialchars($stripped, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')), 'stripped' => true];
            }
        }
        if (trim($htmlBody) !== '') {
            $s = self::stripHtml($htmlBody);
            if ($s !== $htmlBody) {
                return ['body' => $s, 'stripped' => true];
            }
        }
        return ['body' => $htmlBody, 'stripped' => false];
    }
}
