<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * ExportCommand deletions must go through the manifest-aware exporter.
 *
 * Regression: deletions were performed by manually concatenating
 * "{buildDir}/{id}.html" and File::delete()-ing it, which deletes the
 * wrong file (or nothing) when the export manifest maps non-flat paths,
 * and splices $id into a filesystem path. BuildCommand already used
 * ContentExporter::deleteById(); ExportCommand must match.
 */
class ExportCommandDeletionTest extends TestCase
{
    public function test_export_command_deletes_via_exporter(): void
    {
        $source = file_get_contents(dirname(__DIR__).'/src/Commands/ExportCommand.php');

        $this->assertStringContainsString('$exporter->deleteById($id)', $source,
            'ExportCommand must delete via the manifest-aware ContentExporter::deleteById().');
        $this->assertStringNotContainsString(".'.html'", $source,
            'ExportCommand must not build deletion paths by string concatenation.');
        $this->assertStringNotContainsString('File::delete(', $source,
            'ExportCommand must not delete files directly — the exporter owns the manifest.');
    }
}
