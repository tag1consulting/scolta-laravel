<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 760px; margin: 0 auto; padding: 1rem 1.5rem 4rem; line-height: 1.6; color: #222; }
        header { border-bottom: 1px solid #ddd; margin-bottom: 1.5rem; padding-bottom: 0.75rem; }
        header a { text-decoration: none; color: inherit; }
        .recipe-list { list-style: none; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 0.5rem 1.5rem; }
        .recipe-meta { color: #666; font-size: 0.9rem; }
    </style>
</head>
<body>
    <header>
        <h1><a href="/">{{ config('app.name') }}</a></h1>
        <p class="recipe-meta">Scolta testing site — {{ \Workbench\App\Models\Recipe::count() }} recipes from the scolta-php fixture corpus</p>
    </header>
    @yield('content')
</body>
</html>
