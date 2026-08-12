<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Writer;
use PHPUnit\Framework\TestCase;
use PragmaRX\Google2FA\Google2FA;

final class MfaDependencyContractTest extends TestCase
{
    public function test_google2fa_and_bacon_qrcode_support_the_privileged_mfa_contract(): void
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey(32);
        $provisioningUri = $google2fa->getQRCodeUrl(
            'SoleSpace',
            'admin@example.test',
            $secret,
        );

        $renderer = new ImageRenderer(
            new RendererStyle(256),
            new SvgImageBackEnd(),
        );
        $svg = (new Writer($renderer))->writeString($provisioningUri);

        self::assertSame(32, mb_strlen($secret));
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        self::assertStringContainsString('otpauth://', $provisioningUri);
        self::assertNotSame('', trim($svg));
        self::assertStringContainsString('<svg', $svg);
    }
}
