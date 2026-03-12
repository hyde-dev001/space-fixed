import React, { useState } from 'react';

export type ColorVariantImage = {
  id: string;
  file: File | null;
  preview: string;
  alt_text?: string;
  is_thumbnail: boolean;
  sort_order: number;
  uploaded_path?: string;
};

type ColorVariantImageUploaderProps = {
  colorName: string;
  images: ColorVariantImage[];
  onImagesChange: (images: ColorVariantImage[]) => void;
  maxImages?: number;
};

export const ColorVariantImageUploader: React.FC<ColorVariantImageUploaderProps> = ({
  colorName,
  images,
  onImagesChange,
  maxImages = 10,
}) => {
  const [draggingImageId, setDraggingImageId] = useState<string | null>(null);

  const handleAddImage = (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = Array.from(e.target.files || []);
    if (images.length + files.length > maxImages) {
      alert(`Maximum ${maxImages} images allowed per color`);
      return;
    }

    const newImages = files.map((file, index) => {
      const id = `${Date.now()}-${index}`;
      const preview = URL.createObjectURL(file);
      return {
        id,
        file,
        preview,
        is_thumbnail: images.length === 0 && index === 0, // First image is thumbnail
        sort_order: images.length + index,
      };
    });

    onImagesChange([...images, ...newImages]);
    e.target.value = ''; // Reset input
  };

  const handleRemoveImage = (id: string) => {
    const updatedImages = images.filter(img => img.id !== id);
    
    // If we removed the thumbnail, make the first image the new thumbnail
    if (updatedImages.length > 0 && !updatedImages.some(img => img.is_thumbnail)) {
      updatedImages[0].is_thumbnail = true;
    }
    
    // Reorder
    const reorderedImages = updatedImages.map((img, index) => ({
      ...img,
      sort_order: index,
    }));
    
    onImagesChange(reorderedImages);
  };

  const handleSetThumbnail = (id: string) => {
    const updatedImages = images.map(img => ({
      ...img,
      is_thumbnail: img.id === id,
    }));
    onImagesChange(updatedImages);
  };

  const handleDragStart = (e: React.DragEvent, id: string) => {
    setDraggingImageId(id);
    e.dataTransfer.effectAllowed = 'move';
  };

  const handleDragOver = (e: React.DragEvent) => {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
  };

  const handleDrop = (e: React.DragEvent, targetId: string) => {
    e.preventDefault();
    if (!draggingImageId || draggingImageId === targetId) return;

    const dragIndex = images.findIndex(img => img.id === draggingImageId);
    const dropIndex = images.findIndex(img => img.id === targetId);

    const reordered = [...images];
    const [removed] = reordered.splice(dragIndex, 1);
    reordered.splice(dropIndex, 0, removed);

    // Update sort_order
    const updatedImages = reordered.map((img, index) => ({
      ...img,
      sort_order: index,
    }));

    onImagesChange(updatedImages);
    setDraggingImageId(null);
  };

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <div className="text-sm">
          <span className="text-gray-700 dark:text-gray-300 font-medium">
            {images.length} / {maxImages} images
          </span>
          <span className="text-gray-500 dark:text-gray-400 ml-2">
            • First image is thumbnail
          </span>
        </div>
        {images.length < maxImages && (
          <label className="cursor-pointer px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-lg transition-colors inline-flex items-center gap-2">
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
            </svg>
            Add Images
            <input
              type="file"
              accept="image/*"
              multiple
              onChange={handleAddImage}
              className="hidden"
            />
          </label>
        )}
      </div>

      {images.length === 0 ? (
        <div className="inline-block">
          <div className="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-4 text-center w-64">
            <label className="cursor-pointer">
              <div className="flex flex-col items-center gap-2">
                <svg className="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <div>
                  <p className="text-gray-900 dark:text-white font-medium text-sm">Upload images for {colorName}</p>
                  <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                    Click to select up to {maxImages} images • First image will be thumbnail
                  </p>
                </div>
              </div>
              <input
                type="file"
                accept="image/*"
                multiple
                onChange={handleAddImage}
                className="hidden"
              />
            </label>
          </div>
        </div>
      ) : (
        <div className="grid grid-cols-5 gap-3">
          {images.map((image, index) => (
            <div
              key={image.id}
              draggable
              onDragStart={(e) => handleDragStart(e, image.id)}
              onDragOver={handleDragOver}
              onDrop={(e) => handleDrop(e, image.id)}
              className={`relative group cursor-move transition-all ${
                draggingImageId === image.id ? 'opacity-50 scale-95' : ''
              }`}
            >
              <img
                src={image.preview}
                alt={`${colorName} ${index + 1}`}
                className="w-full h-28 object-cover rounded-lg border-2 border-gray-200 dark:border-gray-700 group-hover:border-blue-400 dark:group-hover:border-blue-500 transition-colors"
              />
              
              {/* Thumbnail badge */}
              {image.is_thumbnail && (
                <div className="absolute top-2 left-2 bg-blue-600 text-white text-xs px-2 py-0.5 rounded-full font-medium">
                  Thumbnail
                </div>
              )}
              
              {/* Position badge */}
              <div className="absolute top-2 right-2 bg-black/70 text-white text-xs px-2 py-0.5 rounded-full">
                #{index + 1}
              </div>

              {/* Hover actions */}
              <div className="absolute inset-0 bg-black/0 group-hover:bg-black/40 transition-all rounded-lg flex items-center justify-center gap-2 opacity-0 group-hover:opacity-100">
                {!image.is_thumbnail && (
                  <button
                    type="button"
                    onClick={() => handleSetThumbnail(image.id)}
                    className="bg-blue-600 hover:bg-blue-700 text-white rounded-full p-2 transition-colors"
                    title="Set as thumbnail"
                  >
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                    </svg>
                  </button>
                )}
                <button
                  type="button"
                  onClick={() => handleRemoveImage(image.id)}
                  className="bg-red-600 hover:bg-red-700 text-white rounded-full p-2 transition-colors"
                  title="Remove image"
                >
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      {images.length > 0 && (
        <p className="text-xs text-gray-500 dark:text-gray-400 italic">
          💡 Drag images to reorder • Click thumbnail badge to change
        </p>
      )}
    </div>
  );
};
