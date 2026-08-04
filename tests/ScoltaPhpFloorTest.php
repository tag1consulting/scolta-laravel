<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The scolta-php floor must refuse a release this package cannot run on.
 *
 * This package required tag1/scolta-php as ^1.1.0 while
 * src/AiProvider/Amazee/LaravelConfigStorage.php declared `implements
 * ProvenanceAwareConfigStorageInterface` and typed two of its methods to
 * AmazeeConnectionSource. Neither symbol exists in the 1.1.0 release, and an
 * `implements` clause is resolved when the class is defined, not when a method
 * is called: `composer require tag1/scolta-laravel:dev-main` resolved
 * scolta-php 1.1.0 and fatalled the moment the container built the storage.
 * This repository is public, so anyone could reach that state.
 *
 * Nothing caught it, because every CI job here overwrote the constraint with
 * `dev-main@dev` before running composer. The guard is written at the level the
 * defect lives at: what the shipped file says, evaluated against the release
 * that cannot satisfy it.
 */
class ScoltaPhpFloorTest extends TestCase
{
    /**
     * Every scolta-php symbol this package resolves at class-definition time.
     */
    private const REQUIRES_1_2 = [
        'Tag1\Scolta\AiProvider\Amazee\ProvenanceAwareConfigStorageInterface',
        'Tag1\Scolta\AiProvider\Amazee\AmazeeConnectionSource',
    ];

    private function declaredConstraint(): string
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__).'/composer.json'), true);
        $this->assertIsArray($manifest, 'composer.json must parse');
        $this->assertArrayHasKey('tag1/scolta-php', $manifest['require'] ?? [], 'composer.json must require tag1/scolta-php');

        return (string) $manifest['require']['tag1/scolta-php'];
    }

    /**
     * Does a constraint admit a given release?
     *
     * Hand-written rather than delegated to composer/semver, so the guard has
     * no dependency of its own to go missing. The question is narrow enough to
     * answer exactly: a branch constraint names a branch and can never resolve
     * a tag, and a range constraint admits 1.1.0 only if its lower bound is
     * below 1.2.
     */
    private function admitsRelease(string $constraint, int $major, int $minor): bool
    {
        $c = trim($constraint);
        if (str_starts_with($c, 'dev-') || $c === '@dev') {
            return false;
        }
        // A stability suffix narrows what may be installed, never widens which
        // release line the constraint covers.
        $c = (string) preg_replace('/@\w+$/', '', $c);
        if (! preg_match('/^[\^~]?(\d+)\.(\d+)/', $c, $m)) {
            // Anything this cannot read is reported as admitting the release,
            // so an unrecognised constraint fails the test rather than passing.
            return true;
        }

        return (int) $m[1] < $major || ((int) $m[1] === $major && (int) $m[2] <= $minor);
    }

    public function test_the_declared_floor_refuses_scolta_php_110(): void
    {
        $constraint = $this->declaredConstraint();
        $this->assertFalse(
            $this->admitsRelease($constraint, 1, 1),
            sprintf(
                'composer.json requires tag1/scolta-php as "%s", which resolves the 1.1.0 release. '
                .'This package resolves %s at class-definition time, and 1.1.0 has neither, '
                .'so that resolution is a fatal error rather than a missing feature.',
                $constraint,
                implode(' and ', self::REQUIRES_1_2)
            )
        );
    }

    /**
     * The symbols the floor exists for are really loaded unguarded.
     *
     * Reads the source rather than reflecting on loaded classes, so it holds
     * whether or not scolta-php is installed.
     */
    public function test_the_symbols_the_floor_exists_for_are_still_loaded_unguarded(): void
    {
        $storage = (string) file_get_contents(dirname(__DIR__).'/src/AiProvider/Amazee/LaravelConfigStorage.php');
        $this->assertStringContainsString(
            'implements ProvenanceAwareConfigStorageInterface',
            $storage,
            'If this class no longer implements the interface, the floor may be reconsidered on its own merits.'
        );
        $this->assertStringContainsString('AmazeeConnectionSource $source', $storage);
    }

    /**
     * The reader itself, since every other assertion here rests on it.
     *
     * Verified against composer 2 in the scolta-fleet repository, with only a
     * v1.1.0 tag present and main aliased to 1.2.x-dev.
     */
    public function test_the_constraint_reader_agrees_with_composer(): void
    {
        $this->assertTrue($this->admitsRelease('^1.1.0', 1, 1), '^1.1.0 resolves 1.1.0, which is the defect');
        $this->assertFalse($this->admitsRelease('^1.2', 1, 1));
        $this->assertFalse($this->admitsRelease('^1.2@dev', 1, 1));
        $this->assertFalse($this->admitsRelease('dev-main', 1, 1));
        $this->assertFalse($this->admitsRelease('dev-main@dev', 1, 1));
        $this->assertTrue($this->admitsRelease('whatever', 1, 1));
    }
}
