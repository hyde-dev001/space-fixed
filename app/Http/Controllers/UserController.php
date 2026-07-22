<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserAddress;
use App\Models\ShopOwner;
use App\Models\Employee;
use App\Enums\EmployeeStatus;
use App\Enums\ShopOwnerStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Auth\Events\Registered;
use App\Rules\NotDisposableEmail;
use App\Services\NominatimService;
use Inertia\Inertia;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * UserController
 * 
 * Handles user registration, authentication, and profile management
 * for regular customers on the platform
 */
class UserController extends Controller
{
    private const MAX_SHOP_OWNER_RESUBMISSION_ATTEMPTS = 3;
    private const SHOP_OWNER_LOGIN_2FA_TTL_MINUTES = 10;
    private const SHOP_OWNER_LOGIN_2FA_SESSION_KEY = 'shop_owner_2fa_entry';

    /**
     * Check whether an email can be used for public registration flows.
     */
    public function checkEmailAvailability(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'max:255', new NotDisposableEmail()],
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

        $available = !($existsInEmployees || $existsInUsers || $existsInShopOwners);

        if (!$available) {
            if ($isRejectedShopOwnerEmail && $rejectedRemainingAttempts <= 0) {
                $message = 'Resubmission limit reached. You can only resubmit up to ' . self::MAX_SHOP_OWNER_RESUBMISSION_ATTEMPTS . ' times.';
            } else {
                $message = 'This email is already registered';
            }
        } elseif ($isRejectedShopOwnerEmail) {
            $nextAttempt = $rejectedUsedAttempts + 1;
            $message = 'This email belongs to a previously rejected shop-owner application. Reapply attempt ' . $nextAttempt . ' of ' . self::MAX_SHOP_OWNER_RESUBMISSION_ATTEMPTS . ' is available.';
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
                'exists_in_employees' => false,
                'exists_in_users' => false,
                'exists_in_shop_owners' => false,
                'message' => $message,
            ], 422);
        }

        $normalizedPhone = preg_replace('/\D+/', '', (string) $request->input('phone'));

        $existsInEmployees = Employee::where('phone', $normalizedPhone)->exists();
        $existsInUsers = User::where('phone', $normalizedPhone)->exists();
        $existsInShopOwners = ShopOwner::where('phone', $normalizedPhone)->exists();

        $available = !($existsInEmployees || $existsInUsers || $existsInShopOwners);

        return response()->json([
            'available' => $available,
            'exists_in_employees' => $existsInEmployees,
            'exists_in_users' => $existsInUsers,
            'exists_in_shop_owners' => $existsInShopOwners,
            'message' => $available ? 'Phone number is available' : 'This phone number is already registered',
        ]);
    }

    /**
     * Register a new user account
     * 
     * Users are automatically activated upon registration
     * No admin approval required for user accounts
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function register(Request $request, NominatimService $nominatim)
    {
        try {
            // Validate registration data
            $validated = $request->validate([
                'first_name' => 'required|string|max:255|min:2',
                'last_name' => 'required|string|max:255|min:2',
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email', new NotDisposableEmail()],
                'phone' => ['required', 'regex:/^\d{11}$/'],
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
                'valid_id' => 'required|file|mimes:jpg,jpeg,png|max:5120', // 5MB max
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
                'valid_id.mimes' => 'Valid ID must be JPG, JPEG, or PNG only.',
                'valid_id.max' => 'Valid ID file size must not exceed 5MB.',
            ]);

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
                || !$resolvedRegion || !$resolvedProvince || !$resolvedCity || !$resolvedBarangay
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

            // Handle valid ID upload
            $validIdPath = null;
            if ($request->hasFile('valid_id')) {
                $file = $request->file('valid_id');
                $fileName = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $validIdPath = $file->storeAs('valid_ids', $fileName, 'public');
            }

            $user = DB::transaction(function () use ($validated, $validIdPath) {
                $user = User::create([
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'name' => $validated['first_name'] . ' ' . $validated['last_name'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'],
                    'age' => $validated['age'],
                    'password' => Hash::make($validated['password']),
                    'address' => $validated['address'],
                    'status' => 'active',
                    'valid_id_path' => $validIdPath,
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

                return $user;
            });

            Log::info('User registered successfully', ['user_id' => $user->id, 'email' => $user->email]);

            // Send email verification notification
            event(new Registered($user));

            // Auto-login the user so they can access the verification page
            Auth::guard('web')->login($user);

            // Check if it's an Inertia request
            if ($request->header('X-Inertia')) {
                return redirect()->route('verification.notice')->with([
                    'success' => 'Registration successful! Please check your email to verify your account.',
                    'email' => $user->email,
                ]);
            }

            // Return success response for API calls
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Registration successful! Please check your email to verify your account.',
                    'redirect' => route('verification.notice'),
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                    'csrf_token' => csrf_token(), // Send new CSRF token
                ], 201);
            }

            return redirect()->route('verification.notice')->with([
                'success' => 'Registration successful! Please check your email to verify your account.',
                'email' => $user->email,
            ]);
        } catch (ValidationException $e) {
            Log::warning('User registration validation failed', [
                'errors' => $e->errors(),
                'input' => $request->except(['password', 'password_confirmation', 'valid_id'])
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
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'input' => $request->except(['password', 'password_confirmation', 'valid_id'])
            ]);

            // For Inertia requests
            if ($request->header('X-Inertia')) {
                return back()->withErrors(['message' => 'Registration failed. Please try again.'])->withInput();
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Registration failed. Please try again.',
                    'error' => $e->getMessage(),
                ], 500);
            }

            return back()->withErrors(['message' => 'Registration failed. Please try again.'])->withInput();
        }
    }

    /**
     * Login a user
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
            // Attempt to authenticate as a regular User first
            $user = User::where('email', $credentials['email'])->first();

            if ($user && Hash::check($credentials['password'], $user->password)) {
                // Check if email is verified (only for non-employee users)
                if (!$user->shop_owner_id && !$user->hasVerifiedEmail()) {
                    // Auto-login user so they can access verification page
                    Auth::guard('web')->login($user);
                    
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

                    $employee = Employee::where('email', $user->email)->first();
                    if ($employee) {
                        $employeeStatus = $employee->status;
                        $isEmployeeActive = $employeeStatus instanceof EmployeeStatus
                            ? $employeeStatus === EmployeeStatus::ACTIVE
                            : (string) $employeeStatus === EmployeeStatus::ACTIVE->value;

                        if (!$isEmployeeActive) {
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

                // Determine redirect URL based ONLY on shop_owner_id
                // - If user has shop_owner_id -> they are an employee/staff member -> redirect to erp/time-in
                // - Otherwise -> they are a regular customer -> redirect to landing
                // We use shop_owner_id as the source of truth because role column is unreliable (contaminated by migrations)
                
                $isEmployee = !is_null($user->shop_owner_id);
                
                // Default redirect for customers (no shop_owner_id)
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
                    'is_customer' => !$isEmployee,
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
            }

            // If not a regular user, attempt to authenticate as ShopOwner
            $shopOwner = ShopOwner::where('email', $credentials['email'])->first();

            if (!$shopOwner) {
                throw ValidationException::withMessages([
                    'email' => ['Invalid email or password.'],
                ]);
            }

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

            if (!Hash::check($credentials['password'], $shopOwner->password)) {
                throw ValidationException::withMessages([
                    'email' => ['Invalid email or password.'],
                ]);
            }

            $remember = (bool) $request->boolean('remember');

            if ((bool) ($shopOwner->two_factor_email_enabled ?? false)) {
                $this->beginShopOwnerTwoFactorChallenge($request, $shopOwner, $remember);

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
            $request->session()->regenerate();

            $shopOwner->update([
                'last_login_at' => now(),
                'last_login_ip' => $request->ip(),
            ]);

            Log::info('Shop owner logged in successfully via unified login', [
                'shop_owner_id' => $shopOwner->id,
                'business_name' => $shopOwner->business_name,
            ]);

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

    private function beginShopOwnerTwoFactorChallenge(Request $request, ShopOwner $shopOwner, bool $remember): void
    {
        $request->session()->put('shop_owner_2fa_pending_id', (int) $shopOwner->id);
        $request->session()->put('shop_owner_2fa_remember', $remember);
        $request->session()->put('shop_owner_2fa_pending_at', now()->timestamp);

        $this->issueShopOwnerLoginOtp($request, $shopOwner);
    }

    private function issueShopOwnerLoginOtp(Request $request, ShopOwner $shopOwner): void
    {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttl = now()->addMinutes(self::SHOP_OWNER_LOGIN_2FA_TTL_MINUTES);

        try {
            $request->session()->put(self::SHOP_OWNER_LOGIN_2FA_SESSION_KEY, [
                'shop_owner_id' => (int) $shopOwner->id,
                'otp_hash' => Hash::make($otp),
                'attempts' => 0,
                'expires_at' => $ttl->timestamp,
            ]);

            Mail::raw(
                "Your SoleSpace login verification code is {$otp}. This code expires in "
                . self::SHOP_OWNER_LOGIN_2FA_TTL_MINUTES
                . ' minutes.',
                function ($message) use ($shopOwner) {
                    $message->to($shopOwner->email)
                        ->subject('SoleSpace Login Verification Code');
                }
            );
        } catch (\Throwable $e) {
            $request->session()->forget(self::SHOP_OWNER_LOGIN_2FA_SESSION_KEY);

            Log::error('Failed to send shop owner login 2FA code from unified login', [
                'shop_owner_id' => $shopOwner->id,
                'email' => $shopOwner->email,
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'email' => ['Unable to send verification code right now. Please try again.'],
            ]);
        }
    }

    /**
     * Logout a user
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function logout(Request $request)
    {
        $userId = Auth::id();

        Auth::logout();
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
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function me(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
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
