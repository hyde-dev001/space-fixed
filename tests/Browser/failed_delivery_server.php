<?php
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { http_response_code(403); exit; }
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if ($uri !== '/' && is_file(dirname(__DIR__, 2).'/public'.$uri)) return false;
require __DIR__.'/failed_delivery_environment.php';
$request = Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
$response->headers->set('X-Recovery-Test-Isolation', 'local-mariadb');
$response->send();
$kernel->terminate($request, $response);
