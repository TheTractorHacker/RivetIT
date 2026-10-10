<?php

declare(strict_types=1);

namespace ITFlow\Mail;

/**
 * Turns a fetched message into one neutral array, whatever the transport, so the same preparation and routing code
 * runs for IMAP (Webklex), Microsoft Graph and for .eml fixtures in tests.
 *
 * Shape:
 *   from_email, from_name, subject, ccs[], date ('Y-m-d H:i:s'),
 *   html, text,
 *   headers   (RawHeaders::parse),
 *   message_id (normalised), in_reply_to (raw), references (raw),
 *   attachments[] = {name, content, mime, content_type, disposition ('attachment'|'inline'), cid}
 */
final class MessageNormalizer
{
    public const FALLBACK_FROM = 'itflow-guest@example.com';

    /** @param \Webklex\PHPIMAP\Message $m */
    public static function fromWebklex($m): array
    {
        $rawHeader = (string) $m->getHeader()->raw;
        $headers = RawHeaders::parse($rawHeader);

        $from = $m->getFrom();
        $first = ($from && $from->count()) ? $from->first() : null;

        $subject = (string) $m->getSubject();
        if (strpos($subject, '=?') !== false) {
            $subject = RawHeaders::decode($subject);
        }

        $ccs = [];
        $ccAttr = $m->header->cc ?? null;
        if ($ccAttr) {
            foreach ($ccAttr->toArray() as $addr) {
                if ($addr instanceof \Webklex\PHPIMAP\Address) {
                    $ccs[] = $addr->mail;
                }
            }
        }

        $dateAttr = $m->getDate();
        $dateRaw = $dateAttr ? (string) $dateAttr : '';
        $ts = $dateRaw !== '' ? strtotime($dateRaw) : false;

        $attachments = [];
        foreach ($m->getAttachments() as $att) {
            $attrs = $att->getAttributes();
            $attachments[] = [
                'name' => $att->getName() ?: 'attachment',
                'content' => $attrs['content'] ?? null,
                // 'mime' is what the bytes look like (finfo; right for data: URIs). 'content_type' is what the sender declared
                // (message/delivery-status, message/rfc822, ...), which is what bounce detection needs: for a delivery-status
                // part Webklex's getMimeType() says text/plain.
                'mime' => (string) $att->getMimeType(),
                'content_type' => strtolower((string) ($att->getContentType() ?: ($attrs['content_type'] ?? '') ?: $att->getMimeType())),
                'disposition' => strtolower((string) ($attrs['disposition'] ?? '')),
                'cid' => isset($attrs['id']) ? (string) $attrs['id'] : null,
            ];
        }

        return [
            'from_email' => (string) ($first->mail ?? self::FALLBACK_FROM),
            'from_name' => (string) ($first->personal ?? 'Unknown'),
            'subject' => $subject !== '' ? $subject : 'No Subject',
            'ccs' => $ccs,
            'date' => date('Y-m-d H:i:s', $ts !== false ? $ts : time()),
            'html' => (string) ($m->getHTMLBody() ?? ''),
            'text' => (string) ($m->getTextBody() ?? ''),
            'headers' => $headers,
            'message_id' => MessageId::normalize(RawHeaders::first($headers, 'message-id')),
            'in_reply_to' => RawHeaders::first($headers, 'in-reply-to'),
            'references' => implode(' ', $headers['references'] ?? []),
            'attachments' => $attachments,
        ];
    }

    /**
     * Microsoft Graph: the JSON message (from, subject, body, receivedDateTime...) plus the raw MIME ($value), whose
     * header block carries the Message-ID / In-Reply-To / Auto-Submitted headers Graph's JSON does not expose reliably.
     *
     * @param array $msg decoded Graph message
     * @param string $rawEml full raw message
     * @param array $attachments Graph fileAttachment objects
     */
    public static function fromGraph(array $msg, string $rawEml, array $attachments): array
    {
        $headers = RawHeaders::parse($rawEml);
        $ccs = [];
        foreach (($msg['ccRecipients'] ?? []) as $cc) {
            if (!empty($cc['emailAddress']['address'])) {
                $ccs[] = $cc['emailAddress']['address'];
            }
        }
        $ts = !empty($msg['receivedDateTime']) ? strtotime($msg['receivedDateTime']) : false;
        $content = (string) ($msg['body']['content'] ?? '');
        $isHtml = ($msg['body']['contentType'] ?? 'html') === 'html';

        $atts = [];
        foreach ($attachments as $att) {
            if (($att['@odata.type'] ?? '') !== '#microsoft.graph.fileAttachment' || !isset($att['contentBytes'])) {
                continue; // item/reference attachments have no bytes
            }
            $atts[] = [
                'name' => $att['name'] ?? 'attachment',
                'content' => base64_decode((string) $att['contentBytes']),
                'mime' => $att['contentType'] ?? 'application/octet-stream',
                'content_type' => strtolower((string) ($att['contentType'] ?? 'application/octet-stream')),
                'disposition' => !empty($att['isInline']) ? 'inline' : 'attachment',
                'cid' => !empty($att['contentId']) ? (string) $att['contentId'] : null,
            ];
        }

        return [
            'from_email' => (string) ($msg['from']['emailAddress']['address'] ?? self::FALLBACK_FROM),
            'from_name' => (string) ($msg['from']['emailAddress']['name'] ?? 'Unknown'),
            'subject' => !empty($msg['subject']) ? (string) $msg['subject'] : 'No Subject',
            'ccs' => $ccs,
            'date' => date('Y-m-d H:i:s', $ts !== false ? $ts : time()),
            'html' => $isHtml ? $content : '',
            'text' => $isHtml ? trim(html_entity_decode(strip_tags($content), ENT_QUOTES)) : $content,
            'headers' => $headers,
            'message_id' => MessageId::normalize(RawHeaders::first($headers, 'message-id') ?? ($msg['internetMessageId'] ?? null)),
            'in_reply_to' => RawHeaders::first($headers, 'in-reply-to'),
            'references' => implode(' ', $headers['references'] ?? []),
            'attachments' => $atts,
        ];
    }
}
