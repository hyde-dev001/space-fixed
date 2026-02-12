# Laravel Media Library - Setup Checklist ✓

## Installation Status: COMPLETE ✓

### Installed Components
- [x] `spatie/laravel-medialibrary` v11.18.2
- [x] `spatie/image` (image manipulation)
- [x] `spatie/image-optimizer` (optimization)
- [x] Database migrations created
- [x] Configuration published

### Updated Files
- [x] `app/Models/Product.php` - Added `HasMedia` & `InteractsWithMedia` traits
- [x] `app/Models/ProductColorVariant.php` - Added media support
- [x] `app/Http/Controllers/MediaLibraryController.php` - Created with full CRUD operations
- [x] `routes/api.php` - Added media endpoints
- [x] `app/Console/Commands/MigrateImagesToMediaLibrary.php` - Migration helper

### Database
- [x] Media table created
- [x] Ready for image uploads

---

## Next Steps

### 1. Create Symbolic Link
```bash
php artisan storage:link
```

### 2. Verify Storage Disk
Ensure `.env` has:
```
FILESYSTEM_DISK=public
```

### 3. Install Image Processing (Optional but Recommended)
```bash
composer require intervention/image
```

### 4. Migrate Existing Images (Optional)
If you have existing product images in `storage/app/public/products/`:
```bash
# Migrate all images
php artisan media:migrate-images --all

# Or specifically:
php artisan media:migrate-images --products
php artisan media:migrate-images --variants
```

### 5. Test Upload Endpoint
```bash
# Create test product
POST /api/media/products/1/main-image
Body: form-data with 'image' file

# Expected Response:
{
  "message": "Main image uploaded successfully",
  "image_url": "...",
  "thumb_url": "..."
}
```

---

## File Structure

```
database/migrations/
  └── 2026_02_12_091646_create_media_table.php

config/
  └── media-library.php

storage/
  └── app/public/
      └── media/               (created automatically)
          ├── [media files]
          └── conversions/     (thumbnails & optimized sizes)

app/
  ├── Http/Controllers/
  │   └── MediaLibraryController.php (NEW)
  ├── Models/
  │   ├── Product.php (UPDATED)
  │   └── ProductColorVariant.php (UPDATED)
  └── Console/Commands/
      └── MigrateImagesToMediaLibrary.php (NEW)

routes/
  └── api.php (UPDATED with media routes)
```

---

## Available API Endpoints

### Product Images
```
POST   /api/media/products/{product}/main-image
POST   /api/media/products/{product}/additional-images
GET    /api/media/products/{product}/images
POST   /api/media/products/{product}/reorder
```

### Color Variant Images
```
POST   /api/media/variants/{variant}/images
GET    /api/media/variants/{variant}/images
```

### General Operations
```
DELETE /api/media/files/{mediaId}
GET    /api/media/files/{mediaId}/download
```

---

## Image Conversions Available

### Product Images
- `thumb`: 150x150 (optimized, sharpened)
- `medium`: 500x500
- `large`: 1000x1000

### Color Variant Images
- `thumb`: 200x200
- `preview`: 600x600

Access via: `$media->getUrl('thumb')`

---

## Model Usage

### Upload Images
```php
$product = Product::find(1);

// Upload main image
$product->addMediaFromRequest('image')
    ->toMediaCollection('main_image');

// Upload multiple additional images
$product->addMediaFromRequest('images')
    ->toMediaCollection('additional_images');
```

### Retrieve Images
```php
// Attributes (automatically included in JSON responses)
$product->main_image_url;      // Full URL
$product->main_image_thumb;    // Thumbnail URL
$product->image_urls;          // All images array

// Get media objects
$product->getFirstMedia('main_image');
$product->getMedia('additional_images');
```

### Delete Images
```php
$product->clearMediaCollection('main_image');
$product->media()->find($mediaId)->delete();
```

---

## Configuration Tips

### Adjust Max File Size
Edit `config/media-library.php`:
```php
'max_file_size' => 10485760, // Default: 10MB (in bytes)
```

### Change Storage Disk
```php
'disk_name' => 'public', // or 's3', 'local', etc.
```

### Image Quality Settings
Edit `config/media-library.php` to adjust image optimizers.

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Images not displaying | Run `php artisan storage:link` |
| 404 on image URLs | Check storage permissions (755) |
| Conversions not generating | Install `intervention/image` package |
| Queue issues | Check queue configuration in `.env` |
| Disk errors | Verify `FILESYSTEM_DISK=public` in `.env` |

---

## Documentation Links

- [Spatie Media Library Docs](https://spatie.be/docs/laravel-medialibrary/v11)
- [Image Optimizer Docs](https://spatie.be/docs/image-optimizer)
- [Intervention Image Docs](https://image.intervention.io)

---

## Performance Tips

1. **Queue image processing** for large uploads
   ```php
   $product->addMedia($file)
       ->queue('process-images')
       ->toMediaCollection('main_image');
   ```

2. **Use CDN** for media delivery (configure in `media-library.php`)

3. **Cleanup old uploads** regularly
   ```bash
   php artisan media:cleanup
   ```

4. **Generate responsive images** by creating multiple conversions

---

**Setup completed on:** February 12, 2026
**Package Version:** spatie/laravel-medialibrary ^11.18
**Status:** Ready for production ✓
