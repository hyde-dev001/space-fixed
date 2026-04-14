<?php

namespace App\Http\Controllers;

use App\Models\SuspensionAppeal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class SuspensionAppealPublicController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $appeal = SuspensionAppeal::query()
            ->where('appeal_token', $token)
            ->firstOrFail();

        if ($appeal->isExpired() && in_array((string) $appeal->status, ['eligible', 'submitted'], true)) {
            $appeal->update([
                'status' => 'expired',
                'reviewed_at' => now(),
            ]);
            $appeal->refresh();
        }

        $submitUrl = URL::temporarySignedRoute(
            'appeals.submit',
            $appeal->expires_at ?? now()->addMinutes(5),
            ['token' => $appeal->appeal_token]
        );

        return Inertia::render('Appeals/SubmitAppeal', [
            'appeal' => [
                'token' => $appeal->appeal_token,
                'account_type' => $appeal->account_type,
                'account_name' => $appeal->account_name,
                'recipient_email' => $appeal->recipient_email,
                'suspension_reason' => $appeal->suspension_reason,
                'status' => $appeal->status,
                'expires_at' => $appeal->expires_at?->toDateTimeString(),
                'submitted_at' => $appeal->submitted_at?->toDateTimeString(),
            ],
            'submitUrl' => $submitUrl,
        ]);
    }

    public function submit(Request $request, string $token): JsonResponse
    {
        $appeal = SuspensionAppeal::query()
            ->where('appeal_token', $token)
            ->firstOrFail();

        if ($appeal->isExpired()) {
            $appeal->update([
                'status' => 'expired',
                'reviewed_at' => now(),
            ]);

            return response()->json([
                'message' => 'This appeal link has already expired.',
            ], 410);
        }

        if ($appeal->status !== 'eligible') {
            return response()->json([
                'message' => 'This appeal was already submitted or reviewed.',
            ], 422);
        }

        $validated = $request->validate([
            'appeal_message' => ['required', 'string', 'min:20', 'max:3000'],
        ]);

        $appeal->update([
            'status' => 'submitted',
            'appeal_message' => $validated['appeal_message'],
            'submitted_at' => now(),
        ]);

        return response()->json([
            'message' => 'Appeal submitted successfully. Our team will review your request.',
            'status' => $appeal->status,
        ]);
    }
}
