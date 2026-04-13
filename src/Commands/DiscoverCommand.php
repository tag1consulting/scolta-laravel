<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Tag1\ScoltaLaravel\Searchable;

/**
 * Discover Eloquent models that use the Searchable trait.
 *
 * Scans app/Models/ (and optionally a custom path) for Eloquent models that
 * use the Searchable trait, then compares against the models already listed
 * in config/scolta.php. Reports new candidates and shows the config snippet
 * needed to add them.
 *
 * Equivalent pattern: Laravel Scout's model discovery — surface what's
 * searchable without forcing the developer to grep for it.
 *
 * @since 0.3.0
 * @stability experimental
 */
class DiscoverCommand extends Command
{
    protected $signature = 'scolta:discover
        {--path= : Absolute path to scan (default: app/Models)}';

    protected $description = 'Discover Eloquent models that use the Searchable trait and are not yet configured';

    public function handle(): int
    {
        $scanPath = $this->option('path') ?: app_path('Models');

        if (! is_dir($scanPath)) {
            $this->warn("Directory not found: {$scanPath}");
            $this->line('Create an app/Models/ directory or pass --path=<dir> to specify a custom location.');

            return self::FAILURE;
        }

        $discovered = $this->findSearchableModels($scanPath);
        $configured = array_map('strtolower', (array) config('scolta.models', []));

        $alreadyConfigured = [];
        $newCandidates = [];

        foreach ($discovered as $class) {
            if (in_array(strtolower($class), $configured, true)) {
                $alreadyConfigured[] = $class;
            } else {
                $newCandidates[] = $class;
            }
        }

        if (empty($discovered)) {
            $this->info('No models using the Searchable trait found in: '.$scanPath);
            $this->line('');
            $this->line('To make a model searchable, add the Searchable trait:');
            $this->line('');
            $this->line('    use Tag1\ScoltaLaravel\Searchable;');
            $this->line('');
            $this->line('    class Post extends Model');
            $this->line('    {');
            $this->line('        use Searchable;');
            $this->line('    }');

            return self::SUCCESS;
        }

        if (! empty($alreadyConfigured)) {
            $this->info('Already configured:');
            foreach ($alreadyConfigured as $class) {
                $this->line("  [x] {$class}");
            }
            $this->line('');
        }

        if (empty($newCandidates)) {
            $this->info('All Searchable models are already configured.');

            return self::SUCCESS;
        }

        $this->warn(count($newCandidates).' model(s) use Searchable but are not in config/scolta.php:');
        foreach ($newCandidates as $class) {
            $this->line("  [ ] {$class}");
        }
        $this->line('');

        $this->info('Add the following to the \'models\' array in config/scolta.php:');
        $this->line('');
        $this->line("    'models' => [");
        foreach ($newCandidates as $class) {
            $this->line("        {$class}::class,");
        }
        $this->line('    ],');

        return self::SUCCESS;
    }

    /**
     * Scan a directory recursively for classes that use the Searchable trait.
     *
     * @return string[] Fully-qualified class names.
     */
    private function findSearchableModels(string $dir): array
    {
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = $this->classFromFile($file->getPathname());

            if ($class === null) {
                continue;
            }

            // Autoload the class so we can inspect it.
            if (! class_exists($class)) {
                continue;
            }

            if (in_array(Searchable::class, class_uses_recursive($class), true)) {
                $found[] = $class;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Extract the fully-qualified class name from a PHP file.
     *
     * Reads namespace and class declarations via token_get_all() so we
     * do not have to rely on file-path-to-namespace conventions.
     *
     * @return string|null Class name, or null if not found.
     */
    private function classFromFile(string $path): ?string
    {
        $src = file_get_contents($path);

        if ($src === false) {
            return null;
        }

        $tokens = token_get_all($src);
        $namespace = '';
        $className = null;
        $i = 0;
        $count = count($tokens);

        while ($i < $count) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                $i++;
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                // Consume the namespace declaration.
                $i++;
                $ns = '';
                while ($i < $count) {
                    $t = $tokens[$i];
                    if (is_array($t) && in_array($t[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED], true)) {
                        $ns .= $t[1];
                    } elseif ($t === ';' || $t === '{') {
                        break;
                    }
                    $i++;
                }
                $namespace = $ns;
            }

            if ($token[0] === T_CLASS || $token[0] === T_INTERFACE || $token[0] === T_TRAIT) {
                // Skip anonymous classes: the next meaningful token must be T_STRING.
                $j = $i + 1;
                while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $className = $tokens[$j][1];
                    break;
                }
            }

            $i++;
        }

        if ($className === null) {
            return null;
        }

        return $namespace !== '' ? $namespace.'\\'.$className : $className;
    }
}
