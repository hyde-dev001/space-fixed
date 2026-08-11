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
        $loaderStart = strpos($styles, '.solespace-app-loader {');
        $loaderEnd = strpos($styles, '@layer base', $loaderStart);
        $loaderStyles = substr($styles, $loaderStart, $loaderEnd - $loaderStart);

        self::assertIsString($styles);
        self::assertIsInt($loaderStart);
        self::assertIsInt($loaderEnd);
        self::assertStringContainsString('solespace-loader-letter', $styles);
        self::assertStringContainsString('--loader-origin-x', $styles);
        self::assertStringContainsString('radial-gradient', $styles);
        self::assertStringContainsString('will-change: transform;', $loaderStyles);
        self::assertStringContainsString('backface-visibility: hidden;', $styles);
        self::assertStringContainsString('contain: layout paint;', $styles);
        self::assertStringContainsString('animation: solespace-loader-letter 840ms linear 1 both;', $loaderStyles);
        self::assertStringContainsString('white-space: nowrap;', $styles);
        self::assertStringContainsString('text-shadow: none;', $loaderStyles);
        self::assertStringNotContainsString('animation-delay:', $loaderStyles);
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
        self::assertStringContainsString('dark:bg-[#16233b]', $landing);
        self::assertStringContainsString('dark:text-white', $download);
    }

    public function test_customer_dark_mode_uses_subtle_surface_borders_and_order_date_headers(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));
        $orders = file_get_contents(resource_path('js/Pages/UserSide/Orders/MyOrders.tsx'));
        $repairs = file_get_contents(resource_path('js/Pages/UserSide/Repairs/myRepairs.tsx'));

        self::assertIsString($styles);
        self::assertIsString($orders);
        self::assertIsString($repairs);
        self::assertStringContainsString('[class~="border-gray-100"]', $styles);
        self::assertStringContainsString('[class*="border-white/"]', $styles);
        self::assertStringContainsString('userside-order-date-header', $styles);
        self::assertStringContainsString('userside-order-date-header', $orders);
        self::assertStringContainsString('userside-order-date-header', $repairs);
    }

    public function test_user_auth_pages_expose_scoped_dark_mode_hooks(): void
    {
        $styles = file_get_contents(resource_path('css/app.css'));
        $login = file_get_contents(resource_path('js/Pages/UserSide/Auth/UserLogin.tsx'));
        $register = file_get_contents(resource_path('js/Pages/UserSide/Auth/Register.tsx'));
        $forgot = file_get_contents(resource_path('js/Pages/UserSide/Auth/Forgot.tsx'));
        $passwordFlowPages = [
            file_get_contents(resource_path('js/Pages/UserSide/Auth/NewPassword.tsx')),
            file_get_contents(resource_path('js/Pages/UserSide/Auth/Otp.tsx')),
            file_get_contents(resource_path('js/Pages/UserSide/Auth/VerificationNotice.tsx')),
            file_get_contents(resource_path('js/Pages/UserSide/Auth/VerifyEmail.tsx')),
            file_get_contents(resource_path('js/Pages/UserSide/Auth/ShopOwnerTwoFactor.tsx')),
        ];

        self::assertIsString($styles);
        self::assertIsString($login);
        self::assertIsString($register);
        self::assertIsString($forgot);
        self::assertStringContainsString('html.userside-dark #app .userside-auth-page', $styles);

        foreach ([$login, $register, $forgot] as $page) {
            self::assertStringContainsString('userside-auth-page', $page);
            self::assertStringContainsString('userside-auth-card', $page);
            self::assertStringContainsString('userside-auth-primary', $page);
        }

        foreach ($passwordFlowPages as $page) {
            self::assertIsString($page);
            self::assertStringContainsString('userside-auth-page', $page);
            self::assertStringContainsString('userside-auth-card', $page);
        }
    }

    public function test_landing_hero_waits_for_the_first_open_loader(): void
    {
        $loader = file_get_contents(resource_path('js/utils/appLoader.ts'));
        $landing = file_get_contents(resource_path('js/Pages/UserSide/Products/LandingPage.tsx'));

        self::assertIsString($loader);
        self::assertIsString($landing);
        self::assertStringContainsString('APP_LOADER_READY_CLASS', $loader);
        self::assertStringContainsString('solespace-app-ready', $loader);
        self::assertStringContainsString('landing-hero-motion', $landing);
        self::assertStringContainsString('html.solespace-first-load:not(.solespace-app-ready) .landing-hero-motion', $landing);
        self::assertStringContainsString('animation-play-state: paused', $landing);
    }
}
