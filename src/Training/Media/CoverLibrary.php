<?php

namespace ITFlow\Training\Media;

use ITFlow\Training\Core\Ctx;

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

    /** The gallery as the picker needs it (the cover_presets action). */
    public static function api(): array
    {
        $covers = [];
        foreach (self::COVERS as [$k]) {
            $c = self::get($k);
            $c['thumb_url'] = self::webUrl($k, true);
            $c['image_url'] = self::webUrl($k, false);
            $covers[] = $c;
        }
        $tints = [];
        foreach (self::TINTS as $name => $hex) {
            $tints[] = ['name' => $name, 'color' => $hex];
        }
        $defaults = self::TEMPLATE_COVERS;
        foreach (self::KIND_COVERS as $kind => $key) {
            $defaults['kind:' . $kind] = $key;
        }
        return ['categories' => self::CATEGORIES, 'tints' => $tints, 'covers' => $covers, 'defaults' => $defaults];
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
