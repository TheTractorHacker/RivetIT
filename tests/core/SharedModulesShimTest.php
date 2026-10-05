<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RivetCore\Tests\Support\Fixtures;

if (!function_exists('decryptSetting')) {
    function decryptSetting(string $c): string { return str_starts_with($c, 'ENC:') ? substr($c, 4) : $c; } // test stand-in for functions.php
}

/** The legacy ITFlow\KB / Webhooks / Automation / Workflow / Knowledge entry points still work on RivetCore. Needs a scratch DB with the full schema. */
final class SharedModulesShimTest extends TestCase
{
    private mysqli $m;
    private string $dir;

    protected function setUp(): void
    {
        $name = getenv('RIVETCORE_TEST_DB_NAME');
        if (!$name) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->m = new mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '', $name);
        $this->m->query("SET SESSION sql_mode=''");
        foreach (['webhook_deliveries', 'webhooks', 'automation_rules', 'workflow_run_tasks', 'workflow_runs', 'workflow_template_tasks', 'workflow_templates'] as $t) {
            $this->m->query("DELETE FROM $t");
        }
        $this->dir = sys_get_temp_dir() . '/rit-shim-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    public function testKbConverterAliasesKeepCatchClausesWorking(): void
    {
        // PHP does not autoload a class named in a catch clause: the converter shim must load its exception alias too.
        $caught = [];
        try {
            \ITFlow\KB\DocxConverter::convert($this->dir . '/missing.docx');
        } catch (\ITFlow\KB\DocxConversionException $e) {
            $caught[] = 'docx';
        }
        try {
            \ITFlow\KB\PdfConverter::convert($this->dir . '/missing.pdf');
        } catch (\ITFlow\KB\PdfConversionException $e) {
            $caught[] = 'pdf';
        }
        $this->assertSame(['docx', 'pdf'], $caught);
        $this->assertSame(\RivetCore\KB\DocxConverter::class, (new ReflectionClass(\ITFlow\KB\DocxConverter::class))->getName());
    }

    public function testKbConvertersStillConvert(): void
    {
        Fixtures::docx($this->dir . '/a.docx');
        $this->assertStringContainsString('Reset a password', json_encode(\ITFlow\KB\DocxConverter::convert($this->dir . '/a.docx')));
        Fixtures::pdf($this->dir . '/a.pdf', 'Hello Shim PDF');
        $this->assertStringContainsString('Hello Shim PDF', json_encode(\ITFlow\KB\PdfConverter::convert($this->dir . '/a.pdf')));
    }

    public function testCredentialReferenceRendererKeepsRivetItsRevealControl(): void
    {
        $r = new \ITFlow\Knowledge\CredentialReferenceRenderer();
        $out = $r->render('<p>[[credential:12]]</p>');
        $this->assertStringContainsString('modals/credential/credential_view.php?id=12', $out);
        $this->assertStringContainsString('Reveal linked credential', $out);
        $this->assertTrue($r->containsReference('[[credential:1]]'));
    }

    public function testAutomationShim(): void
    {
        $this->m->query("INSERT INTO automation_rules (name, trigger_event, condition_json, action_type) VALUES ('r', 'contact.started', '{\"action\":\"started\"}', 'notify_user')");
        $e = new \ITFlow\Automation\AutomationRuleEvaluator($this->m);
        $this->assertCount(1, $e->findMatchingRules('contact.started', ['action' => 'started']));
        $this->assertCount(0, $e->findMatchingRules('contact.started', ['action' => 'other']));
        $this->assertTrue($e->conditionsMatch(null, []));
    }

    public function testWorkflowShimRunsTheLifecycle(): void
    {
        $this->m->query("INSERT INTO workflow_templates (workflow_template_id, name, type) VALUES (1, 'On', 'onboarding')");
        $this->m->query("INSERT INTO workflow_template_tasks (workflow_template_id, title, required, sort_order) VALUES (1, 'a', 1, 0), (1, 'b', 0, 1)");
        $w = new \ITFlow\Workflow\WorkflowService($this->m);
        $run = $w->startRun(1, 77, 1);
        $task = (int) $this->m->query("SELECT run_task_id FROM workflow_run_tasks WHERE run_id = $run AND required = 1")->fetch_row()[0];
        $w->completeTask($task, 1);
        $this->assertSame('completed', $this->m->query("SELECT status FROM workflow_runs WHERE run_id = $run")->fetch_row()[0]);
        $w->reopenTask($task);
        $this->assertSame('in_progress', $this->m->query("SELECT status FROM workflow_runs WHERE run_id = $run")->fetch_row()[0]);
        $w->cancelRun($run);
        $this->assertSame('cancelled', $this->m->query("SELECT status FROM workflow_runs WHERE run_id = $run")->fetch_row()[0]);
    }

    public function testWebhookShimMatchesEventsDecryptsSecretAndKeepsBothHeaderFamilies(): void
    {
        file_put_contents($this->dir . '/router.php', '<?php file_put_contents(__DIR__."/got.json", json_encode(["body"=>file_get_contents("php://input"),"h"=>getallheaders()])); http_response_code(200); echo "ok";');
        $port = random_int(40001, 60000);
        $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $this->dir . '/router.php'], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        try {
            for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
                usleep(100000);
            }
            $url = "http://127.0.0.1:$port/hook";
            $this->m->query("INSERT INTO webhooks (webhook_name, webhook_url, webhook_secret, webhook_events, webhook_enabled) VALUES ('on', '$url', 'ENC:topsecret', 'ticket.created, asset.updated', 1), ('off', '$url', 'ENC:x', 'asset.updated', 0), ('other', '$url', 'ENC:y', 'invoice.paid', 1)");
            $results = (new \ITFlow\Webhooks\WebhookDispatcher($this->m))->deliver('asset.updated', ['id' => 9]);
            $this->assertCount(1, $results, 'only the enabled endpoint subscribed to this event is called');
            $this->assertSame(200, $results[0]['http_status']);
            $got = json_decode((string) file_get_contents($this->dir . '/got.json'), true);
            $h = array_change_key_case($got['h'], CASE_LOWER);
            $sig = 'sha256=' . hash_hmac('sha256', $got['body'], 'topsecret');
            $this->assertSame($sig, $h['x-itflow-signature']);
            $this->assertSame($sig, $h['x-rivetit-signature']);
            $this->assertSame('asset.updated', $h['x-itflow-event']);
            $this->assertSame(1, (int) $this->m->query('SELECT COUNT(*) FROM webhook_deliveries')->fetch_row()[0]);
        } finally {
            proc_terminate($proc, 9);
            proc_close($proc);
        }
    }
}
