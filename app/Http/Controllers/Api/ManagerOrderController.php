<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\ReassignOrderRequest;
use App\Models\User;
use App\Services\Manager\ManagerOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class ManagerOrderController extends Controller
{
    public function __construct(
        private readonly ManagerOrderService $orders,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $manager = $this->manager();
        $orders = $this->orders->list($manager, $request->only([
            'status',
            'assignment_state',
            'handler_id',
            'date_from',
            'date_to',
            'overdue',
            'sort',
            'direction',
            'per_page',
        ]));

        return response()->json([
            'data' => $orders,
            'last_updated_at' => now()->toISOString(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json([
            'data' => $this->orders->show($this->manager(), $id),
        ]);
    }

    public function eligibleReplacements(int $id): JsonResponse
    {
        return response()->json([
            'data' => $this->orders->eligibleReplacements($this->manager(), $id),
        ]);
    }

    public function reassign(ReassignOrderRequest $request, int $id): JsonResponse
    {
        try {
            $order = $this->orders->reassign(
                manager: $this->manager(),
                orderId: $id,
                replacementStaffId: (int) $request->validated('replacement_staff_id'),
                reason: (string) $request->validated('reason'),
            );

            return response()->json([
                'success' => true,
                'message' => 'Order reassigned successfully.',
                'data' => $this->orders->show($this->manager(), (int) $order->id),
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Order reassignment was not completed.',
                'errors' => $exception->errors(),
            ], 422);
        }
    }

    private function manager(): User
    {
        /** @var User $manager */
        $manager = Auth::guard('user')->user();

        return $manager;
    }
}
