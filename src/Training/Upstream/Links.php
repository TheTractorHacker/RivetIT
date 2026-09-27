<?php

namespace ITFlow\Training\Upstream;

/**
 * Phase 1/2 page URLs Phase 5 links to, in one place (spec §3.1). Relative to the web root; the
 * notification bell and the pages use them as-is.
 */
final class Links
{
    public static function overview(): string
    {
        return '/agent/training_dashboard.php';
    }

    public static function overdue(?int $clientId = null): string
    {
        return '/agent/training_reports.php?tab=overdue' . ($clientId !== null && $clientId > 0 ? '&client_id=' . $clientId : '');
    }

    public static function transcript(int $contactId): string
    {
        return '/agent/training_transcript.php?contact_id=' . max(0, $contactId);
    }

    public static function record(int $completionId): string
    {
        return '/agent/training_record.php?id=' . max(0, $completionId);
    }

    public static function certificate(int $completionId): string
    {
        return '/agent/training_certificate.php?id=' . max(0, $completionId);
    }

    public static function courseBuilder(int $courseId): string
    {
        return '/agent/training_course.php?course_id=' . max(0, $courseId);
    }

    public static function courseAnalytics(?int $courseId = null): string
    {
        return '/agent/training_reports.php?tab=course' . ($courseId !== null && $courseId > 0 ? '&course_id=' . $courseId : '');
    }

    /** The Training settings page and one of its section anchors (admin or Training 3 page). */
    public static function settings(bool $admin, string $anchor = ''): string
    {
        $anchor = preg_match('/^[a-z0-9-]{1,40}$/D', $anchor) === 1 ? '#' . $anchor : '';
        return ($admin ? '/admin/settings_training.php' : '/agent/training_settings.php') . $anchor;
    }
}
