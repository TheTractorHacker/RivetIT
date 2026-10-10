<?php

declare(strict_types=1);

namespace ITFlow\Mail;

/** Size limits for inbound attachments and inline (cid:) images. Pure. */
final class AttachmentPolicy
{
    /**
     * Split attachments into those to keep and those refused, in message order.
     *
     * @param array<int, array{name: string, content: string}> $attachments
     * @return array{kept: array, rejected: array<int, array{name: string, size: int, reason: string}>}
     */
    public static function apply(array $attachments, int $maxFileBytes, int $maxMessageBytes): array
    {
        $kept = [];
        $rejected = [];
        $total = 0;
        foreach ($attachments as $att) {
            $size = strlen((string) ($att['content'] ?? ''));
            $name = (string) ($att['name'] ?? 'attachment');
            if ($maxFileBytes > 0 && $size > $maxFileBytes) {
                $rejected[] = ['name' => $name, 'size' => $size, 'reason' => 'larger than the per-file limit of ' . self::human($maxFileBytes)];
                continue;
            }
            if ($maxMessageBytes > 0 && $total + $size > $maxMessageBytes) {
                $rejected[] = ['name' => $name, 'size' => $size, 'reason' => 'would exceed the per-message limit of ' . self::human($maxMessageBytes)];
                continue;
            }
            $total += $size;
            $kept[] = $att;
        }
        return ['kept' => $kept, 'rejected' => $rejected];
    }

    /** Whether an inline image of $size bytes may be embedded as a data: URI (otherwise it becomes an ordinary attachment). */
    public static function inlineAllowed(int $size, int $maxInlineBytes): bool
    {
        return $maxInlineBytes <= 0 || $size <= $maxInlineBytes;
    }

    /** HTML note appended to the stored message so the technician knows something was left out. */
    public static function rejectionNote(array $rejected): string
    {
        if ($rejected === []) {
            return '';
        }
        $items = '';
        foreach ($rejected as $r) {
            $items .= '<li>' . htmlspecialchars($r['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' (' . self::human((int) $r['size']) . ') - ' . htmlspecialchars($r['reason'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
        }
        return '<br><br><i>Attachments not imported:</i><ul>' . $items . '</ul>';
    }

    public static function human(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }
        return $bytes . ' B';
    }
}
