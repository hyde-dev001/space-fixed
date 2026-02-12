<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\ShopOwner;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * UserController
 * 
 * Handles user registration, authentication, and profile management
 * for regular customers on the platform
 */
class UserController extends Controller
{
    /**
     * Register a new user account
     * 
     * Users are automatically activated upon registration
     * No admin approval required for user accounts
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function register(Request $request)
    {
        try {
            // Validate registration data
            $validated = $request->validate([
                'first_name' => 'required|string|max:255|min:2',
                'last_name' => 'required|string|max:255|min:2',
                'email' => 'required|string|email|max:255|unique:users,email',
                'phone' => 'required|string|max:15|min:10',
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
                'valid_id' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120', // 5MB max
            ], [
                'first_name.required' => 'First name is required',
                'first_name.min' => 'First name must be at least 2 characters',
                'last_name.required' => 'Last name is required',
                'last_name.min' => 'Last name must be at least 2 characters',
                'email.required' => 'Email is required',
                'email.email' => 'Please provide a valid email address',
                'email.unique' => 'This email is already registered',
                'phone.required' => 'Phone number is required',
                'phone.min' => 'Phone number must be at least 10 digits',
                'age.required' => 'Age is required',
                'age.min' => 'You must be at least 18 years old to register',
                'password.required' => 'Password is required',
                'password.min' => 'Password must be at least 8 characters',
                'password.confirmed' => 'Passwords do not match',
                'password.regex' => 'Password must contain uppercase, lowercase, and numbers',
                'address.required' => 'Address is required',
                'valid_id.required' => 'Valid ID is required',
                'valid_id.mimes' => 'Valid ID must be a JPG, PNG, or PDF file',
                'valid_id.max' => 'File size must not exceed 5MB',
            ]);

            // Handle valid ID upload
            $validIdPath = null;
            if ($request->hasFile('valid_id')) {
                $file = $request->file('valid_id');
                $fileName = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $validIdPath = $file->storeAs('valid_ids', $fileName, 'public');
            }

            // Create the user with active status
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

            \Log::info('User registered successfully', ['user_id' => $user->id, 'email' => $user->email]);

            // Check if it's an Inertia request
            if ($request->header('X-Inertia')) {
                return redirect()->route('login')->with('success', 'Registration successful! You can now login.');
            }

            // Return success response for API calls
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Registration successful! You can now login.',
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                    'csrf_token' => csrf_token(), // Send new CSRF token
                ], 201);
            }

            return redirect()->route('login')->with('success', 'Registration successful! You can now login.');
        } catch (ValidationException $e) {
            \Log::warning('User registration validation failed', [
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
                    'message' => 'Validation failed',
                    'errors' => $e->errors(),
                ], 422);
            }

            throw $e;
        } catch (\Exception $e) {
            \Log::error('Error registering user', [
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
                if ($user->status !== 'active') {
                    throw ValidationException::withMessages([
                        'email' => ['Your account has been suspended. Please contact support.'],
                    ]);
                }

                $employee = Employee::where('email', $user->email)->first();
                if ($employee && $employee->status === 'suspended') {
                    throw ValidationException::withMessages([
                        'email' => ['Your account has been suspended. Please contact support.'],
                    ]);
                }

                Auth::guard('user')->login($user, $request->filled('remember'));
                $request->session()->regenerate();

                $user->update([
                    'last_login_at' => now(),
                    'last_login_ip' => $request->ip(),
                ]);

                \Log::info('User logged in successfully', ['user_id' => $user->id]);

                if ($request->expectsJson()) {
                    // Check both Spatie roles and old role column for backward compatibility
                    // Default redirect is now Time In/Out page for all staff
                    $redirect = route('erp.time-in');
                    $userRole = strtoupper($user->role ?? '');
                    
                    // Role-specific redirects (all staff go to time-in first)
                    if ($user->hasRole('HR') || $userRole === 'HR') {
                        $redirect = route('erp.time-in');
                    } elseif ($user->hasAnyRole(['Finance Staff', 'Finance Manager']) || in_array($userRole, ['FINANCE', 'FINANCE_STAFF', 'FINANCE_MANAGER'])) {
                        $redirect = route('erp.time-in');
                    } elseif ($user->hasRole('CRM') || $userRole === 'CRM') {
                        $redirect = route('erp.time-in');
                    } elseif ($user->hasRole('Manager') || $userRole === 'MANAGER') {
                        $redirect = route('erp.time-in');
                    } elseif ($user->hasRole('Staff') || $userRole === 'STAFF') {
                        $redirect = route('erp.time-in');
                    }

                    return response()->json([
                        'success' => true,
                        'message' => 'Login successful!',
                        'redirect' => $redirect,
                        'user' => [
                            'id' => $user->id,
                            'name' => $user->name,
                            'email' => $user->email,
                            'role' => $user->role,
                        ],
                    ]);
                }

                // If flagged for password change, send to profile page first (applies to all roles)
                if ($user->force_password_change) {
                    return redirect()->route('erp.profile')->with('temporary_password', true);
                }

                // Role-based landing - All staff go to Time In/Out page first
                $userRole = strtoupper($user->role ?? '');
                
                if ($user->hasRole('HR') || $userRole === 'HR') {
                    return redirect()->route('erp.time-in')->with('success', 'Welcome back!');
                }

                if ($user->hasAnyRole(['Finance Staff', 'Finance Manager']) || in_array($userRole, ['FINANCE', 'FINANCE_STAFF', 'FINANCE_MANAGER'])) {
                    return redirect()->route('erp.time-in')->with('success', 'Welcome back!');
                }

                if ($user->hasRole('CRM') || $userRole === 'CRM') {
                    return redirect()->route('erp.time-in')->with('success', 'Welcome back!');
                }

                if ($user->hasRole('Manager') || $userRole === 'MANAGER') {
                    return redirect()->route('erp.time-in')->with('success', 'Welcome back!');
                }

                if ($user->hasRole('Staff') || $userRole === 'STAFF') {
                    return redirect()->route('erp.time-in')->with('success', 'Welcome back!');
                }

                return redirect()->route('landing')->with('success', 'Welcome back!');
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

            if ($shopOwner->status !== 'approved') {
                throw ValidationException::withMessages([
                    'email' => ['Your account is inactive. Please contact support.'],
                ]);
            }

            if (!Hash::check($credentials['password'], $shopOwner->password)) {
                throw ValidationException::withMessages([
                    'email' => ['Invalid email or password.'],
                ]);
            }

            // Login the shop owner using shop_owner guard
            Auth::guard('shop_owner')->login($shopOwner, $request->filled('remember'));
            $request->session()->regenerate();

            $shopOwner->update([
                'last_login_at' => now(),
                'last_login_ip' => $request->ip(),
            ]);

            \Log::info('Shop owner logged in successfully via unified login', [
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
            \Log::error('Error logging in user', ['error' => $e->getMessage()]);

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
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function logout(Request $request)
    {
        $userId = Auth::id();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        \Log::info('User logged out', ['user_id' => $userId]);

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
