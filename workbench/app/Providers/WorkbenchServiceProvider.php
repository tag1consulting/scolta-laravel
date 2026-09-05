<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Recipe;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The workbench has no publishable config file; register the
        // searchable model here instead. Runs after ScoltaServiceProvider's
        // mergeConfigFrom(), so this wins.
        config(['scolta.models' => [Recipe::class]]);
    }
}
