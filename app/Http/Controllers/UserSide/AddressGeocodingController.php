<?php

namespace App\Http\Controllers\UserSide;

use App\Http\Controllers\Controller;
use App\Services\NominatimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AddressGeocodingController extends Controller
{
    public function __construct(private readonly NominatimService $nominatim) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (is_string($request->query('q'))) {
            $request->merge(['q' => preg_replace('/\s+/u', ' ', trim($request->query('q'))) ?? '']);
        }

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'min:2', 'max:200', 'required_without_all:latitude,longitude', 'prohibits:latitude,longitude'],
            'latitude' => ['nullable', 'numeric', 'between:4.5,21.5', 'required_without:q', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:116,127', 'required_without:q', 'required_with:latitude'],
        ]);

        try {
            $payload = isset($validated['q'])
                ? $this->nominatim->search($validated['q'])
                : $this->nominatim->reverse((float) $validated['latitude'], (float) $validated['longitude']);
        } catch (RuntimeException $exception) {
            return $exception->getCode() === 429 ? $this->busy() : $this->unavailable();
        }

        return response()->json($payload);
    }

    private function busy(): JsonResponse
    {
        return response()->json([
            'message' => 'Address lookup is busy. Please try again shortly.',
            'retry_after' => 1,
        ], 429)->header('Retry-After', '1');
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'message' => 'Address lookup is unavailable. Please try again.',
        ], 502);
    }
}
