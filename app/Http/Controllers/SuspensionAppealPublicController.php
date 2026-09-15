<?php

namespace App\Http\Controllers;

use App\Models\SuspensionAppeal;
use App\Services\SuspensionAppealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class SuspensionAppealPublicController extends Controller
{
    public function show(string $token, SuspensionAppealService $appealService): Response
    {
        $appeal = SuspensionAppeal::query()
            ->where('appeal_token', $token)
            ->firstOrFail();
        $presentation = $appealService->presentation($appeal);

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
                'status' => $presentation['status'],
                'persisted_status' => $presentation['persisted_status'],
                'state' => $presentation['state'],
                'current' => $presentation['current'],
                'actionable' => $presentation['actionable'],
                'suspension_id' => $presentation['suspension_id'],
                'expires_at' => $appeal->expires_at?->toDateTimeString(),
                'submitted_at' => $appeal->submitted_at?->toDateTimeString(),
            ],
            'submitUrl' => $submitUrl,
        ]);
    }

    public function submit(Request $request, string $token, SuspensionAppealService $appealService): JsonResponse
    {
        $validated = $request->validate([
            'appeal_message' => ['required', 'string', 'min:20', 'max:3000'],
        ]);

        try {
            $result = $appealService->submit($token, (string) $validated['appeal_message']);

            return response()->json([
                'message' => $result['changed']
                    ? 'Appeal submitted successfully. Our team will review your request.'
                    : 'This appeal submission was already committed.',
                'status' => (string) $result['appeal']->status,
                'changed' => $result['changed'],
            ]);
        } catch (HttpExceptionInterface $exception) {
            $status = $exception->getStatusCode();
            $message = in_array($status, [404, 409, 410, 422], true)
                ? $exception->getMessage()
                : 'The appeal could not be submitted.';

            return response()->json(['message' => $message], $status);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'The appeal could not be submitted.',
            ], 500);
        }
    }
}
