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
            'hideEmptyFacets' => $config->hideEmptyFacets,
            // Read from Laravel config and clamped here rather than through
            // ScoltaConfig, so the setting works against any scolta-php in the
            // supported ^1.2 range: the property landed in 1.2.1, but the
            // behaviour lives entirely in the browser bundle this package
            // publishes. An unrecognized value becomes 'eager', as the bundle
            // would also do.
            'facetMode' => in_array(config('scolta.facet_mode', 'eager'), ['eager', 'deferred', 'disabled'], true)
                ? config('scolta.facet_mode', 'eager')
                : 'eager',
            // Same local-read pattern as facetMode: ScoltaConfig::$labels
            // landed in scolta-php 1.5.0, but the behaviour lives entirely in
            // the browser bundle this package publishes, so the overrides work
            // against any scolta-php in the supported range. Non-string keys
            // and non-string or empty values are dropped, as the bundle would
            // also do; an older bundle without the labels map ignores the key.
            'labels' => array_filter(
                (array) config('scolta.labels', []),
                fn ($value, $key) => is_string($key) && is_string($value) && $value !== '',
                ARRAY_FILTER_USE_BOTH
            ),
            'filterFieldDescriptions' => $config->filterFieldDescriptions,
            // Search as you type. Ten top-level keys, not scoring keys. The
            // suggestion action goes through the normalizer so an unrecognized
            // configured value reaches the browser as 'navigate'.
            'saytEnabled' => $config->saytEnabled,
            'saytMinChars' => $config->saytMinChars,
            'saytDebounceMs' => $config->saytDebounceMs,
            'saytMaxSuggestions' => $config->saytMaxSuggestions,
            'saytRecentSearches' => $config->saytRecentSearches,
            'saytMaxRecent' => $config->saytMaxRecent,
            'saytExpand' => $config->saytExpand,
            'saytExpandPerMinute' => $config->saytExpandPerMinute,
            'saytExpansionDelayMs' => $config->saytExpansionDelayMs,
            'saytSuggestionAction' => $config->normalizedSaytSuggestionAction(),
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

    {{-- Scolta CSS from published assets. Cache-bust on the published file's
         mtime so a deploy that replaces the asset is picked up on a normal
         reload — asset() alone emits no version token, so HTTP caches would
         otherwise keep serving the old CSS. --}}
    @if(file_exists(public_path('vendor/scolta/scolta.css')))
        <link rel="stylesheet" href="{{ asset('vendor/scolta/scolta.css') . '?v=' . filemtime(public_path('vendor/scolta/scolta.css')) }}" />
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

    {{-- Scolta JS from published assets. Cache-bust on the published file's
         mtime so a deploy that replaces the asset is picked up on a normal
         reload — asset() alone emits no version token, so HTTP caches would
         otherwise keep serving the old JS. --}}
    @if(file_exists(public_path('vendor/scolta/scolta.js')))
        <script src="{{ asset('vendor/scolta/scolta.js') . '?v=' . filemtime(public_path('vendor/scolta/scolta.js')) }}" defer></script>
    @else
        <!-- Scolta JS not published. Run: php artisan vendor:publish --tag=scolta-assets -->
    @endif
@endif
