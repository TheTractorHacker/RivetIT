<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivetCore\Compliance\Assessment;
use RivetCore\Compliance\SharedReport;

/**
 * client/compliance.php shows the ONE report an administrator published to every portal user. That is intended for RivetIT
 * (internal IT: portal users are the organisation's own staff, a single installation-wide subject), as long as the view is
 * the reduced one: titles, states and results only. This pins what SharedReport::view() lets through.
 */
final class SharedReportViewTest extends TestCase
{
    public function testViewKeepsOnlyTheReducedFields(): void
    {
        $secret = 'SENSITIVE-DETAIL-Acme-Corp-admin@acme.test';
        $a = new Assessment(
            new DateTimeImmutable('2026-10-06 12:00:00'),
            [['title' => 'MFA enforced', 'category' => 'Access', 'status' => 'pass', 'status_label' => 'Pass', 'controls' => ['iso27001' => 'A.9'], 'detail' => $secret, 'count' => 7, 'accounts' => [$secret], 'link' => '/admin/users.php']],
            [['title' => 'Access review', 'category' => 'Access', 'state' => 'current', 'reviewed_on' => '2026-09-01', 'controls' => ['hipaa' => 'x'], 'reviewer_name' => $secret, 'note' => $secret, 'link' => '/admin/compliance_status.php']],
            ['all' => ['score' => 80.0, 'detail' => $secret]]
        );
        $view = SharedReport::view($a);

        $this->assertSame(['scores', 'manual', 'automatic'], array_keys($view));
        $this->assertSame(['title', 'category', 'status', 'status_label', 'responsible', 'frameworks'], array_keys($view['automatic'][0]));
        $this->assertSame(['title', 'category', 'state', 'reviewed_on', 'responsible', 'frameworks'], array_keys($view['manual'][0]));
        $this->assertStringNotContainsString('SENSITIVE', json_encode($view, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('/admin/', json_encode($view, JSON_THROW_ON_ERROR));
    }

    public function testPortalPageReadsOnlyTheReducedPublishedView(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/client/compliance.php');
        $this->assertStringContainsString('ComplianceService::shared($mysqli)->current()', $src, 'the portal page must read the published report');
        $this->assertStringContainsString("\$view = \$shared['view'];", $src, 'and render only its reduced view');
        foreach (['ComplianceCatalog', 'ComplianceAssessor', 'SnapshotStore', 'compliance_snapshots', 'compliance_attestations'] as $live) {
            $this->assertStringNotContainsString($live, $src, "the portal page must not read the live admin data ($live)");
        }
    }
}
