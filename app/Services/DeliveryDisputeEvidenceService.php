<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class DeliveryDisputeEvidenceService
{
    public const MAX_IMAGE_BYTES = 20 * 1024 * 1024;

    public const MAX_VIDEO_BYTES = 256 * 1024 * 1024;

    private const IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private const VIDEO_MIMES = [
        'video/mp4',
        'video/quicktime',
        'video/x-msvideo',
        'video/x-matroska',
        'video/webm',
    ];

    /**
     * @param array<int, UploadedFile> $files
     */
    public function validateFiles(array $files): void
    {
        if (count($files) !== 6) {
            throw ValidationException::withMessages([
                'media' => 'Upload exactly 5 images and 1 opening-parcel video.',
            ]);
        }

        $imageCount = 0;
        $videoCount = 0;

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw ValidationException::withMessages([
                    'media' => 'Each report proof file must be a valid upload.',
                ]);
            }

            $mime = strtolower((string) $file->getMimeType());
            if (in_array($mime, self::VIDEO_MIMES, true)) {
                if ((int) $file->getSize() > self::MAX_VIDEO_BYTES) {
                    throw ValidationException::withMessages([
                        'media' => 'The opening-parcel video must be 256MB or smaller.',
                    ]);
                }

                $videoCount++;
                continue;
            }

            if (! in_array($mime, self::IMAGE_MIMES, true)) {
                throw ValidationException::withMessages([
                    'media' => 'Report proof must contain JPG, PNG, WEBP images and one supported video.',
                ]);
            }

            if ((int) $file->getSize() > self::MAX_IMAGE_BYTES) {
                throw ValidationException::withMessages([
                    'media' => 'Each report image must be 20MB or smaller.',
                ]);
            }

            $imageCount++;
        }

        if ($imageCount !== 5 || $videoCount !== 1) {
            throw ValidationException::withMessages([
                'media' => 'Upload exactly 5 images and 1 opening-parcel video.',
            ]);
        }
    }

    /**
     * @param array<int, UploadedFile> $files
     * @return array<int, array{id: string, path: string, kind: string, mime_type: string, original_name: string, size: int}>
     */
    public function store(array $files, int $orderId): array
    {
        $stored = [];
        $directory = "delivery-dispute-evidence/order-{$orderId}";

        try {
            foreach ($files as $file) {
                $mime = strtolower((string) $file->getMimeType());
                $path = $file->store($directory, 'local');

                if (! is_string($path) || $path === '') {
                    throw new \RuntimeException('Customer report proof could not be stored.');
                }

                $stored[] = [
                    'id' => (string) Str::uuid(),
                    'path' => $path,
                    'kind' => in_array($mime, self::VIDEO_MIMES, true) ? 'video' : 'image',
                    'mime_type' => $mime,
                    'original_name' => (string) $file->getClientOriginalName(),
                    'size' => (int) $file->getSize(),
                ];
            }

            return $stored;
        } catch (Throwable $exception) {
            $this->delete($stored);

            throw $exception;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $media
     */
    public function delete(array $media): void
    {
        foreach ($media as $entry) {
            $path = $entry['path'] ?? null;
            if (! is_string($path) || ! $this->isSafePath($path)) {
                continue;
            }

            Storage::disk('local')->delete($path);
        }
    }

    public function isSafePath(string $path): bool
    {
        return str_starts_with($path, 'delivery-dispute-evidence/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\');
    }
}
