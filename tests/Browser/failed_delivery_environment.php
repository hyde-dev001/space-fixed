<?php
// Disposable loopback-only test environment. Never loads a production environment file.
$root = dirname(__DIR__, 2);
$testDirectory = $root.'/output/failed-delivery-recovery';
foreach (['APP_ENV' => 'local', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('r', 32)),
    'APP_URL' => 'http://127.0.0.1:8027', 'APP_CONFIG_CACHE' => $testDirectory.'/unused-config.php',
    'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '33317', 'DB_DATABASE' => 'solespace_recovery_test',
    'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'recovery_test_session',
    'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'] as $key => $value) {
    putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value;
}
foreach (['logs', 'framework/sessions', 'framework/views', 'framework/cache/data', 'app/private', 'app/public'] as $directory) {
    if (!is_dir($testDirectory.'/storage/'.$directory)) mkdir($testDirectory.'/storage/'.$directory, 0777, true);
}
require_once $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($testDirectory.'/storage');
$app->instance('request', Illuminate\Http\Request::create('http://127.0.0.1:8027'));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
// Disposable browser journeys must never reach a live payment or other external API.
Illuminate\Support\Facades\Http::preventStrayRequests();
// The disposable browser app must not change the developer's public/hot file.
Illuminate\Support\Facades\Vite::useHotFile($testDirectory.'/batch-interruption/hot');
if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'solespace_recovery_test'
    || (int) config('database.connections.mysql.port') !== 33317 || config('database.connections.mysql.host') !== '127.0.0.1') {
    throw new RuntimeException('Disposable recovery database isolation failed.');
}

$actualDataDirectory = Illuminate\Support\Facades\DB::selectOne('SELECT @@datadir AS path')->path;
if (strtolower((string) realpath(rtrim($actualDataDirectory, '/\\'))) !== strtolower((string) realpath($testDirectory.'/mariadb-data'))) {
    throw new RuntimeException('The database server does not use the disposable worktree data directory.');
}
