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
    $outputDir = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));

    // The PHP indexer writes to {output_dir}/pagefind/ (nested layout).
    // The binary pipeline / Cloud flatten step writes directly to {output_dir}/ (flat layout).
    // Detect which layout exists so URLs point to the right location.
    if (file_exists($outputDir . '/pagefind/pagefind-entry.json')) {
        $indexDir = $outputDir . '/pagefind';
        $indexExists = true;
    } elseif (file_exists($outputDir . '/pagefind-entry.json')) {
        $indexDir = $outputDir;
        $indexExists = true;
    } else {
        $indexDir = $outputDir;
        $indexExists = false;
    }
@endphp

@if(!$indexExists)
    <div style="padding:20px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;margin:20px 0;">
        <p><strong>Scolta:</strong> Search index has not been built yet.</p>
        <p>Run <code>php artisan scolta:build</code> to build the index.</p>
    </div>
@else
    @php
        $config = app(\Tag1\ScoltaLaravel\Services\ScoltaAiService::class)->getConfig();

        // Convert filesystem path to URL path.
        $publicPath = public_path();
        $indexUrl = str_starts_with($indexDir, $publicPath)
            ? substr($indexDir, strlen($publicPath))
            : '/scolta-pagefind';

        $routePrefix = config('scolta.route_prefix', 'api/scolta/v1');
        $scoltaConfig = [
            'scoring' => $config->toJsScoringConfig(),
            'endpoints' => [
                'expand' => url($routePrefix . '/expand-query'),
                'summarize' => url($routePrefix . '/summarize'),
                'followup' => url($routePrefix . '/followup'),
            ],
            'wasmPath' => asset('vendor/scolta/wasm/scolta_core.js'),
            'pagefindPath' => asset(ltrim($indexUrl, '/') . '/pagefind.js'),
            'siteName' => $config->siteName ?: config('app.name', 'Laravel'),
            'container' => '#scolta-search',
            'allowedLinkDomains' => [],
            'disclaimer' => '',
            'currentLanguage' => strtolower(preg_replace('/[_-].*$/', '', app()->getLocale())),
        ];
    @endphp

    {{-- Pagefind UI CSS --}}
    @if(file_exists($indexDir . '/pagefind-ui.css'))
        <link rel="stylesheet" href="{{ asset(ltrim($indexUrl, '/') . '/pagefind-ui.css') }}" />
    @endif

    {{-- Scolta CSS from published assets --}}
    @if(file_exists(public_path('vendor/scolta/scolta.css')))
        <link rel="stylesheet" href="{{ asset('vendor/scolta/scolta.css') }}" />
    @endif

    {{-- Scolta config — sets window.scolta before scolta.js loads --}}
    <script>
        window.scolta = @json($scoltaConfig);
    </script>

    {{-- Search container --}}
    <div id="scolta-search" {{ $attributes }}></div>

    {{-- Optional attribution (opt-in via show_attribution config) --}}
    @if($config->showAttribution)
        <p class="scolta-attribution">Powered by Scolta</p>
    @endif

    {{-- Scolta JS from published assets --}}
    @if(file_exists(public_path('vendor/scolta/scolta.js')))
        <script src="{{ asset('vendor/scolta/scolta.js') }}" defer></script>
    @else
        <!-- Scolta JS not published. Run: php artisan vendor:publish --tag=scolta-assets -->
    @endif
@endif
