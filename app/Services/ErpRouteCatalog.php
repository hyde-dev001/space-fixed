<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ShopOwner;
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

    public function hasOwnerReadablePageContract(string $routeName, ?ShopOwner $owner = null): bool
    {
        $entry = $this->entry($routeName);

        if ($entry === null
            || ($entry['classification'] ?? null) !== 'module'
            || ($entry['audience'] ?? null) !== 'shop_owner'
            || ($entry['actor_guard'] ?? null) !== 'shop_owner'
            || ($entry['owner_access'] ?? null) !== 'allowed'
            || ! is_string($entry['navigation_group'] ?? null)
            || (($entry['navigation_visible'] ?? null) !== true
                && ! ($owner !== null
                    && ($entry['owner_navigation_visible'] ?? false) === true
                    && $this->ownerMatchesPageContract($owner, $entry)))
            || ! is_array($entry['supporting_routes'] ?? null)
            || $entry['supporting_routes'] === []) {
            return false;
        }

        $hasRequiredReadSurface = false;

        foreach ($entry['supporting_routes'] as $supportingRouteName) {
            $supportingRoute = is_string($supportingRouteName)
                ? $this->entry($supportingRouteName)
                : null;

            if ($supportingRoute === null) {
                return false;
            }

            if (! in_array('GET', $supportingRoute['methods'] ?? [], true)) {
                continue;
            }

            $hasRequiredReadSurface = true;

            $loadedSupportingRoute = RouteFacade::getRoutes()->getByName($supportingRouteName);

            if (! $loadedSupportingRoute instanceof Route
                || ! in_array('GET', $loadedSupportingRoute->methods(), true)
                || ! in_array($supportingRoute['classification'] ?? null, ['core', 'module'], true)
                || ($supportingRoute['audience'] ?? null) !== 'shop_owner'
                || ($supportingRoute['actor_guard'] ?? null) !== 'shop_owner'
                || ($supportingRoute['owner_access'] ?? null) !== 'allowed') {
                return false;
            }
        }

        return $hasRequiredReadSurface;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function ownerMatchesPageContract(ShopOwner $owner, array $entry): bool
    {
        $registrationType = strtolower(trim((string) $owner->getRawOriginal('registration_type')));
        $businessType = match (strtolower(trim((string) $owner->getRawOriginal('business_type')))) {
            'both (retail & repair)' => 'both',
            default => strtolower(trim((string) $owner->getRawOriginal('business_type'))),
        };

        return in_array($registrationType, $entry['registration_types'] ?? [], true)
            && in_array($businessType, $entry['business_types'] ?? [], true);
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

        $ownerEntry = is_string($ownerRouteName) ? $this->entry($ownerRouteName) : null;

        if (! $ownerRoute instanceof Route
            || $ownerEntry === null
            || ($ownerEntry['owner_access'] ?? null) !== 'allowed'
            || ! $this->forRoute($method, $ownerRouteName)) {
            return null;
        }

        return [
            'route_name' => $ownerRouteName,
            'methods' => $ownerEntry['methods'],
        ];
    }
}
