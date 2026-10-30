<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Support\PrivilegedFailureResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

trait RespondsToAccountLifecycle
{
    protected function respondToAccountLifecycle(
        Request $request,
        callable $action,
        string $successMessage,
        PrivilegedFailureResponse $failures,
    ): mixed {
        try {
            $result = $action();
            $account = $result['account'];
            $payload = [
                'success' => true,
                'message' => $successMessage,
                'account' => [
                    'id' => (int) $account->getKey(),
                    'status' => (string) $account->getRawOriginal('status'),
                    'archived' => $account->trashed(),
                ],
            ];

            if (array_key_exists('suspension', $result)) {
                $payload['suspension_id'] = $result['suspension']?->getKey();
            }

            if ($request->expectsJson() || $request->ajax() || $request->header('X-Inertia')) {
                return response()->json($payload);
            }

            return redirect()->back()->with('success', $successMessage);
        } catch (ModelNotFoundException $exception) {
            return $failures->notFound(
                request: $request,
                message: 'The requested account was not found.',
                code: 'account_lifecycle_not_found',
                forceJson: $request->expectsJson() || $request->ajax() || (bool) $request->header('X-Inertia'),
            );
        } catch (HttpExceptionInterface $exception) {
            $status = $exception->getStatusCode();
            $forceJson = $request->expectsJson() || $request->ajax() || (bool) $request->header('X-Inertia');

            if ($status === 409) {
                return $failures->conflict(
                    request: $request,
                    operation: 'account_lifecycle',
                    message: 'The account lifecycle operation conflicts with current state.',
                    code: 'account_lifecycle_conflict',
                    forceJson: $forceJson,
                );
            }

            if ($status === 422) {
                return $failures->validation(
                    request: $request,
                    message: 'The account lifecycle operation could not be completed.',
                    code: 'account_lifecycle_validation',
                    forceJson: $forceJson,
                );
            }

            return $failures->unexpected(
                request: $request,
                operation: 'account_lifecycle',
                exception: $exception,
                message: 'The account lifecycle operation could not be completed.',
                code: 'account_lifecycle_error',
                forceJson: $forceJson,
                status: $status >= 400 && $status < 500 ? $status : 500,
            );
        } catch (Throwable $exception) {
            return $failures->unexpected(
                request: $request,
                operation: 'account_lifecycle',
                exception: $exception,
                message: 'The account lifecycle operation could not be completed.',
                code: 'account_lifecycle_error',
                forceJson: $request->expectsJson() || $request->ajax() || (bool) $request->header('X-Inertia'),
            );
        }
    }
}
