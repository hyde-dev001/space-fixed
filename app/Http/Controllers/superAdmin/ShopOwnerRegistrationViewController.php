<?php

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\ApproveShopOwnerRegistrationRequest;
use App\Http\Requests\SuperAdmin\RejectShopOwnerRegistrationRequest;
use App\Models\ShopDocument;
use App\Models\ShopOwner;
use App\Models\SuperAdmin;
use App\Services\ShopDocumentValidityService;
use App\Services\ShopOwnerDocumentScreeningService;
use App\Services\ShopOwnerRegistrationDecisionService;
use App\Support\PrivilegedFailureResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class ShopOwnerRegistrationViewController extends Controller
{
    public function __construct(
        private readonly ShopOwnerRegistrationDecisionService $registrationDecisions,
        private readonly ShopDocumentValidityService $documentValidity,
        private readonly ShopOwnerDocumentScreeningService $documentScreening,
    ) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['all', 'pending', 'approved', 'rejected'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $baseQuery = ShopOwner::query()->whereIn('status', ['pending', 'approved', 'rejected']);
        $query = clone $baseQuery;

        if (($validated['search'] ?? null) !== null && $validated['search'] !== '') {
            $search = (string) $validated['search'];
            $query->where(function (Builder $searchQuery) use ($search): void {
                $this->whereContains($searchQuery, 'business_name', $search);
                $this->whereContains($searchQuery, 'first_name', $search, 'or');
                $this->whereContains($searchQuery, 'last_name', $search, 'or');
                $this->whereContains($searchQuery, 'email', $search, 'or');
            });
        }

        if (($validated['status'] ?? null) !== null && $validated['status'] !== 'all') {
            $query->where('status', $validated['status']);
        }

        $registrations = $query
            ->select([
                'id',
                'first_name',
                'last_name',
                'email',
                'phone',
                'suffix',
                'age',
                'address',
                'address_region',
                'address_province',
                'address_city',
                'address_barangay',
                'address_postal_code',
                'business_name',
                'business_address',
                'business_type',
                'registration_type',
                'operating_hours',
                'status',
                'created_at',
            ])
            ->with([
                'documents' => static function ($documents): void {
                    $documents->where('status', '!=', 'withdrawn')->whereDoesntHave('successors')->select([
                        'id',
                        'shop_owner_id',
                        'document_type',
                        'logical_slot',
                        'version_number',
                        'issued_on',
                        'expiration_mode',
                        'expires_on',
                        'status',
                        'is_current',
                        'checksum_sha256',
                        'ocr_screening',
                    ]);
                },
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25))
            ->withQueryString()
            ->through(fn (ShopOwner $shopOwner): array => $this->formatRegistration($shopOwner));

        $stats = [
            'total' => (clone $baseQuery)->count(),
            'pending' => (clone $baseQuery)->where('status', 'pending')->count(),
            'approved' => (clone $baseQuery)->where('status', 'approved')->count(),
            'rejected' => (clone $baseQuery)->where('status', 'rejected')->count(),
        ];

        $now = now();
        $currentStart = $now->copy()->subDays(30);
        $previousStart = $now->copy()->subDays(60);
        $changeFor = static function (Builder $query) use ($now, $currentStart, $previousStart): int {
            $current = (clone $query)
                ->where('created_at', '>=', $currentStart)
                ->where('created_at', '<', $now)
                ->count();
            $previous = (clone $query)
                ->where('created_at', '>=', $previousStart)
                ->where('created_at', '<', $currentStart)
                ->count();

            if ($previous === 0) {
                return $current > 0 ? 100 : 0;
            }

            return (int) round((($current - $previous) / $previous) * 100);
        };
        $stats['changes'] = [
            'total' => $changeFor($baseQuery),
            'pending' => $changeFor((clone $baseQuery)->where('status', 'pending')),
            'approved' => $changeFor((clone $baseQuery)->where('status', 'approved')),
            'rejected' => $changeFor((clone $baseQuery)->where('status', 'rejected')),
        ];

        return Inertia::render('superAdmin/Shops/ShopOwnerRegistrationView', [
            'registrations' => $registrations,
            'stats' => $stats,
            'filters' => [
                'search' => $validated['search'] ?? null,
                'status' => $validated['status'] ?? 'all',
            ],
        ]);
    }

    private function formatRegistration(ShopOwner $shopOwner): array
    {
        $registrationStatus = $shopOwner->status instanceof \BackedEnum
            ? $shopOwner->status->value
            : (string) $shopOwner->status;
        $screeningCandidates = $this->screeningCandidates($shopOwner->documents, $registrationStatus);
        $candidateIds = $screeningCandidates->map(static fn (ShopDocument $document): int => (int) $document->getKey());
        $screenings = $shopOwner->documents->mapWithKeys(function (ShopDocument $document) use ($shopOwner, $candidateIds, $registrationStatus): array {
            $slot = $this->screeningSlot($document);
            if ($slot === null) {
                return [(int) $document->getKey() => null];
            }

            $projection = $this->documentScreening->adminReviewProjection($shopOwner, $document);
            $projection['historical'] = $registrationStatus !== 'pending'
                || ! $candidateIds->contains((int) $document->getKey());
            $projection['includedInSummary'] = $candidateIds->contains((int) $document->getKey());

            return [(int) $document->getKey() => $projection];
        });
        $scores = [
            'business_registration' => null,
            'mayors_permit' => null,
            'bir_certificate' => null,
            'valid_id' => null,
        ];
        foreach ($screeningCandidates as $slot => $document) {
            $scores[$slot] = $screenings->get((int) $document->getKey())['score'] ?? null;
        }
        $summary = $this->documentScreening->summarizeScores($scores);
        $summary['historical'] = $registrationStatus !== 'pending';

        $documents = $shopOwner->documents->map(function (ShopDocument $document) use ($shopOwner, $screenings): array {
            return [
                'id' => (int) $document->getKey(),
                'url' => route('admin.shop-documents.show', [
                    'shopOwner' => $shopOwner->id,
                    'document' => $document->id,
                ]),
                'type' => $this->reviewType($document),
                'documentType' => (string) $document->document_type,
                'logicalSlot' => filled($document->logical_slot) ? (string) $document->logical_slot : null,
                'versionNumber' => $document->version_number !== null ? (int) $document->version_number : null,
                'issuedOn' => $document->issued_on?->toDateString(),
                'expirationMode' => $document->expiration_mode,
                'expiresOn' => $document->expires_on?->toDateString(),
                'validity' => $this->documentValidity->classify($document),
                'status' => (string) $document->status,
                'ocrScreening' => $screenings->get((int) $document->getKey()),
            ];
        })->values();

        return [
            'id' => $shopOwner->id,
            'firstName' => $shopOwner->first_name,
            'lastName' => $shopOwner->last_name,
            'email' => $shopOwner->email,
            'phone' => $shopOwner->phone,
            'suffix' => $shopOwner->suffix,
            'age' => $shopOwner->age,
            'address' => $shopOwner->address,
            'addressRegion' => $shopOwner->address_region,
            'addressProvince' => $shopOwner->address_province,
            'addressCity' => $shopOwner->address_city,
            'addressBarangay' => $shopOwner->address_barangay,
            'addressPostalCode' => $shopOwner->address_postal_code,
            'businessName' => $shopOwner->business_name,
            'businessAddress' => $shopOwner->business_address,
            'businessType' => $shopOwner->business_type,
            'registrationType' => $shopOwner->registration_type,
            'serviceType' => $shopOwner->business_type,
            'operatingHours' => is_array($shopOwner->operating_hours) ? $shopOwner->operating_hours : [],
            'documents' => $documents->all(),
            'documentUrls' => $documents->pluck('url')->all(),
            'ocrScreeningSummary' => $summary,
            'status' => $shopOwner->status,
            'createdAt' => $shopOwner->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Pick the latest screening candidate for each required slot without mixing predecessors.
     *
     * @param Collection<int, ShopDocument> $documents
     * @return Collection<string, ShopDocument>
     */
    private function screeningCandidates(Collection $documents, string $registrationStatus): Collection
    {
        $eligible = $documents->filter(function (ShopDocument $document) use ($registrationStatus): bool {
            if ($this->screeningSlot($document) === null) {
                return false;
            }

            return $registrationStatus === 'pending'
                ? (string) $document->status === 'pending' && ! (bool) $document->is_current
                : in_array((string) $document->status, ['approved', 'rejected'], true);
        });

        return $eligible
            ->groupBy(fn (ShopDocument $document): string => (string) $this->screeningSlot($document))
            ->map(static fn ($slotDocuments): ShopDocument => $slotDocuments->sort(
                static fn (ShopDocument $left, ShopDocument $right): int =>
                    ((int) $right->version_number <=> (int) $left->version_number)
                    ?: ((int) $right->getKey() <=> (int) $left->getKey()),
            )->first());
    }

    private function screeningSlot(ShopDocument $document): ?string
    {
        $slot = trim((string) $document->logical_slot);
        if (in_array($slot, ['business_registration', 'mayors_permit', 'bir_certificate', 'valid_id'], true)) {
            return $slot;
        }

        $type = trim((string) $document->document_type);
        if ($slot === '' && in_array($type, ['dti_registration', 'sec_registration'], true)) {
            return 'business_registration';
        }

        return null;
    }

    private function whereContains(Builder $query, string $column, string $value, string $boolean = 'and'): void
    {
        $escaped = strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
        $query->whereRaw(
            "{$column} LIKE ? ESCAPE '!'",
            ["%{$escaped}%"],
            $boolean,
        );
    }

    private function reviewType(ShopDocument $document): string
    {
        $type = strtolower(trim((string) $document->document_type));
        $logicalSlot = trim((string) $document->logical_slot);

        if ($logicalSlot === '' && in_array($type, ['dti_registration', 'sec_registration'], true)) {
            return 'legacy_dti_sec_registration';
        }

        return (string) $document->document_type;
    }

    public function approve(ApproveShopOwnerRegistrationRequest $request, int $shopOwner, ?PrivilegedFailureResponse $failures = null)
    {
        $actor = $request->user('super_admin');
        abort_unless($actor instanceof SuperAdmin, 403);

        try {
            $outcome = $this->registrationDecisions->approve($request, $actor, $shopOwner);
        } catch (ConflictHttpException $exception) {
            return $this->conflictResponse($request, $exception->getMessage());
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return ($failures ?? app(PrivilegedFailureResponse::class))->unexpected(
                request: $request,
                operation: 'shop_registration',
                exception: $exception,
                message: 'The shop owner registration could not be approved.',
                code: 'shop_registration_approval_error',
                forceJson: $request->expectsJson() || $request->ajax() || (bool) $request->header('X-Inertia'),
            );
        }

        // If this is an XHR/JSON request (e.g., fetch), return JSON.
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'applied' => $outcome['applied'],
                'message' => $outcome['applied']
                    ? 'Shop owner registration approved.'
                    : 'Shop owner registration was already approved.',
            ]);
        }

        // For Inertia form submissions, redirect back with flash message.
        return redirect()->back()->with('success', $outcome['applied']
            ? 'Shop owner registration approved.'
            : 'Shop owner registration was already approved.');
    }

    public function reject(RejectShopOwnerRegistrationRequest $request, int $shopOwner, ?PrivilegedFailureResponse $failures = null)
    {
        $actor = $request->user('super_admin');
        abort_unless($actor instanceof SuperAdmin, 403);

        try {
            $outcome = $this->registrationDecisions->reject(
                $request,
                $actor,
                $shopOwner,
                (string) $request->validated('rejection_reason'),
            );
        } catch (ConflictHttpException $exception) {
            return $this->conflictResponse($request, $exception->getMessage());
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return ($failures ?? app(PrivilegedFailureResponse::class))->unexpected(
                request: $request,
                operation: 'shop_registration',
                exception: $exception,
                message: 'The shop owner registration could not be rejected.',
                code: 'shop_registration_rejection_error',
                forceJson: $request->expectsJson() || $request->ajax() || (bool) $request->header('X-Inertia'),
            );
        }

        // If this is an XHR/JSON request (e.g., fetch), return JSON.
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'applied' => $outcome['applied'],
                'message' => $outcome['applied']
                    ? 'Shop owner registration rejected.'
                    : 'Shop owner registration was already rejected with the same reason.',
            ]);
        }

        // For Inertia form submissions, redirect back with flash message.
        return redirect()->back()->with('success', $outcome['applied']
            ? 'Shop owner registration rejected.'
            : 'Shop owner registration was already rejected with the same reason.');
    }

    private function conflictResponse(Request $request, string $message)
    {
        if ($request->wantsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
            ], 409);
        }

        return redirect()->back()->withErrors(['registration' => $message]);
    }
}
