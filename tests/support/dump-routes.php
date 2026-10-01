<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route;

/**
 * Prints the application's route table as normalized JSON (method, uri,
 * controller action with short class name, middleware).
 *
 *   php tests/support/dump-routes.php > tests/fixtures/routes-legacy.json
 *
 * Used to pin the legacy route table (see tests/Feature/Api/LegacyRoutesTest.php).
 */
require __DIR__.'/../../vendor/autoload.php';

putenv('APP_KEY=base64:a2tra2tra2tra2tra2tra2tra2tra2tra2tra2tra2s=');
putenv('APP_ENV=testing');
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$rows = [];
foreach (Route::getRoutes() as $route) {
    foreach ($route->methods() as $method) {
        if ($method === 'HEAD') {
            continue;
        }
        $action = $route->getActionName();
        $action = $action === 'Closure' ? 'Closure' : preg_replace('/^.*\\\\([A-Za-z]+@[A-Za-z]+)$/', '$1', $action);
        $rows[] = [
            'method' => $method,
            'uri' => $route->uri(),
            'action' => $action,
            'middleware' => array_values(array_map(
                fn ($m) => is_string($m) ? $m : 'Closure',
                $route->gatherMiddleware()
            )),
        ];
    }
}

usort($rows, fn ($a, $b) => [$a['uri'], $a['method']] <=> [$b['uri'], $b['method']]);

echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
