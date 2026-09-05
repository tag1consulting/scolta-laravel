<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Workbench\App\Models\Recipe;

Route::get('/', function () {
    return view('home', [
        'recipes' => Recipe::query()->orderBy('title')->get(),
    ]);
});

Route::get('/recipes/{slug}', function (string $slug) {
    return view('recipes.show', [
        'recipe' => Recipe::query()->where('slug', $slug)->firstOrFail(),
    ]);
})->name('recipes.show');
