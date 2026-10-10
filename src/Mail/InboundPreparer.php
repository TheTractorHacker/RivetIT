<?php

declare(strict_types=1);

namespace ITFlow\Mail;

/**
 * Builds the pieces the ticket code consumes from a normalised message: the HTML body (inline images embedded up to a
 * size cap), the attachment list after the size limits and the optional virus scan, and the MIME part list the bounce
 * (DSN) sniffing needs.
 */
final class InboundPreparer
{
    /**
     * @param array $n normalised message (MessageNormalizer)
     * @param array{max_file_bytes?: int, max_message_bytes?: int, inline_max_bytes?: int, scan?: ?callable} $limits
     *        scan: fn(string $content): ?string returning a signature name when infected
     * @return array{body: string, body_html: string, note: string, body_text: string, attachments: array, raw_parts: array, rejected: array}
     */
    public static function prepare(array $n, array $limits): array
    {
        $maxFile = (int) ($limits['max_file_bytes'] ?? 0);
        $maxMsg = (int) ($limits['max_message_bytes'] ?? 0);
        $inlineMax = (int) ($limits['inline_max_bytes'] ?? 0);
        $scan = $limits['scan'] ?? null;

        $html = (string) ($n['html'] ?? '');
        $text = (string) ($n['text'] ?? '');
        if ($html !== '') {
            $body = $html;
        } elseif ($text !== '') {
            $body = nl2br(htmlspecialchars($text));
        } else {
            $body = '';
        }

        $regular = [];
        $rawParts = [];
        $rejected = [];
        foreach ($n['attachments'] ?? [] as $att) {
            $content = $att['content'] ?? null;
            $mime = (string) ($att['mime'] ?? 'application/octet-stream');
            $name = (string) ($att['name'] ?? 'attachment');
            // Bounce sniffing only needs the delivery-status and embedded-message parts; do not carry every blob around.
            $declared = (string) ($att['content_type'] ?? $mime);
            $keepContent = stripos($declared, 'delivery-status') !== false || stripos($declared, 'message/rfc822') !== false || stripos($declared, 'text/rfc822-headers') !== false;
            $rawParts[] = ['name' => $name, 'content' => $keepContent ? $content : null, 'content_type' => $declared];
            if ($content === null) {
                continue;
            }

            $cid = isset($att['cid']) ? trim((string) $att['cid'], '<>') : '';
            $inline = ($att['disposition'] ?? '') === 'inline' && $cid !== '';
            if ($inline && AttachmentPolicy::inlineAllowed(strlen($content), $inlineMax)) {
                $uri = 'data:' . $mime . ';base64,' . base64_encode($content);
                $body = str_replace(['cid:' . $cid, 'cid:<' . $cid . '>'], $uri, $body);
                continue;
            }
            // An oversized inline image falls through: it is kept as an ordinary attachment instead of bloating the body.
            $regular[] = ['name' => $name, 'content' => $content];
        }

        if ($scan !== null) {
            $clean = [];
            foreach ($regular as $att) {
                $sig = $scan($att['content']);
                if ($sig !== null) {
                    $rejected[] = ['name' => $att['name'], 'size' => strlen($att['content']), 'reason' => 'blocked by virus scan (' . $sig . ')'];
                } else {
                    $clean[] = $att;
                }
            }
            $regular = $clean;
        }

        $applied = AttachmentPolicy::apply($regular, $maxFile, $maxMsg);
        $rejected = array_merge($rejected, $applied['rejected']);
        $htmlBody = $body;
        $note = AttachmentPolicy::rejectionNote($rejected);
        $body .= $note;

        return [
            'body' => $body,           // HTML, inline images embedded, plus the "not imported" note
            'body_html' => $htmlBody,  // the same without the note (what quote stripping works on)
            'note' => $note,
            'body_text' => $text,
            'attachments' => $applied['kept'],
            'raw_parts' => $rawParts,
            'rejected' => $rejected,
        ];
    }
}
