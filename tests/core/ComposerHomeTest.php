<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/composer_install.php';

/** composer's HOME must be a private, verified, per-user directory - never one another local user could have planted. */
final class ComposerHomeTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/rit-home-test-' . bin2hex(random_bytes(4));
        mkdir($this->base, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->base . '/*') ?: [] as $p) {
            is_link($p) ? unlink($p) : @rmdir($p);
        }
        @rmdir($this->base);
        @unlink($this->base . '-target');
        foreach (glob($this->base . '-target/*') ?: [] as $p) {
            @unlink($p);
        }
        @rmdir($this->base . '-target');
    }

    private function expected(): string
    {
        return $this->base . '/rivetit-composer-home-' . posix_geteuid();
    }

    public function testCreatesAPrivatePerUserDirectory(): void
    {
        $home = rivetit_composer_home($this->base);
        $this->assertSame($this->expected(), $home);
        $this->assertSame(0700, fileperms($home) & 0777);
        $this->assertSame($home, rivetit_composer_home($this->base), 'reusing the existing directory works');
    }

    public function testRefusesADirectoryOthersCanAccess(): void
    {
        mkdir($this->expected(), 0755);
        chmod($this->expected(), 0755);
        $this->assertNull(rivetit_composer_home($this->base));
        chmod($this->expected(), 0770);
        $this->assertNull(rivetit_composer_home($this->base));
    }

    public function testRefusesASymlinkEvenToAGoodDirectory(): void
    {
        mkdir($this->base . '-target', 0700);
        symlink($this->base . '-target', $this->expected());
        $this->assertNull(rivetit_composer_home($this->base), 'a planted symlink is never followed');
    }

    public function testRefusesAPlainFileInThePlace(): void
    {
        file_put_contents($this->expected(), 'x');
        $this->assertNull(rivetit_composer_home($this->base));
        unlink($this->expected());
    }

    public function testDifferentUsersGetDifferentNames(): void
    {
        $this->assertStringEndsWith('-' . posix_geteuid(), (string) rivetit_composer_home($this->base));
    }
}
