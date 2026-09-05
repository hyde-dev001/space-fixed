<?php

namespace App\Services;

use App\Exceptions\IdentityDocumentScreeningException;
use App\Models\IdentityVerification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;

final class IdentityVerificationService
{
    public function __construct(
        private readonly IdentityDocumentClassifier $classifier,
    ) {}

    /**
     * Validate the browser's bounded admission envelope.
     *
     * This service does not perform semantic OCR. Without a trusted server-side
     * OCR engine, semantic document plausibility remains a browser/Tesseract.js
     * admission signal and is never treated as proof of authenticity.
     *
     * @param array<string, mixed> $screeningMetadata
     * @return array<string, mixed>
     */
    public function evaluate(array $screeningMetadata, string $holderName = ''): array
    {
        unset($holderName);

        return $this->classifier->classifySubmission(
            $screeningMetadata['document_type'] ?? null,
            $screeningMetadata['sides'] ?? [],
            is_string($screeningMetadata['duplicate_kind'] ?? null)
                ? $screeningMetadata['duplicate_kind']
                : 'none',
            is_string($screeningMetadata['national_id_format'] ?? null)
                ? $screeningMetadata['national_id_format']
                : 'physical_card',
            ($screeningMetadata['name_match'] ?? null) === true,
            is_string($screeningMetadata['outcome'] ?? null)
                ? $screeningMetadata['outcome']
                : 'screening_passed',
        );
    }

    /**
     * Decode the bounded browser screening envelope shared by registration and
     * customer resubmission.
     *
     * @return array<string, mixed>
     */
    public function decodeScreeningMetadata(mixed $encoded): array
    {
        if (! is_string($encoded) || trim($encoded) === '') {
            throw ValidationException::withMessages([
                'screening_metadata' => 'The ID image check result is invalid. Please try again.',
            ]);
        }

        try {
            $metadata = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'screening_metadata' => 'The ID image check result is invalid. Please try again.',
            ]);
        }

        if (! is_array($metadata)) {
            throw ValidationException::withMessages([
                'screening_metadata' => 'The ID image check result is invalid. Please try again.',
            ]);
        }

        $allowedKeys = ['document_type', 'national_id_format', 'outcome', 'duplicate_kind', 'name_match', 'sides'];
        $validOutcome = is_string($metadata['outcome'] ?? null)
            && in_array($metadata['outcome'], ['reject_upload', 'screening_passed', 'manual_review_required', 'screening_error'], true);
        $validDuplicateKind = is_string($metadata['duplicate_kind'] ?? null)
            && in_array($metadata['duplicate_kind'], ['none', 'exact', 'near'], true);
        $validNationalIdFormat = ! array_key_exists('national_id_format', $metadata)
            || (is_string($metadata['national_id_format'])
                && in_array($metadata['national_id_format'], ['physical_card', 'digital_image'], true));
        $validNameMatch = ! array_key_exists('name_match', $metadata)
            || is_bool($metadata['name_match']);

        if (
            array_diff(array_keys($metadata), $allowedKeys) !== []
            || array_diff(['document_type', 'outcome', 'duplicate_kind', 'sides'], array_keys($metadata)) !== []
            || ! is_string($metadata['document_type'] ?? null)
            || ! is_array($metadata['sides'] ?? null)
            || ! $validOutcome
            || ! $validDuplicateKind
            || ! $validNationalIdFormat
            || ! $validNameMatch
        ) {
            throw ValidationException::withMessages([
                'screening_metadata' => 'The ID image check result is invalid. Please try again.',
            ]);
        }

        return $metadata;
    }

    /**
     * Keep plausible but uncertain submissions eligible for human review.
     *
     * The browser result is an admission signal, not a final decision. If the
     * server-side shape check downgrades a claimed pass to manual review, keep
     * the submission and persist the server result. Obvious rejects and errors
     * still fail closed.
     *
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $decision
     * @return array<string, mixed>
     */
    public function reconcileScreeningOutcome(array $metadata, array $decision): array
    {
        $requestedOutcome = $metadata['outcome'] ?? null;
        $screenedOutcome = $decision['outcome'] ?? null;

        if ($requestedOutcome === $screenedOutcome) {
            return $metadata;
        }

        if (
            in_array($requestedOutcome, ['screening_passed', 'manual_review_required'], true)
            && in_array($screenedOutcome, ['screening_passed', 'manual_review_required'], true)
        ) {
            $metadata['outcome'] = $screenedOutcome;

            return $metadata;
        }

        throw ValidationException::withMessages([
            'screening_metadata' => 'The ID image check result is inconsistent. Please try again.',
        ]);
    }

    /**
     * Store a passed customer document privately.
     *
     * Rejected and errored screening envelopes fail before any file or database
     * write. The original uploaded files remain the authoritative evidence for
     * later authorized investigation; no raw OCR is persisted here.
     *
     * @param array<string, mixed> $screeningMetadata
     */
    public function screen(
        User $user,
        UploadedFile $file,
        array $screeningMetadata = [],
        ?UploadedFile $backFile = null,
        ?IdentityVerification $supersedes = null,
    ): IdentityVerification {
        if (! $user->isCustomerAccount()) {
            throw new InvalidArgumentException('Only customer accounts can submit identity documents.');
        }

        if ($supersedes instanceof IdentityVerification
            && ((int) $supersedes->user_id !== (int) $user->getKey()
                || (string) $supersedes->review_status !== IdentityVerification::REVIEW_REJECTED
                || (int) $supersedes->getKey() !== (int) $user->identityVerifications()->latest('id')->value('id'))) {
            throw new InvalidArgumentException('Only the latest rejected identity submission can be replaced.');
        }

        $decision = $this->evaluate($screeningMetadata, (string) $user->name);

        if (($decision['outcome'] ?? null) === 'screening_error') {
            throw new IdentityDocumentScreeningException($decision);
        }

        if (! in_array(($decision['outcome'] ?? null), ['screening_passed', 'manual_review_required'], true)) {
            throw new InvalidArgumentException($this->rejectionMessage($decision));
        }

        $documentType = (string) ($decision['document_type'] ?? '');
        $nationalIdFormat = (string) ($decision['national_id_format'] ?? 'physical_card');
        $frontMetadata = $this->inspectUpload($file);
        $backMetadata = $backFile ? $this->inspectUpload($backFile) : null;
        $isCardDocument = in_array('back', $this->requiredSlots($documentType, $nationalIdFormat), true);

        if ($isCardDocument && ! $backFile) {
            throw new InvalidArgumentException('Please upload the back image of the selected ID.');
        }

        if (! $isCardDocument && $backFile) {
            throw new InvalidArgumentException('A back image is not used for the selected ID type.');
        }

        if ($isCardDocument && $backMetadata && $this->hasSameBytes($file, $backFile)) {
            throw new IdentityDocumentScreeningException([
                'outcome' => 'reject_upload',
                'screening_status' => IdentityVerification::SCREENING_REJECTED,
                'review_status' => IdentityVerification::REVIEW_NOT_REQUIRED,
                'document_type' => $documentType,
                'failure_reason' => 'duplicate_sides',
                'failure_side' => 'back',
                'side_results' => [],
            ]);
        }

        $disk = (string) config('identity_verification.upload.disk', 'local');
        $path = null;
        $backPath = null;

        try {
            $filename = (string) Str::uuid().'.'.$frontMetadata['extension'];
            $path = Storage::disk($disk)->putFileAs('valid_ids', $file, $filename);

            if (! is_string($path) || $path === '') {
                throw new \RuntimeException('The identity document could not be stored.');
            }

            if ($backFile && $backMetadata) {
                $backFilename = (string) Str::uuid().'.'.$backMetadata['extension'];
                $backPath = Storage::disk($disk)->putFileAs('valid_ids', $backFile, $backFilename);

                if (! is_string($backPath) || $backPath === '') {
                    throw new \RuntimeException('The identity document back image could not be stored.');
                }
            }

            $verification = DB::transaction(function () use ($user, $path, $backPath, $disk, $decision, $supersedes): IdentityVerification {
                $isManualReview = ($decision['outcome'] ?? null) === 'manual_review_required';

                $verification = IdentityVerification::create([
                    'user_id' => $user->getKey(),
                    'document_type' => $decision['document_type'],
                    'screening_status' => $isManualReview
                        ? IdentityVerification::SCREENING_MANUAL_REVIEW_REQUIRED
                        : IdentityVerification::SCREENING_AUTOMATED_CHECK_PASSED,
                    'review_status' => IdentityVerification::REVIEW_PENDING,
                    'file_path' => $path,
                    'file_disk' => $disk,
                    'back_file_path' => $backPath,
                    'back_file_disk' => $backPath ? $disk : null,
                    'ocr_confidence' => $decision['ocr_confidence'] ?? null,
                    'classification_confidence' => null,
                    'failure_reason' => $decision['failure_reason'] ?? null,
                    'supersedes_verification_id' => $supersedes?->getKey(),
                ]);

                $user->forceFill([
                    'valid_id_path' => $path,
                    'valid_id_disk' => $disk,
                    'identity_verification_status' => User::IDENTITY_PENDING_REVIEW,
                ])->save();

                return $verification;
            });
        } catch (\Throwable $exception) {
            if (is_string($path) && $path !== '') {
                Storage::disk($disk)->delete($path);
            }

            if (is_string($backPath) && $backPath !== '') {
                Storage::disk($disk)->delete($backPath);
            }

            throw $exception;
        }

        Log::info('Identity document screening passed and evidence was stored privately', [
            'verification_id' => $verification->getKey(),
            'document_type' => $verification->document_type,
            'screening_status' => $verification->screening_status,
        ]);

        return $verification->fresh();
    }

    /**
     * @return array{mime: string, extension: string}
     */
    private function inspectUpload(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $maxBytes = (int) config('identity_verification.upload.max_kilobytes', 5120) * 1024;

        if (! $file->isValid() || ! $path || ! is_file($path) || (int) $file->getSize() > $maxBytes) {
            throw new InvalidArgumentException('Invalid identity document image.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $extension = is_string($mime)
            ? config('identity_verification.upload.mime_extensions.'.$mime)
            : null;

        if (! is_string($mime) || ! is_string($extension) || @getimagesize($path) === false) {
            throw new InvalidArgumentException('Invalid identity document image.');
        }

        return [
            'mime' => $mime,
            'extension' => $extension,
        ];
    }

    private function hasSameBytes(UploadedFile $front, UploadedFile $back): bool
    {
        $frontPath = $front->getRealPath();
        $backPath = $back->getRealPath();

        if (! is_string($frontPath) || ! is_file($frontPath) || ! is_string($backPath) || ! is_file($backPath)) {
            throw new InvalidArgumentException('Invalid identity document image.');
        }

        $frontHash = hash_file('sha256', $frontPath);
        $backHash = hash_file('sha256', $backPath);

        if (! is_string($frontHash) || ! is_string($backHash)) {
            throw new \RuntimeException('The identity document could not be screened.');
        }

        return hash_equals($frontHash, $backHash);
    }

    /**
     * @return array<int, string>
     */
    private function requiredSlots(string $documentType, string $nationalIdFormat = 'physical_card'): array
    {
        $definition = config('identity_verification.documents.'.$documentType, []);

        if ($documentType === 'national_id' && is_array($definition)) {
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

        $slots = is_array($definition) ? ($definition['required_slots'] ?? []) : [];

        $slots = array_values(array_filter(
            is_array($slots) ? $slots : [],
            static fn (mixed $slot): bool => is_string($slot)
                && in_array($slot, ['front', 'back', 'biodata'], true),
        ));

        if ($slots !== []) {
            return $slots;
        }

        return is_array($definition) && ($definition['requires_back'] ?? false) === true
            ? ['front', 'back']
            : ['biodata'];
    }

    /**
     * @param array<string, mixed> $decision
     */
    private function rejectionMessage(array $decision): string
    {
        if (($decision['failure_reason'] ?? null) === 'duplicate_sides') {
            return 'The front and back images appear to be the same. Please upload the back side of your ID.';
        }

        if (($decision['failure_side'] ?? null) === 'back') {
            return 'The back image does not appear to match the selected ID. Please upload the back side of your valid ID.';
        }

        return 'This image does not appear to match the selected ID type. Please upload a clear image of your valid Philippine ID.';
    }
}
