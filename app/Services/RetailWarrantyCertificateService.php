<?php

namespace App\Services;

use App\Models\RetailWarrantyIssuance;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class RetailWarrantyCertificateService
{
    public function download(RetailWarrantyIssuance $issuance): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        try {
            $path = $this->generate($issuance);
        } catch (\Throwable $failure) {
            \Illuminate\Support\Facades\Log::warning('Product Warranty certificate unavailable.', ['issuance_id' => $issuance->id]);
            abort(503, 'The certificate is temporarily unavailable. Please try again later.');
        }

        return response()->download(Storage::disk('local')->path($path), $issuance->warranty_number.'.pdf',
            ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function generate(RetailWarrantyIssuance $issuance): string
    {
        return DB::transaction(function () use ($issuance) {
            $issuance = RetailWarrantyIssuance::whereKey($issuance->id)->lockForUpdate()->firstOrFail()->load('warranties');
            $disk = Storage::disk('local');
            $path = $this->pathFor($issuance);
            if ($issuance->certificate_path) {
                if ($issuance->certificate_path !== $path) {
                    throw new \LogicException('Invalid private certificate path.');
                }
                if ($disk->exists($path)) {
                    if (! hash_equals((string) $issuance->certificate_hash, hash('sha256', $disk->get($path)))) {
                        throw new \RuntimeException('Certificate integrity check failed.');
                    }

                    return $path;
                }
                // A lost canonical document cannot be silently replaced with different bytes.
                throw new \RuntimeException('The original certificate is unavailable.');
            }
            $issuance->certificate_generated_at = now()->utc();
            $issuance->certificate_status_at_generation = $issuance->warranties->mapWithKeys(fn ($warranty) => [
                $warranty->id => $warranty->status === 'voided' ? 'voided' : ($warranty->status === 'expired' || now()->gte($warranty->warranty_expiration_date) ? 'expired' : 'active'),
            ])->all();
            File::ensureDirectoryExists(storage_path('fonts'));
            $bytes = Pdf::loadView('warranties.retail-certificate', ['issuance' => $issuance])
                ->setPaper('a4')->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false,
                    'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans'])->output();
            if (! str_starts_with($bytes, '%PDF-') || ! $disk->put($path, $bytes)) {
                throw new \RuntimeException('Certificate storage failed.');
            }
            $issuance->certificate_path = $path;
            $issuance->certificate_hash = hash('sha256', $bytes);
            $issuance->save();
            app(RetailWarrantyService::class)->audit($issuance->shop_owner_id, 'retail_warranty.certificate_generated', 'retail_warranty_issuance', $issuance->id,
                ['item_count' => $issuance->warranties->count(), 'sha256' => $issuance->certificate_hash]);

            return $path;
        }, 3);
    }

    public function pathFor(RetailWarrantyIssuance $issuance): string
    {
        if (! preg_match('/\AWRNTY-[A-Z0-9-]+\z/', $issuance->warranty_number)) {
            throw new \LogicException('Invalid certificate reference.');
        }

        return 'retail-warranties/'.(int) $issuance->shop_owner_id.'/'.$issuance->warranty_number.'.pdf';
    }
}
