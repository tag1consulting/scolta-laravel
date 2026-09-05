@extends('layouts.site')

@section('title', $recipe->title.' — '.config('app.name'))

@section('content')
    <article>
        <h1>{{ $recipe->title }}</h1>
        <p class="recipe-meta">
            {{ $recipe->cuisine }}
            @if ($recipe->diet) · {{ $recipe->diet }} @endif
            · {{ $recipe->cook_time }} min
            · {{ $recipe->published_at->format('Y-m-d') }}
        </p>
        {!! $recipe->body_html !!}
    </article>
    <p><a href="/">&larr; All recipes</a></p>
@endsection
