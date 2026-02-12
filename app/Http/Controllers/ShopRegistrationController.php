<?php

namespace App\Http\Controllers;

use App\Models\ShopOwner;
use App\Models\ShopDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ShopRegistrationController extends Controller
{
    // <!-- API endpoint to register shop owner from React frontend -->
    public function store(Request $request)
    {
        // <!-- Validate incoming request data -->
        $validated = $request->validate([
            'firstName' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'email' => 'required|email|unique:shop_owners,email',
            'phone' => 'required|string|max:20',
            'businessName' => 'required|string|max:255',
            'businessAddress' => 'required|string|max:255',
            'businessType' => 'required|string|max:100',
            'registrationType' => 'required|string|max:100',
            'operatingHours' => 'nullable|array',
        ]);

        try {
            // <!-- Create new shop owner record in database with proper field mapping -->
            $shopOwner = ShopOwner::create([
                'first_name' => $validated['firstName'],
                'last_name' => $validated['lastName'],
                'email' => $validated['email'],
                'phone' => $validated['phone'],
                'business_name' => $validated['businessName'],
                'business_address' => $validated['businessAddress'],
                'business_type' => $validated['businessType'],
                'registration_type' => $validated['registrationType'],
                'operating_hours' => isset($validated['operatingHours']) ? json_encode($validated['operatingHours']) : null,
                'status' => 'pending', // <!-- Set initial status to pending for Super Admin approval -->
            ]);

            // <!-- Return JSON success response -->
            return response()->json([
                'success' => true,
                'message' => 'Shop owner registration submitted successfully! Your application is pending review.',
                'data' => $shopOwner,
            ], 201);

        } catch (\Exception $e) {
            // <!-- Log the error for debugging -->
            \Log::error('Shop registration error: ' . $e->getMessage());
            
            // <!-- Return JSON error response -->
            return response()->json([
                'success' => false,
                'message' => 'Registration failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Register a full shop owner with operating hours
     * <!-- Comprehensive registration endpoint for complete shop details -->
     */
    public function storeFull(Request $request)
    {
        // <!-- Validate incoming request data -->
        $validated = $request->validate([
            'firstName' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'email' => 'required|email|unique:shop_owners,email',
            'phone' => 'required|string|max:20',
            'businessName' => 'required|string|max:255',
            'businessAddress' => 'required|string|max:255',
            'businessType' => 'required|string|max:100',
            'registrationType' => 'required|string|max:100',
            'operatingHours' => 'required|array',
            'operatingHours.*.day' => 'required|string',
            'operatingHours.*.open' => 'required|date_format:H:i',
            'operatingHours.*.close' => 'required|date_format:H:i',
            'agreesToRequirements' => 'required|boolean',
            'dtiRegistration' => 'required|file|mimes:jpeg,png|max:5120',
            'mayorsPermit' => 'required|file|mimes:jpeg,png|max:5120',
            'birCertificate' => 'required|file|mimes:jpeg,png|max:5120',
            'validId' => 'required|file|mimes:jpeg,png|max:5120',
        ]);

        try {
            // <!-- Validate that user has acknowledged requirements -->
            if (!$validated['agreesToRequirements']) {
                return response()->json([
                    'success' => false,
                    'message' => 'You must confirm you have all required business permits and valid ID.',
                ], 422);
            }

            // <!-- Format operating hours for storage -->
            $formattedHours = [];
            foreach ($validated['operatingHours'] as $hours) {
                $formattedHours[$hours['day']] = [
                    'open' => $hours['open'],
                    'close' => $hours['close'],
                ];
            }

            // <!-- Create new shop owner record in database -->
            $shopOwner = ShopOwner::create([
                'first_name' => $validated['firstName'],
                'last_name' => $validated['lastName'],
                'email' => $validated['email'],
                'phone' => $validated['phone'],
                'business_name' => $validated['businessName'],
                'business_address' => $validated['businessAddress'],
                'business_type' => $validated['businessType'],
                'registration_type' => $validated['registrationType'],
                'operating_hours' => json_encode($formattedHours),
                'status' => 'pending', // <!-- Set initial status to pending for Super Admin approval -->
            ]);

            $this->storeDocuments($request, $shopOwner);

            // <!-- Log successful registration for audit trail -->
            \Log::info('Shop owner registered: ' . $shopOwner->id . ' - ' . $shopOwner->email);

            // <!-- Return JSON success response -->
            return response()->json([
                'success' => true,
                'message' => 'Shop owner registration submitted successfully! Your application is pending review.',
                'data' => $shopOwner,
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // <!-- Return validation errors -->
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            // <!-- Log the error for debugging -->
            \Log::error('Shop registration error: ' . $e->getMessage());
            
            // <!-- Return JSON error response -->
            return response()->json([
                'success' => false,
                'message' => 'Registration failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Register a full shop owner with Inertia (server-side form submission)
     * <!-- Handles POST from Inertia form with automatic CSRF token handling -->
     */
    public function storeFullInertia(Request $request)
    {
        // <!-- Validate incoming request data -->
        $validated = $request->validate([
            'firstName' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'email' => 'required|email|unique:shop_owners,email',
            'phone' => 'required|string|max:20',
            'businessName' => 'required|string|max:255',
            'businessAddress' => 'required|string|max:255',
            'businessType' => 'required|string|max:100',
            'registrationType' => 'required|string|max:100',
            'operatingHours' => 'required|array',
            'operatingHours.*.day' => 'required|string',
            'operatingHours.*.open' => 'required|date_format:H:i',
            'operatingHours.*.close' => 'required|date_format:H:i',
            'agreesToRequirements' => 'required|boolean',
            'dtiRegistration' => 'required|file|mimes:jpeg,png|max:5120',
            'mayorsPermit' => 'required|file|mimes:jpeg,png|max:5120',
            'birCertificate' => 'required|file|mimes:jpeg,png|max:5120',
            'validId' => 'required|file|mimes:jpeg,png|max:5120',
        ]);

        try {
            // <!-- Validate that user has acknowledged requirements -->
            if (!$validated['agreesToRequirements']) {
                return redirect()->back()->withErrors([
                    'agreesToRequirements' => 'You must confirm you have all required business permits and valid ID.',
                ]);
            }

            // <!-- Format operating hours for storage -->
            $formattedHours = [];
            foreach ($validated['operatingHours'] as $hours) {
                $formattedHours[$hours['day']] = [
                    'open' => $hours['open'],
                    'close' => $hours['close'],
                ];
            }

            // <!-- Create new shop owner record in database -->
            $shopOwner = ShopOwner::create([
                'first_name' => $validated['firstName'],
                'last_name' => $validated['lastName'],
                'email' => $validated['email'],
                'phone' => $validated['phone'],
                'business_name' => $validated['businessName'],
                'business_address' => $validated['businessAddress'],
                'business_type' => $validated['businessType'],
                'registration_type' => $validated['registrationType'],
                'operating_hours' => json_encode($formattedHours),
                'status' => 'pending',
            ]);

            $this->storeDocuments($request, $shopOwner);

            // <!-- Log successful registration for audit trail -->
            \Log::info('Shop owner registered via Inertia: ' . $shopOwner->id . ' - ' . $shopOwner->email);

            // <!-- Redirect with success message -->
            return redirect()->back()->with('success', 'Registration submitted successfully! Your application is pending review.');

        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput();
        } catch (\Exception $e) {
            \Log::error('Shop registration error: ' . $e->getMessage());
            return redirect()->back()->withErrors(['message' => 'Registration failed: ' . $e->getMessage()]);
        }
    }

    private function storeDocuments(Request $request, ShopOwner $shopOwner): void
    {
        $documentMap = [
            'dtiRegistration' => 'dti_registration',
            'mayorsPermit' => 'mayors_permit',
            'birCertificate' => 'bir_certificate',
            'validId' => 'valid_id',
        ];

        foreach ($documentMap as $field => $type) {
            if ($request->hasFile($field)) {
                $path = $request->file($field)->store('shop_documents', 'public');

                ShopDocument::create([
                    'shop_owner_id' => $shopOwner->id,
                    'document_type' => $type,
                    'file_path' => $path,
                    'status' => 'pending',
                ]);
            }
        }
    }
}
