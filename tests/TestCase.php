<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Creates the application.
     *
     * Laravel 12 uses Application::configure() (bootstrap/app.php returns the
     * fully-configured Application instance).  The base TestCase::setUp()
     * bootstraps the HTTP kernel at the correct point in the lifecycle, so we
     * must NOT call $app->make(Kernel::class)->bootstrap() here – doing so
     * triggers RoutingServiceProvider before the test request is bound,
     * causing a UrlGenerator null-request TypeError.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication(): \Illuminate\Foundation\Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        // Pre-bind a stub request so RoutingServiceProvider::registerUrlGenerator()
        // receives a non-null Request (required by the stricter Laravel 12 type hint).
        // The actual test request replaces this binding the moment a test call is made.
        $app->instance('request', \Illuminate\Http\Request::create('/'));

        $app->make(\Illuminate\Contracts\Http\Kernel::class)->bootstrap();

        return $app;
    }
}
