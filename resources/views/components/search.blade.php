{{--
    Scolta search component.

    Usage: <x-scolta::search />

    This is the Blade equivalent of WordPress's [scolta_search] shortcode
    and Drupal's scolta search block. Outputs the container div, includes
    scolta.js, and injects the configuration as window.scolta.

    Laravel's Blade components are elegant — they work anywhere in any
    Blade template, support attributes, and can be overridden by
    publishing the views to resources/views/vendor/scolta/.

    The component is intentionally minimal: a container div + config script.
    The actual search UI is rendered client-side by scolta.js, identical
    to how it works on WordPress and Drupal.
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

{{-- Scolta JS --}}
@php
    // Look for scolta.js in the vendor package path.
    $scoltaJsPath = base_path('vendor/tag1/scolta/assets/js/scolta.js');
    $scoltaJsUrl = file_exists($scoltaJsPath)
        ? asset('vendor/scolta/scolta.js')
        : null;
@endphp

@if($scoltaJsUrl)
    <script src="{{ $scoltaJsUrl }}" defer></script>
@else
    {{-- Fallback: try loading from public directory --}}
    @if(file_exists(public_path('vendor/scolta/scolta.js')))
        <script src="{{ asset('vendor/scolta/scolta.js') }}" defer></script>
    @endif
@endif
