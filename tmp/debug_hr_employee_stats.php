<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $user = App\Models\User::where('email', 'hr.2@solespace.com')->firstOrFail();
    auth()->guard('user')->login($user);

    $controller = app(App\Http\Controllers\Erp\HR\EmployeeController::class);
    $response = $controller->stats(request());

    echo 'status=' . $response->getStatusCode() . PHP_EOL;
    echo $response->getContent() . PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
}
