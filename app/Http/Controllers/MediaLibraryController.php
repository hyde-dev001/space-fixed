<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductColorVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MediaLibraryController extends Controller
{
    /**
     * Upload main product image
     */
    public function uploadProductMainImage(Request $request, Product $product)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        // Clear existing main image
        $product->clearMediaCollection('main_image');

        // Add new main image
        $product->addMediaFromRequest('image')
            ->toMediaCollection('main_image');

        return response()->json([
            'message' => 'Main image uploaded successfully',
            'image_url' => $product->main_image_url,
            'thumb_url' => $product->main_image_thumb,
        ]);
    }

    /**
     * Upload additional product images
     */
    public function uploadProductAdditionalImages(Request $request, Product $product)
    {
        $request->validate([
            'images' => 'required|array',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        $urls = [];

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $media = $product->addMedia($image)
                    ->toMediaCollection('additional_images');

                $urls[] = [
                    'id' => $media->id,
                    'url' => $media->getUrl(),
                    'thumb' => $media->getUrl('thumb'),
                ];
            }
        }

        return response()->json([
            'message' => 'Images uploaded successfully',
            'images' => $urls,
        ]);
    }

    /**
     * Upload color variant images
     */
    public function uploadVariantImages(Request $request, ProductColorVariant $variant)
    {
        $request->validate([
            'images' => 'required|array',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'is_thumbnail' => 'sometimes|array',
        ]);

        $urls = [];

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $image) {
                $isThumbnail = isset($request->is_thumbnail[$index]) ? (bool)$request->is_thumbnail[$index] : false;
                
                $collection = $isThumbnail ? 'color_thumbnail' : 'color_images';
                
                $media = $variant->addMedia($image)
                    ->toMediaCollection($collection);

                $urls[] = [
                    'id' => $media->id,
                    'url' => $media->getUrl(),
                    'thumb' => $media->getUrl('thumb'),
                    'is_thumbnail' => $isThumbnail,
                ];
            }
        }

        return response()->json([
            'message' => 'Variant images uploaded successfully',
            'images' => $urls,
        ]);
    }

    /**
     * Get all product images
     */
    public function getProductImages(Product $product)
    {
        return response()->json([
            'main_image' => [
                'url' => $product->main_image_url,
                'thumb' => $product->main_image_thumb,
                'large' => $product->getFirstMedia('main_image')?->getUrl('large'),
                'medium' => $product->getFirstMedia('main_image')?->getUrl('medium'),
            ],
            'additional_images' => $product->getMedia('additional_images')->map(fn($media) => [
                'id' => $media->id,
                'url' => $media->getUrl(),
                'thumb' => $media->getUrl('thumb'),
                'medium' => $media->getUrl('medium'),
                'large' => $media->getUrl('large'),
                'name' => $media->name,
                'size' => $media->size,
            ]),
            'all_images' => $product->image_urls,
        ]);
    }

    /**
     * Get color variant images
     */
    public function getVariantImages(ProductColorVariant $variant)
    {
        return response()->json([
            'thumbnail' => optional($variant->getFirstMedia('color_thumbnail'))->toArray(),
            'images' => $variant->getMedia('color_images')->map(fn($media) => [
                'id' => $media->id,
                'url' => $media->getUrl(),
                'preview' => $media->getUrl('preview'),
                'thumb' => $media->getUrl('thumb'),
                'name' => $media->name,
                'size' => $media->size,
            ]),
        ]);
    }

    /**
     * Delete specific media file
     */
    public function deleteMedia(Request $request, $mediaId)
    {
        $media = \Spatie\MediaLibrary\MediaCollections\Models\Media::find($mediaId);

        if (!$media) {
            return response()->json(['error' => 'Media not found'], 404);
        }

        $media->delete();

        return response()->json([
            'message' => 'Media deleted successfully',
        ]);
    }

    /**
     * Reorder images in collection
     */
    public function reorderImages(Request $request, Product $product)
    {
        $request->validate([
            'order' => 'required|array',
        ]);

        foreach ($request->order as $index => $mediaId) {
            $media = $product->media()->find($mediaId);
            if ($media) {
                $media->update(['order_column' => $index]);
            }
        }

        return response()->json([
            'message' => 'Images reordered successfully',
        ]);
    }

    /**
     * Download media file
     */
    public function downloadMedia($mediaId)
    {
        $media = \Spatie\MediaLibrary\MediaCollections\Models\Media::find($mediaId);

        if (!$media) {
            return response()->json(['error' => 'Media not found'], 404);
        }

        return Storage::disk($media->disk)->download($media->getPath(), $media->file_name);
    }
}
