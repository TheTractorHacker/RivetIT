<?php
declare(strict_types=1);

namespace ITFlow\Onboarding;

/**
 * Stores a copy of an onboarding packet as a ticket attachment, by the same rules agent/post/ticket.php's
 * upload_ticket_attachment uses: the ticketAttachmentAllowedExtensions() allow-list, checkFileUpload()'s 500 MB
 * ceiling, uploads/tickets/<ticket id>/ created with mkdirMissing(), a random reference name that passes
 * isUploadReferenceName(), default file mode (0666 & ~umask, the mode move_uploaded_file() gives an upload) and a
 * ticket_attachments row with ticket_attachment_reply_id NULL.
 *
 * The bytes are the server's own render, not an upload, so there is no tmp file for checkFileUpload() or
 * move_uploaded_file(); those checks are applied here instead. The reference name is random_bytes(), never derived
 * from user input or from the content (checkFileUpload() names files after an md5 of the content, which would
 * put a hash of the packet in the URL).
 *
 * store() must run inside the caller's open transaction (PacketSaver::save's $inTransaction). It removes its own
 * file if it fails; $writtenPath tells the caller which file to remove if the transaction later rolls back.
 */
final class PacketTicketAttachment
{
    /** Same ceiling checkFileUpload() applies to uploads. */
    public const MAX_BYTES = 500 * 1024 * 1024;

    /** "Onboarding-packet-<person slug>-<Y-m-d>.pdf": only [A-Za-z0-9-] from the name, so safe to show anywhere. */
    public static function displayName(string $person, ?int $time = null): string
    {
        $slug = trim(substr(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $person), '-'), 0, 100), '-');
        return 'Onboarding-packet-' . ($slug !== '' ? $slug : 'person') . '-' . date('Y-m-d', $time ?? time()) . '.pdf';
    }

    /** 32 random hex characters + ".pdf". */
    public static function referenceName(): string
    {
        return bin2hex(random_bytes(16)) . '.pdf';
    }

    /**
     * @param string      $uploadsRoot  the app's uploads/ directory
     * @param string|null $writtenPath  set to the stored file's full path once it exists on disk (null otherwise)
     * @return int the new ticket_attachment_id
     */
    public static function store(\mysqli $db, string $uploadsRoot, int $ticketId, string $displayName, string $bytes, ?string &$writtenPath = null): int
    {
        $writtenPath = null;
        if ($ticketId <= 0) {
            throw new \InvalidArgumentException('Invalid ticket.');
        }
        if (!in_array('pdf', \ticketAttachmentAllowedExtensions(), true)) {
            throw new \RuntimeException('PDF is not an allowed ticket attachment type.');
        }
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new \RuntimeException('Attachment size out of range.');
        }
        $ref = self::referenceName();
        if (!\isUploadReferenceName($ref)) {
            throw new \RuntimeException('Could not name the attachment.');
        }

        $base = rtrim($uploadsRoot, '/') . '/tickets/';
        \mkdirMissing($base);
        $dir = $base . $ticketId . '/';
        \mkdirMissing($dir);
        if (!is_dir($dir)) {
            throw new \RuntimeException('Could not create the ticket attachment folder.');
        }

        $path = $dir . $ref;
        $fh = @fopen($path, 'xb'); // 'x': never overwrite an existing file
        if ($fh === false) {
            throw new \RuntimeException('Could not create the attachment file.');
        }
        $writtenPath = $path;
        try {
            $ok = fwrite($fh, $bytes) === strlen($bytes);
            $ok = fclose($fh) && $ok;
            if (!$ok) {
                throw new \RuntimeException('Could not write the attachment file.');
            }
            $st = $db->prepare('INSERT INTO ticket_attachments SET ticket_attachment_name = ?, ticket_attachment_reference_name = ?, ticket_attachment_reply_id = NULL, ticket_attachment_ticket_id = ?');
            if (!$st) {
                throw new \RuntimeException('Could not record the attachment.');
            }
            $st->bind_param('ssi', $displayName, $ref, $ticketId);
            if (!$st->execute()) {
                throw new \RuntimeException('Could not record the attachment.');
            }
            $id = (int) $db->insert_id;
            $st->close();
            return $id;
        } catch (\Throwable $e) {
            if (is_resource($fh)) {
                @fclose($fh);
            }
            @unlink($path);
            $writtenPath = null;
            throw $e;
        }
    }
}
