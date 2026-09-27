<?php

namespace ITFlow\Training\Certificates;

use ITFlow\Training\Automation\AutomationSettings;
use ITFlow\Training\Core\Db;

/**
 * POST side of the Certificates section of Training settings (Phase 5 spec §4.3, §5.4; one settings
 * page since v1.12.1: admin/settings_training.php and its Training-level-3 twin agent/training_settings.php).
 *
 * The caller has already checked the CSRF token. handle() reacts only to its own keys and returns
 * null for any other post, or [type, message] for flash_alert:
 *
 *   ta_cert_save              signer_name <= 200, signer_title <= 200, verify_enabled (0|1, admin only), version
 *   ta_cert_signature         multipart 'cert_signature': PNG or JPEG <= 2 MB, <= 2400 x 1200 px (checked with
 *                             getimagesize BEFORE decoding), GD re-encode to PNG with alpha scaled to <= 1200 x 400,
 *                             result <= 200 KB, stored base64 in tauto_cert_signer_png (never training_media); version
 *   ta_cert_signature_clear   removes the signature; version
 *
 * Who may change what:
 *   signatory name, title and signature   an administrator or Training level 3
 *   the public certificate check switch   administrators, on Admin > Training only. On the agent page it is
 *                                         read-only, and a post that carries verify_enabled is refused for
 *                                         everyone, admins included (the v1.13.0 agent-page rule).
 * $adminPage: true = admin/post.php (admins only); false = agent/training_settings.php; null = unknown (the
 * caller did not say): then the user's role decides whether verify_enabled may be sent.
 *
 * Saves go through AutomationSettings::save('cert', ..., $expectVersion) (optimistic lock: a stale form is
 * "changed by someone else; reload"). After a save: logAction + AuditService 'training.automation_saved'.
 * The signature image itself is never logged.
 */
final class CertAdmin
{
    public const KEYS = ['ta_cert_save', 'ta_cert_signature', 'ta_cert_signature_clear'];
    public const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;
    public const MAX_IN_W = 2400;
    public const MAX_IN_H = 1200;
    public const MAX_OUT_W = 1200;
    public const MAX_OUT_H = 400;
    public const MAX_OUT_BYTES = 200 * 1024;

    /** @return array{0:string,1:string}|null */
    public static function handle(\mysqli $db, array $post, array $files, int $userId, ?bool $adminPage = null): ?array
    {
        $action = null;
        foreach (self::KEYS as $k) {
            if (isset($post[$k])) {
                $action = $k;
                break;
            }
        }
        if ($action === null) {
            return null;
        }
        if (!class_exists(AutomationSettings::class)) {
            return ['error', 'Run the database update first: Training automation is not installed yet.'];
        }
        $who = self::who($db, $userId);
        if ($who === null || (!$who['is_admin'] && $who['level'] < 3)) {
            return ['error', 'Nothing was saved. Only administrators and people with full Training access can change certificate settings.'];
        }
        $current = AutomationSettings::load($db);
        if (empty($current['ready'])) {
            return ['error', 'Run the database update first: Training automation is not installed yet.'];
        }
        $version = self::intOrNull($post['version'] ?? null);
        if ($version === null) {
            return ['error', 'Nothing was saved: the form was incomplete. Reload the page and try again.'];
        }

        try {
            switch ($action) {
                case 'ta_cert_save':
                    $values = [];
                    foreach (['signer_name' => 'tauto_cert_signer_name', 'signer_title' => 'tauto_cert_signer_title'] as $in => $col) {
                        $v = self::text($post[$in] ?? '', 200);
                        if ($v === false) {
                            return ['error', 'Nothing was saved: the signer ' . ($in === 'signer_name' ? 'name' : 'title') . ' must be text of at most 200 characters.'];
                        }
                        $values[$col] = $v;
                    }
                    if (array_key_exists('verify_enabled', $post)) {
                        $mayVerify = $adminPage === true || ($adminPage === null && $who['is_admin']);
                        if (!$mayVerify) {
                            return ['error', $who['is_admin']
                                ? 'Nothing was saved. Admin only: the public certificate check switch. Change it in Admin › Training.'
                                : 'Nothing was saved. Admin only: the public certificate check switch. Ask an administrator.'];
                        }
                        $values['tauto_verify_enabled'] = ((string) $post['verify_enabled'] === '1') ? 1 : 0;
                    }
                    AutomationSettings::save($db, 'cert', $values, $version, $userId);
                    $changed = [];
                    foreach ($values as $col => $v) {
                        if ((string) ($current[$col] ?? '') !== (string) ($v ?? '')) {
                            $changed[] = $col;
                        }
                    }
                    self::log($who['name'], $userId, 'edited the certificate settings', ['changed' => $changed]);
                    if (in_array('tauto_verify_enabled', $changed, true)) {
                        return ['success', ($values['tauto_verify_enabled'] ?? 1) === 1
                            ? 'Certificate settings saved. The public certificate check is on.'
                            : 'Certificate settings saved. The public certificate check is off: printed QR codes now show "Not available".'];
                    }
                    return ['success', 'Certificate settings saved.'];

                case 'ta_cert_signature':
                    $png = self::signatureFromUpload($files['cert_signature'] ?? null);
                    if (is_array($png)) {
                        return $png;   // [error, message]
                    }
                    AutomationSettings::save($db, 'cert', ['tauto_cert_signer_png' => base64_encode($png)], $version, $userId);
                    self::log($who['name'], $userId, 'uploaded a certificate signature', ['bytes' => strlen($png)]);
                    return ['success', 'Signature saved. It prints on new certificate PDFs.'];

                case 'ta_cert_signature_clear':
                    AutomationSettings::save($db, 'cert', ['tauto_cert_signer_png' => null], $version, $userId);
                    self::log($who['name'], $userId, 'removed the certificate signature', []);
                    return ['success', 'Signature removed. Certificates print a blank signature line.'];
            }
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'conflict') {
                return ['error', 'Changed by someone else; reload the page and try again.'];
            }
            error_log('Training CertAdmin: ' . get_class($e) . ': ' . $e->getMessage());
            return ['error', 'Nothing was saved: the certificate settings could not be stored. Try again.'];
        } catch (\Throwable $e) {
            error_log('Training CertAdmin: ' . get_class($e) . ': ' . $e->getMessage());
            return ['error', 'Nothing was saved: the certificate settings could not be stored. Try again.'];
        }
        return null;
    }

    /**
     * The uploaded signature, re-encoded: PNG bytes, or [error, message]. Dimensions are read from the
     * header (getimagesize) before GD decodes anything, so a decompression bomb is refused unread.
     *
     * @return string|array{0:string,1:string}
     */
    public static function signatureFromUpload(mixed $f): string|array
    {
        if (!is_array($f) || !isset($f['error'], $f['tmp_name'], $f['size']) || is_array($f['error'])) {
            return ['error', 'Choose a PNG or JPEG image of the signature.'];
        }
        $err = (int) $f['error'];
        if ($err === UPLOAD_ERR_NO_FILE) {
            return ['error', 'Choose a PNG or JPEG image of the signature.'];
        }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || (int) $f['size'] > self::MAX_UPLOAD_BYTES) {
            return ['error', 'That image is too large. Use a PNG or JPEG of at most 2 MB.'];
        }
        if ($err !== UPLOAD_ERR_OK || !is_string($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
            return ['error', 'The upload did not arrive. Try again.'];
        }
        $size = filesize($f['tmp_name']);
        if ($size === false || $size < 1 || $size > self::MAX_UPLOAD_BYTES) {
            return ['error', 'That image is too large. Use a PNG or JPEG of at most 2 MB.'];
        }
        return self::reencode((string) file_get_contents($f['tmp_name']));
    }

    /** @return string|array{0:string,1:string} PNG bytes or [error, message] (also used by tests). */
    public static function reencode(string $bytes): string|array
    {
        if (strlen($bytes) > self::MAX_UPLOAD_BYTES) {
            return ['error', 'That image is too large. Use a PNG or JPEG of at most 2 MB.'];
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($info[2] ?? 0, [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            return ['error', 'Use a PNG or JPEG image of the signature.'];
        }
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        if ($w < 1 || $h < 1 || $w > self::MAX_IN_W || $h > self::MAX_IN_H) {
            return ['error', 'That image is ' . $w . ' × ' . $h . ' pixels. Use one of at most ' . self::MAX_IN_W . ' × ' . self::MAX_IN_H . '.'];
        }
        if (!function_exists('imagecreatefromstring')) {
            return ['error', 'Images cannot be processed on this server (the PHP GD extension is missing).'];
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return ['error', 'That image could not be read. Save it again as PNG or JPEG.'];
        }
        $scale = min(1.0, self::MAX_OUT_W / $w, self::MAX_OUT_H / $h);
        $png = null;
        foreach ([$scale, $scale * 0.75, $scale * 0.5] as $s) {
            $nw = max(1, (int) round($w * $s));
            $nh = max(1, (int) round($h * $s));
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start();
            imagepng($dst, null, 9);
            $png = (string) ob_get_clean();
            imagedestroy($dst);
            if (strlen($png) <= self::MAX_OUT_BYTES) {
                break;
            }
        }
        imagedestroy($src);
        if ($png === null || $png === '' || strlen($png) > self::MAX_OUT_BYTES) {
            return ['error', 'That signature image is too detailed to store. Crop it closer or use a simpler scan.'];
        }
        return $png;
    }

    /** {name, is_admin, level} of an active agent, or null. Reads the roles tables fresh (never the session). */
    private static function who(\mysqli $db, int $userId): ?array
    {
        if ($userId < 1) {
            return null;
        }
        $r = Db::one($db, "SELECT u.user_name, r.role_is_admin,
                (SELECT p.user_role_permission_level FROM user_role_permissions p JOIN modules m ON m.module_id = p.module_id
                  WHERE p.user_role_id = u.user_role_id AND m.module_name = 'module_training' LIMIT 1) AS lvl
            FROM users u JOIN user_roles r ON r.role_id = u.user_role_id
            WHERE u.user_id = ? AND u.user_status = 1 AND u.user_archived_at IS NULL", 'i', [$userId]);
        if ($r === null) {
            return null;
        }
        $admin = (int) $r['role_is_admin'] === 1;
        return ['name' => (string) $r['user_name'], 'is_admin' => $admin, 'level' => $admin ? 3 : max(0, min(3, (int) ($r['lvl'] ?? 0)))];
    }

    /** Trimmed text or null when empty; false when not valid text or longer than $max characters. */
    private static function text(mixed $v, int $max): string|null|false
    {
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $v) === 1) {
            return false;
        }
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        return mb_strlen($v, 'UTF-8') > $max ? false : $v;
    }

    private static function intOrNull(mixed $v): ?int
    {
        return (is_string($v) || is_int($v)) && preg_match('/^[0-9]{1,10}$/D', (string) $v) === 1 ? (int) $v : null;
    }

    private static function log(string $name, int $userId, string $what, array $meta): void
    {
        try {
            if (function_exists('logAction')) {
                \logAction('Training', 'Edit', $name . ' ' . $what);
            }
            if (class_exists(\ITFlow\Audit\AuditService::class)) {
                \ITFlow\Audit\AuditService::record('training.automation_saved', $userId, 'training_automation', 1, 'cert', $name . ' ' . $what, $meta);
            }
        } catch (\Throwable $e) {
            error_log('Training CertAdmin log: ' . get_class($e));
        }
    }
}
