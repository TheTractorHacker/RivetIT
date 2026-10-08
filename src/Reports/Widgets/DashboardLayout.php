<?php

namespace ITFlow\Reports\Widgets;

/**
 * A user's "My dashboard" layout (dashboard_layouts.layout_widgets): an ordered list of
 * ['id' => widget id, 'size' => sm|md|lg, 'hidden' => bool]. Everything read from or written to the table passes
 * through normalize(), so unknown widgets, duplicate ids, bad sizes and widgets the user may not see are dropped.
 */
final class DashboardLayout
{
    public const MAX_WIDGETS = 30;

    /** @param array $raw decoded JSON (or posted data) @param string[] $allowedIds widget ids this user may use */
    public static function normalize($raw, array $allowedIds): array
    {
        $out = [];
        $seen = [];
        if (!is_array($raw)) {
            return [];
        }
        foreach ($raw as $e) {
            if (!is_array($e) || !isset($e['id']) || !is_string($e['id'])) {
                continue;
            }
            $id = $e['id'];
            if (isset($seen[$id]) || !in_array($id, $allowedIds, true) || !WidgetRegistry::exists($id)) {
                continue;
            }
            $size = (isset($e['size']) && is_string($e['size']) && isset(WidgetRegistry::SIZES[$e['size']])) ? $e['size'] : WidgetRegistry::definitions()[$id]['size'];
            $out[] = ['id' => $id, 'size' => $size, 'hidden' => !empty($e['hidden'])];
            $seen[$id] = true;
            if (count($out) >= self::MAX_WIDGETS) {
                break;
            }
        }
        return $out;
    }

    public static function defaultLayout(array $allowedIds): array
    {
        return self::normalize(array_map(static fn ($id) => ['id' => $id], $allowedIds), $allowedIds);
    }

    /** Stored layout for a user (normalized against what they may see), or the default when they have none. */
    public static function load(\mysqli $db, int $userId, array $allowedIds): array
    {
        $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT layout_widgets FROM dashboard_layouts WHERE layout_user_id = $userId"));
        if (!$row || $row['layout_widgets'] === null) {
            return self::defaultLayout($allowedIds);
        }
        return self::normalize(json_decode($row['layout_widgets'], true), $allowedIds);
    }

    public static function save(\mysqli $db, int $userId, array $layout, array $allowedIds): array
    {
        $clean = self::normalize($layout, $allowedIds);
        $json = json_encode($clean, JSON_UNESCAPED_SLASHES);
        $stmt = mysqli_prepare($db, "INSERT INTO dashboard_layouts (layout_user_id, layout_widgets, layout_updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE layout_widgets = VALUES(layout_widgets), layout_updated_at = NOW()");
        mysqli_stmt_bind_param($stmt, 'is', $userId, $json);
        mysqli_stmt_execute($stmt);
        return $clean;
    }

    /**
     * Apply one customisation step to a layout and return the new layout (the caller saves it).
     * Actions: add, remove, up, down, size, toggle, reset.
     */
    public static function apply(array $layout, string $action, string $id, string $size, array $allowedIds): array
    {
        $idx = null;
        foreach ($layout as $i => $e) {
            if ($e['id'] === $id) { $idx = $i; }
        }
        switch ($action) {
            case 'add':
                if ($idx === null && in_array($id, $allowedIds, true)) {
                    $layout[] = ['id' => $id, 'size' => WidgetRegistry::definitions()[$id]['size'] ?? 'md', 'hidden' => false];
                } elseif ($idx !== null) {
                    $layout[$idx]['hidden'] = false;
                }
                break;
            case 'remove':
                if ($idx !== null) { array_splice($layout, $idx, 1); }
                break;
            case 'up':
                if ($idx !== null && $idx > 0) { [$layout[$idx - 1], $layout[$idx]] = [$layout[$idx], $layout[$idx - 1]]; }
                break;
            case 'down':
                if ($idx !== null && $idx < count($layout) - 1) { [$layout[$idx + 1], $layout[$idx]] = [$layout[$idx], $layout[$idx + 1]]; }
                break;
            case 'size':
                if ($idx !== null && isset(WidgetRegistry::SIZES[$size])) { $layout[$idx]['size'] = $size; }
                break;
            case 'toggle':
                if ($idx !== null) { $layout[$idx]['hidden'] = !$layout[$idx]['hidden']; }
                break;
            case 'reset':
                return self::defaultLayout($allowedIds);
        }
        return array_values($layout);
    }
}
