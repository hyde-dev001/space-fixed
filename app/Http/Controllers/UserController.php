<?php

namespace App\Http\Controllers;

use App\Enums\ShopOwnerStatus;
use App\Exceptions\IdentityDocumentScreeningException;
use App\Models\Employee;
use App\Models\ShopOwner;
use App\Models\User;
use App\Models\UserAddress;
use App\Rules\NotDisposableEmail;
use App\Rules\ValidIdentityDocumentImage;
use App\Services\Authentication\UnifiedLoginContextResolver;
use App\Services\HR\EmployeeOperationalPolicy;
use App\Services\IdentityVerificationService;
use App\Services\NominatimService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * UserController
 *
 * Handles user registration, authentication, and profile management
 * for customers, staff, and shop owners on the platform
 */
class UserController extends Controller
{
    public function __construct(
        private readonly EmployeeOperationalPolicy $employeePolicy,
        private readonly UnifiedLoginContextResolver $loginContext,
        private readonly ShopOwnerAuthController $shopOwnerAuth,
    )
    {
    }

    private const MAX_SHOP_OWNER_RESUBMISSION_ATTEMPTS = 3;

    private const DUMMY_PASSWORD_HASH = '$2y$10$5n3DruMVEXy/QDrfseoa.uJ3ed2F8YjGuWk8rbM.tE0uNTd85ew.C';

    /**
     * Check whether an email can be used for public registration flows.
     */
    public function checkEmailAvailability(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'max:255', new NotDisposableEmail],
        ], [
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address (example: name@email.com).',
        ]);

        if ($validator->fails()) {
            $message = (string) ($validator->errors()->first('email') ?: 'Email is not available');

            return response()->json([
                'available' => false,
                'exists_in_employees' => false,
                'exists_in_users' => false,
                'exists_in_shop_owners' => false,
                'message' => $message,
            ], 422);
        }

        $normalizedEmail = strtolower(trim((string) $request->input('email')));

        $existsInEmployees = Employee::whereRaw('LOWER(email) = ?', [$normalizedEmail])->exists();
        $existsInUsers = User::whereRaw('LOWER(email) = ?', [$normalizedEmail])->exists();

        $existingShopOwner = ShopOwner::whereRaw('LOWER(email) = ?', [$normalizedEmail])->first();
        $existsInShopOwners = false;
        $isRejectedShopOwnerEmail = false;
        $rejectedUsedAttempts = 0;
        $rejectedRemainingAttempts = self::MAX_SHOP_OWNER_RESUBMISSION_ATTEMPTS;

        if ($existingShopOwner) {
            $statusValue = $existingShopOwner->status instanceof ShopOwnerStatus
                ? $existingShopOwner->status->value
                : (string) $existingShopOwner->status;

            if ($statusValue === ShopOwnerStatus::REJECTED->value) {
                $isRejectedShopOwnerEmail = true;
                $rejectedUsedAttempts = max(0, (int) ($existingShopOwner->resubmission_count ?? 0));
                $rejectedRemainingAttempts = max(0, self::MAX_SHOP_OWNER_RESUBMISSION_ATTEMPTS - $rejectedUsedAttempts);

                if ($rejectedRemainingAttempts <= 0) {
                    $existsInShopOwners = true;
                }
            } else {
                $existsInShopOwners = true;
            }
        }

        $available = ! ($existsInEmployees || $existsInUsers || $existsInShopOwners);

        if (! $available) {
            if ($isRejectedShopOwnerEmail && $rejectedRemainingAttempts <= 0) {
                $message = 'Resubmission limit reached. You can only resubmit up to '.self::MAX_SHOP_OWNER_RESUBMISSION_ATTEMPTS.' times.';
            } else {
                $message = 'This email is already registered';
            }
        } elseif ($isRejectedShopOwnerEmail) {
            $nextAttempt = $rejectedUsedAttempts + 1;
            $message = 'This email belongs to a previously rejected shop-owner application. Reapply attempt '.$nextAttempt.' of '.self::MAX_SHOP_OWNER_RESUBMISSION_ATTEMPTS.' is available.';
        } else {
            $message = 'Email is available';
        }

        return response()->json([
            'available' => $available,
            'exists_in_employees' => $existsInEmployees,
            'exists_in_users' => $existsInUsers,
            'exists_in_shop_owners' => $existsInShopOwners,
            'is_rejected_shop_owner_email' => $isRejectedShopOwnerEmail,
            'max_resubmission_attempts' => self::MAX_SHOP_OWNER_RESUBMISSION_ATTEMPTS,
            'rejected_used_attempts' => $rejectedUsedAttempts,
            'rejected_remaining_attempts' => $rejectedRemainingAttempts,
            'message' => $message,
        ]);
    }

    /**
     * Check whether a phone number can be used for registration and employee creation flows.
     */
    public function checkPhoneAvailability(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'regex:/^\d{11}$/'],
        ], [
            'phone.required' => 'Please enter your phone number.',
            'phone.regex' => 'Phone number must be exactly 11 digits.',
        ]);

        if ($validator->fails()) {
            $message = (string) ($validator->errors()->first('phone') ?: 'Phone number is not available');

            return response()->json([
                'available' => false,
                'message' => $message,
            ], 422);
        }

        $normalizedPhone = preg_replace('/\D+/', '', (string) $request->input('phone'));

        $existsInEmployees = Employee::where('phone', $normalizedPhone)->exists();
        $existsInUsers = User::where('phone', $normalizedPhone)->exists();
        $existsInShopOwners = ShopOwner::where('phone', $normalizedPhone)->exists();

        $available = ! ($existsInEmployees || $existsInUsers || $existsInShopOwners);

        return response()->json([
            'available' => $available,
            'message' => $available
                ? 'Phone number is available'
                : 'This phone number is already registered. Try another number or sign in instead.',
        ]);
    }

    /**
     * Register a new user account
     *
     * Customer accounts are created after the document admission screen passes.
     * Email verification, not administrator approval, gates normal access.
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function register(
        Request $request,
        NominatimService $nominatim,
        IdentityVerificationService $identityVerification,
    )
    {
        try {
            $documentDefinitions = (array) config('identity_verification.documents', []);
            $documentTypeInput = $request->input('document_type');
            $selectedDocumentDefinition = is_string($documentTypeInput)
                ? ($documentDefinitions[$documentTypeInput] ?? null)
                : null;
            $requestedNationalIdFormat = is_string($request->input('national_id_format'))
                ? $request->input('national_id_format')
                : 'physical_card';
            $requiredDocumentSlots = $this->requiredIdentityDocumentSlots(
                $selectedDocumentDefinition,
                $documentTypeInput === 'national_id' ? $requestedNationalIdFormat : 'physical_card',
            );
            $requiresBackImage = in_array('back', $requiredDocumentSlots, true);

            // Validate registration data
            $validated = $request->validate([
                'first_name' => 'required|string|max:255|min:2',
                'last_name' => 'required|string|max:255|min:2',
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email', new NotDisposableEmail],
                'phone' => [
                    'required',
                    'regex:/^\d{11}$/',
                    Rule::unique('users', 'phone'),
                    Rule::unique('employees', 'phone'),
                    Rule::unique('shop_owners', 'phone'),
                ],
                'age' => 'required|integer|min:18|max:120',
                'password' => [
                    'required',
                    'string',
                    'min:8',
                    'confirmed',
                    'regex:/[a-z]/',      // must contain at least one lowercase letter
                    'regex:/[A-Z]/',      // must contain at least one uppercase letter
                    'regex:/[0-9]/',      // must contain at least one digit
                ],
                'address' => 'required|string|max:500',
                'address_region' => 'required|string|max:255',
                'address_province' => 'required|string|max:255',
                'address_city' => 'required|string|max:255',
                'address_barangay' => 'required|string|max:255',
                'address_postal_code' => 'nullable|string|max:10',
                'address_latitude' => 'required|numeric|between:4.5,21.5',
                'address_longitude' => 'required|numeric|between:116,127',
                'valid_id' => [
                    'required',
                    'file',
                    'mimes:jpg,jpeg,png,webp',
                    'mimetypes:image/jpeg,image/png,image/webp',
                    'max:5120',
                    new ValidIdentityDocumentImage,
                ],
                'valid_id_back' => [
                    $requiresBackImage ? 'required' : 'prohibited',
                    'file',
                    'mimes:jpg,jpeg,png,webp',
                    'mimetypes:image/jpeg,image/png,image/webp',
                    'max:5120',
                    new ValidIdentityDocumentImage,
                ],
                'document_type' => [
                    'required',
                    'string',
                    Rule::in(array_keys((array) config('identity_verification.documents', []))),
                ],
                'national_id_format' => [
                    'nullable',
                    'string',
                    Rule::in(['physical_card', 'digital_image']),
                ],
                'screening_metadata' => ['required', 'string', 'json', 'max:20000'],
            ], [
                'first_name.required' => 'Please enter your first name.',
                'first_name.min' => 'First name must be at least 2 characters.',
                'last_name.required' => 'Please enter your last name.',
                'last_name.min' => 'Last name must be at least 2 characters.',
                'email.required' => 'Please enter your email address.',
                'email.email' => 'Please enter a valid email address (example: name@email.com).',
                'email.unique' => 'This email is already registered. Try another email or sign in instead.',
                'phone.required' => 'Please enter your phone number.',
                'phone.regex' => 'Phone number must be exactly 11 digits (example: 09171234567).',
                'phone.unique' => 'This phone number is already registered. Try another number or sign in instead.',
                'age.required' => 'Please enter your age.',
                'age.integer' => 'Age must be a whole number.',
                'age.min' => 'You must be at least 18 years old to register.',
                'age.max' => 'Please enter a valid age (120 or below).',
                'password.required' => 'Please enter a password.',
                'password.min' => 'Password must be at least 8 characters.',
                'password.confirmed' => 'Passwords do not match.',
                'password.regex' => 'Password must include uppercase, lowercase, and at least one number.',
                'address.required' => 'Please enter your address.',
                'address.max' => 'Address is too long. Maximum allowed is 500 characters.',
                'address_region.required' => 'Please select a complete address on the map.',
                'address_province.required' => 'Please select a complete address on the map.',
                'address_city.required' => 'Please select a city or municipality on the map.',
                'address_barangay.required' => 'Please select a barangay on the map.',
                'address_latitude.required' => 'Please select your location on the map.',
                'address_longitude.required' => 'Please select your location on the map.',
                'address_latitude.between' => 'Please select a location within the Philippines.',
                'address_longitude.between' => 'Please select a location within the Philippines.',
                'valid_id.required' => 'Please upload a valid government-issued ID.',
                'valid_id.file' => 'Valid ID must be an uploaded file.',
                'valid_id.mimes' => 'Valid ID must be JPG, JPEG, PNG, or WEBP only.',
                'valid_id.mimetypes' => 'Valid ID must be a JPG, PNG, or WEBP image.',
                'valid_id.max' => 'Valid ID file size must not exceed 5MB.',
                'valid_id_back.required' => 'Please upload a clear back image of the selected ID.',
                'valid_id_back.file' => 'The ID back image must be an uploaded file.',
                'valid_id_back.mimes' => 'The ID back image must be JPG, JPEG, PNG, or WEBP only.',
                'valid_id_back.mimetypes' => 'The ID back image must be a JPG, PNG, or WEBP image.',
                'valid_id_back.max' => 'The ID back image file size must not exceed 5MB.',
                'valid_id_back.prohibited' => 'A back image is not used for the selected ID type.',
                'document_type.required' => 'Please select the type of ID you are uploading.',
                'document_type.in' => 'Please select a supported ID type.',
                'national_id_format.in' => 'Please select a supported National ID format.',
                'screening_metadata.required' => 'Please complete the ID image check before creating your account.',
                'screening_metadata.string' => 'The ID image check result is invalid. Please try again.',
                'screening_metadata.json' => 'The ID image check result is invalid. Please try again.',
                'screening_metadata.max' => 'The ID image check result is too large. Please try again.',
            ]);

            $nationalIdFormat = $validated['national_id_format'] ?? 'physical_card';
            if ($validated['document_type'] !== 'national_id' && $nationalIdFormat !== 'physical_card') {
                throw ValidationException::withMessages([
                    'national_id_format' => 'A digital National ID format is only available for National ID submissions.',
                ]);
            }
            $validated['national_id_format'] = $nationalIdFormat;

            $screeningMetadata = $identityVerification->decodeScreeningMetadata($validated['screening_metadata']);
            if (($screeningMetadata['document_type'] ?? null) !== $validated['document_type']) {
                throw ValidationException::withMessages([
                    'screening_metadata' => 'The ID image check does not match the selected document type. Please try again.',
                ]);
            }

            $metadataNationalIdFormat = $screeningMetadata['national_id_format'] ?? 'physical_card';
            if ($metadataNationalIdFormat !== $nationalIdFormat) {
                throw ValidationException::withMessages([
                    'screening_metadata' => 'The ID image check does not match the selected National ID format. Please try again.',
                ]);
            }
            $screeningMetadata['national_id_format'] = $nationalIdFormat;

            $screeningDecision = $identityVerification->evaluate(
                $screeningMetadata,
                trim($validated['first_name'].' '.$validated['last_name']),
            );

            $screeningMetadata = $identityVerification->reconcileScreeningOutcome(
                $screeningMetadata,
                $screeningDecision,
            );

            $this->throwForScreeningFailure($screeningDecision);

            try {
                $resolvedAddress = $nominatim->reverse(
                    (float) $validated['address_latitude'],
                    (float) $validated['address_longitude'],
                )['address'] ?? [];
            } catch (\Throwable) {
                $resolvedAddress = [];
            }

            $resolvedProvince = $resolvedAddress['province'] ?? $resolvedAddress['state'] ?? '';
            $resolvedRegion = $resolvedAddress['region'] ?? $resolvedAddress['state'] ?? $resolvedProvince;
            $resolvedCity = $resolvedAddress['city'] ?? $resolvedAddress['municipality'] ?? $resolvedAddress['town'] ?? $resolvedAddress['county'] ?? '';
            $resolvedBarangay = $resolvedAddress['suburb'] ?? $resolvedAddress['quarter'] ?? $resolvedAddress['neighbourhood'] ?? $resolvedAddress['village'] ?? '';

            if (
                strtolower((string) ($resolvedAddress['country_code'] ?? '')) !== 'ph'
                || ! $resolvedRegion || ! $resolvedProvince || ! $resolvedCity || ! $resolvedBarangay
            ) {
                throw ValidationException::withMessages([
                    'address_latitude' => 'Please select a verified location within the Philippines.',
                ]);
            }

            $validated['address_region'] = $resolvedRegion;
            $validated['address_province'] = $resolvedProvince;
            $validated['address_city'] = $resolvedCity;
            $validated['address_barangay'] = $resolvedBarangay;
            $validated['address_postal_code'] = $resolvedAddress['postcode'] ?? $validated['address_postal_code'] ?? null;

            $user = DB::transaction(function () use ($validated, $request, $identityVerification, $screeningMetadata) {
                $user = User::create([
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'name' => $validated['first_name'].' '.$validated['last_name'],
                    'email' => $validated['email'],
                    'email_verified_at' => null,
                    'phone' => $validated['phone'],
                    'age' => $validated['age'],
                    'password' => Hash::make($validated['password']),
                    'address' => $validated['address'],
                    'status' => 'active',
                ]);

                UserAddress::create([
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'region' => $validated['address_region'],
                    'province' => $validated['address_province'],
                    'city' => $validated['address_city'],
                    'barangay' => $validated['address_barangay'],
                    'postal_code' => $validated['address_postal_code'] ?? null,
                    'address_line' => $validated['address'],
                    'latitude' => $validated['address_latitude'],
                    'longitude' => $validated['address_longitude'],
                    'is_default' => true,
                ]);

                try {
                    $identityVerification->screen(
                        $user,
                        $request->file('valid_id'),
                        $screeningMetadata,
                        $request->file('valid_id_back'),
                    );
                } catch (IdentityDocumentScreeningException $exception) {
                    throw ValidationException::withMessages(
                        $this->screeningFailureErrors($exception->decision),
                    );
                }

                return $user;
            });

            Log::info('User registered successfully', ['user_id' => $user->id]);

            // Send email verification notification
            $verificationEmailFailed = false;
            try {
                event(new Registered($user));
            } catch (\Throwable $exception) {
                // The account and verification record already exist. Keep the
                // customer in the verification-only state so they can retry.
                $verificationEmailFailed = true;
                Log::warning('Registration verification email delivery failed', [
                    'user_id' => $user->id,
                    'exception' => $exception::class,
                ]);
            }

            $registrationMessage = $verificationEmailFailed
                ? 'Your account was created, but we could not send the verification email. Use the resend button on the next page.'
                : 'Registration successful! Please check your email to verify your account.';
            $registrationFlash = $verificationEmailFailed
                ? [
                    'warning' => $registrationMessage,
                    'registration_email_failed' => true,
                ]
                : [
                    'success' => $registrationMessage,
                    'registration_email_failed' => false,
                ];

            // Registration never establishes normal customer authentication.
            Auth::guard('user')->logout();
            $request->session()->regenerate();
            $request->session()->put('pending_customer_verification_user_id', $user->getKey());

            // Check if it's an Inertia request
            if ($request->header('X-Inertia')) {
                return redirect()->route('verification.notice')->with(array_merge($registrationFlash, [
                    'email' => $user->email,
                ]));
            }

            // Return success response for API calls
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $registrationMessage,
                    'email_delivery_status' => $verificationEmailFailed ? 'failed' : 'sent',
                    'redirect' => route('verification.notice'),
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                    'csrf_token' => csrf_token(), // Send new CSRF token
                ], 201);
            }

            return redirect()->route('verification.notice')->with(array_merge($registrationFlash, [
                'email' => $user->email,
            ]));
        } catch (ValidationException $e) {
            Log::warning('User registration validation failed', [
                'error_fields' => array_keys($e->errors()),
            ]);

            // For Inertia requests, let Laravel handle validation normally
            if ($request->header('X-Inertia')) {
                throw $e; // This will automatically redirect back with errors for Inertia
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please review the highlighted fields and try again.',
                    'errors' => $e->errors(),
                ], 422);
            }

            throw $e;
        } catch (\Exception $e) {
            Log::error('Error registering user', [
                'route' => (string) $request->route()?->getName(),
            ]);

            // For Inertia requests
            if ($request->header('X-Inertia')) {
                return back()->withErrors(['message' => 'Registration failed. Please try again.'])->withInput();
            }

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
     * @param mixed $definition
     * @return array<int, string>
     */
    private function requiredIdentityDocumentSlots(mixed $definition, string $nationalIdFormat = 'physical_card'): array
    {
        if (! is_array($definition)) {
            return [];
        }

        if (is_array($definition['formats'] ?? null)) {
            $formatDefinition = $definition['formats'][$nationalIdFormat] ?? null;
            $formatSlots = is_array($formatDefinition) ? $formatDefinition['required_slots'] ?? [] : [];
            $formatSlots = array_values(array_filter(
                is_array($formatSlots) ? $formatSlots : [],
                static fn (mixed $slot): bool => is_string($slot)
                    && in_array($slot, ['front', 'back', 'biodata'], true),
            ));

            if ($formatSlots !== []) {
                return $formatSlots;
            }
        }

        $slots = array_values(array_filter(
            (array) ($definition['required_slots'] ?? []),
            static fn (mixed $slot): bool => is_string($slot)
                && in_array($slot, ['front', 'back', 'biodata'], true),
        ));

        if ($slots !== []) {
            return $slots;
        }

        return ($definition['requires_back'] ?? false) === true
            ? ['front', 'back']
            : ['biodata'];
    }

    /**
     * Stop registration before geocoding/account creation for non-pass outcomes.
     *
     * @param array<string, mixed> $decision
     */
    private function throwForScreeningFailure(array $decision): void
    {
        if (in_array(($decision['outcome'] ?? null), ['screening_passed', 'manual_review_required'], true)) {
            return;
        }

        throw ValidationException::withMessages($this->screeningFailureErrors($decision));
    }

    /**
     * @param array<string, mixed> $decision
     * @return array<string, string>
     */
    private function screeningFailureErrors(array $decision): array
    {
        $outcome = $decision['outcome'] ?? null;

        if ($outcome === 'screening_error') {
            return [
                'screening_metadata' => 'We couldn\'t check this image right now. Please try again or select another image.',
            ];
        }

        if ($outcome !== 'reject_upload') {
            return [
                'screening_metadata' => 'The ID image check result is invalid. Please try again.',
            ];
        }

        if (($decision['failure_reason'] ?? null) === 'name_mismatch') {
            return [
                'screening_metadata' => 'The name on the uploaded ID does not match the registration name.',
            ];
        }

        $failureSide = ($decision['failure_side'] ?? null) === 'back' ? 'valid_id_back' : 'valid_id';
        $message = ($decision['failure_reason'] ?? null) === 'duplicate_sides'
            ? 'The front and back images appear to be the same. Please upload the back side of your ID.'
            : ($failureSide === 'valid_id_back'
                ? 'The back image does not appear to match the selected ID. Please upload the back side of your valid ID.'
                : 'This image does not appear to match the selected ID type. Please upload a clear image of your valid Philippine ID.');

        return [$failureSide => $message];
    }

    /**
     * Login a user
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function login(Request $request)
    {
        try {
            $credentials = $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);
            $context = $this->loginContext->resolve(
                (string) $credentials['email'],
                (string) $credentials['password'],
            );

            if ($context === 'shop_owner') {
                return $this->shopOwnerAuth->login($request);
            }

            if ($context !== 'user') {
                throw ValidationException::withMessages([
                    'email' => ['Invalid email or password.'],
                ]);
            }

            $user = User::where('email', $credentials['email'])->first();
            $passwordHash = $user?->getAuthPassword() ?: self::DUMMY_PASSWORD_HASH;

            if (! $user || ! Hash::check((string) $credentials['password'], (string) $passwordHash)) {
                throw ValidationException::withMessages([
                    'email' => ['Invalid email or password.'],
                ]);
            }

            // Check if email is verified (only for non-employee users)
            if ($user->isCustomerAccount() && ! $user->hasVerifiedEmail()) {
                Auth::guard('user')->logout();
                $request->session()->regenerate();
                $request->session()->put('pending_customer_verification_user_id', $user->getKey());

                throw ValidationException::withMessages([
                    'email' => ['Please verify your email address before logging in. Check your inbox for the verification link.'],
                ])->redirectTo(route('verification.notice'));
            }

            // Check user status (stored as string in database)
            if ($user->status !== 'active') {
                throw ValidationException::withMessages([
                    'email' => ['Your account has been suspended. Please contact support.'],
                ]);
            }

            // For employees/staff, only active employment status can access the system.
            if ($user->shop_owner_id) {
                $shopOwner = ShopOwner::find($user->shop_owner_id);
                if ($shopOwner) {
                    $shopOwnerStatus = $shopOwner->status;
                    $isShopSuspended = $shopOwnerStatus instanceof ShopOwnerStatus
                        ? $shopOwnerStatus === ShopOwnerStatus::SUSPENDED
                        : (string) $shopOwnerStatus === ShopOwnerStatus::SUSPENDED->value;

                    if ($isShopSuspended) {
                        throw ValidationException::withMessages([
                            'email' => ['Your shop account has been suspended. Please contact your administrator.'],
                        ]);
                    }
                }

                $employees = Employee::where('shop_owner_id', $user->shop_owner_id)
                    ->whereRaw('LOWER(email) = ?', [strtolower((string) $user->email)])
                    ->orderBy('id')
                    ->limit(2)
                    ->get();
                if ($employees->count() > 1) {
                    throw ValidationException::withMessages([
                        'email' => ['Your account is unavailable. Please contact support.'],
                    ]);
                }

                if ($employees->count() === 1) {
                    if (! $this->employeePolicy->canAuthenticate($employees->first())) {
                        throw ValidationException::withMessages([
                            'email' => ['Your account has been suspended. Please contact support.'],
                        ]);
                    }
                }
            }

            Auth::guard('user')->login($user, $request->filled('remember'));
            $request->session()->regenerate();

            // CRITICAL: Explicitly save the session to ensure it persists
            $request->session()->save();

            $user->update([
                'last_login_at' => now(),
                'last_login_ip' => $request->ip(),
            ]);

            Log::info('User logged in successfully', ['user_id' => $user->id, 'user_role' => $user->role, 'shop_owner_id' => $user->shop_owner_id]);

            // Use the shared account classifier so employees are not treated as customers.

            $isEmployee = ! $user->isCustomerAccount();

            // Default redirect for customer accounts.
            $redirectUrl = route('landing');

            // If user is an employee, redirect to time-in
            if ($isEmployee) {
                $redirectUrl = route('erp.time-in');

                // Check for password change requirement (staff only)
                if ($user->force_password_change) {
                    $redirectUrl = route('erp.profile');
                }
            }

            Log::info('Login redirect decision', [
                'user_id' => $user->id,
                'shop_owner_id' => $user->shop_owner_id,
                'role' => $user->role,
                'is_employee' => $isEmployee,
                'is_customer' => ! $isEmployee,
                'redirect_url' => $redirectUrl,
            ]);

            // For Inertia requests, return a redirect that preserves the session
            if ($request->header('X-Inertia')) {
                return redirect($redirectUrl)->with('success', 'Welcome back!');
            }

            // For API/JSON requests, return the redirect URL in JSON
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Login successful!',
                    'redirect' => $redirectUrl,
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'is_employee' => $isEmployee,
                    ],
                ]);
            }

            // For regular requests, perform a server-side redirect
            return redirect($redirectUrl)->with('success', 'Welcome back!');
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
            Log::error('Error logging in user', ['error' => $e->getMessage()]);

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
     * Logout a user
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function logout(Request $request)
    {
        $userId = Auth::guard('user')->id();

        Auth::guard('user')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Log::info('User logged out', ['user_id' => $userId]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully',
            ]);
        }

        return redirect()->route('landing');
    }

    /**
     * Get current authenticated user
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(Request $request)
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Not authenticated',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'age' => $user->age,
                'address' => $user->address,
                'status' => $user->status,
            ],
        ]);
    }
}
