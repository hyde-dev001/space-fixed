<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductColorVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateImagesToMediaLibrary extends Command
{
    protected $signature = 'media:migrate-images {--products} {--variants} {--all}';
    protected $description = 'Migrate existing product and variant images to Media Library';

    public function handle()
    {
        $all = $this->option('all') ?? (!$this->option('products') && !$this->option('variants'));
        
        if ($all || $this->option('products')) {
            $this->migrateProductImages();
        }

        if ($all || $this->option('variants')) {
            $this->migrateVariantImages();
        }

        $this->info('Migration completed!');
    }

    private function migrateProductImages()
    {
        $this->info('Migrating product images...');
        $count = 0;

        Product::chunk(100, function ($products) use (&$count) {
            foreach ($products as $product) {
                try {
                    // Migrate main image
                    if ($product->main_image && Storage::disk('public')->exists('products/' . $product->main_image)) {
                        $product->addMedia(storage_path('app/public/products/' . $product->main_image))
                            ->toMediaCollection('main_image');
                        $count++;
                    }

                    // Migrate additional images
                    if ($product->additional_images && is_array($product->additional_images)) {
                        foreach ($product->additional_images as $image) {
                            if (Storage::disk('public')->exists('products/' . $image)) {
                                $product->addMedia(storage_path('app/public/products/' . $image))
                                    ->toMediaCollection('additional_images');
                                $count++;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $this->warn("Failed for product {$product->id}: " . $e->getMessage());
                }
            }
        });

        $this->line("✓ Migrated {$count} product images");
    }

    private function migrateVariantImages()
    {
        $this->info('Migrating color variant images...');
        $count = 0;

        ProductColorVariant::chunk(100, function ($variants) use (&$count) {
            foreach ($variants as $variant) {
                try {
                    $images = $variant->images()->get();
                    foreach ($images as $image) {
                        if ($image->image_path && Storage::disk('public')->exists($image->image_path)) {
                            $variant->addMedia(storage_path('app/public/' . $image->image_path))
                                ->toMediaCollection($image->is_thumbnail ? 'color_thumbnail' : 'color_images');
                            $count++;
                        }
                    }
                } catch (\Exception $e) {
                    $this->warn("Failed for variant {$variant->id}: " . $e->getMessage());
                }
            }
        });

        $this->line("✓ Migrated {$count} color variant images");
    }
}
