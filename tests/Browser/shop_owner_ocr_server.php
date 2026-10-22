<?php

// Local verification entry point, outside public/. Normal HTTP middleware and
// controllers still handle every request; no authentication bypass is added.
if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__, 2);
$testDirectory = $root.'/output/ocr-relevance-fix';
$database = $testDirectory.'/browser.sqlite';
if (! is_file($database)) {
    throw new RuntimeException('Prepare a disposable local SQLite fixture before starting this test server.');
}

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if ($uri !== '/' && is_file($root.'/public'.$uri)) {
    return false;
}

foreach ([
    'APP_ENV' => 'local',
    'APP_URL' => 'http://127.0.0.1:8017',
    'APP_CONFIG_CACHE' => $testDirectory.'/unused-config-cache.php',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $database,
    'SESSION_DRIVER' => 'file',
    'SESSION_COOKIE' => 'ocr_relevance_test_session',
    'CACHE_STORE' => 'file',
    'MAIL_MAILER' => 'smtp',
    'MAIL_HOST' => '127.0.0.1',
    'MAIL_PORT' => '1025',
    'MAIL_SCHEME' => 'smtp',
    'MAIL_ENCRYPTION' => '(null)',
    'MAIL_USERNAME' => '(null)',
    'MAIL_PASSWORD' => '(null)',
    'MAIL_URL' => '(null)',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $database
    || config('mail.default') !== 'smtp' || config('mail.mailers.smtp.host') !== '127.0.0.1'
    || (int) config('mail.mailers.smtp.port') !== 1025) {
    throw new RuntimeException('Local OCR verification isolation is not configured.');
}

$request = Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
$response->headers->set('X-Ocr-Test-Isolation', 'local-sqlite-smtp');
$response->send();
$kernel->terminate($request, $response);
