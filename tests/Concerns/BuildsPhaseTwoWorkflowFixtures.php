<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\ShopOwnerStatus;
use App\Models\Employee;
use App\Models\ReviewReport;
use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\ShopReport;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\ShopOwnerDocumentRequirementService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Storage;

trait BuildsPhaseTwoWorkflowFixtures
{
    protected function phaseTwoAdmin(): SuperAdmin
    {
        return SuperAdmin::factory()->admin()->mfaEnrolled()->create();
    }

    protected function phaseTwoSuperAdmin(): SuperAdmin
    {
        return SuperAdmin::factory()->superAdmin()->mfaEnrolled()->create();
    }

    protected function pendingRegistrationWithRequiredDocuments(): ShopOwner
    {
        $shopOwner = ShopOwner::factory()->pending()->create();
        $requirements = app(ShopOwnerDocumentRequirementService::class)->requiredTypes();

        foreach ($requirements as $type) {
            $path = "phase-two/{$shopOwner->id}/{$type}.png";
            Storage::disk('local')->put($path, 'phase-two-document');

            $document = ShopDocument::create([
                'shop_owner_id' => $shopOwner->id,
                'document_type' => $type,
                'logical_slot' => $type === 'dti_registration' ? 'business_registration' : $type,
                'version_number' => 1,
                'is_current' => false,
                'file_path' => $path,
                'status' => 'pending',
                'issued_on' => '2026-01-01',
                'expiration_mode' => $type === 'mayors_permit' ? 'dated' : 'none',
                'expires_on' => $type === 'mayors_permit' ? '2027-01-01' : null,
            ]);
            $document->forceFill(['disk' => 'local'])->save();
        }

        return $shopOwner->fresh();
    }

    /**
     * @return array{documents: array<int, array<string, int|string|bool|null>>}
     */
    protected function approvalPayloadFor(ShopOwner $shopOwner): array
    {
        return [
            'documents' => $shopOwner->documents()
                ->orderBy('id')
                ->get()
                ->map(static fn (ShopDocument $document): array => [
                    'id' => (int) $document->getKey(),
                    'document_type' => (string) $document->document_type,
                    'logical_slot' => (string) $document->logical_slot,
                    'version_number' => (int) $document->version_number,
                    'issued_on' => $document->issued_on?->toDateString(),
                    'expiration_mode' => (string) $document->expiration_mode,
                    'expires_on' => $document->expires_on?->toDateString(),
                    'viewed' => true,
                ])
                ->all(),
        ];
    }

    protected function activePhaseTwoUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['status' => 'active'], $attributes));
    }

    protected function suspendedPhaseTwoUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['status' => 'suspended'], $attributes));
    }

    protected function approvedPhaseTwoShop(array $attributes = []): ShopOwner
    {
        return ShopOwner::factory()->approved()->create($attributes);
    }

    protected function suspendedPhaseTwoShop(array $attributes = []): ShopOwner
    {
        return ShopOwner::factory()->create(array_merge([
            'status' => ShopOwnerStatus::SUSPENDED->value,
            'suspension_reason' => 'Phase two fixture suspension',
        ], $attributes));
    }

    protected function linkedPhaseTwoEmployee(User $user, ShopOwner $shopOwner, array $attributes = []): Employee
    {
        return Employee::factory()->active()->create(array_merge([
            'shop_owner_id' => $shopOwner->id,
            'email' => $user->email,
        ], $attributes));
    }

    /** @return array<int, Employee> */
    protected function ambiguousPhaseTwoEmployees(User $user, ShopOwner $shopOwner): array
    {
        return [
            $this->linkedPhaseTwoEmployee($user, $shopOwner),
            $this->linkedPhaseTwoEmployee($user, $shopOwner),
        ];
    }

    /** @return EloquentCollection<int, ShopReport> */
    protected function openPhaseTwoShopReports(ShopOwner $shopOwner, int $count = 2): EloquentCollection
    {
        $reports = new EloquentCollection();

        for ($index = 0; $index < $count; $index++) {
            $reporter = User::factory()->create();
            $reports->push(ShopReport::create([
                'user_id' => $reporter->id,
                'shop_owner_id' => $shopOwner->id,
                'reason' => 'misconduct',
                'description' => "Phase two report {$index}",
                'status' => 'submitted',
            ]));
        }

        return $reports;
    }

    protected function flaggedPhaseTwoReviewReport(User $customer, array $attributes = []): ReviewReport
    {
        return ReviewReport::create(array_merge([
            'review_type' => 'product',
            'review_id' => 1,
            'user_id' => $customer->id,
            'reason' => 'fake_review',
            'notes' => 'Phase two flagged-account fixture',
            'status' => 'pending_review',
            'review_snapshot' => ['fixture' => true],
        ], $attributes));
    }

    /**
     * Return persistence attributes for a current suspension and appeal.
     * The concrete records are created by the Phase 2 services once their
     * aggregate locks and schema are available.
     *
     * @return array{account_type: string, account_id: int, suspension: array<string, mixed>, appeal: array<string, mixed>}
     */
    protected function currentPhaseTwoSuspensionAppealAttributes(
        string $accountType,
        int $accountId,
        string $status = 'eligible',
    ): array {
        return [
            'account_type' => $accountType,
            'account_id' => $accountId,
            'suspension' => [
                'account_type' => $accountType,
                'account_id' => $accountId,
                'source' => 'test_fixture',
                'reason' => 'Phase two fixture suspension',
            ],
            'appeal' => [
                'account_type' => $accountType,
                'account_id' => $accountId,
                'status' => $status,
                'suspension_reason' => 'Phase two fixture suspension',
                'appeal_message' => 'Phase two fixture appeal',
            ],
        ];
    }
}
