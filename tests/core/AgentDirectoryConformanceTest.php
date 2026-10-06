<?php

declare(strict_types=1);

require_once __DIR__ . '/KitSupport.php';

use PHPUnit\Framework\TestCase;

if (!KitSupport::has(\RivetCore\Testing\AgentDirectoryConformanceTestCase::class)) {
    /** Placeholder until the pinned RivetCore carries the conformance kit. */
    final class AgentDirectoryConformanceTest extends TestCase
    {
        public function testKitIsAvailable(): void
        {
            $this->markTestSkipped(KitSupport::MISSING);
        }
    }

    return;
}

use RivetCore\Mcp\AgentDirectoryInterface;
use RivetCore\Testing\AgentDirectoryConformanceTestCase;

/** Mcp\UsersAgentDirectory over `users` (user_type = 1 are agents). */
final class AgentDirectoryConformanceTest extends AgentDirectoryConformanceTestCase
{
    protected function directory(): AgentDirectoryInterface
    {
        return new \ITFlow\Core\Adapter\Mcp\UsersAgentDirectory(KitSupport::db());
    }

    protected function storeAgent(string $name, string $email, bool $active): int
    {
        return (int) KitSupport::db()->execute(
            "INSERT INTO users (user_name, user_email, user_password, user_auth_method, user_type, user_status) VALUES (?, ?, 'x', 'local', 1, ?)",
            [$name, $email, $active ? 1 : 0]
        )->insertId;
    }

    protected function deleteAgent(int $userId): void
    {
        KitSupport::db()->execute('DELETE FROM users WHERE user_id = ?', [$userId]);
    }
}
