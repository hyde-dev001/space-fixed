<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ErpRouteCatalog;
use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as RouteFacade;

final class ErpRouteMatrixCommand extends Command
{
    protected $signature = 'erp:route-matrix {--write : Write the generated matrix to the architecture docs}';

    protected $description = 'Generate the reviewable shop owner ERP route capability matrix.';

    public function __construct(private readonly ErpRouteCatalog $catalog)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $loadedRoutes = $this->loadedRoutes();
        $errors = $this->validateCatalog($loadedRoutes);

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $matrix = $this->renderMatrix($loadedRoutes);
        if ((bool) $this->option('write')) {
            File::put(base_path('docs/architecture/shop-owner-erp-route-matrix.md'), $matrix);
            $this->info('Wrote docs/architecture/shop-owner-erp-route-matrix.md.');
        }

        $this->line($matrix);

        return self::SUCCESS;
    }

    /**
     * @return array<string, array<int, Route>>
     */
    private function loadedRoutes(): array
    {
        $routes = [];

        foreach (RouteFacade::getRoutes() as $route) {
            $name = (string) $route->getName();
            if ($name === '') {
                continue;
            }

            $routes[$name][] = $route;
        }

        return $routes;
    }

    /**
     * @param  array<string, array<int, Route>>  $loadedRoutes
     * @return array<int, string>
     */
    private function validateCatalog(array $loadedRoutes): array
    {
        $errors = [];
        $supportedModes = config('shop_modules.supported_gate_modes', []);
        $moduleKeys = array_keys(config('shop_modules.modules', []));

        foreach ($this->catalog->all() as $routeName => $entry) {
            if (! isset($loadedRoutes[$routeName])) {
                $errors[] = "configured route is not loaded: {$routeName}";
                continue;
            }

            if (! is_array($entry['methods'] ?? null) || $this->methodsFor($loadedRoutes[$routeName]) !== $this->normalizeMethods($entry['methods'])) {
                $errors[] = "configured methods do not match the loaded route: {$routeName}";
            }

            if (! in_array($entry['classification'] ?? null, ['core', 'module', 'excluded'], true)) {
                $errors[] = "invalid route classification: {$routeName}";
            }

            if (($entry['classification'] ?? null) === 'module'
                && (($entry['module_keys'] ?? []) === [] || ! in_array($entry['mode'] ?? null, $supportedModes, true))) {
                $errors[] = "module route has no valid module gate: {$routeName}";
            }

            if (($entry['classification'] ?? null) !== 'module' && ($entry['module_keys'] ?? []) !== []) {
                $errors[] = "core/excluded route has module keys: {$routeName}";
            }

            if (($entry['owner_access'] ?? null) === 'denied' && trim((string) ($entry['owner_denial_reason'] ?? '')) === '') {
                $errors[] = "owner-denied route has no stable denial reason: {$routeName}";
            }

            if (($entry['owner_access'] ?? null) === 'allowed') {
                if (($entry['actor_persistence'] ?? 'not_applicable') === 'not_applicable' && $this->isMutation($entry)) {
                    $errors[] = "allowed mutation has no actor persistence decision: {$routeName}";
                }

                if (($entry['supporting_routes'] ?? []) === []) {
                    $errors[] = "owner-capable component has no supporting API list: {$routeName}";
                }

                if ($this->isMutation($entry) && trim((string) ($entry['domain_rule'] ?? '')) === '') {
                    $errors[] = "owner-capable mutation has no descriptive domain rule: {$routeName}";
                }
            }

            if (! in_array($entry['risk_tier'] ?? null, ['normal', 'sensitive', 'financial'], true)) {
                $errors[] = "route has no valid risk tier: {$routeName}";
            }

            $this->validatePair($routeName, $entry, $loadedRoutes, $errors);

            if (($entry['actor_persistence'] ?? null) === 'not_applicable'
                && ($entry['owner_access'] ?? null) === 'allowed'
                && ($entry['action'] ?? null) !== 'view') {
                $errors[] = "allowed owner operation has not_applicable persistence: {$routeName}";
            }

            if (array_diff($entry['module_keys'] ?? [], $moduleKeys) !== []) {
                $errors[] = "route references an unknown module key: {$routeName}";
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, array<int, Route>>  $loadedRoutes
     * @param  array<int, string>  $errors
     */
    private function validatePair(string $routeName, array $entry, array $loadedRoutes, array &$errors): void
    {
        $pairedRouteName = $entry['paired_route'] ?? null;
        if (! is_string($pairedRouteName)) {
            return;
        }

        $pairedEntry = $this->catalog->entry($pairedRouteName);
        if ($pairedEntry === null || ! isset($loadedRoutes[$pairedRouteName])) {
            $errors[] = "paired route is missing or not loaded: {$routeName}";

            return;
        }

        if (($pairedEntry['paired_route'] ?? null) !== $routeName) {
            $errors[] = "paired route is not bidirectional: {$routeName}";
        }

        if (($pairedEntry['action'] ?? null) !== ($entry['action'] ?? null)) {
            $errors[] = "paired route action classification differs: {$routeName}";
        }

        if (! $this->parametersMatch($loadedRoutes[$routeName], $loadedRoutes[$pairedRouteName])) {
            $errors[] = "paired route parameters are incompatible: {$routeName}";
        }
    }

    /**
     * @param  array<int, Route>  $routes
     */
    private function methodsFor(array $routes): array
    {
        $methods = [];
        foreach ($routes as $route) {
            $methods = array_merge($methods, $route->methods());
        }

        return $this->normalizeMethods($methods);
    }

    /**
     * @param  array<int, string>  $methods
     */
    private function normalizeMethods(array $methods): array
    {
        $methods = array_values(array_unique(array_map('strtoupper', array_diff($methods, ['HEAD']))));
        sort($methods);

        return $methods;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function isMutation(array $entry): bool
    {
        return ($entry['action'] ?? 'view') !== 'view';
    }

    /**
     * @param  array<int, Route>  $left
     * @param  array<int, Route>  $right
     */
    private function parametersMatch(array $left, array $right): bool
    {
        $leftParameters = array_map(static fn (Route $route): array => $route->parameterNames(), $left);
        $rightParameters = array_map(static fn (Route $route): array => $route->parameterNames(), $right);

        sort($leftParameters);
        sort($rightParameters);

        return $leftParameters === $rightParameters;
    }

    /**
     * @param  array<string, array<int, Route>>  $loadedRoutes
     */
    private function renderMatrix(array $loadedRoutes): string
    {
        $rows = [];
        foreach ($this->catalog->all() as $routeName => $entry) {
            foreach ($this->normalizeMethods($entry['methods']) as $method) {
                $ownerExposure = $this->catalog->ownerExposure($method, $routeName);
                $loadedRoute = $loadedRoutes[$routeName][0];
                $businessTypes = $entry['business_types'] === [] ? '—' : implode(', ', $entry['business_types']);
                $registrationTypes = $entry['registration_types'] === [] ? '—' : implode(', ', $entry['registration_types']);
                $moduleGate = $entry['module_keys'] === []
                    ? '—'
                    : $entry['mode'].'('.implode(',', $entry['module_keys']).')';
                $employeeRule = $this->catalog->employeeRule($routeName);
                $employeeRule = $employeeRule === '' ? '—' : $employeeRule;
                $supportingRoutes = $entry['supporting_routes'] === [] ? '—' : implode(', ', $entry['supporting_routes']);
                $exposure = $ownerExposure === null ? 'absent' : 'exposed: `'.$ownerExposure['route_name'].'`';
                $action = $loadedRoute->getActionName() === 'Closure' ? 'Closure' : $loadedRoute->getActionName();

                $rows[] = [
                    $method,
                    '`'.$routeName.'`',
                    $entry['owner_access'],
                    $exposure,
                    $action,
                    $supportingRoutes,
                    $entry['navigation_group'] ?? $entry['classification'],
                    $moduleGate,
                    $registrationTypes.' / '.$businessTypes,
                    $employeeRule,
                    $entry['domain_rule'] ?? '—',
                    $entry['risk_tier'],
                    $entry['actor_persistence'],
                    $entry['self_service'] ? 'yes' : 'no',
                ];
            }
        }

        usort($rows, static fn (array $left, array $right): int => [$left[1], $left[0]] <=> [$right[1], $right[0]]);

        $lines = [
            '# Shop Owner ERP Route Matrix',
            '',
            '> Generated review artifact; not a policy source.',
            '',
            '| Method | Employee route | Owner policy | Owner exposure/route | Component/controller | Supporting APIs | ERP group | Module gate | Business type | Employee rule | Domain rule | Risk | Actor persistence | Self-service |',
            '| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |',
        ];

        foreach ($rows as $row) {
            $lines[] = '| '.implode(' | ', $row).' |';
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
