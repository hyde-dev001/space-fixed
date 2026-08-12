<?php

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\DecideSuspensionAppealRequest;
use App\Models\SuperAdmin;
use App\Models\SuspensionAppeal;
use App\Services\SuspensionAppealService;
use App\Support\PrivilegedFailureResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class SuspensionAppealsController extends Controller
{
    public function index(SuspensionAppealService $appealService): Response
    {
        $appeals = SuspensionAppeal::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(function (SuspensionAppeal $appeal) use ($appealService): array {
                $presentation = $appealService->presentation($appeal);
                $displayStatus = $presentation['state'] === 'stale'
                    ? 'stale'
                    : $presentation['status'];

                return [
                    'id' => (int) $appeal->id,
                    'account_type' => $appeal->account_type,
                    'account_id' => (int) $appeal->account_id,
                    'account_name' => $appeal->account_name,
                    'recipient_email' => $appeal->recipient_email,
                    'suspension_reason' => $appeal->suspension_reason,
                    'status' => $displayStatus,
                    'persisted_status' => $presentation['persisted_status'],
                    'state' => $presentation['state'],
                    'current' => $presentation['current'],
                    'actionable' => $presentation['actionable'],
                    'suspension_id' => $presentation['suspension_id'],
                    'appeal_message' => $appeal->appeal_message,
                    'reviewer_notes' => $appeal->reviewer_notes,
                    'submitted_at' => $appeal->submitted_at?->toDateTimeString(),
                    'reviewed_at' => $appeal->reviewed_at?->toDateTimeString(),
                    'expires_at' => $appeal->expires_at?->toDateTimeString(),
                    'created_at' => $appeal->created_at?->toDateTimeString(),
                ];
            })
            ->values();

        $stats = [
            'total' => $appeals->count(),
            'eligible' => $appeals->where('status', 'eligible')->count(),
            'submitted' => $appeals->where('status', 'submitted')->count(),
            'approved' => $appeals->where('status', 'approved')->count(),
            'rejected' => $appeals->where('status', 'rejected')->count(),
            'expired' => $appeals->where('status', 'expired')->count(),
            'superseded' => $appeals->where('status', 'superseded')->count(),
            'stale' => $appeals->where('status', 'stale')->count(),
        ];

        return Inertia::render('superAdmin/Users/SuspensionAppeals', [
            'appeals' => $appeals,
            'stats' => $stats,
        ]);
    }

    public function approve(
        DecideSuspensionAppealRequest $request,
        int $id,
        SuspensionAppealService $appealService,
        PrivilegedFailureResponse $failures,
    ): mixed {
        return $this->decide($request, $id, 'approve', $appealService, $failures);
    }

    public function reject(
        DecideSuspensionAppealRequest $request,
        int $id,
        SuspensionAppealService $appealService,
        PrivilegedFailureResponse $failures,
    ): mixed {
        return $this->decide($request, $id, 'reject', $appealService, $failures);
    }

    private function decide(
        DecideSuspensionAppealRequest $request,
        int $id,
        string $decision,
        SuspensionAppealService $appealService,
        PrivilegedFailureResponse $failures,
    ): mixed {
        $actor = Auth::guard('super_admin')->user();
        abort_unless($actor instanceof SuperAdmin, 403);

        try {
            $result = $appealService->decide(
                appealId: $id,
                decision: $decision,
                reviewerNotes: $request->validated('reviewer_notes'),
                actor: $actor,
                request: $request,
            );

            $message = $decision === 'approve'
                ? 'Appeal approved and account access restored.'
                : 'Appeal rejected; the account remains suspended.';
            $payload = [
                'success' => true,
                'changed' => $result['changed'],
                'status' => (string) $result['appeal']->status,
                'account_status' => (string) $result['account']->getRawOriginal('status'),
                'suspension_id' => $result['suspension_id'],
                'message' => $message,
            ];

            if ($this->usesApiResponse($request)) {
                return response()->json($payload);
            }

            return back()->with('success', $message);
        } catch (HttpExceptionInterface $exception) {
            $status = $exception->getStatusCode();
            $forceJson = $this->usesApiResponse($request);
            if ($status === 409) {
                return $failures->conflict(
                    request: $request,
                    operation: 'suspension_appeal',
                    message: 'The appeal decision conflicts with current state.',
                    code: 'suspension_appeal_conflict',
                    forceJson: $forceJson,
                );
            }

            if ($status === 422) {
                return $failures->validation(
                    request: $request,
                    message: 'The appeal decision input is invalid.',
                    code: 'suspension_appeal_validation',
                    forceJson: $forceJson,
                );
            }

            return $failures->unexpected(
                request: $request,
                operation: 'suspension_appeal',
                exception: $exception,
                message: 'The appeal decision could not be completed.',
                code: 'suspension_appeal_error',
                forceJson: $forceJson,
            );
        } catch (Throwable $exception) {
            return $failures->unexpected(
                request: $request,
                operation: 'suspension_appeal',
                exception: $exception,
                message: 'The appeal decision could not be completed.',
                code: 'suspension_appeal_error',
                forceJson: $this->usesApiResponse($request),
            );
        }
    }

    private function usesApiResponse(Request $request): bool
    {
        return $request->expectsJson() || $request->ajax() || (bool) $request->header('X-Inertia');
    }
}
