@extends('layouts.site')

@section('title', config('app.name'))

@section('content')
    <x-scolta::search />

    <h2>All recipes</h2>
    <ul class="recipe-list">
        @foreach ($recipes as $recipe)
            <li>
                <a href="{{ route('recipes.show', $recipe->slug) }}">{{ $recipe->title }}</a>
                <div class="recipe-meta">{{ $recipe->cuisine }} · {{ $recipe->cook_time }} min</div>
            </li>
        @endforeach
    </ul>
@endsection
