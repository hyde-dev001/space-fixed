# Laravel Media Library Implementation Guide

## Overview
Laravel Media Library (Spatie) has been integrated for managing product images and color variant images. This provides automatic image optimization, conversions, and organized file storage.

## Configuration
- Config file: `config/media-library.php`
- Database: Media files are tracked in the `media` table
- Storage: Files stored in `storage/app/public` (disk: 'public')

## Usage Examples

### Product Images

#### Adding Main Image
```php
$product = Product::find(1);

// Add main image (single file collection)
$product->addMediaFromRequest('image')
    ->toMediaCollection('main_image');

// Or from file path
$product->addMediaFromDisk('/path/to/image.jpg', 'public')
    ->toMediaCollection('main_image');

// Or from URL
$product->addMediaFromUrl('https://example.com/image.jpg')
    ->toMediaCollection('main_image');
```

#### Adding Additional Images
```php
$product = Product::find(1);

// Add multiple images to 'additional_images' collection
$product->addMediaFromRequest('images')
    ->toMediaCollection('additional_images');

// Add multiple files
$product->addMedia('path/to/image1.jpg')
    ->toMediaCollection('additional_images');

$product->addMedia('path/to/image2.jpg')
    ->toMediaCollection('additional_images');
```

#### Retrieving Images
```php
// Get main image URL
echo $product->main_image_url; // attribute

// Get main image thumbnail
echo $product->main_image_thumb; // attribute

// Get all images
$images = $product->image_urls; // attribute

// Get media objects
$mainMedia = $product->getFirstMedia('main_image');
$additionalMedia = $product->getMedia('additional_images');

// Get specific size conversion
echo $mainMedia->getUrl('thumb');    // 150x150
echo $mainMedia->getUrl('medium');   // 500x500
echo $mainMedia->getUrl('large');    // 1000x1000
```

#### Deleting Images
```php
// Delete all media in collection
$product->clearMediaCollection('additional_images');

// Delete specific media
$product->getFirstMedia('main_image')->delete();

// Delete by media ID
$media = $product->getMedia('additional_images')->first();
$media->delete();
```

### Color Variant Images

#### Adding Color Images
```php
$colorVariant = ProductColorVariant::find(1);

// Add thumbnail image
$colorVariant->addMediaFromRequest('thumbnail')
    ->toMediaCollection('color_thumbnail');

// Add color variant images
$colorVariant->addMediaFromRequest('images')
    ->toMediaCollection('color_images');
```

#### Retrieving Color Images
```php
$colorVariant = ProductColorVariant::find(1);

// Get thumbnail
$thumbnail = $colorVariant->getFirstMedia('color_thumbnail');
echo $thumbnail?->getUrl('thumb');

// Get all color images
$images = $colorVariant->getMedia('color_images');
foreach ($images as $image) {
    echo $image->getUrl('preview'); // 600x600
}
```

## Image Conversions Available

### Product Images
- **thumb**: 150x150 (with sharpening)
- **medium**: 500x500
- **large**: 1000x1000

### Color Variant Images
- **thumb**: 200x200
- **preview**: 600x600

## Controllers Example

```php
<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class ProductImageController extends Controller
{
    public function uploadMainImage(Request $request, Product $product)
    {
        $request->validate([
            'image' => 'required|image|max:5120', // 5MB
        ]);

        $product->addMediaFromRequest('image')
            ->toMediaCollection('main_image');

        return back()->with('success', 'Main image uploaded');
    }

    public function uploadAdditionalImages(Request $request, Product $product)
    {
        $request->validate([
            'images.*' => 'required|image|max:5120',
        ]);

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $product->addMedia($image)
                    ->toMediaCollection('additional_images');
            }
        }

        return back()->with('success', 'Images uploaded');
    }

    public function getImages(Product $product)
    {
        return response()->json([
            'main_image' => $product->main_image_url,
            'main_thumb' => $product->main_image_thumb,
            'all_images' => $product->image_urls,
        ]);
    }

    public function deleteImage(Product $product, $mediaId)
    {
        $product->media()->findOrFail($mediaId)->delete();
        return back()->with('success', 'Image deleted');
    }
}
```

## Database Queries

Get products with their media:
```php
// Eager load media
$products = Product::with('media')->get();

// Query by media
$productsWithImages = Product::whereHas('media')->get();

// Get media for collection
$product->getMedia('main_image');
$product->getMedia('additional_images');
```

## Migration Notes

The migration file created: `database/migrations/2026_02_12_091646_create_media_table.php`

Schema includes:
- `media` table with columns for storing file metadata
- Relationships to polymorphic models
- File path, MIME type, size, and custom properties

## Best Practices

1. **Always validate file uploads** before adding to media library
2. **Use collections** to organize different image types
3. **Configure file sizes** based on your needs in `config/media-library.php`
4. **Queue image processing** for better performance:
   ```php
   $product->addMediaFromRequest('image')
       ->queue('process-images')
       ->toMediaCollection('main_image');
   ```
5. **Soft deletes** are supported - media can be restored with models
6. **Use responsive images** by generating multiple conversions

## API Response Example

```json
{
  "product": {
    "id": 1,
    "name": "Premium Shoe",
    "main_image_url": "https://example.com/storage/media/abc123.jpg",
    "main_image_thumb": "https://example.com/storage/media/conversions/abc123-thumb.jpg",
    "image_urls": [
      {
        "url": "https://example.com/storage/media/abc123.jpg",
        "type": "main"
      },
      {
        "url": "https://example.com/storage/media/def456.jpg",
        "type": "additional"
      }
    ]
  }
}
```

## Troubleshooting

**Images not displaying?**
- Ensure `FILESYSTEM_DISK=public` in `.env`
- Run `php artisan storage:link` to create symbolic link
- Check file permissions in `storage/app/public`

**Conversions not generating?**
- Install `intervention/image`: `composer require intervention/image`
- Ensure GD library is enabled in PHP

**Storage disk issues?**
- Check `config/filesystems.php` configuration
- Verify disk path is writable

## Removing Old System

To completely remove the old image storage system:
1. Migrate existing images to media library
2. Remove `main_image`, `additional_images` from Product migration
3. Remove old storage methods from controllers
4. Update tests to use Media Library

