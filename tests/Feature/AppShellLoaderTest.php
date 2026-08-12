<?php

namespace Tests\Feature;

use Tests\TestCase;

class AppShellLoaderTest extends TestCase
{
    private function readProjectFile(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/'.$relativePath);

        self::assertIsString($contents);

        return $contents;
    }

    public function test_loader_wordmark_stays_inside_the_styled_content_region(): void
    {
        $markup = $this->readProjectFile('resources/views/app.blade.php');

        self::assertStringContainsString('<div class="solespace-app-loader__content" aria-hidden="true">', $markup);
        self::assertStringContainsString('<div class="solespace-app-loader__wordmark" aria-hidden="true">', $markup);
    }

    public function test_loader_uses_the_approved_galaxy_reveal_styles(): void
    {
        $styles = $this->readProjectFile('resources/css/app.css');
        $loaderStart = strpos($styles, '.solespace-app-loader {');
        $loaderEnd = strpos($styles, '@layer base', $loaderStart);
        $loaderStyles = substr($styles, $loaderStart, $loaderEnd - $loaderStart);

        self::assertIsInt($loaderStart);
        self::assertIsInt($loaderEnd);
        self::assertStringContainsString('solespace-loader-letter', $styles);
        self::assertStringContainsString('--loader-origin-x', $styles);
        self::assertStringContainsString('radial-gradient', $styles);
        self::assertStringContainsString('backface-visibility: hidden;', $styles);
        self::assertStringContainsString('contain: layout paint;', $styles);
        self::assertStringContainsString('animation: solespace-loader-letter 720ms cubic-bezier(0.22, 1, 0.36, 1) 1 both;', $loaderStyles);
        self::assertStringContainsString('transform: translate3d(var(--loader-origin-x), var(--loader-origin-y), 0);', $loaderStyles);
        self::assertStringContainsString('will-change: auto;', $loaderStyles);
        self::assertStringContainsString('@media (max-width: 640px) and (pointer: coarse)', $loaderStyles);
        self::assertStringContainsString('animation: solespace-loader-wordmark 560ms cubic-bezier(0.22, 1, 0.36, 1) 1 both;', $loaderStyles);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $loaderStyles);
        self::assertLessThanOrEqual(2, substr_count($loaderStyles, 'radial-gradient('));
        self::assertStringContainsString('white-space: nowrap;', $styles);
        self::assertStringContainsString('text-shadow: none;', $loaderStyles);
        self::assertStringNotContainsString('rotate(', $loaderStyles);
        self::assertStringNotContainsString('scale(', $loaderStyles);
        self::assertStringNotContainsString('filter:', $loaderStyles);
        self::assertStringNotContainsString('box-shadow:', $loaderStyles);
        self::assertStringNotContainsString('background-position', $loaderStyles);
        self::assertStringNotContainsString('animation-delay:', $loaderStyles);
    }

    public function test_loader_uses_a_white_surface_with_readable_light_gray_text(): void
    {
        $styles = $this->readProjectFile('resources/css/app.css');
        $loaderStart = strpos($styles, '.solespace-app-loader {');
        $loaderEnd = strpos($styles, '@layer base', $loaderStart);
        $loaderStyles = substr($styles, $loaderStart, $loaderEnd - $loaderStart);

        self::assertIsInt($loaderStart);
        self::assertIsInt($loaderEnd);
        self::assertStringContainsString('background: #ffffff;', $loaderStyles);
        self::assertStringContainsString('color: #667085;', $loaderStyles);
    }

    public function test_customer_landing_and_download_surfaces_keep_dark_mode_content_readable(): void
    {
        $styles = $this->readProjectFile('resources/css/app.css');
        $landing = $this->readProjectFile('resources/js/Pages/UserSide/Products/LandingPage.tsx');
        $download = $this->readProjectFile('resources/js/Pages/UserSide/app/apk.tsx');

        self::assertStringContainsString('scrollbar-width: none;', $styles);
        self::assertStringContainsString('landing-primary-cta', $landing);
        self::assertStringContainsString('bg-[#ffffff] text-[#0f172a]', $landing);
        self::assertStringContainsString('dark:bg-[#16233b]', $landing);
        self::assertStringContainsString('dark:text-white', $download);
    }

    public function test_customer_dark_mode_uses_subtle_surface_borders_and_order_date_headers(): void
    {
        $styles = $this->readProjectFile('resources/css/app.css');
        $orders = $this->readProjectFile('resources/js/Pages/UserSide/Orders/MyOrders.tsx');
        $repairs = $this->readProjectFile('resources/js/Pages/UserSide/Repairs/myRepairs.tsx');

        self::assertStringContainsString('[class~="border-gray-100"]', $styles);
        self::assertStringContainsString('[class*="border-white/"]', $styles);
        self::assertStringContainsString('userside-order-date-header', $styles);
        self::assertStringContainsString('userside-order-date-header', $orders);
        self::assertStringContainsString('userside-order-date-header', $repairs);
    }

    public function test_user_auth_pages_expose_scoped_dark_mode_hooks(): void
    {
        $styles = $this->readProjectFile('resources/css/app.css');
        $login = $this->readProjectFile('resources/js/Pages/UserSide/Auth/UserLogin.tsx');
        $register = $this->readProjectFile('resources/js/Pages/UserSide/Auth/Register.tsx');
        $forgot = $this->readProjectFile('resources/js/Pages/UserSide/Auth/Forgot.tsx');
        $passwordFlowPages = [
            $this->readProjectFile('resources/js/Pages/UserSide/Auth/NewPassword.tsx'),
            $this->readProjectFile('resources/js/Pages/UserSide/Auth/Otp.tsx'),
            $this->readProjectFile('resources/js/Pages/UserSide/Auth/VerificationNotice.tsx'),
            $this->readProjectFile('resources/js/Pages/UserSide/Auth/VerifyEmail.tsx'),
            $this->readProjectFile('resources/js/Pages/UserSide/Auth/ShopOwnerTwoFactor.tsx'),
        ];

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
        $loader = $this->readProjectFile('resources/js/utils/appLoader.ts');
        $landing = $this->readProjectFile('resources/js/Pages/UserSide/Products/LandingPage.tsx');

        self::assertStringContainsString('APP_LOADER_READY_CLASS', $loader);
        self::assertStringContainsString('solespace-app-ready', $loader);
        self::assertStringContainsString('landing-hero-motion', $landing);
        self::assertStringContainsString('html.solespace-first-load:not(.solespace-app-ready) .landing-hero-motion', $landing);
        self::assertStringContainsString('animation-play-state: paused', $landing);
    }

    public function test_current_worktree_marks_the_hero_copy_and_actions_for_loader_handoff(): void
    {
        $landing = $this->readProjectFile('resources/js/Pages/UserSide/Products/LandingPage.tsx');

        self::assertStringContainsString('hero-headline-line hero-line-1 landing-hero-motion', $landing);
        self::assertStringContainsString('hero-description landing-hero-motion', $landing);
        self::assertStringContainsString('landing-hero-motion hero-actions', $landing);
    }
}
