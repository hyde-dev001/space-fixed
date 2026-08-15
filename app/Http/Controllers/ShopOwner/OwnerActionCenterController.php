<?php

declare(strict_types=1);

namespace App\Http\Controllers\ShopOwner;

use App\Enums\OwnerShellPresentation;
use App\Http\Controllers\Controller;
use App\Models\ShopOwner;
use App\Services\OwnerActionCenter\OwnerActionCenterRolloutPolicy;
use App\Services\OwnerActionCenter\OwnerActionCenterService;
use App\Services\OwnerShell\CanonicalOwnerShellService;
use App\Support\OwnerActionCenter\OwnerAttentionQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class OwnerActionCenterController extends Controller
{
    public function __construct(
        private readonly OwnerActionCenterRolloutPolicy $rollout,
        private readonly CanonicalOwnerShellService $shell,
        private readonly OwnerActionCenterService $actionCenter,
    ) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $owner = $request->user('shop_owner');
        if (! $owner instanceof ShopOwner || ! $this->canonicalActionCenterOwner($owner)) {
            return redirect()->route('shop-owner.shell.home');
        }

        $query = $this->queryFrom($request);

        try {
            $result = $this->actionCenter->queueForActionCenter($owner, $query);
        } catch (Throwable $exception) {
            report($exception);
            Log::warning('owner_action_center.route_failed', [
                'shop_id' => (int) $owner->getKey(),
                'coverage' => $query->coverage,
                'page' => $query->page,
                'per_page' => $query->perPage,
                'correlation_id' => $this->correlationId($request),
            ]);

            return redirect()->route('shop-owner.shell.home');
        }

        return Inertia::render('ShopOwner/ActionCenter', [
            'ownerActionCenter' => $result->toArray(),
            'source' => $result->coverage,
            'page' => $result->page,
            'per_page' => $result->perPage,
        ]);
    }

    private function canonicalActionCenterOwner(ShopOwner $owner): bool
    {
        return $this->rollout->select($owner)->selected
            && $this->shell->forOwner($owner)->presentation === OwnerShellPresentation::Canonical;
    }

    private function queryFrom(Request $request): OwnerAttentionQuery
    {
        $source = $request->query('source', 'all');
        if (! is_string($source) || ! in_array($source, OwnerAttentionQuery::COVERAGES, true)) {
            throw ValidationException::withMessages([
                'source' => 'The Action Center source filter is invalid.',
            ]);
        }

        $maxPage = $this->configuredBound('max_page', OwnerAttentionQuery::MAX_PAGE);
        $maxPerPage = $this->configuredBound('max_per_page', OwnerAttentionQuery::MAX_PER_PAGE);
        $defaultPerPage = $this->configuredDefaultPerPage($maxPerPage);

        return new OwnerAttentionQuery(
            coverage: $source,
            page: $this->boundedInteger($request->query('page', 1), 'page', 1, $maxPage),
            perPage: $this->boundedInteger($request->query('per_page', $defaultPerPage), 'per_page', 1, $maxPerPage),
        );
    }

    private function configuredBound(string $key, int $hardMaximum): int
    {
        $value = config('owner_action_center.'.$key, $hardMaximum);
        if (! is_int($value) || $value < 1 || $value > $hardMaximum) {
            throw new \InvalidArgumentException("Owner Action Center {$key} is out of bounds.");
        }

        return $value;
    }

    private function configuredDefaultPerPage(int $maximum): int
    {
        $value = config('owner_action_center.per_page', 20);
        if (! is_int($value) || $value < 1 || $value > $maximum) {
            throw new \InvalidArgumentException('Owner Action Center default per-page value is out of bounds.');
        }

        return $value;
    }

    private function boundedInteger(mixed $value, string $field, int $minimum, int $maximum): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1) {
            $integer = (int) $value;
        } else {
            throw ValidationException::withMessages([
                $field => "The Action Center {$field} value is invalid.",
            ]);
        }

        if ($integer < $minimum || $integer > $maximum) {
            throw ValidationException::withMessages([
                $field => "The Action Center {$field} value is out of bounds.",
            ]);
        }

        return $integer;
    }

    private function correlationId(Request $request): ?string
    {
        $value = $request->header('X-Request-ID') ?? $request->header('X-Correlation-ID');

        return is_string($value) && preg_match('/\A[a-zA-Z0-9._:-]{1,128}\z/', $value) === 1
            ? $value
            : null;
    }
}
