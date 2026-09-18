<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Vite;
use Tests\TestCase;

class ProductionViteAssetTest extends TestCase
{
    public function test_production_ignores_the_public_hot_marker(): void
    {
        $originalEnvironment = $this->app->environment();
        $vite = $this->app->make(Vite::class);
        $originalHotFile = $vite->hotFile();

        try {
            $this->app->detectEnvironment(static fn (): string => 'production');
            (new AppServiceProvider($this->app))->boot();

            $this->assertSame(
                $this->app->storagePath('framework/vite.hot'),
                $vite->hotFile(),
            );
        } finally {
            $vite->useHotFile($originalHotFile);
            $this->app->detectEnvironment(static fn (): string => $originalEnvironment);
        }
    }
}
