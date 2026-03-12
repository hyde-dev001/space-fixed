import React, { useState, useRef } from 'react';
import axios from 'axios';

interface ImageUploadProps {
  productId?: number;
  variantId?: number;
  type: 'main' | 'additional' | 'variant';
  onSuccess?: (images: any[]) => void;
  onError?: (error: string) => void;
}

const MediaLibraryUpload: React.FC<ImageUploadProps> = ({
  productId,
  variantId,
  type,
  onSuccess,
  onError,
}) => {
  const [loading, setLoading] = useState(false);
  const [uploadedImages, setUploadedImages] = useState<any[]>([]);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const handleFileSelect = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = e.target.files;
    if (!files) return;

    setLoading(true);

    try {
      const formData = new FormData();

      if (type === 'main') {
        formData.append('image', files[0]);
        const response = await axios.post(
          `/api/media/products/${productId}/main-image`,
          formData,
          {
            headers: { 'Content-Type': 'multipart/form-data' },
          }
        );
        setUploadedImages([response.data]);
        onSuccess?.([response.data]);
      } else if (type === 'additional') {
        for (let i = 0; i < files.length; i++) {
          formData.append('images[]', files[i]);
        }
        const response = await axios.post(
          `/api/media/products/${productId}/additional-images`,
          formData,
          {
            headers: { 'Content-Type': 'multipart/form-data' },
          }
        );
        setUploadedImages((prev) => [...prev, ...response.data.images]);
        onSuccess?.(response.data.images);
      } else if (type === 'variant') {
        for (let i = 0; i < files.length; i++) {
          formData.append('images[]', files[i]);
        }
        const response = await axios.post(
          `/api/media/variants/${variantId}/images`,
          formData,
          {
            headers: { 'Content-Type': 'multipart/form-data' },
          }
        );
        setUploadedImages((prev) => [...prev, ...response.data.images]);
        onSuccess?.(response.data.images);
      }
    } catch (error: any) {
      const message = error.response?.data?.message || 'Upload failed';
      onError?.(message);
      console.error('Upload error:', error);
    } finally {
      setLoading(false);
      if (fileInputRef.current) {
        fileInputRef.current.value = '';
      }
    }
  };

  const handleDelete = async (mediaId: number) => {
    try {
      await axios.delete(`/api/media/files/${mediaId}`);
      setUploadedImages((prev) =>
        prev.filter((img) => img.id !== mediaId)
      );
    } catch (error) {
      console.error('Delete error:', error);
    }
  };

  const triggerFileInput = () => {
    fileInputRef.current?.click();
  };

  return (
    <div className="media-upload-container">
      <div className="upload-section">
        <button
          onClick={triggerFileInput}
          disabled={loading}
          className="btn btn-primary"
        >
          {loading ? 'Uploading...' : `Select ${type === 'main' ? 'Image' : 'Images'}`}
        </button>

        <input
          ref={fileInputRef}
          type="file"
          multiple={type !== 'main'}
          accept="image/*"
          onChange={handleFileSelect}
          style={{ display: 'none' }}
        />
      </div>

      {uploadedImages.length > 0 && (
        <div className="images-gallery mt-4">
          <h4>Uploaded Images ({uploadedImages.length})</h4>
          <div className="gallery-grid">
            {uploadedImages.map((image) => (
              <div key={image.id} className="gallery-item">
                <img
                  src={image.thumb || image.url}
                  alt="Uploaded"
                  className="gallery-thumbnail"
                />
                <div className="gallery-actions">
                  <a
                    href={image.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="btn btn-sm btn-info"
                  >
                    View
                  </a>
                  <button
                    onClick={() => handleDelete(image.id)}
                    className="btn btn-sm btn-danger"
                  >
                    Delete
                  </button>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      <style jsx>{`
        .media-upload-container {
          padding: 20px;
          border: 1px solid #ddd;
          border-radius: 8px;
          background: #f9f9f9;
        }

        .upload-section {
          margin-bottom: 20px;
        }

        .gallery-grid {
          display: grid;
          grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
          gap: 15px;
          margin-top: 15px;
        }

        .gallery-item {
          position: relative;
          border: 1px solid #ddd;
          border-radius: 6px;
          overflow: hidden;
          background: white;
        }

        .gallery-thumbnail {
          width: 100%;
          height: 150px;
          object-fit: cover;
          display: block;
        }

        .gallery-actions {
          display: flex;
          gap: 5px;
          padding: 8px;
          background: #f0f0f0;
        }

        .gallery-actions button,
        .gallery-actions a {
          flex: 1;
          padding: 5px;
          font-size: 12px;
          text-align: center;
          cursor: pointer;
        }
      `}</style>
    </div>
  );
};

export default MediaLibraryUpload;

// Usage Example:
/*
import MediaLibraryUpload from '@/Components/MediaLibraryUpload';

export default function ProductImageManager() {
  return (
    <div>
      <h3>Main Product Image</h3>
      <MediaLibraryUpload
        productId={1}
        type="main"
        onSuccess={(images) => console.log('Main image uploaded:', images)}
        onError={(error) => console.error('Error:', error)}
      />

      <h3>Additional Images</h3>
      <MediaLibraryUpload
        productId={1}
        type="additional"
        onSuccess={(images) => console.log('Additional images uploaded:', images)}
      />

      <h3>Color Variant Images</h3>
      <MediaLibraryUpload
        variantId={1}
        type="variant"
        onSuccess={(images) => console.log('Variant images uploaded:', images)}
      />
    </div>
  );
}
*/
