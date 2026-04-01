{{--
    Scolta search component.

    Usage: <x-scolta::search />

    Outputs the container div, includes scolta.js + scolta.css, and injects
    the configuration as window.scolta. The actual search UI is rendered
    client-side by scolta.js, identical across all three platforms.

    Assets must be published first:
      php artisan vendor:publish --tag=scolta-assets
--}}

@php
    $config = app(\Tag1\ScoltaLaravel\Services\ScoltaAiService::class)->getConfig();
    $outputDir = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));

    // Convert filesystem path to URL path.
    $publicPath = public_path();
    $pagefindUrl = str_starts_with($outputDir, $publicPath)
        ? substr($outputDir, strlen($publicPath))
        : '/scolta-pagefind';
@endphp

{{-- Scolta CSS (published asset) --}}
@if(file_exists(public_path('vendor/scolta/scolta.css')))
    <link rel="stylesheet" href="{{ asset('vendor/scolta/scolta.css') }}" />
@endif

{{-- Pagefind UI CSS --}}
@if(file_exists($outputDir . '/pagefind-ui.css'))
    <link rel="stylesheet" href="{{ asset(ltrim($pagefindUrl, '/') . '/pagefind-ui.css') }}" />
@endif

{{-- Scolta config — sets window.scolta before scolta.js loads --}}
<script>
    window.scolta = @json([
        'scoring' => $config->toJsScoringConfig(),
        'endpoints' => [
            'expand' => url(config('scolta.route_prefix', 'api/scolta/v1') . '/expand-query'),
            'summarize' => url(config('scolta.route_prefix', 'api/scolta/v1') . '/summarize'),
            'followup' => url(config('scolta.route_prefix', 'api/scolta/v1') . '/followup'),
        ],
        'pagefindPath' => asset(ltrim($pagefindUrl, '/') . '/pagefind.js'),
        'siteName' => $config->siteName ?: config('app.name', 'Laravel'),
        'container' => '#scolta-search',
        'allowedLinkDomains' => [],
        'disclaimer' => '',
    ]);
</script>

{{-- Search container --}}
<div id="scolta-search" {{ $attributes }}></div>

{{-- Scolta JS (published asset) --}}
@if(file_exists(public_path('vendor/scolta/scolta.js')))
    <script src="{{ asset('vendor/scolta/scolta.js') }}" defer></script>
@else
    <!-- Scolta JS not found. Run: php artisan vendor:publish --tag=scolta-assets -->
@endif
