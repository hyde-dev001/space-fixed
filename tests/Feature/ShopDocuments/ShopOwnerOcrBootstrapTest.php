<?php

declare(strict_types=1);

namespace Tests\Feature\ShopDocuments;

use App\Models\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ShopOwnerOcrBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private string $originalPublicPath;

    private ?string $temporaryPublicPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->originalPublicPath = app()->publicPath();
    }

    protected function tearDown(): void
    {
        app()->usePublicPath($this->originalPublicPath);
        if ($this->temporaryPublicPath !== null
            && str_starts_with($this->temporaryPublicPath, sys_get_temp_dir())) {
            File::deleteDirectory($this->temporaryPublicPath);
        }
        parent::tearDown();
    }

    public function test_fresh_registration_receives_validated_same_origin_local_asset_paths(): void
    {
        $this->installManifest();

        $this->get('/shop-owner-register')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('UserSide/Auth/ShopOwnerRegistration')
            ->where('ocrBootstrap.available', true)
            ->where('ocrBootstrap.assets.workerPath', '/ocr/tesseract-7.0.0-core-7.0.0-eng-1.0.0/worker.min.js')
            ->where('ocrBootstrap.assets.corePath', '/ocr/tesseract-7.0.0-core-7.0.0-eng-1.0.0/core/')
            ->where('ocrBootstrap.assets.langPath', '/ocr/tesseract-7.0.0-core-7.0.0-eng-1.0.0/lang/')
            ->where('ocrBootstrap.timezone', config('app.shop_timezone', 'Asia/Manila'))
            ->where('ocrBootstrap.referenceDay', app(\App\Services\ShopOwnerDocumentScreeningService::class)->referenceDay())
        );
    }

    public function test_signed_resubmission_receives_the_same_optional_bootstrap(): void
    {
        $this->installManifest();
        $owner = ShopOwner::factory()->rejected()->create();
        $url = URL::temporarySignedRoute(
            'shop-owner.resubmission.form',
            now()->addMinutes(5),
            ['shopOwner' => $owner->id],
        );

        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('UserSide/Auth/ShopOwnerRegistration')
            ->where('resubmission.isResubmission', true)
            ->where('ocrBootstrap.available', true)
            ->where('ocrBootstrap.assets.workerPath', '/ocr/tesseract-7.0.0-core-7.0.0-eng-1.0.0/worker.min.js')
        );
    }

    public function test_local_paths_keep_the_application_base_path_and_never_expose_an_asset_host(): void
    {
        $this->installManifest();
        URL::forceRootUrl('https://solespace.example/portal');

        try {
            $bootstrap = app(\App\Services\ShopOwnerDocumentScreeningService::class)->browserBootstrap();
        } finally {
            URL::forceRootUrl(null);
        }

        self::assertTrue($bootstrap['available']);
        self::assertSame(
            '/portal/ocr/tesseract-7.0.0-core-7.0.0-eng-1.0.0/worker.min.js',
            $bootstrap['assets']['workerPath'],
        );
        self::assertStringNotContainsString('solespace.example', $bootstrap['assets']['workerPath']);
    }

    public function test_missing_or_invalid_manifest_degrades_to_unavailable_without_page_failure(): void
    {
        $root = $this->newPublicRoot();

        $this->get('/shop-owner-register')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('ocrBootstrap.available', false)
            ->where('ocrBootstrap.assets', null)
        );

        $manifest = $root . DIRECTORY_SEPARATOR . 'ocr' . DIRECTORY_SEPARATOR . 'manifest.json';
        File::ensureDirectoryExists(dirname($manifest));
        File::put($manifest, json_encode([
            'schema_version' => 1,
            'bundle' => 'tesseract-7.0.0-core-7.0.0-eng-1.0.0',
            'paths' => ['worker' => '//external.example/worker.js'],
            'files' => [],
        ], JSON_THROW_ON_ERROR));

        $this->get('/shop-owner-register')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('ocrBootstrap.available', false)
            ->where('ocrBootstrap.assets', null)
        );
    }

    private function installManifest(): void
    {
        $root = $this->newPublicRoot();
        $bundle = 'tesseract-7.0.0-core-7.0.0-eng-1.0.0';
        $prefix = 'ocr/' . $bundle . '/';
        $files = [
            $prefix . 'worker.min.js' => str_repeat('a', 64),
            $prefix . 'core/tesseract-core.wasm' => str_repeat('b', 64),
            $prefix . 'core/tesseract-core.wasm.js' => str_repeat('d', 64),
            $prefix . 'lang/eng.traineddata.gz' => str_repeat('c', 64),
        ];

        foreach (array_keys($files) as $relativePath) {
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            File::ensureDirectoryExists(dirname($path));
            File::put($path, 'synthetic local asset');
        }

        $manifestPath = $root . DIRECTORY_SEPARATOR . 'ocr' . DIRECTORY_SEPARATOR . 'manifest.json';
        File::ensureDirectoryExists(dirname($manifestPath));
        File::put($manifestPath, json_encode([
            'schema_version' => 1,
            'bundle' => $bundle,
            'paths' => [
                'worker' => $prefix . 'worker.min.js',
                'core' => $prefix . 'core/',
                'lang' => $prefix . 'lang/',
            ],
            'files' => $files,
        ], JSON_THROW_ON_ERROR));
    }

    private function newPublicRoot(): string
    {
        $this->temporaryPublicPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'solespace-ocr-' . Str::uuid();
        File::ensureDirectoryExists($this->temporaryPublicPath);
        app()->usePublicPath($this->temporaryPublicPath);

        return $this->temporaryPublicPath;
    }
}
