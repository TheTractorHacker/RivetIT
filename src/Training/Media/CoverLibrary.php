<?php

namespace ITFlow\Training\Media;

use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;

/**
 * The built-in cover gallery (plan A20).
 *
 * Thirty illustrated 1280x720 transparent PNGs ship as static files under /img/training/covers/
 * (thumbnails 384x216 under thumbs/). They are only the gallery: choosing one INGESTS the PNG
 * through MediaStore (content-addressed, so every course that picks "loto" shares one media row)
 * and the course or path points at that media id like any uploaded cover. Revisions, learner
 * access and the kiosk therefore treat a preset exactly like an upload.
 *
 * The art is transparent; cards draw a soft tint of course_color / tpath_color behind it. Each
 * cover has a default tint, a saturated accent stored as the course colour.
 */
final class CoverLibrary
{
    public const WEB_DIR = '/img/training/covers';

    public const CATEGORIES = ['Safety', 'PPE', 'Equipment', 'Shop Floor', 'Emergency', 'IT', 'HR & General'];

    /** Tint name => the accent colour stored in course_color (cards mix it with the surface). */
    public const TINTS = [
        'red' => '#DC2626',
        'amber' => '#D97706',
        'green' => '#16A34A',
        'teal' => '#0D9488',
        'blue' => '#2563EB',
        'indigo' => '#4F46E5',
        'slate' => '#475569',
        'brown' => '#92400E',
    ];

    /** key, label, category, default tint, search keywords */
    private const COVERS = [
        ['loto', 'Lockout/Tagout', 'Safety', 'red', ['lockout', 'tagout', 'loto', 'padlock', 'lock', 'tag', 'disconnect', 'energy control', 'isolation', 'hasp']],
        ['hazcom', 'Hazard Communication', 'Safety', 'teal', ['hazcom', 'chemical', 'chemicals', 'sds', 'label', 'warning label', 'right to know', 'hazardous materials', 'ghs']],
        ['fall-protection', 'Fall Protection', 'Safety', 'blue', ['fall protection', 'harness', 'lanyard', 'anchor', 'working at height', 'tie-off', 'd-ring']],
        ['electrical', 'Electrical Safety', 'Safety', 'amber', ['electrical', 'panel', 'breaker', 'shock', 'arc flash', 'high voltage', 'qualified worker']],
        ['machine-guarding', 'Machine Guarding', 'Safety', 'slate', ['machine guard', 'gears', 'pinch point', 'rotating parts', 'point of operation', 'guarding']],
        ['confined-space', 'Confined Space Entry', 'Safety', 'brown', ['confined space', 'permit-required', 'manhole', 'tank entry', 'gas monitor', 'atmospheric testing']],
        ['lifting', 'Safe Lifting', 'Safety', 'blue', ['lifting', 'manual handling', 'back safety', 'ergonomics', 'carrying', 'box']],
        ['housekeeping', 'Shop Housekeeping', 'Safety', 'green', ['housekeeping', '5s', 'clean up', 'sweeping', 'broom', 'slips trips falls', 'tidy work area']],
        ['heat-stress', 'Heat Stress', 'Safety', 'red', ['heat illness', 'heat exhaustion', 'heat stroke', 'hydration', 'hot weather', 'water', 'rest', 'shade']],
        ['ppe', 'Personal Protective Equipment', 'PPE', 'blue', ['ppe', 'hard hat', 'safety glasses', 'eye protection', 'gloves', 'head protection']],
        ['hearing', 'Hearing Conservation', 'PPE', 'blue', ['hearing protection', 'ear muffs', 'noise', 'decibels', 'hearing loss']],
        ['respirator', 'Respiratory Protection', 'PPE', 'teal', ['respirator', 'half mask', 'cartridge', 'filter', 'fit test', 'fumes', 'dust']],
        ['forklift', 'Forklift Operation', 'Equipment', 'green', ['forklift', 'powered industrial truck', 'pit', 'pallet', 'material handling', 'lift truck']],
        ['overhead-crane', 'Overhead Crane & Rigging', 'Equipment', 'slate', ['overhead crane', 'crane', 'hoist', 'rigging', 'chain sling', 'hook', 'load']],
        ['ladder', 'Ladder Safety', 'Equipment', 'blue', ['ladder', 'step ladder', 'a-frame', 'three points of contact', 'ladder inspection']],
        ['hand-tools', 'Hand & Power Tools', 'Equipment', 'indigo', ['hand tools', 'power tools', 'wrench', 'drill', 'tool inspection', 'tool safety']],
        ['vehicle', 'Vehicle Safety', 'Equipment', 'teal', ['vehicle', 'driving', 'pickup truck', 'company vehicle', 'fleet', 'defensive driving', 'dot', 'seat belt']],
        ['shipping', 'Shipping & Receiving', 'Equipment', 'brown', ['shipping', 'receiving', 'warehouse', 'pallet jack', 'boxes', 'loading dock']],
        ['welding', 'Welding Safety', 'Shop Floor', 'brown', ['welding', 'mig', 'welding helmet', 'torch', 'sparks', 'hot work', 'fabrication', 'arc']],
        ['cnc', 'CNC Machining', 'Shop Floor', 'indigo', ['cnc', 'milling', 'mill', 'spindle', 'end mill', 'machining', 'chips', 'machine shop']],
        ['quality', 'Quality & Inspection', 'Shop Floor', 'blue', ['quality', 'inspection', 'measurement', 'caliper', 'tolerance', 'qc', 'iso', 'first article']],
        ['fire-extinguisher', 'Fire Safety', 'Emergency', 'amber', ['fire', 'extinguisher', 'fire extinguisher', 'pass', 'flame', 'fire prevention']],
        ['emergency-exit', 'Emergency Evacuation', 'Emergency', 'green', ['emergency exit', 'evacuation', 'egress', 'exit route', 'fire drill', 'muster point']],
        ['first-aid', 'First Aid', 'Emergency', 'green', ['first aid', 'first-aid kit', 'medical', 'injury', 'bandage', 'cpr', 'bloodborne']],
        ['spill', 'Spill Response', 'Emergency', 'teal', ['spill', 'chemical spill', 'drum', 'leak', 'containment', 'absorbent', 'spill kit', 'cleanup']],
        ['cybersecurity', 'Cybersecurity Awareness', 'IT', 'indigo', ['cybersecurity', 'security awareness', 'phishing', 'passwords', 'mfa', 'laptop']],
        ['it-basics', 'IT Basics', 'IT', 'teal', ['it basics', 'computer', 'laptop', 'cloud', 'onedrive', 'file sync', 'backup', 'files']],
        ['orientation', 'New Hire Orientation', 'HR & General', 'green', ['orientation', 'onboarding', 'new hire', 'id badge', 'first day', 'checklist']],
        ['policy', 'Policies & Procedures', 'HR & General', 'indigo', ['policy', 'procedures', 'handbook', 'acknowledgment', 'sop', 'sign-off', 'document']],
        ['general', 'General Training', 'HR & General', 'slate', ['general', 'training', 'learning', 'skills', 'course', 'development']],
    ];

    /** Default cover per template key (TemplateCatalog), and per course kind when no template is used. */
    private const TEMPLATE_COVERS = [
        'required_document' => 'policy',
        'toolbox_talk' => 'ppe',
        'safety_course_quiz' => 'general',
        'equipment_certification' => 'hand-tools',
        'annual_refresher' => 'general',
        'hazcom' => 'hazcom',
        'loto' => 'loto',
        'ppe' => 'ppe',
        'fire_extinguisher' => 'fire-extinguisher',
        'forklift' => 'forklift',
        'overhead_crane' => 'overhead-crane',
    ];
    private const KIND_COVERS = ['document' => 'policy', 'training' => 'general'];

    /** The cover row for $key, or null. */
    public static function get(string $key): ?array
    {
        foreach (self::COVERS as [$k, $label, $category, $tint, $keywords]) {
            if ($k === $key) {
                return ['key' => $k, 'label' => $label, 'category' => $category, 'tint' => $tint,
                        'color' => self::TINTS[$tint], 'keywords' => $keywords];
            }
        }
        return null;
    }

    public static function defaultFor(string $kind, ?string $templateKey): string
    {
        if ($templateKey !== null && isset(self::TEMPLATE_COVERS[$templateKey])) {
            return self::TEMPLATE_COVERS[$templateKey];
        }
        return self::KIND_COVERS[$kind] ?? 'general';
    }

    /**
     * The gallery as the picker needs it (the cover_presets action): the 30 built-in illustrations plus every cover an
     * author has shared (addCustom()). A shared cover is already a training_media row - the picker applies it the same
     * way it applies an upload ({media_id, url}, no re-ingest), so it carries 'media_id' and a 'custom:N' key instead of
     * a built-in one; everything else (label/category/tint/thumb_url/image_url) has the identical shape either way.
     */
    public static function api(Ctx $c): array
    {
        $covers = [];
        foreach (self::COVERS as [$k]) {
            $cov = self::get($k);
            $cov['thumb_url'] = self::webUrl($k, true);
            $cov['image_url'] = self::webUrl($k, false);
            $covers[] = $cov;
        }
        foreach (self::customCovers($c) as $cov) {
            $covers[] = $cov;
        }
        $tints = [];
        foreach (self::TINTS as $name => $hex) {
            $tints[] = ['name' => $name, 'color' => $hex];
        }
        $categories = self::CATEGORIES;
        foreach (self::customCategories($c) as $cat) {
            if (!in_array($cat, $categories, true)) {
                $categories[] = $cat;
            }
        }
        $defaults = self::TEMPLATE_COVERS;
        foreach (self::KIND_COVERS as $kind => $key) {
            $defaults['kind:' . $kind] = $key;
        }
        return ['categories' => $categories, 'tints' => $tints, 'covers' => $covers, 'defaults' => $defaults];
    }

    /** Covers an author shared to the gallery from their own upload - same row shape as a built-in cover, plus media_id. */
    public static function customCovers(Ctx $c): array
    {
        $rows = Db::all($c->db, 'SELECT g.gallery_id, g.gallery_media_id, g.gallery_label, g.gallery_category, g.gallery_tint, m.media_path
            FROM training_cover_gallery g JOIN training_media m ON m.media_id = g.gallery_media_id ORDER BY g.gallery_created_at DESC');
        $out = [];
        foreach ($rows as $r) {
            $tintName = isset(self::TINTS[(string) $r['gallery_tint']]) ? (string) $r['gallery_tint'] : 'slate';
            $out[] = [
                'key' => 'custom:' . (int) $r['gallery_id'], 'label' => (string) $r['gallery_label'], 'category' => (string) $r['gallery_category'],
                'tint' => $tintName, 'color' => self::TINTS[$tintName], 'keywords' => [],
                'media_id' => (int) $r['gallery_media_id'], 'thumb_url' => MediaStore::url((int) $r['gallery_media_id']),
                'image_url' => MediaStore::url((int) $r['gallery_media_id']),
            ];
        }
        return $out;
    }

    /** @return list<string> the distinct categories shared covers have used, beyond the 7 built-in ones - so the picker's category filter still finds them. */
    private static function customCategories(Ctx $c): array
    {
        $rows = Db::all($c->db, 'SELECT DISTINCT gallery_category FROM training_cover_gallery ORDER BY gallery_category');
        return array_map(static fn(array $r): string => (string) $r['gallery_category'], $rows);
    }

    /**
     * Shares an already-uploaded cover (a training_media row an author just uploaded for their own course) to the
     * gallery, so it becomes a pickable option for every other course too. Re-sharing the same media_id updates its
     * label/category/tint rather than creating a duplicate entry - the UNIQUE key on gallery_media_id is what a second
     * call races against, not application logic, so this stays correct under concurrent requests.
     *
     * @throws \InvalidArgumentException the media row does not exist, or is not an image (a cover must be one)
     */
    public static function addCustom(Ctx $c, int $mediaId, string $label, string $category, string $tint): array
    {
        $media = Db::one($c->db, 'SELECT media_id, media_kind, media_uploaded_by FROM training_media WHERE media_id = ?', 'i', [$mediaId]);
        if ($media === null || (string) $media['media_kind'] !== 'image') {
            throw new \InvalidArgumentException('CoverLibrary: that upload is not an image.');
        }
        // IDOR guard: a media_id is a bare integer an author never sees for anything but their own upload's reply -
        // without this, any course author could pull an arbitrary media row (another course's cover, a KB image, an
        // evidence photo) into the gallery, where it is visible to every course author in the company. Only the
        // person who uploaded it, or an admin, may publish it.
        if ((int) $media['media_uploaded_by'] !== $c->userId && !$c->isAdmin) {
            throw new \InvalidArgumentException('CoverLibrary: you can only share a cover you uploaded yourself.');
        }
        $tintName = isset(self::TINTS[$tint]) ? $tint : 'slate';
        $label = trim($label) !== '' ? mb_substr(trim($label), 0, 100) : 'Untitled cover';
        $category = trim($category) !== '' ? mb_substr(trim($category), 0, 50) : 'Custom';
        // Same guard against overwriting someone ELSE's existing gallery entry for this media_id: only its original
        // sharer, or an admin, may update it (re-sharing your own upload to fix a typo is fine; relabeling another
        // author's shared cover out from under them is not).
        $existing = Db::one($c->db, 'SELECT gallery_id, gallery_added_by FROM training_cover_gallery WHERE gallery_media_id = ?', 'i', [$mediaId]);
        if ($existing !== null && (int) $existing['gallery_added_by'] !== $c->userId && !$c->isAdmin) {
            throw new \InvalidArgumentException('CoverLibrary: this cover was already shared by someone else.');
        }
        Db::exec($c->db, 'INSERT INTO training_cover_gallery (gallery_media_id, gallery_label, gallery_category, gallery_tint, gallery_added_by, gallery_created_at)
            VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE gallery_label = VALUES(gallery_label), gallery_category = VALUES(gallery_category), gallery_tint = VALUES(gallery_tint)',
            'issis', [$mediaId, $label, $category, $tintName, $c->userId]);
        $row = Db::one($c->db, 'SELECT gallery_id FROM training_cover_gallery WHERE gallery_media_id = ?', 'i', [$mediaId]);
        $galleryId = (int) ($row['gallery_id'] ?? 0);
        return ['key' => 'custom:' . $galleryId, 'label' => $label, 'category' => $category, 'tint' => $tintName, 'color' => self::TINTS[$tintName],
            'keywords' => [], 'media_id' => $mediaId, 'thumb_url' => MediaStore::url($mediaId), 'image_url' => MediaStore::url($mediaId)];
    }

    /**
     * Ingests the preset's PNG (deduplicated by sha256) and returns the media row. Must run
     * outside a transaction, like every MediaStore ingest.
     *
     * @throws \InvalidArgumentException unknown key; MediaException budget_exceeded / busy
     */
    public static function ingest(Ctx $c, string $key): array
    {
        if (self::get($key) === null) {
            throw new \InvalidArgumentException('CoverLibrary: unknown cover');
        }
        $path = self::filePath($key);
        $size = is_file($path) && is_readable($path) ? getimagesize($path) : false;
        if ($size === false || ($size[2] ?? null) !== IMAGETYPE_PNG) {
            throw new \RuntimeException('CoverLibrary: the cover file is missing or not a PNG: ' . $key);
        }
        return (new MediaStore($c))->ingestFile($path, 'image', 'image/png', 'png', $key . '.png',
            ['width' => (int) $size[0], 'height' => (int) $size[1]], 'cover_preset');
    }

    public static function filePath(string $key, bool $thumb = false): string
    {
        return dirname(__DIR__, 3) . self::WEB_DIR . ($thumb ? '/thumbs/' : '/') . $key . '.png';
    }

    private static function webUrl(string $key, bool $thumb): string
    {
        $file = self::filePath($key, $thumb);
        $mtime = is_file($file) ? filemtime($file) : false;
        return self::WEB_DIR . ($thumb ? '/thumbs/' : '/') . rawurlencode($key) . '.png' . ($mtime ? '?v=' . $mtime : '');
    }
}
