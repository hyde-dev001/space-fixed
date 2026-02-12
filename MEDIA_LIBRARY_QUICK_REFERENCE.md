# 🖼️ Media Library - Quick Reference

## Installation Complete ✓

```bash
# Already done:
composer require spatie/laravel-medialibrary
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider"
php artisan migrate
```

---

## 📝 Quick Commands

```bash
# Create symbolic link (required)
php artisan storage:link

# Migrate existing images
php artisan media:migrate-images --all

# Cleanup old files
php artisan media-library:clean

# Regenerate conversions
php artisan media-library:regenerate
```

---

## 💾 Backend Usage (Laravel)

### Upload Images
```php
// Single main image
$product->addMediaFromRequest('image')->toMediaCollection('main_image');

// Multiple additional images
$product->addMediaFromRequest('images')->toMediaCollection('additional_images');

// From file path
$product->addMedia('/path/to/image.jpg')->toMediaCollection('main_image');

// From URL
$product->addMediaFromUrl('https://example.com/img.jpg')->toMediaCollection('main_image');
```

### Get Images
```php
// Direct attribute access (for JSON responses)
$product->main_image_url        // Main image URL
$product->main_image_thumb      // Thumbnail
$product->image_urls            // All images array

// Get specific conversion
$media = $product->getFirstMedia('main_image');
$media->getUrl('thumb');        // 150x150
$media->getUrl('medium');       // 500x500
$media->getUrl('large');        // 1000x1000

// Get all in collection
$product->getMedia('additional_images')
```

### Delete Images
```php
// Delete all in collection
$product->clearMediaCollection('main_image');

// Delete specific
$product->media()->find($mediaId)->delete();

// Delete by media object
$media->delete();
```

---

## 🌐 API Endpoints

### Uploads
```
POST /api/media/products/{id}/main-image
POST /api/media/products/{id}/additional-images
POST /api/media/variants/{id}/images
```

### Retrieve
```
GET /api/media/products/{id}/images
GET /api/media/variants/{id}/images
```

### Manage
```
DELETE /api/media/files/{mediaId}
POST /api/media/products/{id}/reorder
GET /api/media/files/{mediaId}/download
```

---

## ⚛️ Frontend Usage (React)

```tsx
import MediaLibraryUpload from '@/Components/MediaLibraryUpload';

<MediaLibraryUpload
  productId={1}
  type="main"           // or "additional", "variant"
  onSuccess={(images) => console.log(images)}
  onError={(error) => console.error(error)}
/>
```

---

## 📐 Image Conversions

### Products
| Name | Size | Usage |
|------|------|-------|
| thumb | 150×150 | Lists, thumbnails |
| medium | 500×500 | Gallery, previews |
| large | 1000×1000 | Detail pages |

### Variants
| Name | Size | Usage |
|------|------|-------|
| thumb | 200×200 | Color picker |
| preview | 600×600 | Variant view |

Access: `$media->getUrl('conversion_name')`

---

## 🛠️ Configuration

**File:** `config/media-library.php`

Key settings:
```php
'disk_name' => 'public',              // Storage disk
'max_file_size' => 10485760,          // 10MB limit (bytes)
'queue_conversions_by_default' => true,  // Async processing
```

---

## 🔍 Model Collections

### Product
- `main_image` - Single product image
- `additional_images` - Multiple product images

### ProductColorVariant
- `color_images` - Multiple variant images
- `color_thumbnail` - Single thumbnail

---

## 📊 JSON Response Format

```json
{
  "main_image_url": "https://example.com/storage/media/abc.jpg",
  "main_image_thumb": "https://example.com/storage/media/conversions/abc-thumb.jpg",
  "image_urls": [
    {
      "url": "https://...",
      "type": "main"
    },
    {
      "url": "https://...",
      "type": "additional"
    }
  ]
}
```

---

## 🆘 Troubleshooting

| Problem | Solution |
|---------|----------|
| 404 on images | Run `php artisan storage:link` |
| Conversions missing | Install `composer require intervention/image` |
| Files not uploading | Check `FILESYSTEM_DISK=public` in `.env` |
| Slow uploads | Set `queue_conversions_by_default = true` in config |
| Storage full | Run `php artisan media-library:clean` |

---

## 📂 Files Modified/Created

```
✅ app/Models/Product.php
✅ app/Models/ProductColorVariant.php
✅ app/Http/Controllers/MediaLibraryController.php
✅ app/Console/Commands/MigrateImagesToMediaLibrary.php
✅ routes/api.php
✅ resources/js/Components/MediaLibraryUpload.tsx
✅ config/media-library.php (published)
✅ database/migrations/2026_02_12_091646_create_media_table.php
```

---

## 📖 Documentation

- 📘 [Full Implementation Guide](MEDIA_LIBRARY_GUIDE.md)
- 📋 [Setup Checklist](MEDIA_LIBRARY_SETUP.md)
- 🔗 [Official Docs](https://spatie.be/docs/laravel-medialibrary/v11)

---

## ✨ Key Features

✅ Automatic image optimization
✅ Multiple responsive conversions
✅ Database-tracked files
✅ Queue support for async processing
✅ Polymorphic model relations
✅ Soft delete support
✅ File management (upload/delete/reorder)
✅ CDN-ready configuration

---

**Ready to use!** 🚀

Next step: Run `php artisan storage:link` and test uploads
