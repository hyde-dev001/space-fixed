<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\RepairManagerDecisionRequest;
use App\Models\User;
use App\Services\Manager\ManagerRepairService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class ManagerRepairController extends Controller
{
    public function __construct(
        private readonly ManagerRepairService $repairs,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $repairs = $this->repairs->list($this->manager(), $request->only([
            'status',
            'assignment_state',
            'repairer_id',
            'review_pending',
            'search',
            'date_from',
            'date_to',
            'overdue',
            'sort',
            'direction',
            'per_page',
        ]));

        return response()->json([
            'data' => $repairs,
            'last_updated_at' => now()->toISOString(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json([
            'data' => $this->repairs->show($this->manager(), $id),
        ]);
    }

    public function eligibleRepairers(int $id): JsonResponse
    {
        return response()->json([
            'data' => $this->repairs->eligibleRepairers($this->manager(), $id),
        ]);
    }

    public function reassign(RepairManagerDecisionRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();
        if (! isset($validated['replacement_repairer_id'])) {
            throw ValidationException::withMessages([
                'replacement_repairer_id' => ['Choose an eligible replacement repairer.'],
            ]);
        }

        try {
            $repair = $this->repairs->reassign(
                manager: $this->manager(),
                repairId: $id,
                replacementRepairerId: (int) $validated['replacement_repairer_id'],
                reason: (string) $validated['reason'],
            );

            return response()->json([
                'success' => true,
                'message' => 'Repair request reassigned successfully.',
                'data' => $this->repairs->show($this->manager(), (int) $repair->id),
            ]);
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        }
    }

    public function finalReject(RepairManagerDecisionRequest $request, int $id): JsonResponse
    {
        try {
            $repair = $this->repairs->finalReject(
                manager: $this->manager(),
                repairId: $id,
                reason: (string) $request->validated('reason'),
            );

            return response()->json([
                'success' => true,
                'message' => 'Repair request was rejected by the Manager.',
                'data' => $this->repairs->show($this->manager(), (int) $repair->id),
            ]);
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        }
    }

    /** Explicit exception route for a shop policy that requires Owner review. */
    public function forwardToOwner(RepairManagerDecisionRequest $request, int $id): JsonResponse
    {
        try {
            $repair = $this->repairs->forwardToOwner(
                manager: $this->manager(),
                repairId: $id,
                reason: (string) $request->validated('reason'),
            );

            return response()->json([
                'success' => true,
                'message' => 'Repair rejection was forwarded to the Shop Owner under the explicit approval policy.',
                'data' => $this->repairs->show($this->manager(), (int) $repair->id),
            ]);
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        }
    }

    private function manager(): User
    {
        /** @var User $manager */
        $manager = Auth::guard('user')->user();

        return $manager;
    }

    private function validationError(ValidationException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Repair decision was not completed.',
            'errors' => $exception->errors(),
        ], 422);
    }
}
