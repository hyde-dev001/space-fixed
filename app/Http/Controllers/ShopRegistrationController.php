<?php

namespace App\Http\Controllers;

use App\Services\CaviteLocationPolicyService;
use Illuminate\Http\Request;

final class ShopRegistrationController extends Controller
{
    /**
     * The legacy API route remains registered for compatibility, but it must
     * not create an owner without the canonical immutable document contract.
     */
    public function store(Request $request, CaviteLocationPolicyService $caviteLocationPolicy)
    {
        $validated = $request->validate([
            'firstName' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'email' => 'required|email|unique:shop_owners,email',
            'phone' => 'required|string|max:20|unique:shop_owners,phone',
            'businessName' => 'required|string|max:255',
            'businessAddress' => 'required|string|max:255',
            'postalCode' => 'nullable|string|max:20',
            'postal_code' => 'nullable|string|max:20',
            'zipCode' => 'nullable|string|max:20',
            'zip_code' => 'nullable|string|max:20',
            'businessType' => 'required|string|max:100',
            'registrationType' => 'required|string|max:100',
            'operatingHours' => 'nullable|array',
            'shop_latitude' => 'nullable|numeric|between:-90,90',
            'shop_longitude' => 'nullable|numeric|between:-180,180',
            'shopLatitude' => 'nullable|numeric|between:-90,90',
            'shopLongitude' => 'nullable|numeric|between:-180,180',
        ]);

        try {
            $caviteLocationPolicy->assertRegistrationLocation(
                $validated['shop_latitude'] ?? $validated['shopLatitude'] ?? null,
                $validated['shop_longitude'] ?? $validated['shopLongitude'] ?? null,
                $validated['businessAddress'] ?? null,
                $request,
                null,
                [
                    'email' => $validated['email'] ?? null,
                    'business_name' => $validated['businessName'] ?? null,
                    'target_type' => 'shop_owner_registration',
                ],
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $caviteLocationPolicy->denialMessage(),
                'errors' => $e->errors(),
            ], 422);
        }

        return $this->rejectLegacyDocumentContract($request);
    }

    /**
     * The legacy full-registration API has no versioned metadata contract.
     */
    public function storeFull(Request $request, CaviteLocationPolicyService $caviteLocationPolicy)
    {
        $validated = $request->validate([
            'firstName' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'email' => 'required|email|unique:shop_owners,email',
            'phone' => 'required|string|max:20|unique:shop_owners,phone',
            'businessName' => 'required|string|max:255',
            'businessAddress' => 'required|string|max:255',
            'postalCode' => 'nullable|string|max:20',
            'postal_code' => 'nullable|string|max:20',
            'zipCode' => 'nullable|string|max:20',
            'zip_code' => 'nullable|string|max:20',
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
            'shop_latitude' => 'nullable|numeric|between:-90,90',
            'shop_longitude' => 'nullable|numeric|between:-180,180',
            'shopLatitude' => 'nullable|numeric|between:-90,90',
            'shopLongitude' => 'nullable|numeric|between:-180,180',
        ]);

        try {
            $caviteLocationPolicy->assertRegistrationLocation(
                $validated['shop_latitude'] ?? $validated['shopLatitude'] ?? null,
                $validated['shop_longitude'] ?? $validated['shopLongitude'] ?? null,
                $validated['businessAddress'] ?? null,
                $request,
                null,
                [
                    'email' => $validated['email'] ?? null,
                    'business_name' => $validated['businessName'] ?? null,
                    'target_type' => 'shop_owner_registration',
                ],
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $caviteLocationPolicy->denialMessage(),
                'errors' => $e->errors(),
            ], 422);
        }

        if (! $validated['agreesToRequirements']) {
            return response()->json([
                'success' => false,
                'message' => 'You must confirm you have all required business permits and valid ID.',
            ], 422);
        }

        return $this->rejectLegacyDocumentContract($request);
    }

    /**
     * The legacy Inertia action remains registered but has no write path.
     */
    public function storeFullInertia(Request $request, CaviteLocationPolicyService $caviteLocationPolicy)
    {
        $validated = $request->validate([
            'firstName' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'email' => 'required|email|unique:shop_owners,email',
            'phone' => 'required|string|max:20|unique:shop_owners,phone',
            'businessName' => 'required|string|max:255',
            'businessAddress' => 'required|string|max:255',
            'postalCode' => 'nullable|string|max:20',
            'postal_code' => 'nullable|string|max:20',
            'zipCode' => 'nullable|string|max:20',
            'zip_code' => 'nullable|string|max:20',
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
            'shop_latitude' => 'nullable|numeric|between:-90,90',
            'shop_longitude' => 'nullable|numeric|between:-180,180',
            'shopLatitude' => 'nullable|numeric|between:-90,90',
            'shopLongitude' => 'nullable|numeric|between:-180,180',
        ]);

        try {
            $caviteLocationPolicy->assertRegistrationLocation(
                $validated['shop_latitude'] ?? $validated['shopLatitude'] ?? null,
                $validated['shop_longitude'] ?? $validated['shopLongitude'] ?? null,
                $validated['businessAddress'] ?? null,
                $request,
                null,
                [
                    'email' => $validated['email'] ?? null,
                    'business_name' => $validated['businessName'] ?? null,
                    'target_type' => 'shop_owner_registration',
                ],
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput();
        }

        if (! $validated['agreesToRequirements']) {
            return redirect()->back()->withErrors([
                'agreesToRequirements' => 'You must confirm you have all required business permits and valid ID.',
            ])->withInput();
        }

        return $this->rejectLegacyDocumentContract($request);
    }

    private function rejectLegacyDocumentContract(Request $request)
    {
        $errors = [
            'documents' => [
                'This compatibility route no longer accepts the legacy document contract. Submit through the canonical shop-owner registration form.',
            ],
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => 'Please use the canonical versioned document registration form.',
                'errors' => $errors,
            ], 422);
        }

        return redirect()->back()->withErrors($errors)->withInput();
    }
}
