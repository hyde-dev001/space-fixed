<?php

namespace App\Http\Controllers;

use App\Services\CaviteLocationPolicyService;
use App\Models\ShopOwner;
use App\Enums\ShopOwnerStatus;
use App\Models\ShopDocument;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Events\Registered;
use App\Rules\NotDisposableEmail;
use Inertia\Inertia;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * ShopOwnerAuthController
 * 
 * Handles shop owner registration, authentication, and document uploads
 * Shop owners require admin approval before they can fully access the system
 */
class ShopOwnerAuthController extends Controller
{
    private const REGISTRATION_EMAIL_OTP_TTL_MINUTES = 10;
    private const REGISTRATION_EMAIL_OTP_MAX_ATTEMPTS = 5;
    private const REGISTRATION_EMAIL_VERIFIED_TTL_MINUTES = 60;
    private const LOGIN_EMAIL_OTP_TTL_MINUTES = 10;
    private const LOGIN_EMAIL_OTP_MAX_ATTEMPTS = 5;

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
                'email' => ['required', 'string', 'email', 'max:255', 'unique:shop_owners,email', new NotDisposableEmail()],
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

                // Document uploads
                'dti_registration' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
                'mayors_permit' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
                'bir_certificate' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
                'valid_id' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
                'other_documents' => 'nullable|array|max:8',
                'other_documents.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
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
                'dti_registration.required' => 'Upload your Shop Registration document (DTI or SEC).',
                'dti_registration.file' => 'Shop Registration (DTI/SEC) must be a valid file.',
                'dti_registration.mimes' => 'Shop Registration (DTI/SEC) must be JPG, JPEG, PNG, or PDF only.',
                'dti_registration.max' => 'Shop Registration (DTI/SEC) file size must not exceed 5MB.',
                'mayors_permit.required' => "Upload your Mayor's Permit or Shop Permit.",
                'mayors_permit.file' => "Mayor's Permit / Shop Permit must be a valid file.",
                'mayors_permit.mimes' => "Mayor's Permit / Shop Permit must be JPG, JPEG, PNG, or PDF only.",
                'mayors_permit.max' => "Mayor's Permit / Shop Permit file size must not exceed 5MB.",
                'bir_certificate.required' => 'Upload your BIR Certificate of Registration (COR).',
                'bir_certificate.file' => 'BIR Certificate of Registration must be a valid file.',
                'bir_certificate.mimes' => 'BIR Certificate of Registration must be JPG, JPEG, PNG, or PDF only.',
                'bir_certificate.max' => 'BIR Certificate of Registration file size must not exceed 5MB.',
                'valid_id.required' => 'Upload a valid government-issued ID of the owner.',
                'valid_id.file' => 'Valid ID must be a valid file.',
                'valid_id.mimes' => 'Valid ID must be JPG, JPEG, PNG, or PDF only.',
                'valid_id.max' => 'Valid ID file size must not exceed 5MB.',
                'other_documents.array' => 'Other supporting documents must be a valid list of files.',
                'other_documents.max' => 'You can upload up to 8 other supporting documents.',
                'other_documents.*.file' => 'Each supporting document must be a valid file.',
                'other_documents.*.mimes' => 'Each supporting document must be JPG, JPEG, PNG, or PDF only.',
                'other_documents.*.max' => 'Each supporting document must not exceed 5MB.',
            ]);

            $normalizedEmail = $this->normalizeEmail((string) ($validated['email'] ?? ''));
            $this->assertRegistrationEmailVerified($normalizedEmail);

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

            DB::beginTransaction();

            // Create shop owner with pending status
            $shopOwner = ShopOwner::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $normalizedEmail,
                'phone' => $validated['phone'],
                'password' => null, // Will be set after admin approval via email
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
                // operating_hours intentionally omitted (removed client-side)
                'status' => 'pending', // Requires admin approval
            ]);

            // Upload and save documents
            $documents = [
                'dti_registration',
                'mayors_permit',
                'bir_certificate',
                'valid_id',
            ];

            foreach ($documents as $documentType) {
                if ($request->hasFile($documentType)) {
                    $file = $request->file($documentType);
                    $fileName = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                    $filePath = $file->storeAs('shop_documents', $fileName, 'public');

                    ShopDocument::create([
                        'shop_owner_id' => $shopOwner->id,
                        'document_type' => $documentType,
                        'file_path' => $filePath,
                        'status' => 'pending',
                    ]);
                }
            }

            if ($request->hasFile('other_documents')) {
                foreach ((array) $request->file('other_documents') as $file) {
                    if (!$file) {
                        continue;
                    }

                    $fileName = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                    $filePath = $file->storeAs('shop_documents', $fileName, 'public');

                    ShopDocument::create([
                        'shop_owner_id' => $shopOwner->id,
                        'document_type' => 'other_supporting_document',
                        'file_path' => $filePath,
                        'status' => 'pending',
                    ]);
                }
            }

            DB::commit();
            Cache::forget($this->registrationEmailOtpCacheKey($normalizedEmail));

            Log::info('Shop owner registered successfully', [
                'shop_owner_id' => $shopOwner->id,
                'email' => $shopOwner->email,
                'business_name' => $shopOwner->business_name,
            ]);

            // Send email verification notification
            event(new Registered($shopOwner));

            // Auto-login the shop owner so they can access the verification page
            Auth::guard('shop_owner')->login($shopOwner);

            // Return success response
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Registration successful! Please check your email to verify your account.',
                    'redirect' => route('verification.notice'),
                    'shop_owner' => [
                        'id' => $shopOwner->id,
                        'business_name' => $shopOwner->business_name,
                        'email' => $shopOwner->email,
                        'status' => $shopOwner->status,
                    ],
                    'csrf_token' => csrf_token(), // Send new CSRF token
                ], 201);
            }

            return redirect()->route('verification.notice')->with([
                'success' => 'Registration successful! Please check your email to verify your account.',
                'email' => $shopOwner->email,
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
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
            DB::rollBack();
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
        $existsInShopOwners = ShopOwner::whereRaw('LOWER(email) = ?', [$normalizedEmail])->exists();
        $available = !($existsInEmployees || $existsInUsers || $existsInShopOwners);

        return [
            'available' => $available,
            'message' => $available ? 'Email is available' : 'This email is already registered',
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

            // Check if shop owner exists
            if (!$shopOwner) {
                throw ValidationException::withMessages([
                    'email' => ['Invalid email or password.'],
                ]);
            }

            // Check if email is verified
            if (!$shopOwner->hasVerifiedEmail()) {
                // Auto-login shop owner so they can access verification page
                Auth::guard('shop_owner')->login($shopOwner);
                
                throw ValidationException::withMessages([
                    'email' => ['Please verify your email address before logging in. Check your inbox for the verification link.'],
                ])->redirectTo(route('verification.notice'));
            }

            // Check if account is approved
            if ($shopOwner->status === 'pending') {
                throw ValidationException::withMessages([
                    'email' => ['Your application is still pending admin approval. Please wait for confirmation.'],
                ]);
            }

            if ($shopOwner->status === 'rejected') {
                $reason = $shopOwner->rejection_reason ? ': ' . $shopOwner->rejection_reason : '';
                throw ValidationException::withMessages([
                    'email' => ['Your application was rejected' . $reason . '. Please contact support.'],
                ]);
            }

            if ($shopOwner->status !== ShopOwnerStatus::APPROVED) {
                throw ValidationException::withMessages([
                    'email' => ['Your account is inactive. Please contact support.'],
                ]);
            }

            // Verify password
            if (!Hash::check($credentials['password'], $shopOwner->password)) {
                throw ValidationException::withMessages([
                    'email' => ['Invalid email or password.'],
                ]);
            }

            $remember = (bool) $request->boolean('remember');

            if ((bool) ($shopOwner->two_factor_email_enabled ?? false)) {
                $this->beginLoginTwoFactorChallenge($request, $shopOwner, $remember);

                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => true,
                        'requires_two_factor' => true,
                        'message' => 'Verification code sent to your email.',
                        'redirect' => route('shop-owner.two-factor.challenge'),
                    ], 202);
                }

                return redirect()->route('shop-owner.two-factor.challenge')->with('status', 'otp-sent');
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

        $entry = Cache::get($this->loginTwoFactorCacheKey((int) $shopOwner->id));
        $secondsRemaining = max(0, ((int) ($entry['expires_at'] ?? now()->timestamp)) - now()->timestamp);

        return Inertia::render('UserSide/Auth/ShopOwnerTwoFactor', [
            'status' => session('status'),
            'email' => $this->maskEmail((string) $shopOwner->email),
            'seconds_remaining' => $secondsRemaining,
        ]);
    }

    /**
     * Verify login two-factor OTP for shop owner.
     */
    public function verifyLoginTwoFactorOtp(Request $request)
    {
        try {
            $validated = $request->validate([
                'otp' => ['required', 'digits:6'],
            ]);

            $shopOwner = $this->resolvePendingTwoFactorShopOwner($request);
            if (!$shopOwner) {
                throw ValidationException::withMessages([
                    'otp' => ['Your login session expired. Please sign in again.'],
                ]);
            }

            $cacheKey = $this->loginTwoFactorCacheKey((int) $shopOwner->id);
            $entry = Cache::get($cacheKey);
            if (!is_array($entry)) {
                throw ValidationException::withMessages([
                    'otp' => ['Verification code expired. Please request a new code.'],
                ]);
            }

            if ((int) ($entry['attempts'] ?? 0) >= self::LOGIN_EMAIL_OTP_MAX_ATTEMPTS) {
                $this->clearLoginTwoFactorChallenge($request, (int) $shopOwner->id);

                throw ValidationException::withMessages([
                    'otp' => ['Too many failed attempts. Please sign in again.'],
                ]);
            }

            if ((int) ($entry['expires_at'] ?? 0) < now()->timestamp) {
                $this->clearLoginTwoFactorChallenge($request, (int) $shopOwner->id);

                throw ValidationException::withMessages([
                    'otp' => ['Verification code expired. Please sign in again.'],
                ]);
            }

            $otp = (string) ($validated['otp'] ?? '');
            if (!Hash::check($otp, (string) ($entry['otp_hash'] ?? ''))) {
                $entry['attempts'] = (int) ($entry['attempts'] ?? 0) + 1;
                $secondsLeft = max(1, ((int) ($entry['expires_at'] ?? now()->timestamp)) - now()->timestamp);
                Cache::put($cacheKey, $entry, now()->addSeconds($secondsLeft));

                throw ValidationException::withMessages([
                    'otp' => ['Incorrect verification code. Please try again.'],
                ]);
            }

            $remember = (bool) $request->session()->get('shop_owner_2fa_remember', false);
            $this->clearLoginTwoFactorChallenge($request, (int) $shopOwner->id);

            Auth::guard('shop_owner')->login($shopOwner, $remember);
            $request->session()->regenerate();

            $shopOwner->update([
                'last_login_at' => now(),
                'last_login_ip' => $request->ip(),
            ]);

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
            Log::error('Error verifying shop owner two-factor OTP', ['error' => $e->getMessage()]);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to verify code right now. Please try again.',
                ], 500);
            }

            return back()->withErrors(['otp' => 'Unable to verify code right now. Please try again.']);
        }
    }

    /**
     * Resend login two-factor OTP for shop owner.
     */
    public function resendLoginTwoFactorOtp(Request $request)
    {
        try {
            $shopOwner = $this->resolvePendingTwoFactorShopOwner($request);
            if (!$shopOwner) {
                throw ValidationException::withMessages([
                    'otp' => ['Your login session expired. Please sign in again.'],
                ]);
            }

            $this->issueLoginTwoFactorOtp($shopOwner);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'A new verification code has been sent to your email.',
                ]);
            }

            return back()->with('status', 'otp-resent');
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to resend verification code.',
                    'errors' => $e->errors(),
                ], 422);
            }

            throw $e;
        } catch (\Exception $e) {
            Log::error('Error resending shop owner two-factor OTP', ['error' => $e->getMessage()]);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to resend verification code right now. Please try again.',
                ], 500);
            }

            return back()->withErrors(['otp' => 'Unable to resend verification code right now. Please try again.']);
        }
    }

    private function beginLoginTwoFactorChallenge(Request $request, ShopOwner $shopOwner, bool $remember): void
    {
        $request->session()->put('shop_owner_2fa_pending_id', (int) $shopOwner->id);
        $request->session()->put('shop_owner_2fa_remember', $remember);
        $request->session()->put('shop_owner_2fa_pending_at', now()->timestamp);

        $this->issueLoginTwoFactorOtp($shopOwner);
    }

    private function resolvePendingTwoFactorShopOwner(Request $request): ?ShopOwner
    {
        $pendingId = (int) $request->session()->get('shop_owner_2fa_pending_id', 0);
        if ($pendingId <= 0) {
            return null;
        }

        $shopOwner = ShopOwner::find($pendingId);
        if (!$shopOwner) {
            $this->clearLoginTwoFactorChallenge($request, $pendingId);

            return null;
        }

        return $shopOwner;
    }

    private function issueLoginTwoFactorOtp(ShopOwner $shopOwner): void
    {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttl = now()->addMinutes(self::LOGIN_EMAIL_OTP_TTL_MINUTES);

        Cache::put($this->loginTwoFactorCacheKey((int) $shopOwner->id), [
            'otp_hash' => Hash::make($otp),
            'attempts' => 0,
            'expires_at' => $ttl->timestamp,
        ], $ttl);

        try {
            Mail::raw(
                "Your SoleSpace login verification code is {$otp}. This code expires in "
                . self::LOGIN_EMAIL_OTP_TTL_MINUTES
                . ' minutes.',
                function ($message) use ($shopOwner) {
                    $message->to($shopOwner->email)
                        ->subject('SoleSpace Login Verification Code');
                }
            );
        } catch (\Throwable $e) {
            Cache::forget($this->loginTwoFactorCacheKey((int) $shopOwner->id));

            Log::error('Failed to send shop owner login two-factor OTP email', [
                'shop_owner_id' => $shopOwner->id,
                'email' => $shopOwner->email,
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'email' => ['Unable to send verification code right now. Please try again.'],
            ]);
        }
    }

    private function loginTwoFactorCacheKey(int $shopOwnerId): string
    {
        return 'shop_owner_login_2fa:' . $shopOwnerId;
    }

    private function clearLoginTwoFactorChallenge(Request $request, ?int $shopOwnerId = null): void
    {
        $resolvedShopOwnerId = $shopOwnerId ?? (int) $request->session()->get('shop_owner_2fa_pending_id', 0);
        if ($resolvedShopOwnerId > 0) {
            Cache::forget($this->loginTwoFactorCacheKey($resolvedShopOwnerId));
        }

        $request->session()->forget('shop_owner_2fa_pending_id');
        $request->session()->forget('shop_owner_2fa_remember');
        $request->session()->forget('shop_owner_2fa_pending_at');
    }

    private function maskEmail(string $email): string
    {
        $email = trim($email);
        if ($email === '' || !str_contains($email, '@')) {
            return 'your email';
        }

        [$local, $domain] = explode('@', $email, 2);

        if ($local === '') {
            return '***@' . $domain;
        }

        if (strlen($local) <= 2) {
            return substr($local, 0, 1) . '*@' . $domain;
        }

        return substr($local, 0, 2) . str_repeat('*', max(strlen($local) - 2, 2)) . '@' . $domain;
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
