<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SuperAdmin;
use App\Services\PrivilegedAudit;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class PrivilegedFailureResponse
{
    public function __construct(private readonly PrivilegedAudit $audit)
    {
    }

    public function capabilityDenied(Request $request, SuperAdmin $actor, string $capability): Response
    {
        $correlationId = $this->audit->correlationId($request);

        $this->safeAudit(function () use ($request, $actor, $capability): void {
            $this->audit->privilegedCapabilityDenied($request, $actor, $capability);
        });

        return $this->render(
            request: $request,
            status: 403,
            message: 'This privileged operation is not permitted.',
            code: 'privileged_capability_denied',
            correlationId: $correlationId,
        );
    }

    public function conflict(
        Request $request,
        string $operation,
        ?string $message = null,
        ?string $code = null,
        bool $forceJson = false,
    ): Response {
        $actor = $this->actor($request);
        $correlationId = $this->audit->correlationId($request);

        $this->safeAudit(function () use ($request, $actor, $operation): void {
            $this->audit->privilegedWorkflowConflict($request, $actor, $operation);
        });

        return $this->render(
            request: $request,
            status: 409,
            message: $message ?? 'This privileged operation conflicts with current state.',
            code: $code ?? $operation.'_conflict',
            correlationId: $correlationId,
            forceJson: $forceJson,
        );
    }

    public function unexpected(
        Request $request,
        string $operation,
        Throwable $exception,
        ?string $message = null,
        ?string $code = null,
        bool $forceJson = false,
        int $status = 500,
    ): Response {
        report($exception);

        $actor = $this->actor($request);
        $correlationId = $this->audit->correlationId($request);

        $this->safeAudit(function () use ($request, $actor, $operation): void {
            $this->audit->privilegedWorkflowFailed($request, $actor, $operation);
        });

        return $this->render(
            request: $request,
            status: $status,
            message: $message ?? 'The privileged operation could not be completed.',
            code: $code ?? $operation.'_error',
            correlationId: $correlationId,
            forceJson: $forceJson,
        );
    }

    public function notFound(
        Request $request,
        string $message = 'The requested resource was not found.',
        string $code = 'not_found',
        bool $forceJson = false,
    ): Response {
        return $this->render(
            request: $request,
            status: 404,
            message: $message,
            code: $code,
            correlationId: $this->audit->correlationId($request),
            forceJson: $forceJson,
        );
    }

    public function validation(
        Request $request,
        string $message,
        string $code = 'validation_error',
        bool $forceJson = false,
    ): Response {
        return $this->render(
            request: $request,
            status: 422,
            message: $message,
            code: $code,
            correlationId: $this->audit->correlationId($request),
            forceJson: $forceJson,
        );
    }

    private function render(
        Request $request,
        int $status,
        string $message,
        string $code,
        string $correlationId,
        bool $forceJson = false,
    ): Response {
        if ($forceJson || $request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'code' => $code,
                'correlation_id' => $correlationId,
            ], $status)->header('X-Correlation-ID', $correlationId);
        }

        return back()
            ->withErrors(['error' => $message])
            ->with('correlation_id', $correlationId)
            ->setStatusCode($status)
            ->header('X-Correlation-ID', $correlationId);
    }

    private function actor(Request $request): ?SuperAdmin
    {
        $actor = $request->user('super_admin');

        return $actor instanceof SuperAdmin ? $actor : null;
    }

    /** @param callable(): void $callback */
    private function safeAudit(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
