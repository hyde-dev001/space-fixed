<?php
if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { http_response_code(403); exit; }
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if (getenv('PARCEL_BROWSER_BUILD') === '1' && str_starts_with($uri, '/parcel-test-build/')) {
    $directory = realpath(dirname(__DIR__, 2).'/output/parcel-dead-end-build');
    $file = $directory ? realpath($directory.'/'.substr($uri, strlen('/parcel-test-build/'))) : false;
    if (! $file || ! str_starts_with(strtolower($file), strtolower($directory.DIRECTORY_SEPARATOR)) || ! is_file($file)) { http_response_code(404); exit; }
    header('Content-Type: '.match (pathinfo($file, PATHINFO_EXTENSION)) {
        'js' => 'application/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml',
        'woff2' => 'font/woff2', 'woff' => 'font/woff', default => 'application/octet-stream',
    });
    readfile($file); exit;
}
if ($uri !== '/' && is_file(dirname(__DIR__, 2).'/public'.$uri)) return false;
require __DIR__.'/failed_delivery_environment.php';
if (getenv('PARCEL_BROWSER_BUILD') === '1') {
    \Illuminate\Support\Facades\Vite::useHotFile(base_path('output/parcel-incident-browser/unused-hot'))
        ->useBuildDirectory('../output/parcel-dead-end-build')
        ->createAssetPathsUsing(fn ($path) => str_replace('../output/parcel-dead-end-build/', '/parcel-test-build/', $path));
} else {
    \Illuminate\Support\Facades\Vite::useHotFile(base_path('output/parcel-incident-browser/hot'));
}
$request = \Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
$response->headers->set('X-Recovery-Test-Isolation', 'local-mariadb');
$response->send();
$kernel->terminate($request, $response);
