<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/composer_install.php';

/**
 * The in-app Update gives composer a throwaway home and then deletes it recursively. That delete must only ever touch a
 * directory this code made: it refuses anything without the exact prefix, and never follows a symlink.
 */
final class ComposerCleanupTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/rit-cleanup-' . bin2hex(random_bytes(4));
        mkdir($this->base, 0700);
    }

    protected function tearDown(): void
    {
        $this->rm($this->base);
    }

    private function rm(string $p): void
    {
        if (is_link($p) || is_file($p)) {
            @unlink($p);
            return;
        }
        foreach (glob($p . '/{,.}*', GLOB_BRACE) ?: [] as $c) {
            if (!in_array(basename($c), ['.', '..'], true)) {
                $this->rm($c);
            }
        }
        @rmdir($p);
    }

    public function testRemovesNestedContentOfAnOwnedHome(): void
    {
        $home = $this->base . '/rivetit-composer-abc123';
        mkdir($home . '/cache/files', 0700, true);
        file_put_contents($home . '/cache/files/x', 'x');
        file_put_contents($home . '/config.json', '{}');
        rivetit_composer_remove_dir($home);
        $this->assertDirectoryDoesNotExist($home);
    }

    public function testRefusesADirectoryWithoutTheExpectedPrefix(): void
    {
        $other = $this->base . '/something-else';
        mkdir($other, 0700);
        file_put_contents($other . '/keep', 'x');
        rivetit_composer_remove_dir($other);
        $this->assertFileExists($other . '/keep');
    }

    public function testNeverFollowsASymlinkEvenWithTheRightName(): void
    {
        $victim = $this->base . '/victim';
        mkdir($victim, 0700);
        file_put_contents($victim . '/precious', 'x');
        symlink($victim, $this->base . '/rivetit-composer-planted');
        rivetit_composer_remove_dir($this->base . '/rivetit-composer-planted');
        $this->assertFileExists($victim . '/precious', 'the link target must survive');
    }

    public function testMissingDirectoryIsANoOp(): void
    {
        rivetit_composer_remove_dir($this->base . '/rivetit-composer-nothing');
        $this->addToAssertionCount(1);
    }
}
