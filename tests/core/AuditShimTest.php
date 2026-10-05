<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The legacy entry points still work and now go through RivetCore. Needs a scratch DB that holds RivetIT's
 * schema (import db.sql); the test only inserts and reads its own rows.
 */
final class AuditShimTest extends TestCase
{
    private mysqli $m;

    protected function setUp(): void
    {
        $name = getenv('RIVETCORE_TEST_DB_NAME');
        if (!$name) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->m = new mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '', $name);
        $this->m->query("DELETE FROM audit_events WHERE event_type LIKE 'shimtest.%'");
        $GLOBALS['mysqli'] = $this->m; // what Connection::get() reads in the real app
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_USER_AGENT'] = 'phpunit';
        $_SERVER['RIVET_REQUEST_ID'] = 'rid-77';
        $_SERVER['HTTP_X_REQUEST_ID'] = 'attacker-chosen-id';
    }

    public function testStaticRecordWritesThroughCore(): void
    {
        $this->assertTrue(class_exists(\RivetCore\Audit\AuditService::class));
        \ITFlow\Audit\AuditService::record('shimtest.static', 4, 'user', 4, 'login', 'hello', ['ip' => '1.2.3.4']);
        $row = $this->m->query("SELECT * FROM audit_events WHERE event_type='shimtest.static'")->fetch_assoc();
        $this->assertSame('203.0.113.9', $row['ip_address']);
        $this->assertSame('phpunit', $row['user_agent']);
        $this->assertSame('rid-77', $row['request_id'], 'the server-assigned id is stored, never the client header');
        $this->assertSame('{"ip":"1.2.3.4"}', $row['metadata_json']);
        $this->assertSame('4', $row['entity_id']);
    }

    public function testConstructWithRawMysqliStillWorks(): void
    {
        (new \ITFlow\Audit\AuditService($this->m))->log('shimtest.ctor', null, null, null, 'x');
        $this->assertSame(1, (int) $this->m->query("SELECT COUNT(*) c FROM audit_events WHERE event_type='shimtest.ctor'")->fetch_assoc()['c']);
    }

    public function testClientRequestIdHeaderIsNeverStored(): void
    {
        unset($_SERVER['RIVET_REQUEST_ID']);
        $_SERVER['HTTP_X_REQUEST_ID'] = 'attacker-chosen-id';
        $ctx = new \ITFlow\Core\Adapter\Http\ServerRequestContext();
        $id = $ctx->requestId();
        $this->assertNotSame('attacker-chosen-id', $id);
        $this->assertMatchesRegularExpression('/^req_[0-9a-f]{16}$/', (string) $id);
        $this->assertSame($id, $ctx->requestId(), 'stable for the whole request');
    }
}
