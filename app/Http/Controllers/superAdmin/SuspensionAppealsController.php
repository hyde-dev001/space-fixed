<?php

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ShopOwner;
use App\Models\SuspensionAppeal;
use App\Models\User;
use App\Services\SuspensionAppealService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class SuspensionAppealsController extends Controller
{
    public function index(): Response
    {
        SuspensionAppeal::query()
            ->whereIn('status', ['eligible', 'submitted'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update([
                'status' => 'expired',
                'reviewed_at' => now(),
            ]);

        $appeals = SuspensionAppeal::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(function (SuspensionAppeal $appeal) {
                return [
                    'id' => $appeal->id,
                    'account_type' => $appeal->account_type,
                    'account_id' => $appeal->account_id,
                    'account_name' => $appeal->account_name,
                    'recipient_email' => $appeal->recipient_email,
                    'suspension_reason' => $appeal->suspension_reason,
                    'status' => $appeal->status,
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
            'total' => SuspensionAppeal::count(),
            'eligible' => SuspensionAppeal::where('status', 'eligible')->count(),
            'submitted' => SuspensionAppeal::where('status', 'submitted')->count(),
            'approved' => SuspensionAppeal::where('status', 'approved')->count(),
            'rejected' => SuspensionAppeal::where('status', 'rejected')->count(),
        ];

        return Inertia::render('superAdmin/Users/SuspensionAppeals', [
            'appeals' => $appeals,
            'stats' => $stats,
        ]);
    }

    public function approve(Request $request, int $id, SuspensionAppealService $appealService): RedirectResponse
    {
        $validated = $request->validate([
            'reviewer_notes' => ['nullable', 'string', 'max:3000'],
        ]);

        $appeal = SuspensionAppeal::query()->findOrFail($id);

        if ($appeal->status !== 'submitted') {
            return back()->withErrors(['error' => 'Only submitted appeals can be approved.']);
        }

        if ($appeal->account_type === 'shop_owner') {
            $shop = ShopOwner::query()->find($appeal->account_id);
            if (!$shop) {
                return back()->withErrors(['error' => 'Shop owner account no longer exists.']);
            }

            $shop->update([
                'status' => 'approved',
                'suspension_reason' => null,
            ]);
        } else {
            $user = User::query()->find($appeal->account_id);
            if (!$user) {
                return back()->withErrors(['error' => 'Customer account no longer exists.']);
            }

            $user->update([
                'status' => 'active',
            ]);
        }

        $appeal->update([
            'status' => 'approved',
            'reviewer_notes' => $validated['reviewer_notes'] ?? null,
            'reviewed_at' => now(),
        ]);

        $appealService->sendDecisionEmail($appeal->fresh());

        AuditLog::create([
            'shop_owner_id' => $appeal->account_type === 'shop_owner' ? (int) $appeal->account_id : null,
            'actor_user_id' => Auth::guard('super_admin')->id(),
            'action' => 'suspension_appeal_approved',
            'target_type' => $appeal->account_type,
            'target_id' => (int) $appeal->account_id,
            'metadata' => [
                'appeal_id' => $appeal->id,
                'recipient_email' => $appeal->recipient_email,
                'reviewer_notes' => $validated['reviewer_notes'] ?? null,
            ],
        ]);

        return back()->with('success', 'Appeal approved and decision email sent.');
    }

    public function reject(Request $request, int $id, SuspensionAppealService $appealService): RedirectResponse
    {
        $validated = $request->validate([
            'reviewer_notes' => ['nullable', 'string', 'max:3000'],
        ]);

        $appeal = SuspensionAppeal::query()->findOrFail($id);

        if ($appeal->status !== 'submitted') {
            return back()->withErrors(['error' => 'Only submitted appeals can be rejected.']);
        }

        $appeal->update([
            'status' => 'rejected',
            'reviewer_notes' => $validated['reviewer_notes'] ?? null,
            'reviewed_at' => now(),
        ]);

        $appealService->sendDecisionEmail($appeal->fresh());

        AuditLog::create([
            'shop_owner_id' => $appeal->account_type === 'shop_owner' ? (int) $appeal->account_id : null,
            'actor_user_id' => Auth::guard('super_admin')->id(),
            'action' => 'suspension_appeal_rejected',
            'target_type' => $appeal->account_type,
            'target_id' => (int) $appeal->account_id,
            'metadata' => [
                'appeal_id' => $appeal->id,
                'recipient_email' => $appeal->recipient_email,
                'reviewer_notes' => $validated['reviewer_notes'] ?? null,
            ],
        ]);

        return back()->with('success', 'Appeal rejected and decision email sent.');
    }
}
