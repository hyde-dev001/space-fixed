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
            <p class="solespace-app-loader__eyebrow">SOLESPACE</p>
            <div class="solespace-app-loader__shoe">
                <svg viewBox="0 0 240 128" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M24 82.5C42.5 81.7 59 73.4 69.2 56.7L80.8 37.8C83.1 34 88.5 34.4 90.9 38.3L104.2 59.8C111 70.7 120.3 78.2 132.8 82.3L204.1 105.7C211.8 108.2 216.5 114.8 215.2 120.8H34.2C22.9 120.8 16.4 112.3 18.9 102.7C19.9 98.6 21.6 90.9 24 82.5Z" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                    <path d="M69.2 56.7C78.8 63.9 91.1 68.3 104.2 69.3" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                    <path d="M27.4 91.6C54.3 96.1 79.5 91.8 98.1 80.1" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                    <path d="M116 87.1C143.1 92.4 173.2 99.1 204.1 105.7" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                    <path d="M83.4 41.8L96.2 48.8M78.2 50.3L91.5 57.6M73.1 58.7L86.4 65.8" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                </svg>
            </div>
            <div class="solespace-app-loader__line"></div>
            <p class="solespace-app-loader__caption">Preparing your space</p>
        </div>
        <span class="solespace-app-loader__sr-only">Loading SoleSpace</span>
    </div>
    @inertia
</body>
</html>
