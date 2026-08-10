<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

final class ErpRouteCatalog
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return config('shop_modules.routes', []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function entry(string $routeName): ?array
    {
        $entry = $this->all()[$routeName] ?? null;

        return is_array($entry) ? $entry : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forRoute(string $method, string $routeName): ?array
    {
        $entry = $this->entry($routeName);
        $method = strtoupper($method);

        if ($entry === null || ! in_array($method, $entry['methods'] ?? [], true)) {
            return null;
        }

        return ['route_name' => $routeName] + $entry;
    }

    public static function capabilityKey(string $method, string $routeName): string
    {
        return strtoupper($method).':'.$routeName;
    }

    public function canonicalClientKey(string $method, string $routeName): string
    {
        $entry = $this->entry($routeName);
        $employeeRoute = $entry !== null && ($entry['audience'] ?? null) === 'shop_owner'
            ? ($entry['paired_route'] ?? $routeName)
            : $routeName;

        return self::capabilityKey($method, (string) $employeeRoute);
    }

    public function employeeRule(string $routeName): string
    {
        $route = RouteFacade::getRoutes()->getByName($routeName);

        return $route instanceof Route ? implode(', ', $route->gatherMiddleware()) : '';
    }

    /**
     * @return array{route_name: string, methods: array<int, string>}|null
     */
    public function ownerExposure(string $method, string $employeeRouteName): ?array
    {
        $employeeEntry = $this->entry($employeeRouteName);
        $ownerRouteName = $employeeEntry['paired_route'] ?? null;

        if (! is_string($ownerRouteName)) {
            foreach ($this->all() as $candidateRouteName => $candidate) {
                if (($candidate['audience'] ?? null) === 'shop_owner'
                    && ($candidate['paired_route'] ?? null) === $employeeRouteName) {
                    $ownerRouteName = $candidateRouteName;
                    break;
                }
            }
        }

        $ownerRoute = is_string($ownerRouteName)
            ? RouteFacade::getRoutes()->getByName($ownerRouteName)
            : null;

        if (! $ownerRoute instanceof Route || ! $this->forRoute($method, $ownerRouteName)) {
            return null;
        }

        return [
            'route_name' => $ownerRouteName,
            'methods' => $this->entry($ownerRouteName)['methods'],
        ];
    }
}
