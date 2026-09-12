<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />

    <title inertia>{{ config('app.name', 'Laravel') }}</title>

    @routes
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    @inertiaHead
</head>
<body class="font-sans antialiased">
    <script>
        (() => {
            const loaderSeenKey = 'solespace-app-loader-seen';
            const isDesktopViewport = typeof window.matchMedia === 'function'
                ? window.matchMedia('(min-width: 1280px)').matches
                : window.innerWidth >= 1280;

            if (isDesktopViewport) {
                return;
            }

            try {
                if (!window.sessionStorage.getItem(loaderSeenKey)) {
                    document.documentElement.classList.add('solespace-first-load');
                    document.documentElement.dataset.solespaceLoaderStartedAt = String(Date.now());
                    window.sessionStorage.setItem(loaderSeenKey, '1');
                }
            } catch {
                document.documentElement.classList.add('solespace-first-load');
                document.documentElement.dataset.solespaceLoaderStartedAt = String(Date.now());
            }
        })();
    </script>
    <div
        id="solespace-app-loader"
        class="solespace-app-loader"
        role="status"
        aria-live="polite"
        aria-label="Loading SoleSpace"
    >
        <div class="solespace-app-loader__content" aria-hidden="true">
            <div class="solespace-app-loader__wordmark" aria-hidden="true">
                <span>S</span>
                <span>O</span>
                <span>L</span>
                <span>E</span>
                <span>S</span>
                <span>P</span>
                <span>A</span>
                <span>C</span>
                <span>E</span>
            </div>
            <div class="solespace-app-loader__line"></div>
            <p class="solespace-app-loader__caption">Preparing your space</p>
        </div>
        <span class="solespace-app-loader__sr-only">Loading SoleSpace</span>
    </div>
    @inertia
</body>
</html>
