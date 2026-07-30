<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Support;

/**
 * Resolve Laravel's APP_KEY into a chunk integrity secret, or into nothing.
 *
 * `config('app.key')` on an application that has not run
 * `php artisan key:generate` is `string(0) ""`, not null. Forwarded unguarded
 * into the PHP indexer that becomes
 * `hash_init('sha256', HASH_HMAC, '')`, which throws, so
 * `php artisan scolta:build --force` aborted with
 * "hash_init(): Argument #3 ($key) must not be empty when HMAC is requested"
 * on the first command a new adopter runs. The message named neither APP_KEY
 * nor the remedy, so it read as a Scolta bug rather than an unconfigured app.
 *
 * An empty key therefore resolves to null here: integrity tagging is skipped,
 * CRC32 corruption detection is unaffected, and the build completes. It is
 * deliberately not a hard failure. An index without an integrity tag is a
 * working index, and refusing to build would block the evaluation path for no
 * security gain, since an operator with no APP_KEY was never getting a tag.
 *
 * Whitespace-only keys resolve to null too, on the same reasoning
 * scolta-php applies: `APP_KEY=" "` is an accident, and honouring it would
 * write a tag that only a caller reproducing the same accidental whitespace
 * could verify.
 *
 * This is complementary to the library-side fix in tag1consulting/scolta-php,
 * not made redundant by it. The library stops crashing for every adapter; this
 * puts a warning naming APP_KEY and `key:generate` in front of the operator
 * who can act on it, at the moment they run the build.
 *
 * @since 1.1.0
 *
 * @stability experimental
 */
final class HmacSecret
{
    /**
     * Operator-facing warning for a build running without an integrity tag.
     *
     * Shared so the console path and the queued path cannot drift into telling
     * an operator two different things about the same condition.
     *
     * Held as separate lines rather than one string because Symfony's console
     * hard-wraps at the terminal width, and a wrap landing inside
     * "php artisan key:generate" would break the one part of this the operator
     * has to be able to copy.
     *
     * @var list<string>
     *
     * @since 1.1.0
     *
     * @stability experimental
     */
    public const EMPTY_APP_KEY_WARNING_LINES = [
        'APP_KEY is empty. Building without an integrity tag.',
        'Run: php artisan key:generate to enable it.',
        'CRC32 corruption detection stays active either way.',
    ];

    /**
     * The same warning as a single line, for log channels.
     *
     * @since 1.1.0
     *
     * @stability experimental
     */
    public static function emptyAppKeyWarning(): string
    {
        return implode(' ', self::EMPTY_APP_KEY_WARNING_LINES);
    }

    /**
     * Reduce a configured key to null when it carries no key material.
     *
     * Takes mixed because it reads a config value: a missing `app.key` is
     * null, an unset one is `''`, and a mis-typed config could be neither.
     * Anything that is not a non-blank string means "no secret configured".
     *
     * A key with real content is returned verbatim rather than trimmed, so an
     * index already built under a padded key keeps verifying.
     *
     * @param  mixed  $key  Raw `config('app.key')` value.
     * @return string|null The key unchanged, or null if absent, empty, blank
     *                     or not a string.
     *
     * @since 1.1.0
     *
     * @stability experimental
     */
    public static function normalize(mixed $key): ?string
    {
        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        return $key;
    }
}
