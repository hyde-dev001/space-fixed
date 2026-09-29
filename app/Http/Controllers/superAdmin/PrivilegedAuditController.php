<?php

declare(strict_types=1);

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Privileged\PrivilegedAuditIndexRequest;
use App\Models\SuperAdmin;
use App\Services\PrivilegedAuditVisibility;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final class PrivilegedAuditController extends Controller
{
    public function __construct(private readonly PrivilegedAuditVisibility $visibility)
    {
    }

    public function index(PrivilegedAuditIndexRequest $request): InertiaResponse
    {
        $viewer = $request->user('super_admin');
        abort_unless($viewer instanceof SuperAdmin, Response::HTTP_UNAUTHORIZED);

        $filters = array_merge([
            'event' => '',
            'actor_id' => '',
            'target_type' => '',
            'target_id' => '',
            'correlation_id' => '',
            'date_from' => '',
            'date_to' => '',
            'per_page' => 25,
        ], $request->validated());
        foreach (['actor_id', 'target_id', 'per_page'] as $integerFilter) {
            if (isset($filters[$integerFilter]) && $filters[$integerFilter] !== '') {
                $filters[$integerFilter] = (int) $filters[$integerFilter];
            }
        }
        $paginator = $this->visibility->paginate($viewer, $filters);

        return Inertia::render('superAdmin/Audit/PrivilegedAuditHistory', [
            'entries' => $paginator->getCollection()
                ->map(fn ($activity): array => $this->visibility->serialize($activity, $viewer))
                ->values()
                ->all(),
            'filters' => $filters,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'event_options' => $this->visibility->eventOptions($viewer),
            'target_type_options' => $this->visibility->targetTypeOptions(),
        ]);
    }
}
