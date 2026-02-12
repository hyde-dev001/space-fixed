<?php

namespace App\Http\Controllers;

use App\Models\ShopOwner;
use App\Models\ShopDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * ShopOwnerAuthController
 * 
 * Handles shop owner registration, authentication, and document uploads
 * Shop owners require admin approval before they can fully access the system
 */
class ShopOwnerAuthController extends Controller
{
    /**
     * Register a new shop owner
     * 
     * Shop owners are created with 'pending' status
     * Super admin must approve before they can login
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
                'email' => 'required|string|email|max:255|unique:shop_owners,email',
                'phone' => 'required|string|max:20|min:10',
                'business_name' => 'required|string|max:255',
                'business_address' => 'required|string|max:500',
                'business_type' => 'required|in:retail,repair,both (retail & repair)',
                'registration_type' => 'required|in:individual,company',
                // operating hours removed from required validation

                // Document uploads
                'dti_registration' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
                'mayors_permit' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
                'bir_certificate' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
                'valid_id' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            ], [
                'first_name.required' => 'First name is required',
                'last_name.required' => 'Last name is required',
                'email.required' => 'Email is required',
                'email.email' => 'Please provide a valid email address',
                'email.unique' => 'This email is already registered',
                'phone.required' => 'Phone number is required',
                'business_name.required' => 'Business name is required',
                'business_address.required' => 'Business address is required',
                'business_type.required' => 'Business type is required',
                'registration_type.required' => 'Registration type is required',
                'dti_registration.required' => 'DTI Registration is required',
                'mayors_permit.required' => "Mayor's Permit is required",
                'bir_certificate.required' => 'BIR Certificate is required',
                'valid_id.required' => 'Valid ID is required',
            ]);

            DB::beginTransaction();

            // Create shop owner with pending status
            $shopOwner = ShopOwner::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'],
                'password' => null, // Will be set after admin approval via email
                'business_name' => $validated['business_name'],
                'business_address' => $validated['business_address'],
                'business_type' => $validated['business_type'],
                'registration_type' => $validated['registration_type'],
                // operating_hours intentionally omitted (removed client-side)
                'status' => 'pending', // Requires admin approval
            ]);

            // Upload and save documents
            $documents = [
                'dti_registration' => 'DTI Registration',
                'mayors_permit' => "Mayor's Permit",
                'bir_certificate' => 'BIR Certificate',
                'valid_id' => 'Valid ID',
            ];

            foreach ($documents as $fieldName => $documentType) {
                if ($request->hasFile($fieldName)) {
                    $file = $request->file($fieldName);
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

            DB::commit();

            \Log::info('Shop owner registered successfully', [
                'shop_owner_id' => $shopOwner->id,
                'email' => $shopOwner->email,
                'business_name' => $shopOwner->business_name,
            ]);

            // Return success response
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Registration successful! Your application is pending admin approval.',
                    'shop_owner' => [
                        'id' => $shopOwner->id,
                        'business_name' => $shopOwner->business_name,
                        'email' => $shopOwner->email,
                        'status' => $shopOwner->status,
                    ],
                    'csrf_token' => csrf_token(), // Send new CSRF token
                ], 201);
            }

            return redirect()->route('shop-owner.login')->with('success', 'Registration successful! Your application is pending admin approval.');
        } catch (ValidationException $e) {
            DB::rollBack();
            \Log::warning('Shop owner registration validation failed', ['errors' => $e->errors()]);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $e->errors(),
                ], 422);
            }

            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error registering shop owner', [
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

            if ($shopOwner->status !== 'approved') {
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

            // Login the shop owner using shop_owner guard
            Auth::guard('shop_owner')->login($shopOwner, $request->filled('remember'));

            // Regenerate session
            $request->session()->regenerate();

            // Update last login information
            $shopOwner->update([
                'last_login_at' => now(),
                'last_login_ip' => $request->ip(),
            ]);

            \Log::info('Shop owner logged in successfully', [
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
            \Log::error('Error logging in shop owner', ['error' => $e->getMessage()]);

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
     * Logout a shop owner
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function logout(Request $request)
    {
        $shopOwnerId = Auth::guard('shop_owner')->id();

        Auth::guard('shop_owner')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        \Log::info('Shop owner logged out', ['shop_owner_id' => $shopOwnerId]);

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
