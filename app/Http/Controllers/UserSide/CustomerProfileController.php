<?php

namespace App\Http\Controllers\UserSide;

use App\Exceptions\IdentityDocumentScreeningException;
use App\Http\Controllers\Controller;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Rules\ValidIdentityDocumentImage;
use App\Services\IdentityVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CustomerProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = Auth::guard('user')->user();

        if ($user && $user->shop_owner_id) {
            return redirect()->route('erp.profile');
        }

        $user->load('latestIdentityVerification');

        return Inertia::render('UserSide/Profile/customerProfile', [
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'address' => $user->address,
                'profile_photo_url' => $user->profile_photo ? "/storage/{$user->profile_photo}" : null,
            ],
            'identity_verification' => $this->identityPayload($user),
        ]);
    }

    public function resubmitIdentity(Request $request, IdentityVerificationService $identityVerification)
    {
        $user = Auth::guard('user')->user();

        if (! $user instanceof User || ! $user->isCustomerAccount()) {
            abort(404);
        }

        $validated = $request->validate([
            'valid_id' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:5120',
                new ValidIdentityDocumentImage,
            ],
            'valid_id_back' => [
                'nullable',
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
        ]);

        $nationalIdFormat = $validated['national_id_format'] ?? 'physical_card';
        if ($validated['document_type'] !== 'national_id' && $nationalIdFormat !== 'physical_card') {
            throw ValidationException::withMessages([
                'national_id_format' => 'A digital National ID format is only available for National ID submissions.',
            ]);
        }

        $screeningMetadata = $identityVerification->decodeScreeningMetadata($validated['screening_metadata']);
        if (($screeningMetadata['document_type'] ?? null) !== $validated['document_type']) {
            throw ValidationException::withMessages([
                'screening_metadata' => 'The ID image check does not match the selected document type. Please try again.',
            ]);
        }

        if (($screeningMetadata['national_id_format'] ?? 'physical_card') !== $nationalIdFormat) {
            throw ValidationException::withMessages([
                'screening_metadata' => 'The ID image check does not match the selected National ID format. Please try again.',
            ]);
        }

        $screeningMetadata['national_id_format'] = $nationalIdFormat;
        $screeningDecision = $identityVerification->evaluate($screeningMetadata, (string) $user->name);
        $screeningMetadata = $identityVerification->reconcileScreeningOutcome(
            $screeningMetadata,
            $screeningDecision,
        );

        if (! in_array(($screeningDecision['outcome'] ?? null), ['screening_passed', 'manual_review_required'], true)) {
            throw ValidationException::withMessages([
                'screening_metadata' => ($screeningDecision['outcome'] ?? null) === 'screening_error'
                    ? 'We couldn\'t check this image right now. Please try again or select another image.'
                    : 'Please upload a clear image of your valid ID.',
            ]);
        }

        try {
            $verification = DB::transaction(function () use (
                $user,
                $identityVerification,
                $request,
                $screeningMetadata,
            ): IdentityVerification {
                $lockedUser = User::query()->lockForUpdate()->find($user->getKey());
                $latest = $lockedUser?->identityVerifications()->latest('id')->first();

                if (
                    ! $lockedUser instanceof User
                    || ! $latest instanceof IdentityVerification
                    || (string) $latest->review_status !== IdentityVerification::REVIEW_REJECTED
                ) {
                    throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException(
                        'Only a rejected identity verification can be resubmitted.',
                    );
                }

                return $identityVerification->screen(
                    $lockedUser,
                    $request->file('valid_id'),
                    $screeningMetadata,
                    $request->file('valid_id_back'),
                    $latest,
                );
            });
        } catch (IdentityDocumentScreeningException $exception) {
            throw ValidationException::withMessages([
                'screening_metadata' => 'We couldn\'t accept this ID image. Please upload a clear, valid replacement.',
            ]);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'valid_id' => $exception->getMessage(),
            ]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Your new ID was submitted for review.',
                'identity_verification' => $this->identityRecordPayload($verification),
            ]);
        }

        return back()->with('success', 'Your new ID was submitted for review.');
    }

    public function update(Request $request)
    {
        $user = Auth::guard('user')->user();

        if ($user && $user->shop_owner_id) {
            return redirect()->route('erp.profile');
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'profile_photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120',
        ]);

        if ($request->hasFile('profile_photo')) {
            if ($user->profile_photo && Storage::disk('public')->exists($user->profile_photo)) {
                Storage::disk('public')->delete($user->profile_photo);
            }

            $path = $request->file('profile_photo')->store('profile-photos/users', 'public');
            $validated['profile_photo'] = $path;
        }

        $validated['name'] = trim(($validated['first_name'] ?? '') . ' ' . ($validated['last_name'] ?? ''));

        $user->update($validated);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Profile updated successfully',
                'user' => [
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'address' => $user->address,
                    'profile_photo_url' => $user->profile_photo ? "/storage/{$user->profile_photo}" : null,
                ],
            ]);
        }

        return back()->with('success', 'Profile updated successfully');
    }

    /** @return array<string, mixed>|null */
    private function identityPayload(User $user): ?array
    {
        $verification = $user->latestIdentityVerification;
        if (! $verification instanceof IdentityVerification) {
            return null;
        }

        return [
            'status' => (string) $user->identity_verification_status,
            'current' => $this->identityRecordPayload($verification),
            'can_resubmit' => (string) $verification->review_status === IdentityVerification::REVIEW_REJECTED,
            'history' => $user->identityVerifications()
                ->latest('id')
                ->get()
                ->map(fn (IdentityVerification $record): array => $this->identityRecordPayload($record))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function identityRecordPayload(IdentityVerification $verification): array
    {
        return [
            'id' => (int) $verification->getKey(),
            'document_type' => $verification->document_type,
            'screening_status' => $verification->screening_status,
            'review_status' => $verification->review_status,
            'failure_reason' => $verification->failure_reason,
            'rejection_reason' => $verification->rejection_reason,
            'rejection_notes' => $verification->rejection_notes,
            'submitted_at' => $verification->created_at?->toIso8601String(),
            'reviewed_at' => $verification->reviewed_at?->toIso8601String(),
            'front_url' => route('customer.identity-verifications.front', ['verification' => $verification->getKey()]),
            'back_url' => $verification->back_file_path
                ? route('customer.identity-verifications.back', ['verification' => $verification->getKey()])
                : null,
        ];
    }

    public function updatePassword(Request $request)
    {
        $user = Auth::guard('user')->user();

        if ($user && $user->shop_owner_id) {
            return redirect()->route('erp.profile');
        }

        $request->validate([
            'current_password' => ['required'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect',
                'errors' => ['current_password' => ['Current password is incorrect']],
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Password updated successfully',
            ]);
        }

        return back()->with('success', 'Password updated successfully');
    }
}
