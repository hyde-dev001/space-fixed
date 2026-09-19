<?php

namespace App\Http\Controllers;

use App\Enums\NotificationType;
use App\Mail\ShopOwnerApplicationUnderReviewMail;
use App\Services\CaviteLocationPolicyService;
use App\Models\Notification;
use App\Models\ShopOwner;
use App\Enums\ShopOwnerStatus;
use App\Models\ShopDocument;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeMfaService;
use App\Services\ShopOwnerDocumentRequirementService;
use App\Services\ShopDocumentLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Rules\NotDisposableEmail;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * ShopOwnerAuthController
 * 
 * Handles shop owner registration, authentication, and document uploads
 * Shop owners require admin approval before they can fully access the system
 */
class ShopOwnerAuthController extends Controller
{
    public function __construct(
        private readonly ShopOwnerDocumentRequirementService $documentRequirements,
        private readonly ShopDocumentLifecycleService $documentLifecycle,
        private readonly EmployeeMfaService $mfa,
    ) {}

    private const MAX_RESUBMISSION_ATTEMPTS = 3;
    private const REGISTRATION_EMAIL_OTP_TTL_MINUTES = 10;
    private const REGISTRATION_EMAIL_OTP_MAX_ATTEMPTS = 5;
    private const REGISTRATION_EMAIL_VERIFIED_TTL_MINUTES = 60;
    private const LOGIN_TOTP_TTL_MINUTES = 10;
    private const LOGIN_TOTP_MAX_ATTEMPTS = 5;
    private const LOGIN_TWO_FACTOR_ATTEMPTS_SESSION_KEY = 'shop_owner_2fa_attempts';
    private const LOGIN_TWO_FACTOR_SESSION_KEY = 'shop_owner_2fa_entry';
    private const LOGIN_TOTP_ENROLLMENT_SESSION_KEY = 'shop_owner_totp_login_enrollment';
    private const DUMMY_PASSWORD_HASH = '$2y$10$5n3DruMVEXy/QDrfseoa.uJ3ed2F8YjGuWk8rbM.tE0uNTd85ew.C';

    /**
     * Show signed resubmission form for rejected applications.
     */
    public function showResubmissionForm(Request $request, ShopOwner $shopOwner): InertiaResponse
    {
        $statusValue = $shopOwner->status instanceof ShopOwnerStatus
            ? $shopOwner->status->value
            : (string) $shopOwner->status;

        if ($statusValue !== ShopOwnerStatus::REJECTED->value) {
            abort(403, 'Only rejected applications can be resubmitted.');
        }

        $usedAttempts = max(0, (int) ($shopOwner->resubmission_count ?? 0));
        $remainingAttempts = max(0, self::MAX_RESUBMISSION_ATTEMPTS - $usedAttempts);
        $limitReached = $remainingAttempts <= 0;

        $documents = $shopOwner->documents()->get();
        $latestRequiredDocuments = $this->documentRequirements->latestRequiredDocuments($documents);
        $latestDocumentsBySlot = $this->latestDocumentsByLogicalSlot($shopOwner);
        $otherDocuments = $documents
            ->filter(function ($document) use ($latestDocumentsBySlot): bool {
                $normalizedType = $this->documentRequirements->normalizeType((string) $document->document_type);
                if ($normalizedType === 'other_supporting_document') {
                    return true;
                }

                $slot = trim((string) $document->logical_slot);

                return str_starts_with($slot, 'supporting_document:')
                    && ($latestDocumentsBySlot[$slot]->id ?? null) === $document->id;
            })
            ->sortByDesc('id')
            ->values();
        $documentLinkExpiresAt = now()->addDays(14);
        $toDocumentPayload = function ($document) use ($shopOwner, $documentLinkExpiresAt) {
            if (!$document) {
                return null;
            }

            $logicalSlot = trim((string) $document->logical_slot);
            if ($logicalSlot === ''
                && $this->documentRequirements->normalizeType((string) $document->document_type) === 'other_supporting_document') {
                $logicalSlot = 'supporting_document:legacy:' . $document->id;
            }

            return [
                'id' => $document->id,
                'type' => $document->document_type,
                'logical_slot' => $logicalSlot !== '' ? $logicalSlot : null,
                'url' => URL::temporarySignedRoute(
                    'shop-owner.resubmission.document',
                    $documentLinkExpiresAt,
                    ['shopOwner' => $shopOwner->id, 'document' => $document->id],
                ),
                'fileName' => basename((string) $document->file_path),
            ];
        };

        $submitUrl = $limitReached
            ? null
            : URL::temporarySignedRoute(
                'shop-owner.resubmission.submit',
                $documentLinkExpiresAt,
                ['shopOwner' => $shopOwner->id]
            );

        return Inertia::render('UserSide/Auth/ShopOwnerRegistration', [
            'resubmission' => [
                'isResubmission' => true,
                'submitUrl' => $submitUrl,
                'rejectionReason' => $shopOwner->rejection_reason,
                'maxAttempts' => self::MAX_RESUBMISSION_ATTEMPTS,
                'usedAttempts' => $usedAttempts,
                'remainingAttempts' => $remainingAttempts,
                'limitReached' => $limitReached,
                'form' => [
                    'firstName' => (string) ($shopOwner->first_name ?? ''),
                    'lastName' => (string) ($shopOwner->last_name ?? ''),
                    'email' => (string) ($shopOwner->email ?? ''),
                    'phone' => (string) ($shopOwner->phone ?? ''),
                    'businessName' => (string) ($shopOwner->business_name ?? ''),
                    'businessAddress' => (string) ($shopOwner->business_address ?? ''),
                    'postalCode' => (string) ($shopOwner->postal_code ?? ''),
                    'businessType' => (string) ($shopOwner->business_type ?? ''),
                    'registrationType' => (string) ($shopOwner->registration_type ?? 'individual'),
                    'shopLatitude' => $shopOwner->shop_latitude,
                    'shopLongitude' => $shopOwner->shop_longitude,
                    'shopAddress' => (string) ($shopOwner->shop_address ?? ''),
                    'shopGeofenceRadius' => (int) ($shopOwner->shop_geofence_radius ?? 90),
                ],
                'documents' => [
                    'dti_registration' => $toDocumentPayload(
                        $latestDocumentsBySlot['business_registration']
                            ?? $latestRequiredDocuments['dti_registration']
                            ?? null,
                    ),
                    'mayors_permit' => $toDocumentPayload(
                        $latestDocumentsBySlot['mayors_permit']
                            ?? $latestRequiredDocuments['mayors_permit']
                            ?? null,
                    ),
                    'bir_certificate' => $toDocumentPayload(
                        $latestDocumentsBySlot['bir_certificate']
                            ?? $latestRequiredDocuments['bir_certificate']
                            ?? null,
                    ),
                    'valid_id' => $toDocumentPayload(
                        $latestDocumentsBySlot['valid_id']
                            ?? $latestRequiredDocuments['valid_id']
                            ?? null,
                    ),
                    'other_documents' => $otherDocuments
                        ->map(fn ($document) => $toDocumentPayload($document))
                        ->all(),
                ],
            ],
        ]);
    }

    /**
     * Show pending-approval status page using a signed public email link.
     */
    public function showPendingApprovalFromEmail(Request $request, ShopOwner $shopOwner)
    {
        $statusValue = $shopOwner->status instanceof ShopOwnerStatus
            ? $shopOwner->status->value
            : (string) $shopOwner->status;

        if ($statusValue === ShopOwnerStatus::APPROVED->value && !empty($shopOwner->password)) {
            return redirect()->route('shop-owner.login.form')->with('success', 'Your application is approved. Please sign in to continue.');
        }

        return Inertia::render('Auth/PendingApproval', [
            'shopOwner' => [
                'email' => $shopOwner->email,
                'business_name' => $shopOwner->business_name,
                'status' => $statusValue,
                'email_verified_at' => $shopOwner->email_verified_at,
                'created_at' => $shopOwner->created_at,
                'rejection_reason' => $shopOwner->rejection_reason,
            ],
        ]);
    }

    /**
     * Resubmit rejected application with updated fields and documents.
     */
    public function resubmit(Request $request, ShopOwner $shopOwner, CaviteLocationPolicyService $caviteLocationPolicy)
    {
        $statusValue = $shopOwner->status instanceof ShopOwnerStatus
            ? $shopOwner->status->value
            : (string) $shopOwner->status;

        if (!in_array($statusValue, [
            ShopOwnerStatus::REJECTED->value,
            ShopOwnerStatus::PENDING->value,
        ], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only rejected applications can be resubmitted.',
            ], 422);
        }

        $usedAttempts = max(0, (int) ($shopOwner->resubmission_count ?? 0));
        if ($statusValue === ShopOwnerStatus::REJECTED->value
            && $usedAttempts >= self::MAX_RESUBMISSION_ATTEMPTS) {
            return response()->json([
                'success' => false,
                'message' => 'Resubmission limit reached. You can only resubmit up to ' . self::MAX_RESUBMISSION_ATTEMPTS . ' times.',
                'errors' => [
                    'email' => ['Resubmission limit reached. You can only resubmit up to ' . self::MAX_RESUBMISSION_ATTEMPTS . ' times.'],
                ],
            ], 422);
        }

        try {
            $validated = $request->validate([
                'first_name' => 'required|string|max:255|min:2',
                'last_name' => 'required|string|max:255|min:2',
                'phone' => ['required', 'regex:/^\d{11}$/'],
                'business_name' => 'required|string|max:255',
                'business_address' => 'required|string|max:500',
                'postal_code' => 'nullable|string|max:20',
                'zip_code' => 'nullable|string|max:20',
                'business_type' => 'required|in:retail,repair,both (retail & repair)',
                'registration_type' => 'required|in:individual,company',
                'attendance_geofence_enabled' => 'sometimes|boolean',
                'shop_latitude' => 'nullable|numeric|between:-90,90',
                'shop_longitude' => 'nullable|numeric|between:-180,180',
                'shop_address' => 'nullable|string|max:500',
                'shop_geofence_radius' => 'nullable|integer|min:10|max:5000',
                'business_registration' => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
                'business_registration_type' => 'required|in:dti_registration,sec_registration',
                'mayors_permit' => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
                'bir_certificate' => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
                'valid_id' => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
                'document_metadata' => 'required|array',
                'document_metadata.*' => 'required|array',
                'document_metadata.*.expiration_mode' => 'required|string',
                'document_metadata.*.expires_on' => 'nullable|date_format:Y-m-d',
                'document_metadata.*.issued_on' => 'nullable|date_format:Y-m-d',
                'submission_keys' => 'nullable|array',
                'submission_keys.*' => 'uuid',
                'other_documents' => 'nullable|array|max:8',
                'other_documents.*' => 'file|mimes:jpg,jpeg,png|max:5120',
                'other_document_metadata' => 'nullable|array|max:8',
                'other_document_metadata.*' => 'required|array',
                'other_document_metadata.*.expiration_mode' => 'required|string',
                'other_document_metadata.*.expires_on' => 'nullable|date_format:Y-m-d',
                'other_document_metadata.*.issued_on' => 'nullable|date_format:Y-m-d',
            ]);

            $caviteLocationPolicy->assertRegistrationLocation(
                $validated['shop_latitude'] ?? null,
                $validated['shop_longitude'] ?? null,
                $validated['business_address'] ?? null,
                $request,
                null,
                [
                    'email' => (string) $shopOwner->email,
                    'business_name' => $validated['business_name'] ?? null,
                    'target_type' => 'shop_owner_resubmission',
                    'shop_owner_id' => (int) $shopOwner->id,
                ]
            );

            if ($statusValue === ShopOwnerStatus::PENDING->value) {
                return $this->resubmissionResponse($request, true);
            }

            $shopOwner = DB::transaction(function () use ($shopOwner, $validated, $request): ShopOwner {
                $lockedShopOwner = ShopOwner::query()->lockForUpdate()->findOrFail($shopOwner->id);
                $lockedStatusValue = $lockedShopOwner->status instanceof ShopOwnerStatus
                    ? $lockedShopOwner->status->value
                    : (string) $lockedShopOwner->status;

                if ($lockedStatusValue !== ShopOwnerStatus::REJECTED->value) {
                    throw ValidationException::withMessages([
                        'status' => ['Only rejected applications can be resubmitted.'],
                    ]);
                }

                $lockedUsedAttempts = max(0, (int) ($lockedShopOwner->resubmission_count ?? 0));
                if ($lockedUsedAttempts >= self::MAX_RESUBMISSION_ATTEMPTS) {
                    throw ValidationException::withMessages([
                        'email' => ['Resubmission limit reached. You can only resubmit up to ' . self::MAX_RESUBMISSION_ATTEMPTS . ' times.'],
                    ]);
                }

                $predecessors = $this->latestDocumentsByLogicalSlot($lockedShopOwner);
                $entries = $this->registrationDocumentEntries($request, $validated, $predecessors, true);

                $lockedShopOwner->forceFill([
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'phone' => $validated['phone'],
                    'business_name' => $validated['business_name'],
                    'business_address' => $validated['business_address'],
                    'postal_code' => $validated['postal_code'] ?? $validated['zip_code'] ?? null,
                    'business_type' => $validated['business_type'],
                    'registration_type' => $validated['registration_type'],
                    'attendance_geofence_enabled' => (bool) ($validated['attendance_geofence_enabled'] ?? false),
                    'shop_latitude' => $validated['shop_latitude'] ?? null,
                    'shop_longitude' => $validated['shop_longitude'] ?? null,
                    'shop_address' => $validated['shop_address'] ?? $validated['business_address'],
                    'shop_geofence_radius' => $validated['shop_geofence_radius'] ?? 100,
                    'status' => ShopOwnerStatus::PENDING->value,
                    'rejection_reason' => null,
                    'resubmission_count' => $lockedUsedAttempts + 1,
                ])->save();

                $this->documentLifecycle->createPendingVersions($lockedShopOwner, $entries, true);

                return $lockedShopOwner->fresh();
            });

            Log::info('Shop owner application resubmitted successfully', [
                'shop_owner_id' => $shopOwner->id,
                'email' => $shopOwner->email,
            ]);

            $this->notifySuperAdminsOfPendingRegistration($shopOwner);
            $this->sendApplicationUnderReviewEmail($shopOwner);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'applied' => true,
                    'message' => 'Application resubmitted successfully. Please wait for admin review.',
                ]);
            }

            return redirect()->route('shop-owner-register')->with('success', 'Application resubmitted successfully. Please wait for admin review.');
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $caviteLocationPolicy->isLocationPolicyValidationException($e)
                        ? $caviteLocationPolicy->denialMessage()
                        : 'Please review the highlighted fields and try again.',
                    'errors' => $e->errors(),
                ], 422);
            }

            throw $e;
        } catch (\Throwable $e) {
            Log::error('Error resubmitting shop owner application', [
                'shop_owner_id' => $shopOwner->id,
                'error' => $e->getMessage(),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resubmission failed. Please try again.',
                ], 500);
            }

            return back()->withErrors(['message' => 'Resubmission failed. Please try again.'])->withInput();
        }
    }

    private function resubmissionResponse(Request $request, bool $idempotent = false)
    {
        $message = $idempotent
            ? 'This application is already pending review.'
            : 'Application resubmitted successfully. Please wait for admin review.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'applied' => !$idempotent,
                'idempotent' => $idempotent,
                'message' => $message,
            ]);
        }

        return redirect()->route('shop-owner-register')->with('success', $message);
    }

    /**
     * Send verification OTP to a shop owner registration email.
     */
    public function sendRegistrationEmailOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'max:255', new NotDisposableEmail()],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => (string) ($validator->errors()->first('email') ?: 'Invalid email address.'),
            ], 422);
        }

        $email = $this->normalizeEmail((string) $request->input('email'));

        $availability = $this->checkRegistrationEmailAvailability($email);
        if (!$availability['available']) {
            return response()->json([
                'success' => false,
                'message' => $availability['message'],
            ], 422);
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $cacheKey = $this->registrationEmailOtpCacheKey($email);

        $existingEntry = Cache::get($cacheKey);
        if (is_array($existingEntry) && ($existingEntry['verified'] ?? false)) {
            return response()->json([
                'success' => true,
                'already_verified' => true,
                'message' => 'Email is already verified. You can proceed to the next step.',
            ]);
        }

        $ttl = now()->addMinutes(self::REGISTRATION_EMAIL_OTP_TTL_MINUTES);

        Cache::put($cacheKey, [
            'otp_hash' => Hash::make($otp),
            'attempts' => 0,
            'expires_at' => $ttl->timestamp,
            'verified' => false,
            'verified_at' => null,
        ], $ttl);

        try {
            Mail::raw(
                "Your SoleSpace shop registration verification code is {$otp}. This code expires in "
                . self::REGISTRATION_EMAIL_OTP_TTL_MINUTES
                . ' minutes.',
                function ($message) use ($email) {
                    $message->to($email)
                        ->subject('SoleSpace Shop Registration Verification Code');
                }
            );
        } catch (\Throwable $e) {
            Cache::forget($cacheKey);

            Log::error('Failed to send shop owner registration OTP email', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to send verification code right now. Please try again later.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Verification code sent to your email.',
        ]);
    }

    /**
     * Verify OTP sent for shop owner registration email.
     */
    public function verifyRegistrationEmailOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'otp' => ['required', 'digits:6'],
        ]);

        $email = $this->normalizeEmail($validated['email']);
        $otp = (string) $validated['otp'];
        $cacheKey = $this->registrationEmailOtpCacheKey($email);
        $entry = Cache::get($cacheKey);

        if (!is_array($entry)) {
            return response()->json([
                'success' => false,
                'message' => 'Verification code is invalid or expired. Please request a new code.',
            ], 422);
        }

        if ((int) ($entry['attempts'] ?? 0) >= self::REGISTRATION_EMAIL_OTP_MAX_ATTEMPTS) {
            Cache::forget($cacheKey);

            return response()->json([
                'success' => false,
                'message' => 'Too many failed attempts. Please request a new code.',
            ], 422);
        }

        if ((int) ($entry['expires_at'] ?? 0) < now()->timestamp) {
            Cache::forget($cacheKey);

            return response()->json([
                'success' => false,
                'message' => 'Verification code has expired. Please request a new code.',
            ], 422);
        }

        if (!Hash::check($otp, (string) ($entry['otp_hash'] ?? ''))) {
            $entry['attempts'] = (int) ($entry['attempts'] ?? 0) + 1;
            $secondsLeft = max(1, ((int) ($entry['expires_at'] ?? now()->timestamp)) - now()->timestamp);
            Cache::put($cacheKey, $entry, now()->addSeconds($secondsLeft));

            return response()->json([
                'success' => false,
                'message' => 'Incorrect verification code. Please try again.',
            ], 422);
        }

        $entry['verified'] = true;
        $entry['verified_at'] = now()->timestamp;
        $entry['otp_hash'] = null;
        $entry['attempts'] = 0;
        Cache::put($cacheKey, $entry, now()->addMinutes(self::REGISTRATION_EMAIL_VERIFIED_TTL_MINUTES));

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully.',
        ]);
    }

    /**
     * Register a new shop owner
     * 
     * Shop owners are created with 'pending' status
     * Super admin must approve before they can login
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function register(Request $request, CaviteLocationPolicyService $caviteLocationPolicy)
    {
        try {
            // Validate registration data
            $validated = $request->validate([
                'first_name' => 'required|string|max:255|min:2',
                'last_name' => 'required|string|max:255|min:2',
                'email' => ['required', 'string', 'email', 'max:255', new NotDisposableEmail()],
                'phone' => ['required', 'regex:/^\d{11}$/'],
                'business_name' => 'required|string|max:255',
                'business_address' => 'required|string|max:500',
                'postal_code' => 'nullable|string|max:20',
                'zip_code' => 'nullable|string|max:20',
                'business_type' => 'required|in:retail,repair,both (retail & repair)',
                'registration_type' => 'required|in:individual,company',
                'attendance_geofence_enabled' => 'sometimes|boolean',
                'shop_latitude' => 'nullable|numeric|between:-90,90',
                'shop_longitude' => 'nullable|numeric|between:-180,180',
                'shop_address' => 'nullable|string|max:500',
                'shop_geofence_radius' => 'nullable|integer|min:10|max:5000',
                // operating hours removed from required validation

                // Versioned document uploads
                'business_registration' => 'required|file|mimes:jpg,jpeg,png|max:5120',
                'business_registration_type' => 'required|in:dti_registration,sec_registration',
                'mayors_permit' => 'required|file|mimes:jpg,jpeg,png|max:5120',
                'bir_certificate' => 'required|file|mimes:jpg,jpeg,png|max:5120',
                'valid_id' => 'required|file|mimes:jpg,jpeg,png|max:5120',
                'document_metadata' => 'required|array',
                'document_metadata.business_registration' => 'required|array',
                'document_metadata.mayors_permit' => 'required|array',
                'document_metadata.bir_certificate' => 'required|array',
                'document_metadata.valid_id' => 'required|array',
                'document_metadata.*.expiration_mode' => 'required|string',
                'document_metadata.*.expires_on' => 'nullable|date_format:Y-m-d',
                'document_metadata.*.issued_on' => 'nullable|date_format:Y-m-d',
                'submission_keys' => 'nullable|array',
                'submission_keys.*' => 'uuid',
                'other_documents' => 'nullable|array|max:8',
                'other_documents.*' => 'file|mimes:jpg,jpeg,png|max:5120',
                'other_document_metadata' => 'nullable|array|max:8',
                'other_document_metadata.*' => 'required|array',
                'other_document_metadata.*.expiration_mode' => 'required|string',
                'other_document_metadata.*.expires_on' => 'nullable|date_format:Y-m-d',
                'other_document_metadata.*.issued_on' => 'nullable|date_format:Y-m-d',
            ], [
                'first_name.required' => 'Please enter your first name.',
                'first_name.min' => 'First name must be at least 2 characters.',
                'last_name.required' => 'Please enter your last name.',
                'last_name.min' => 'Last name must be at least 2 characters.',
                'email.required' => 'Please enter your email address.',
                'email.email' => 'Please enter a valid email address (example: name@email.com).',
                'phone.required' => 'Please enter your phone number.',
                'phone.regex' => 'Phone number must be exactly 11 digits (example: 09171234567).',
                'business_name.required' => 'Please enter your shop name.',
                'business_address.required' => 'Please enter your shop address.',
                'business_type.required' => 'Please select your shop type.',
                'business_type.in' => 'Invalid shop type. Choose Retail, Repair, or Both (Retail & Repair).',
                'registration_type.required' => 'Please select your registration type.',
                'registration_type.in' => 'Invalid registration type. Choose Individual or Company.',
                'shop_latitude.numeric' => 'Shop latitude must be a valid number.',
                'shop_latitude.between' => 'Shop latitude is out of range. Set a valid map location.',
                'shop_longitude.numeric' => 'Shop longitude must be a valid number.',
                'shop_longitude.between' => 'Shop longitude is out of range. Set a valid map location.',
                'shop_geofence_radius.integer' => 'Geofence radius must be a whole number.',
                'shop_geofence_radius.min' => 'Geofence radius must be at least 10 meters.',
                'shop_geofence_radius.max' => 'Geofence radius must not exceed 5000 meters.',
                'business_registration.required' => 'Upload your Shop Registration document (DTI or SEC).',
                'business_registration.file' => 'Business registration must be a valid file.',
                'business_registration.mimes' => 'Business registration must be JPG, JPEG, or PNG only.',
                'business_registration.max' => 'Business registration file size must not exceed 5MB.',
                'business_registration_type.required' => 'Choose whether the business registration is DTI or SEC.',
                'mayors_permit.required' => "Upload your Mayor's Permit or Shop Permit.",
                'mayors_permit.file' => "Mayor's Permit / Shop Permit must be a valid file.",
                'mayors_permit.mimes' => "Mayor's Permit / Shop Permit must be JPG, JPEG, or PNG only.",
                'mayors_permit.max' => "Mayor's Permit / Shop Permit file size must not exceed 5MB.",
                'bir_certificate.required' => 'Upload your BIR Certificate of Registration (COR).',
                'bir_certificate.file' => 'BIR Certificate of Registration must be a valid file.',
                'bir_certificate.mimes' => 'BIR Certificate of Registration must be JPG, JPEG, or PNG only.',
                'bir_certificate.max' => 'BIR Certificate of Registration file size must not exceed 5MB.',
                'valid_id.required' => 'Upload a valid government-issued ID of the owner.',
                'valid_id.file' => 'Valid ID must be a valid file.',
                'valid_id.mimes' => 'Valid ID must be JPG, JPEG, or PNG only.',
                'valid_id.max' => 'Valid ID file size must not exceed 5MB.',
                'other_documents.array' => 'Other supporting documents must be a valid list of files.',
                'other_documents.max' => 'You can upload up to 8 other supporting documents.',
                'other_documents.*.file' => 'Each supporting document must be a valid file.',
                'other_documents.*.mimes' => 'Each supporting document must be JPG, JPEG, or PNG only.',
                'other_documents.*.max' => 'Each supporting document must not exceed 5MB.',
            ]);

            $normalizedEmail = $this->normalizeEmail((string) ($validated['email'] ?? ''));

            $availability = $this->checkRegistrationEmailAvailability($normalizedEmail);
            if (!$availability['available']) {
                throw ValidationException::withMessages([
                    'email' => [$availability['message'] ?? 'This email is already registered'],
                ]);
            }

            $this->assertRegistrationEmailVerified($normalizedEmail);

            $rejectedShopOwnerId = isset($availability['rejected_shop_owner_id'])
                ? (int) $availability['rejected_shop_owner_id']
                : 0;
            $existingRejectedShopOwner = $rejectedShopOwnerId > 0
                ? ShopOwner::find($rejectedShopOwnerId)
                : null;
            $isReapplication = $existingRejectedShopOwner instanceof ShopOwner;

            if ($isReapplication && (int) ($existingRejectedShopOwner->resubmission_count ?? 0) >= self::MAX_RESUBMISSION_ATTEMPTS) {
                throw ValidationException::withMessages([
                    'email' => ['Resubmission limit reached. You can only resubmit up to ' . self::MAX_RESUBMISSION_ATTEMPTS . ' times.'],
                ]);
            }

            $caviteLocationPolicy->assertRegistrationLocation(
                $validated['shop_latitude'] ?? null,
                $validated['shop_longitude'] ?? null,
                $validated['business_address'] ?? null,
                $request,
                null,
                [
                    'email' => $normalizedEmail,
                    'business_name' => $validated['business_name'] ?? null,
                    'target_type' => 'shop_owner_registration',
                ]
            );

            $shopOwner = DB::transaction(function () use (
                $validated,
                $normalizedEmail,
                $existingRejectedShopOwner,
                $isReapplication,
                $request,
            ): ShopOwner {
                if ($isReapplication) {
                    $shopOwner = ShopOwner::query()->lockForUpdate()->findOrFail($existingRejectedShopOwner->id);
                    $shopOwner->update([
                        'first_name' => $validated['first_name'],
                        'last_name' => $validated['last_name'],
                        'email' => $normalizedEmail,
                        'phone' => $validated['phone'],
                        'password' => null,
                        'business_name' => $validated['business_name'],
                        'business_address' => $validated['business_address'],
                        'postal_code' => $validated['postal_code'] ?? $validated['zip_code'] ?? null,
                        'business_type' => $validated['business_type'],
                        'registration_type' => $validated['registration_type'],
                        'attendance_geofence_enabled' => (bool) ($validated['attendance_geofence_enabled'] ?? false),
                        'shop_latitude' => $validated['shop_latitude'] ?? null,
                        'shop_longitude' => $validated['shop_longitude'] ?? null,
                        'shop_address' => $validated['shop_address'] ?? $validated['business_address'],
                        'shop_geofence_radius' => $validated['shop_geofence_radius'] ?? 100,
                        'status' => 'pending',
                        'rejection_reason' => null,
                        'resubmission_count' => (int) ($shopOwner->resubmission_count ?? 0) + 1,
                    ]);
                } else {
                    $shopOwner = ShopOwner::create([
                        'first_name' => $validated['first_name'],
                        'last_name' => $validated['last_name'],
                        'email' => $normalizedEmail,
                        'phone' => $validated['phone'],
                        'password' => null,
                        'business_name' => $validated['business_name'],
                        'business_address' => $validated['business_address'],
                        'postal_code' => $validated['postal_code'] ?? $validated['zip_code'] ?? null,
                        'business_type' => $validated['business_type'],
                        'registration_type' => $validated['registration_type'],
                        'attendance_geofence_enabled' => (bool) ($validated['attendance_geofence_enabled'] ?? false),
                        'shop_latitude' => $validated['shop_latitude'] ?? null,
                        'shop_longitude' => $validated['shop_longitude'] ?? null,
                        'shop_address' => $validated['shop_address'] ?? $validated['business_address'],
                        'shop_geofence_radius' => $validated['shop_geofence_radius'] ?? 100,
                        'status' => 'pending',
                        'resubmission_count' => 0,
                    ]);
                }

                $predecessors = $isReapplication
                    ? $this->latestDocumentsByLogicalSlot($shopOwner)
                    : [];
                $entries = $this->registrationDocumentEntries($request, $validated, $predecessors, $isReapplication);
                $this->documentLifecycle->createPendingVersions($shopOwner, $entries, $isReapplication);

                return $shopOwner->fresh();
            });

            Cache::forget($this->registrationEmailOtpCacheKey($normalizedEmail));

            Log::info('Shop owner registered successfully', [
                'shop_owner_id' => $shopOwner->id,
                'email' => $shopOwner->email,
                'business_name' => $shopOwner->business_name,
            ]);

            $this->notifySuperAdminsOfPendingRegistration($shopOwner);
            $this->sendApplicationUnderReviewEmail($shopOwner);

            // Auto-login the shop owner so they can access the pending approval page
            Auth::guard('shop_owner')->login($shopOwner);

            // Return success response
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $isReapplication
                        ? 'Application resubmitted successfully! Your application is now pending admin review.'
                        : 'Registration successful! Your application is now pending admin review.',
                    'redirect' => route('shop-owner.pending-approval'),
                    'shop_owner' => [
                        'id' => $shopOwner->id,
                        'business_name' => $shopOwner->business_name,
                        'email' => $shopOwner->email,
                        'status' => $shopOwner->status,
                    ],
                    'csrf_token' => csrf_token(), // Send new CSRF token
                ], 201);
            }

            return redirect()->route('shop-owner.pending-approval')->with([
                'success' => $isReapplication
                    ? 'Application resubmitted successfully! Your application is now pending admin review.'
                    : 'Registration successful! Your application is now pending admin review.',
                'email' => $shopOwner->email,
            ]);
        } catch (ValidationException $e) {
            Log::warning('Shop owner registration validation failed', ['errors' => $e->errors()]);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $caviteLocationPolicy->isLocationPolicyValidationException($e)
                        ? $caviteLocationPolicy->denialMessage()
                        : 'Please review the highlighted fields and try again.',
                    'errors' => $e->errors(),
                ], 422);
            }

            throw $e;
        } catch (\Exception $e) {
            Log::error('Error registering shop owner', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Registration failed. Please try again.',
                ], 500);
            }

            return back()->withErrors(['message' => 'Registration failed. Please try again.'])->withInput();
        }
    }

    /**
     * @return array<string, ShopDocument>
     */
    private function latestDocumentsByLogicalSlot(ShopOwner $shopOwner): array
    {
        $latest = [];

        foreach ($shopOwner->documents()->orderBy('id')->get() as $document) {
            $slot = trim((string) $document->logical_slot);
            if ($slot !== '' && $this->documentRequirements->slotForType($slot) !== $slot) {
                $slot = '';
            }

            if ($slot === '') {
                $normalizedType = $this->documentRequirements->normalizeType((string) $document->document_type);
                $slot = match ($normalizedType) {
                    'dti_registration', 'sec_registration' => 'business_registration',
                    'mayors_permit', 'bir_certificate', 'valid_id' => $normalizedType,
                    'other_supporting_document' => 'supporting_document:legacy:' . $document->id,
                    default => '',
                };
            }

            if ($slot !== '') {
                $latest[$slot] = $document;
            }
        }

        return $latest;
    }

    /**
     * @param array<string, mixed> $validated
     * @param array<string, ShopDocument> $predecessors
     * @return array<int, array{metadata: array<string, mixed>, file?: \Illuminate\Http\UploadedFile|null, predecessor?: ShopDocument|null, submission_key?: string|null}>
     */
    private function registrationDocumentEntries(
        Request $request,
        array $validated,
        array $predecessors,
        bool $isReapplication,
    ): array {
        $documentTypes = [
            'business_registration' => (string) $validated['business_registration_type'],
            'mayors_permit' => 'mayors_permit',
            'bir_certificate' => 'bir_certificate',
            'valid_id' => 'valid_id',
        ];
        $errors = [];
        $records = [];
        $entries = [];

        foreach ($documentTypes as $slot => $documentType) {
            $metadata = is_array($validated['document_metadata'][$slot] ?? null)
                ? $validated['document_metadata'][$slot]
                : [];
            $metadata['document_type'] = $documentType;
            $metadata['logical_slot'] = $slot;
            $file = $request->file($slot);
            $predecessor = $predecessors[$slot] ?? null;

            if (! $file && ! $predecessor) {
                $errors[$slot][] = 'This document is required.';
            }

            if (! $file && $predecessor && $slot === 'business_registration') {
                $previousType = $this->documentRequirements->normalizeType((string) $predecessor->document_type);
                if ($previousType !== $documentType) {
                    $errors[$slot][] = 'Upload a new file when changing DTI/SEC registration type.';
                }
            }

            if (! $file && $predecessor && (
                (string) $predecessor->disk !== 'local'
                || trim((string) $predecessor->file_path) === ''
                || ! Storage::disk('local')->exists((string) $predecessor->file_path)
            )) {
                $errors[$slot][] = 'Upload a replacement because the previous private document is unavailable.';
            }

            $records[] = $metadata;
            $entries[] = [
                'metadata' => $metadata,
                'file' => $file,
                'predecessor' => $predecessor,
                'submission_key' => $validated['submission_keys'][$slot] ?? Str::uuid()->toString(),
            ];
        }

        $uploadedSupportingDocuments = $request->file('other_documents', []);
        $supportingMetadata = $validated['other_document_metadata'] ?? [];
        $supportingKeys = array_values(array_unique(array_merge(
            array_keys(is_array($uploadedSupportingDocuments) ? $uploadedSupportingDocuments : []),
            array_keys(is_array($supportingMetadata) ? $supportingMetadata : []),
        )));

        foreach ($supportingKeys as $supportingKey) {
            $isUuidSlot = is_string($supportingKey) && Str::isUuid($supportingKey);
            $isLegacySlot = is_string($supportingKey)
                && preg_match('/^legacy:[1-9][0-9]*$/', $supportingKey) === 1
                && isset($predecessors['supporting_document:'.$supportingKey]);
            if (! $isUuidSlot && ! $isLegacySlot) {
                $errors['other_documents'][] = 'Supporting document slots must use a stable UUID or an existing legacy slot.';
                continue;
            }

            $slot = 'supporting_document:' . strtolower($supportingKey);
            $metadata = is_array($supportingMetadata[$supportingKey] ?? null)
                ? $supportingMetadata[$supportingKey]
                : [];
            $metadata['document_type'] = 'supporting_document';
            $metadata['logical_slot'] = $slot;
            $file = is_array($uploadedSupportingDocuments)
                ? ($uploadedSupportingDocuments[$supportingKey] ?? null)
                : null;
            $predecessor = $predecessors[$slot] ?? null;

            if (! $file && ! $predecessor) {
                $errors[$slot][] = 'Upload this supporting document or provide a reusable predecessor.';
            }

            $records[] = $metadata;
            $entries[] = [
                'metadata' => $metadata,
                'file' => $file,
                'predecessor' => $predecessor,
                'submission_key' => $validated['submission_keys'][$slot] ?? Str::uuid()->toString(),
            ];
        }

        foreach ($this->documentRequirements->validateSubmission($records) as $slot => $messages) {
            $errors[$slot] = array_merge($errors[$slot] ?? [], $messages);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $entries;
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function registrationEmailOtpCacheKey(string $email): string
    {
        return 'shop_owner_registration_email_otp:' . sha1($this->normalizeEmail($email));
    }

    private function checkRegistrationEmailAvailability(string $email): array
    {
        $normalizedEmail = $this->normalizeEmail($email);

        $existsInEmployees = Employee::whereRaw('LOWER(email) = ?', [$normalizedEmail])->exists();
        $existsInUsers = User::whereRaw('LOWER(email) = ?', [$normalizedEmail])->exists();
        $existingShopOwner = ShopOwner::whereRaw('LOWER(email) = ?', [$normalizedEmail])->first();

        $rejectedShopOwnerId = null;
        $existsInShopOwners = false;
        $isRejectedShopOwnerEmail = false;
        $rejectedUsedAttempts = 0;
        $rejectedRemainingAttempts = self::MAX_RESUBMISSION_ATTEMPTS;

        if ($existingShopOwner) {
            $statusValue = $existingShopOwner->status instanceof ShopOwnerStatus
                ? $existingShopOwner->status->value
                : (string) $existingShopOwner->status;

            if ($statusValue === ShopOwnerStatus::REJECTED->value) {
                $rejectedShopOwnerId = (int) $existingShopOwner->id;
                $isRejectedShopOwnerEmail = true;
                $rejectedUsedAttempts = max(0, (int) ($existingShopOwner->resubmission_count ?? 0));
                $rejectedRemainingAttempts = max(0, self::MAX_RESUBMISSION_ATTEMPTS - $rejectedUsedAttempts);

                if ($rejectedRemainingAttempts <= 0) {
                    $existsInShopOwners = true;
                }
            } else {
                $existsInShopOwners = true;
            }
        }

        $available = !($existsInEmployees || $existsInUsers || $existsInShopOwners);

        if (!$available) {
            if ($isRejectedShopOwnerEmail && $rejectedRemainingAttempts <= 0) {
                $message = 'Resubmission limit reached. You can only resubmit up to ' . self::MAX_RESUBMISSION_ATTEMPTS . ' times.';
            } else {
                $message = 'This email is already registered';
            }
        } elseif ($rejectedShopOwnerId !== null) {
            $nextAttempt = $rejectedUsedAttempts + 1;
            $message = 'This email belongs to a previously rejected application. Reapply attempt ' . $nextAttempt . ' of ' . self::MAX_RESUBMISSION_ATTEMPTS . ' is available.';
        } else {
            $message = 'Email is available';
        }

        return [
            'available' => $available,
            'message' => $message,
            'rejected_shop_owner_id' => $rejectedShopOwnerId,
            'is_rejected_shop_owner_email' => $isRejectedShopOwnerEmail,
            'max_resubmission_attempts' => self::MAX_RESUBMISSION_ATTEMPTS,
            'rejected_used_attempts' => $rejectedUsedAttempts,
            'rejected_remaining_attempts' => $rejectedRemainingAttempts,
        ];
    }

    private function assertRegistrationEmailVerified(string $email): void
    {
        $entry = Cache::get($this->registrationEmailOtpCacheKey($email));

        if (!is_array($entry) || !($entry['verified'] ?? false)) {
            throw ValidationException::withMessages([
                'email' => ['Please verify your email first before proceeding to the next step.'],
            ]);
        }
    }

    private function sendApplicationUnderReviewEmail(ShopOwner $shopOwner): void
    {
        $pendingApprovalUrl = URL::temporarySignedRoute(
            'shop-owner.pending-approval.public',
            now()->addDays(30),
            ['shopOwner' => $shopOwner->id]
        );

        try {
            Mail::to($shopOwner->email)->send(new ShopOwnerApplicationUnderReviewMail(
                ownerName: trim(((string) $shopOwner->first_name) . ' ' . ((string) $shopOwner->last_name)),
                businessName: (string) $shopOwner->business_name,
                pendingApprovalUrl: $pendingApprovalUrl,
            ));
        } catch (\Throwable $e) {
            Log::warning('Failed to send shop owner application under-review email', [
                'shop_owner_id' => $shopOwner->id,
                'email' => $shopOwner->email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifySuperAdminsOfPendingRegistration(ShopOwner $shopOwner): void
    {
        Notification::notifyAllSuperAdmins(
            type: NotificationType::SHOP_REGISTRATION_PENDING,
            title: 'New shop registration',
            message: (string) $shopOwner->business_name.' submitted a registration for review.',
            actionUrl: '/admin/registrations?status=pending',
            data: [
                'shop_owner_id' => (int) $shopOwner->getKey(),
                'business_name' => (string) $shopOwner->business_name,
            ],
        );
    }

    /**
     * Login a shop owner
     * 
     * Only approved shop owners can login
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function login(Request $request)
    {
        try {
            $credentials = $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);

            // Find shop owner by email
            $shopOwner = ShopOwner::where('email', $credentials['email'])->first();

            $passwordHash = $shopOwner?->getAuthPassword() ?: self::DUMMY_PASSWORD_HASH;
            if (! $shopOwner || ! Hash::check((string) $credentials['password'], (string) $passwordHash)) {
                throw ValidationException::withMessages([
                    'email' => ['Invalid email or password.'],
                ]);
            }

            $statusValue = $shopOwner->status instanceof ShopOwnerStatus
                ? $shopOwner->status->value
                : (string) $shopOwner->status;

            // Account status is revealed only after selected-context credentials verify.
            if ($statusValue === ShopOwnerStatus::PENDING->value) {
                throw ValidationException::withMessages([
                    'email' => ['Your application is still pending admin approval. Please wait for confirmation.'],
                ]);
            }

            if ($statusValue === ShopOwnerStatus::REJECTED->value) {
                $reason = $shopOwner->rejection_reason ? ': ' . $shopOwner->rejection_reason : '';
                throw ValidationException::withMessages([
                    'email' => ['Your application was rejected' . $reason . '. Please contact support.'],
                ]);
            }

            if ($statusValue !== ShopOwnerStatus::APPROVED->value) {
                throw ValidationException::withMessages([
                    'email' => ['Your account is inactive. Please contact support.'],
                ]);
            }

            $remember = (bool) $request->boolean('remember');

            if ($shopOwner->hasTotpEnabled()) {
                $this->beginLoginTwoFactorChallenge($request, $shopOwner, $remember);

                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => true,
                        'requires_two_factor' => true,
                        'message' => 'Authenticator verification required.',
                        'redirect' => route('shop-owner.two-factor.challenge'),
                    ], 202);
                }

                return redirect()->route('shop-owner.two-factor.challenge');
            }

            if ((bool) ($shopOwner->two_factor_email_enabled ?? false)) {
                $this->beginLoginTwoFactorEnrollment($request, $shopOwner, $remember);

                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => true,
                        'requires_two_factor_setup' => true,
                        'message' => 'Authenticator setup is required before you can sign in.',
                        'redirect' => route('shop-owner.two-factor.enroll'),
                    ], 202);
                }

                return redirect()->route('shop-owner.two-factor.enroll');
            }

            // Login the shop owner using shop_owner guard
            Auth::guard('shop_owner')->login($shopOwner, $remember);

            // Regenerate session
            $request->session()->regenerate();

            // Update last login information
            $shopOwner->update([
                'last_login_at' => now(),
                'last_login_ip' => $request->ip(),
            ]);

            Log::info('Shop owner logged in successfully', [
                'shop_owner_id' => $shopOwner->id,
                'business_name' => $shopOwner->business_name,
            ]);

            // Return success response
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Login successful!',
                    'shop_owner' => [
                        'id' => $shopOwner->id,
                        'business_name' => $shopOwner->business_name,
                        'email' => $shopOwner->email,
                    ],
                ]);
            }

            return redirect()->route('shop-owner.dashboard')->with('success', 'Welcome back!');
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Login failed',
                    'errors' => $e->errors(),
                ], 422);
            }

            throw $e;
        } catch (\Exception $e) {
            Log::error('Error logging in shop owner', ['error' => $e->getMessage()]);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Login failed. Please try again.',
                ], 500);
            }

            return back()->withErrors(['email' => 'Login failed. Please try again.']);
        }
    }

    /**
     * Show the shop owner login two-factor challenge page.
     */
    public function showTwoFactorChallenge(Request $request)
    {
        $shopOwner = $this->resolvePendingTwoFactorShopOwner($request);
        if (!$shopOwner) {
            return redirect()->route('shop-owner.login.form');
        }

        if ($request->session()->has(self::LOGIN_TOTP_ENROLLMENT_SESSION_KEY)) {
            return redirect()->route('shop-owner.two-factor.enroll');
        }

        return Inertia::render('ERP/EmployeeMfaChallenge', [
            'companyAccount' => (string) $shopOwner->email,
            'verifyRoute' => route('shop-owner.two-factor.verify'),
            'loginRoute' => route('login'),
        ]);
    }

    public function showTwoFactorEnrollment(Request $request)
    {
        $shopOwner = $this->resolvePendingTwoFactorShopOwner($request);
        $pending = $shopOwner ? $this->pendingLoginEnrollment($request, $shopOwner) : null;

        if (! $shopOwner || $pending === null) {
            $this->clearLoginTwoFactorChallenge($request, $shopOwner?->getKey());

            return redirect()->route('shop-owner.login.form');
        }

        try {
            $secret = Crypt::decryptString($pending['secret']);
        } catch (\Throwable) {
            $this->clearLoginTwoFactorChallenge($request, (int) $shopOwner->getKey());

            return redirect()->route('shop-owner.login.form');
        }

        return Inertia::render('UserSide/Auth/ShopOwnerTotpEnrollment', [
            'qr_code' => $this->mfa->qrDataUri(
                $this->mfa->provisioningUriForEmail((string) $shopOwner->email, $secret),
            ),
            'manual_key' => $secret,
            'expires_at' => $pending['expires_at'],
        ]);
    }

    /**
     * Verify login two-factor OTP for shop owner.
     */
    public function verifyLoginTwoFactorOtp(Request $request)
    {
        try {
            $validated = $request->validate([
                'code' => ['required', 'string', 'max:32'],
            ]);

            $shopOwner = $this->resolvePendingTwoFactorShopOwner($request);
            if (!$shopOwner) {
                throw ValidationException::withMessages([
                    'code' => ['Your login session expired. Please sign in again.'],
                ]);
            }

            if ($request->session()->has(self::LOGIN_TOTP_ENROLLMENT_SESSION_KEY)) {
                throw ValidationException::withMessages([
                    'code' => ['Authenticator setup is required before you can sign in.'],
                ]);
            }

            $attempts = (int) $request->session()->get(self::LOGIN_TWO_FACTOR_ATTEMPTS_SESSION_KEY, 0);
            if ($attempts >= self::LOGIN_TOTP_MAX_ATTEMPTS) {
                $this->clearLoginTwoFactorChallenge($request, (int) $shopOwner->id);

                throw ValidationException::withMessages([
                    'code' => ['Too many failed attempts. Please sign in again.'],
                ]);
            }

            $code = trim((string) $validated['code']);
            $method = preg_match('/\A\d{6}\z/', $code) === 1 ? 'totp' : 'recovery_code';
            $authenticatedOwner = DB::transaction(function () use ($shopOwner, $request, $code, $method): ShopOwner|false {
                $lockedOwner = ShopOwner::query()
                    ->whereKey($shopOwner->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $lockedOwner instanceof ShopOwner
                    || ! $this->isApprovedShopOwner($lockedOwner)
                    || ! $lockedOwner->hasTotpEnabled()) {
                    return false;
                }

                if ($method === 'totp') {
                    $secret = $lockedOwner->shop_owner_totp_secret;
                    $acceptedTimestep = is_string($secret)
                        ? $this->mfa->consumeTotpState(
                            $secret,
                            $lockedOwner->shop_owner_totp_last_used_timestep,
                            $code,
                            intdiv(now()->timestamp, 30),
                        )
                        : false;

                    if (! is_int($acceptedTimestep)) {
                        return false;
                    }

                    $lockedOwner->shop_owner_totp_last_used_timestep = $acceptedTimestep;
                } else {
                    $remainingCodes = $this->mfa->consumeRecoveryCodeFromHashes(
                        is_array($lockedOwner->shop_owner_totp_recovery_codes)
                            ? $lockedOwner->shop_owner_totp_recovery_codes
                            : [],
                        $code,
                    );

                    if ($remainingCodes === false) {
                        return false;
                    }

                    $lockedOwner->shop_owner_totp_recovery_codes = $remainingCodes;
                }

                $lockedOwner->save();

                return $lockedOwner;
            });

            if (! $authenticatedOwner instanceof ShopOwner) {
                $attempts++;
                if ($attempts >= self::LOGIN_TOTP_MAX_ATTEMPTS) {
                    $this->clearLoginTwoFactorChallenge($request, (int) $shopOwner->id);
                } else {
                    $request->session()->put(self::LOGIN_TWO_FACTOR_ATTEMPTS_SESSION_KEY, $attempts);
                }

                throw ValidationException::withMessages([
                    'code' => ['Incorrect verification code. Please try again.'],
                ]);
            }

            $this->completeTwoFactorLogin($request, $authenticatedOwner);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Two-factor verification successful.',
                    'redirect' => route('shop-owner.dashboard'),
                ]);
            }

            return redirect()->route('shop-owner.dashboard')->with('success', 'Welcome back!');
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Two-factor verification failed.',
                    'errors' => $e->errors(),
                ], 422);
            }

            throw $e;
        } catch (\Exception $e) {
            Log::error('Error verifying shop owner TOTP', ['error' => $e->getMessage()]);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to verify authenticator code right now. Please try again.',
                ], 500);
            }

            return back()->withErrors(['code' => 'Unable to verify authenticator code right now. Please try again.']);
        }
    }

    public function verifyTwoFactorEnrollment(Request $request)
    {
        try {
            $validated = $request->validate([
                'code' => ['required', 'digits:6'],
            ]);

            $shopOwner = $this->resolvePendingTwoFactorShopOwner($request);
            $pending = $shopOwner ? $this->pendingLoginEnrollment($request, $shopOwner) : null;
            if (! $shopOwner || $pending === null) {
                throw ValidationException::withMessages([
                    'code' => ['Your authenticator setup session expired. Please sign in again.'],
                ]);
            }

            try {
                $secret = Crypt::decryptString($pending['secret']);
            } catch (\Throwable) {
                $this->clearLoginTwoFactorChallenge($request, (int) $shopOwner->getKey());

                throw ValidationException::withMessages([
                    'code' => ['Your authenticator setup session is invalid. Please sign in again.'],
                ]);
            }

            $attempts = (int) $request->session()->get(self::LOGIN_TWO_FACTOR_ATTEMPTS_SESSION_KEY, 0);
            if ($attempts >= self::LOGIN_TOTP_MAX_ATTEMPTS) {
                $this->clearLoginTwoFactorChallenge($request, (int) $shopOwner->getKey());

                throw ValidationException::withMessages([
                    'code' => ['Too many failed attempts. Please sign in again.'],
                ]);
            }

            $result = DB::transaction(function () use ($shopOwner, $secret, $validated, $request): array|false {
                $lockedOwner = ShopOwner::query()
                    ->whereKey($shopOwner->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $lockedOwner instanceof ShopOwner
                    || ! $this->isApprovedShopOwner($lockedOwner)
                    || $lockedOwner->hasTotpEnabled()
                    || ! (bool) $lockedOwner->two_factor_email_enabled
                    || ! $this->mfa->verifyEnrollment($secret, (string) $validated['code'])) {
                    return false;
                }

                $recoveryCodes = $this->mfa->generateRecoveryCodes();
                $lockedOwner->forceFill([
                    'shop_owner_totp_secret' => $secret,
                    'shop_owner_totp_enabled_at' => now(),
                    'shop_owner_totp_recovery_codes' => $this->mfa->hashRecoveryCodes($recoveryCodes),
                    'shop_owner_totp_last_used_timestep' => null,
                    'two_factor_email_enabled' => false,
                ])->save();

                return [
                    'owner' => $lockedOwner,
                    'recovery_codes' => $recoveryCodes,
                ];
            });

            if ($result === false) {
                $attempts++;
                if ($attempts >= self::LOGIN_TOTP_MAX_ATTEMPTS) {
                    $this->clearLoginTwoFactorChallenge($request, (int) $shopOwner->getKey());
                } else {
                    $request->session()->put(self::LOGIN_TWO_FACTOR_ATTEMPTS_SESSION_KEY, $attempts);
                }

                throw ValidationException::withMessages([
                    'code' => ['The authenticator code is invalid. Please try again.'],
                ]);
            }

            /** @var ShopOwner $authenticatedOwner */
            $authenticatedOwner = $result['owner'];
            $this->completeTwoFactorLogin($request, $authenticatedOwner);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Two-factor authentication enabled.',
                    'recovery_codes' => $result['recovery_codes'],
                    'redirect' => route('shop-owner.dashboard'),
                ]);
            }

            return redirect()->route('shop-owner.dashboard')->with('success', 'Welcome back!');
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authenticator setup could not be completed.',
                    'errors' => $e->errors(),
                ], 422);
            }

            throw $e;
        } catch (\Exception $e) {
            Log::error('Error completing Shop Owner TOTP enrollment', ['error' => $e->getMessage()]);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to complete authenticator setup right now. Please try again.',
                ], 500);
            }

            return back()->withErrors(['code' => 'Unable to complete authenticator setup right now. Please try again.']);
        }
    }

    private function beginLoginTwoFactorChallenge(Request $request, ShopOwner $shopOwner, bool $remember): void
    {
        $request->session()->put([
            'shop_owner_2fa_pending_id' => (int) $shopOwner->id,
            'shop_owner_2fa_remember' => $remember,
            'shop_owner_2fa_pending_at' => now()->timestamp,
            self::LOGIN_TWO_FACTOR_ATTEMPTS_SESSION_KEY => 0,
        ]);
        $request->session()->forget(self::LOGIN_TWO_FACTOR_SESSION_KEY);
        $request->session()->forget(self::LOGIN_TOTP_ENROLLMENT_SESSION_KEY);
    }

    private function beginLoginTwoFactorEnrollment(Request $request, ShopOwner $shopOwner, bool $remember): void
    {
        $this->beginLoginTwoFactorChallenge($request, $shopOwner, $remember);

        $secret = $this->mfa->generateSecret();
        $expiresAt = now()->addMinutes(self::LOGIN_TOTP_TTL_MINUTES);

        $request->session()->put(self::LOGIN_TOTP_ENROLLMENT_SESSION_KEY, [
            'shop_owner_id' => (int) $shopOwner->getKey(),
            'secret' => Crypt::encryptString($secret),
            'expires_at' => $expiresAt->timestamp,
        ]);
    }

    private function resolvePendingTwoFactorShopOwner(Request $request): ?ShopOwner
    {
        $pendingId = (int) $request->session()->get('shop_owner_2fa_pending_id', 0);
        $pendingAt = (int) $request->session()->get('shop_owner_2fa_pending_at', 0);
        if ($pendingId <= 0 || $pendingAt <= 0 || $pendingAt + (self::LOGIN_TOTP_TTL_MINUTES * 60) <= now()->timestamp) {
            if ($pendingId > 0) {
                $this->clearLoginTwoFactorChallenge($request, $pendingId);
            }

            return null;
        }

        $shopOwner = ShopOwner::find($pendingId);
        if (! $shopOwner instanceof ShopOwner || ! $this->isApprovedShopOwner($shopOwner)) {
            $this->clearLoginTwoFactorChallenge($request, $pendingId);

            return null;
        }

        return $shopOwner;
    }

    /** @return array{shop_owner_id: int, secret: string, expires_at: int}|null */
    private function pendingLoginEnrollment(Request $request, ShopOwner $shopOwner): ?array
    {
        $pending = $request->session()->get(self::LOGIN_TOTP_ENROLLMENT_SESSION_KEY);
        if (! is_array($pending)
            || (int) ($pending['shop_owner_id'] ?? 0) !== (int) $shopOwner->getKey()
            || ! is_string($pending['secret'] ?? null)
            || ! is_numeric($pending['expires_at'] ?? null)) {
            return null;
        }

        if ((int) $pending['expires_at'] <= now()->timestamp) {
            $request->session()->forget(self::LOGIN_TOTP_ENROLLMENT_SESSION_KEY);

            return null;
        }

        return [
            'shop_owner_id' => (int) $pending['shop_owner_id'],
            'secret' => $pending['secret'],
            'expires_at' => (int) $pending['expires_at'],
        ];
    }

    private function completeTwoFactorLogin(Request $request, ShopOwner $shopOwner): void
    {
        $remember = (bool) $request->session()->get('shop_owner_2fa_remember', false);
        $this->clearLoginTwoFactorChallenge($request, (int) $shopOwner->getKey());

        Auth::guard('shop_owner')->login($shopOwner, $remember);
        $request->session()->regenerate();

        Log::info('Shop owner logged in successfully after TOTP verification', [
            'shop_owner_id' => $shopOwner->getKey(),
            'business_name' => $shopOwner->business_name,
        ]);
    }

    private function clearLoginTwoFactorChallenge(Request $request, ?int $shopOwnerId = null): void
    {
        $request->session()->forget('shop_owner_2fa_pending_id');
        $request->session()->forget('shop_owner_2fa_remember');
        $request->session()->forget('shop_owner_2fa_pending_at');
        $request->session()->forget(self::LOGIN_TWO_FACTOR_ATTEMPTS_SESSION_KEY);
        $request->session()->forget(self::LOGIN_TWO_FACTOR_SESSION_KEY);
        $request->session()->forget(self::LOGIN_TOTP_ENROLLMENT_SESSION_KEY);
    }

    private function isApprovedShopOwner(ShopOwner $shopOwner): bool
    {
        $statusValue = $shopOwner->status instanceof ShopOwnerStatus
            ? $shopOwner->status->value
            : (string) $shopOwner->status;

        return $statusValue === ShopOwnerStatus::APPROVED->value;
    }

    /**
     * Logout a shop owner
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function logout(Request $request)
    {
        $shopOwnerId = Auth::guard('shop_owner')->id();

        $this->clearLoginTwoFactorChallenge($request);

        Auth::guard('shop_owner')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Log::info('Shop owner logged out', ['shop_owner_id' => $shopOwnerId]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully',
            ]);
        }

        return redirect()->route('shop-owner.login.form')->with('success', 'You have been logged out.');
    }

    /**
     * Get current authenticated shop owner
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(Request $request)
    {
        $shopOwner = Auth::guard('shop_owner')->user();

        if (!$shopOwner) {
            return response()->json([
                'success' => false,
                'message' => 'Not authenticated',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'shop_owner' => [
                'id' => $shopOwner->id,
                'first_name' => $shopOwner->first_name,
                'last_name' => $shopOwner->last_name,
                'business_name' => $shopOwner->business_name,
                'email' => $shopOwner->email,
                'phone' => $shopOwner->phone,
                'business_address' => $shopOwner->business_address,
                'business_type' => $shopOwner->business_type,
                'status' => $shopOwner->status,
                'operating_hours' => $shopOwner->operating_hours ?? [],
            ],
        ]);
    }
}
