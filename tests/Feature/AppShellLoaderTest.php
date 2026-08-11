<?php

namespace Tests\Feature;

use Tests\TestCase;

class AppShellLoaderTest extends TestCase
{
    public function test_loader_wordmark_stays_inside_the_styled_content_region(): void
    {
        $markup = file_get_contents(resource_path('views/app.blade.php'));

        self::assertIsString($markup);
        self::assertStringContainsString('<div class="solespace-app-loader__content" aria-hidden="true">', $markup);
        self::assertStringContainsString('<div class="solespace-app-loader__wordmark" aria-hidden="true">', $markup);
    }

    public function test_loader_uses_the_approved_galaxy_reveal_styles(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));

        self::assertIsString($styles);
        self::assertStringContainsString('solespace-loader-letter', $styles);
        self::assertStringContainsString('--loader-origin-x', $styles);
        self::assertStringContainsString('radial-gradient', $styles);
        self::assertStringContainsString('will-change: transform, opacity;', $styles);
        self::assertStringContainsString('backface-visibility: hidden;', $styles);
        self::assertStringContainsString('contain: layout paint;', $styles);
        self::assertStringContainsString('animation: solespace-loader-letter 1.45s cubic-bezier(0.22, 1, 0.36, 1) 1 both;', $styles);
    }

    public function test_loader_uses_a_white_surface_with_readable_light_gray_text(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));
        $loaderStart = strpos($styles, '.solespace-app-loader {');
        $loaderEnd = strpos($styles, '@layer base', $loaderStart);
        $loaderStyles = substr($styles, $loaderStart, $loaderEnd - $loaderStart);

        self::assertIsString($styles);
        self::assertIsInt($loaderStart);
        self::assertIsInt($loaderEnd);
        self::assertStringContainsString('background: #ffffff;', $loaderStyles);
        self::assertStringContainsString('color: #667085;', $loaderStyles);
    }

    public function test_customer_landing_and_download_surfaces_keep_dark_mode_content_readable(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));
        $landing = file_get_contents(resource_path('js/Pages/UserSide/Products/LandingPage.tsx'));
        $download = file_get_contents(resource_path('js/Pages/UserSide/app/apk.tsx'));

        self::assertIsString($styles);
        self::assertIsString($landing);
        self::assertIsString($download);
        self::assertStringContainsString('scrollbar-width: none;', $styles);
        self::assertStringContainsString('landing-primary-cta', $landing);
        self::assertStringContainsString('bg-[#ffffff] text-[#0f172a]', $landing);
        self::assertStringContainsString('dark:text-white', $download);
    }
}
