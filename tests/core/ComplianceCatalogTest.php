<?php

declare(strict_types=1);

use ITFlow\Compliance\ComplianceCatalog;
use ITFlow\Compliance\ComplianceService;
use PHPUnit\Framework\TestCase;
use RivetCore\Compliance\ComplianceAssessor;
use RivetCore\Compliance\Framework;
use RivetCore\Support\SystemClock;

/** The RivetIT checks against a scratch database holding RivetIT's schema. Seeded rows are rolled back. */
final class ComplianceCatalogTest extends TestCase
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
        $this->m->begin_transaction();
        foreach (['users', 'user_roles', 'user_passkeys', 'api_keys', 'credentials', 'auth_logs', 'audit_events', 'compliance_attestations', 'compliance_snapshots'] as $t) {
            $this->m->query("DELETE FROM $t");
        }
        if (!defined('LATEST_DATABASE_VERSION')) {
            define('LATEST_DATABASE_VERSION', '2.6.126');
            define('CURRENT_DATABASE_VERSION', '2.6.126');
        }
    }

    protected function tearDown(): void
    {
        $this->m->rollback();
    }

    private function results(array $settings = []): array
    {
        $cat = new ComplianceCatalog($this->m, sys_get_temp_dir(), $settings + ['config_compliance_profile' => 'none', 'config_log_retention' => 90, 'config_audit_retention_days' => 365, 'config_login_session_lifetime' => 43200]);
        $a = (new ComplianceAssessor($cat->checks(), $cat->manualItems(), ComplianceService::attestations($this->m), new SystemClock()))->assess();

        return array_column($a->automatic, null, 'id');
    }

    private function user(string $email, int $role, string $token = '', string $auth = 'local'): int
    {
        $this->m->query("INSERT INTO users (user_name, user_email, user_password, user_auth_method, user_type, user_status, user_token, user_role_id, user_created_at) VALUES ('t', '$email', 'x', '$auth', 1, 1, '$token', $role, NOW() - INTERVAL 200 DAY)");

        return (int) $this->m->insert_id;
    }

    private function role(int $admin): int
    {
        $this->m->query("INSERT INTO user_roles (role_name, role_is_admin) VALUES ('r$admin', $admin)");

        return (int) $this->m->insert_id;
    }

    public function testMfaAndAdminChecks(): void
    {
        $this->assertSame('na', $this->results()['mfa_coverage']['status'], 'no agents => not applicable');
        $admin = $this->role(1);
        $plain = $this->role(0);
        $a = $this->user('a@x.test', $admin);
        $b = $this->user('b@x.test', $plain, 'SECRET');
        $r = $this->results();
        $this->assertSame('fail', $r['mfa_coverage']['status']);
        $this->assertSame('fail', $r['admin_mfa']['status']);
        $this->m->query("INSERT INTO user_passkeys (passkey_user_id, passkey_name, passkey_credential_id, passkey_public_key, passkey_created_at) VALUES ($a, 'k', 'cid', 'pk', NOW())");
        $r = $this->results();
        $this->assertSame('pass', $r['mfa_coverage']['status']);
        $this->assertSame('pass', $r['admin_mfa']['status']);
        $c = $this->user('c@x.test', $plain, '', 'oidc');
        $this->assertSame('pass', $this->results()['mfa_coverage']['status'], 'single sign-on counts');
        $d = $this->user('d@x.test', $plain);
        $this->assertSame('fail', $this->results()['mfa_coverage']['status']);
        $this->assertStringContainsString('1 of 4', $this->results()['mfa_coverage']['summary']);
    }

    public function testDormantAgentsAndApiKeysAndCredentials(): void
    {
        $role = $this->role(0);
        $u = $this->user('dorm@x.test', $role);
        $this->assertSame('warn', $this->results()['dormant_agents']['status']);
        $this->m->query("INSERT INTO auth_logs (auth_log_status, auth_log_details, auth_log_user_id, auth_log_created_at) VALUES (1, 'ok', $u, NOW() - INTERVAL 3 DAY)");
        $this->assertSame('pass', $this->results()['dormant_agents']['status']);

        $this->assertSame('na', $this->results()['api_keys']['status']);
        $this->m->query("INSERT INTO api_keys (api_key_name, api_key_secret, api_key_decrypt_hash, api_key_expire) VALUES ('k', 's', 'h', NOW() + INTERVAL 30 DAY)");
        $this->assertSame('pass', $this->results()['api_keys']['status']);
        $this->m->query("INSERT INTO api_keys (api_key_name, api_key_secret, api_key_decrypt_hash, api_key_expire) VALUES ('k2', 's', 'h', '2099-12-31 00:00:00')");
        $this->assertSame('warn', $this->results()['api_keys']['status']);

        $this->assertSame('pass', $this->results()['credential_rotation']['status']);
        $this->m->query("INSERT INTO credentials (credential_name, credential_rotation_due_at, credential_created_at) VALUES ('c', NOW() - INTERVAL 2 DAY, NOW())");
        $this->assertSame('warn', $this->results()['credential_rotation']['status']);
    }

    public function testRetentionSessionAndAuditChecks(): void
    {
        $this->assertSame('warn', $this->results()['log_retention']['status'], 'no preset');
        $this->assertSame('pass', $this->results(['config_compliance_profile' => 'hipaa', 'config_log_retention' => 2190, 'config_audit_retention_days' => 0])['log_retention']['status']);
        $this->assertSame('fail', $this->results(['config_compliance_profile' => 'hipaa', 'config_log_retention' => 400])['log_retention']['status']);
        $this->assertSame('pass', $this->results()['session_lifetime']['status']);
        $this->assertSame('fail', $this->results(['config_login_session_lifetime' => 200000])['session_lifetime']['status']);
        $this->assertSame('warn', $this->results()['audit_trail_recording']['status'], 'on but empty');
        $this->m->query("INSERT INTO audit_events (event_type, action) VALUES ('t', 'a')");
        $this->assertSame('pass', $this->results()['audit_trail_recording']['status']);
        $this->assertSame('pass', $this->results()['schema_current']['status']);
    }

    public function testEveryItemIsTaggedAndIdsAreUnique(): void
    {
        $cat = new ComplianceCatalog($this->m, sys_get_temp_dir(), []);
        $ids = [];
        foreach ($cat->checks() as $c) {
            $ids[] = $c->id();
            $this->assertNotEmpty($c->controls(), $c->id() . ' maps to at least one framework');
            foreach ($c->controls() as $fw => $refs) {
                $this->assertTrue(Framework::isValid($fw));
                $this->assertNotEmpty($refs);
            }
        }
        foreach ($cat->manualItems() as $m) {
            $ids[] = $m->id;
            $this->assertMatchesRegularExpression('/^[a-z0-9_]{1,64}$/', $m->id, 'manual ids must satisfy the store validation');
            $this->assertNotEmpty($m->controls);
            foreach ($m->controls as $fw => $refs) {
                $this->assertTrue(Framework::isValid($fw));
            }
        }
        $this->assertSame($ids, array_values(array_unique($ids)), 'ids are unique across automatic checks and manual items');
    }

    /** The admin page does not load includes/database_version.php, so the check must find the latest version itself. */
    public function testSchemaCheckWorksWhenVersionConstantsAreNotDefined(): void
    {
        $root = dirname(__DIR__, 2);
        $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; mysqli_report(MYSQLI_REPORT_OFF);'
            . '$m = new mysqli(getenv("RIVETCORE_TEST_DB_HOST") ?: "localhost", getenv("RIVETCORE_TEST_DB_USER"), getenv("RIVETCORE_TEST_DB_PASS"), getenv("RIVETCORE_TEST_DB_NAME"));'
            . '$latest = preg_match("/LATEST_DATABASE_VERSION.,\\s*.([0-9.]+)/", file_get_contents(' . var_export($root . '/includes/database_version.php', true) . '), $v) ? $v[1] : "";'
            . '$cat = new \\ITFlow\\Compliance\\ComplianceCatalog($m, ' . var_export($root, true) . ', ["config_current_database_version" => $latest]);'
            . 'foreach ($cat->checks() as $c) { if ($c->id() === "schema_current") { echo $c->run()->status->value; } }';
        $this->assertSame('pass', trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1')));
    }
}
