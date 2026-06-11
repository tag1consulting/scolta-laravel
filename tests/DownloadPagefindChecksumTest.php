<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;
use Tag1\ScoltaLaravel\Commands\DownloadPagefindCommand;

/**
 * SHA-256 verification of downloaded Pagefind tarballs.
 *
 * Pagefind publishes a .sha256 asset next to every release tarball;
 * scolta:download-pagefind must verify the download against it before
 * extracting, and must fail closed on mismatch or malformed input.
 */
class DownloadPagefindChecksumTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = (string) tempnam(sys_get_temp_dir(), 'scolta_test_');
        file_put_contents($this->tmpFile, 'tarball-bytes');
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpFile);
    }

    public function test_matching_checksum_verifies(): void
    {
        $hash = hash('sha256', 'tarball-bytes');

        $this->assertTrue(DownloadPagefindCommand::checksumMatches(
            $this->tmpFile,
            "{$hash}  pagefind-v1.5.2-x86_64-unknown-linux-musl.tar.gz\n",
        ));
    }

    public function test_bare_hash_without_filename_verifies(): void
    {
        $hash = hash('sha256', 'tarball-bytes');

        $this->assertTrue(DownloadPagefindCommand::checksumMatches($this->tmpFile, $hash));
    }

    public function test_uppercase_hash_verifies(): void
    {
        $hash = strtoupper(hash('sha256', 'tarball-bytes'));

        $this->assertTrue(DownloadPagefindCommand::checksumMatches($this->tmpFile, $hash));
    }

    public function test_mismatched_checksum_fails(): void
    {
        $wrong = hash('sha256', 'different-bytes');

        $this->assertFalse(DownloadPagefindCommand::checksumMatches(
            $this->tmpFile,
            "{$wrong}  pagefind.tar.gz",
        ));
    }

    public function test_malformed_checksum_document_fails(): void
    {
        $this->assertFalse(DownloadPagefindCommand::checksumMatches($this->tmpFile, ''));
        $this->assertFalse(DownloadPagefindCommand::checksumMatches($this->tmpFile, 'not-a-hash'));
        $this->assertFalse(DownloadPagefindCommand::checksumMatches($this->tmpFile, '<html>404</html>'));
    }

    // -------------------------------------------------------------------
    // The command verifies BEFORE extracting, and fails closed.
    // -------------------------------------------------------------------

    public function test_handle_verifies_before_extraction(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__).'/src/Commands/DownloadPagefindCommand.php');

        $checksumPos = strpos($source, 'checksumMatches(');
        $extractPos = strpos($source, 'tar -xzf');

        $this->assertNotFalse($checksumPos, 'handle() must verify the checksum.');
        $this->assertNotFalse($extractPos, 'handle() must extract via tar.');
        $this->assertLessThan($extractPos, $checksumPos,
            'The checksum must be verified BEFORE the tarball is extracted.');
        $this->assertStringContainsString("'.sha256'", $source,
            'handle() must fetch the upstream .sha256 asset.');
    }
}
