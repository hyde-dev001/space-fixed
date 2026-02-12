import React, { useState } from 'react';
import { Head, usePage, Link } from '@inertiajs/react';
import Navigation from './Navigation';

// Mock repair services data for a shop
const mockRepairServices = [
  { id: 1, title: 'Sole Replacement', price: '₱450', description: 'Complete sole replacement with premium materials' },
  { id: 2, title: 'Heel Repair', price: '₱300', description: 'Professional heel repair and restoration' },
  { id: 3, title: 'Stitching & Patch', price: '₱250', description: 'Expert stitching and patching services' },
  { id: 4, title: 'Deep Clean & Condition', price: '₱200', description: 'Deep cleaning and leather conditioning' },
  { id: 5, title: 'Zipper Replacement', price: '₱350', description: 'High-quality zipper replacement' },
];

// Mock reviews data
const mockReviews = [
  {
    id: 1,
    userName: 'Sarah Chen',
    rating: 5,
    date: '2026-01-28',
    comment: 'Excellent shoe repair service! The sole replacement looks brand new. Highly professional team.',
    verified: true
  },
  {
    id: 2,
    userName: 'Mike Johnson',
    rating: 4,
    date: '2026-01-25',
    comment: 'Good quality repairs and reasonable prices. They were very helpful and finished on time.',
    verified: true
  },
  {
    id: 3,
    userName: 'Emma Rodriguez',
    rating: 5,
    date: '2026-01-22',
    comment: 'Best repair shop in the area! My shoes look better than before. Will definitely come back.',
    verified: true
  },
  {
    id: 4,
    userName: 'David Lee',
    rating: 5,
    date: '2026-01-20',
    comment: 'Outstanding craftsmanship. The attention to detail is remarkable. Worth every peso!',
    verified: true
  },
  {
    id: 5,
    userName: 'Jessica Martinez',
    rating: 4,
    date: '2026-01-18',
    comment: 'Very professional service. Reasonable turnaround time and quality work. Recommended!',
    verified: true
  }
];

const RepairShow: React.FC = () => {
  const { repair } = usePage().props as any;

  // Mock shop data (will be replaced with real data from backend)
  const shop = {
    id: 1,
    name: 'SoleHouse',
    location: 'Dasmariñas, Cavite',
    rating: 4.8,
    image: '/images/shop/shop1.jpg',
    description: 'Premium shoe repair and restoration services with over 15 years of experience. We specialize in professional repairs for all types of footwear.',
    hours: '9:00 AM - 6:00 PM',
    phone: '(046) 123-4567',
    email: 'info@solehouse.com'
  };

  const [newComment, setNewComment] = useState('');
  const [userRating, setUserRating] = useState(0);
  const [hoverRating, setHoverRating] = useState(0);
  const [selectedServices, setSelectedServices] = useState<number[]>([]);
  const [enlargedImage, setEnlargedImage] = useState<string | null>(null);
  const [imageUploadGroups, setImageUploadGroups] = useState<Array<{id: string; file: File | null; preview: string}>>([{id: '0', file: null, preview: ''}]);

  const handleServiceToggle = (serviceId: number) => {
    setSelectedServices(prev => 
      prev.includes(serviceId)
        ? prev.filter(id => id !== serviceId)
        : [...prev, serviceId]
    );
  };

  const handleImageUpload = (id: string, e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      const reader = new FileReader();
      reader.onloadend = () => {
        setImageUploadGroups(prev => 
          prev.map(group => 
            group.id === id ? {id, file, preview: reader.result as string} : group
          )
        );
      };
      reader.readAsDataURL(file);
    }
  };

  const addImageUploadBox = () => {
    if (imageUploadGroups.length < 5) {
      const newId = Math.random().toString(36).substr(2, 9);
      setImageUploadGroups(prev => [...prev, {id: newId, file: null, preview: ''}]);
    }
  };

  const removeImageBox = (id: string) => {
    setImageUploadGroups(prev => prev.filter(group => group.id !== id));
  };

  const handleSubmitReview = () => {
    if (!newComment.trim() || userRating === 0) {
      alert('Please provide both a rating and a comment');
      return;
    }
    // Frontend only - in production this would send to backend
    alert('Thank you for your review! (Backend integration pending)');
    setNewComment('');
    setUserRating(0);
  };

  const renderStars = (rating: number, interactive: boolean = false, onRate?: (rating: number) => void) => {
    return (
      <div className="flex gap-1">
        {[1, 2, 3, 4, 5].map((star) => (
          <button
            key={star}
            type="button"
            onClick={() => interactive && onRate && onRate(star)}
            onMouseEnter={() => interactive && setHoverRating(star)}
            onMouseLeave={() => interactive && setHoverRating(0)}
            className={`${interactive ? 'cursor-pointer' : 'cursor-default'} transition-colors`}
            disabled={!interactive}
          >
            <svg
              className={`w-5 h-5 ${
                star <= (interactive ? (hoverRating || userRating) : rating)
                  ? 'text-yellow-400 fill-yellow-400'
                  : 'text-gray-300 fill-gray-300'
              }`}
              xmlns="http://www.w3.org/2000/svg"
              viewBox="0 0 24 24"
            >
              <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
            </svg>
          </button>
        ))}
      </div>
    );
  };

  return (
    <>
      <Head title={shop.name} />
      <div className="min-h-screen bg-white font-outfit antialiased">
        <Navigation />

        <div className="max-w-6xl mx-auto px-6 lg:px-12 py-12 lg:py-20">
          {/* Shop Cover Image - Full Width Hero */}
          <div className="relative h-64 md:h-80 bg-gray-200 overflow-hidden rounded-2xl mb-8 shadow-lg">
            <img
              src={shop.image}
              alt={shop.name}
              className="w-full h-full object-cover"
              onError={(e) => {
                const target = e.target as HTMLImageElement;
                target.src = '/images/shop/shop.jpg';
              }}
            />
            <div className="absolute inset-0 bg-gradient-to-t from-black/50 to-transparent"></div>
            
            {/* Shop Name Overlay */}
            <div className="absolute bottom-8 left-8">
              <Link href={`/shop-profile/${shop.id}`} className="text-5xl font-bold text-white hover:text-gray-200 transition-colors inline-block drop-shadow-lg">
                {shop.name}
              </Link>
              <div className="flex items-center gap-2 text-white mt-3">
                <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" /><circle cx="12" cy="10" r="3" />
                </svg>
                <span className="text-lg font-medium">{shop.location}</span>
              </div>
            </div>

            {/* Rating Badge (Top Right) - removed */}
          </div>

          {/* Shop Description */}
          <div className="mb-12">
            <p className="text-lg text-gray-700 leading-relaxed max-w-3xl">{shop.description}</p>
          </div>

          {/* Info Grid */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-10">
            {/* Shop Information */}
            <div className="bg-gradient-to-br from-gray-50 to-white rounded-2xl p-8 border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
              <div className="flex items-center justify-between gap-3 mb-6">
                <div className="flex items-center gap-3">
                  <div className="w-12 h-12 bg-black rounded-xl flex items-center justify-center shadow-md">
                    <svg xmlns="http://www.w3.org/2000/svg" className="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                      <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" /><polyline points="9 22 9 12 15 12 15 22" />
                    </svg>
                  </div>
                  <h3 className="text-2xl font-bold text-black">Shop Information</h3>
                </div>
                <Link
                  href={`/message/${shop.id}`}
                  className="px-6 py-2 border border-gray-300 rounded-lg text-sm font-semibold text-black hover:bg-black hover:text-white hover:border-black transition-all"
                >
                  Message
                </Link>
              </div>
              <div className="space-y-5 text-sm text-gray-700">
                <div className="flex items-start gap-3">
                  <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5 text-black mt-0.5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" /><circle cx="12" cy="10" r="3" />
                  </svg>
                  <div>
                    <div className="font-bold text-black mb-1">Location</div>
                    <div className="text-gray-600">{shop.location}</div>
                  </div>
                </div>
                <div className="flex items-start gap-3">
                  <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5 text-black mt-0.5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" />
                  </svg>
                  <div>
                    <div className="font-bold text-black mb-1">Hours</div>
                    <div className="text-gray-600">{shop.hours}</div>
                  </div>
                </div>
                <div className="flex items-start gap-3">
                  <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5 text-black mt-0.5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" />
                  </svg>
                  <div>
                    <div className="font-bold text-black mb-1">Phone</div>
                    <a href={`tel:${shop.phone}`} className="text-black hover:text-gray-600 transition-colors underline">{shop.phone}</a>
                  </div>
                </div>
                <div className="flex items-start gap-3">
                  <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5 text-black mt-0.5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <rect x="2" y="4" width="20" height="16" rx="2" /><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
                  </svg>
                  <div>
                    <div className="font-bold text-black mb-1">Email</div>
                    <a href={`mailto:${shop.email}`} className="text-black hover:text-gray-600 transition-colors underline">{shop.email}</a>
                  </div>
                </div>
              </div>
            </div>

            {/* Shop Rating */}
            <div className="bg-gradient-to-br from-yellow-50 to-white rounded-2xl p-8 border border-yellow-100 shadow-sm hover:shadow-md transition-shadow">
              <div className="flex items-center gap-3 mb-6">
                <div className="w-12 h-12 bg-yellow-400 rounded-xl flex items-center justify-center shadow-md">
                  <svg xmlns="http://www.w3.org/2000/svg" className="w-6 h-6 text-white fill-white" viewBox="0 0 24 24">
                    <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                  </svg>
                </div>
                <h3 className="text-2xl font-bold text-black">Customer Rating</h3>
              </div>
              <div className="flex items-center gap-8">
                <div>
                  <div className="flex items-baseline gap-3 mb-2">
                    <span className="text-6xl font-bold text-black">{shop.rating}</span>
                    <span className="text-4xl text-yellow-400">⭐</span>
                  </div>
                  <span className="text-sm text-gray-600">Based on {mockReviews.length} reviews</span>
                </div>
                <div className="flex flex-col gap-1">
                  {renderStars(shop.rating)}
                  <span className="text-xs text-gray-500 mt-1">Excellent Service</span>
                </div>
              </div>
            </div>
          </div>

          {/* Repair Services Section */}
          <div className="mb-12">
            <h2 className="text-3xl font-bold text-black mb-8">Our Repair Services</h2>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
              {mockRepairServices.map((service) => (
                <div
                  key={service.id}
                  className={`bg-white rounded-2xl p-6 border-2 cursor-pointer transition-all hover:shadow-lg ${
                    selectedServices.includes(service.id)
                      ? 'border-black shadow-md'
                      : 'border-gray-200 hover:border-gray-300'
                  }`}
                  onClick={() => handleServiceToggle(service.id)}
                >
                  <div className="flex items-start justify-between mb-4">
                    <div className="flex-1">
                      <h3 className="text-lg font-bold text-black mb-2">{service.title}</h3>
                      <p className="text-sm text-gray-600 mb-4">{service.description}</p>
                      <div className="text-2xl font-bold text-black">{service.price}</div>
                    </div>
                    <div className={`w-6 h-6 rounded-full border-2 flex items-center justify-center flex-shrink-0 transition-all ${
                      selectedServices.includes(service.id)
                        ? 'border-black bg-black'
                        : 'border-gray-300'
                    }`}>
                      {selectedServices.includes(service.id) && (
                        <svg className="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                          <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                        </svg>
                      )}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>

          {/* Request Service Button */}
          <Link
            href={`/repair-process${selectedServices.length > 0 ? `?services=${selectedServices.join(',')}` : ''}`}
            className="block w-full bg-black text-white py-5 rounded-2xl hover:bg-gray-900 active:scale-[0.98] transition-all font-bold text-xl shadow-xl hover:shadow-2xl text-center mb-16"
          >
            Request Repair Service {selectedServices.length > 0 && `(${selectedServices.length} service${selectedServices.length !== 1 ? 's' : ''} selected)`}
          </Link>

          {/* Reviews and Comments Section */}
          <div className="border-t border-gray-200 pt-16">
            <div className="mb-12">
              <h2 className="text-4xl font-bold text-black mb-6">Customer Reviews</h2>
              <div className="flex items-center gap-6">
                <div className="flex items-center gap-4">
                  <span className="text-6xl font-bold text-black">{shop.rating}</span>
                  <div>
                    <div className="flex gap-1 mb-2">
                      {renderStars(shop.rating)}
                    </div>
                    <span className="text-sm text-gray-600">Based on {mockReviews.length} reviews</span>
                  </div>
                </div>
              </div>
            </div>

            {/* Write a Review Section */}
            <div className="bg-gradient-to-br from-gray-50 to-white rounded-2xl p-10 mb-12 border border-gray-100 shadow-sm hover:shadow-md transition-all">
              <div className="flex items-center gap-3 mb-8">
                <div className="w-12 h-12 bg-black rounded-xl flex items-center justify-center shadow-md">
                  <svg xmlns="http://www.w3.org/2000/svg" className="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                    <circle cx="12" cy="7" r="4"></circle>
                  </svg>
                </div>
                <h3 className="text-3xl font-bold text-black">Share Your Experience</h3>
              </div>

              <div className="mb-8">
                <label className="block text-base font-bold text-black mb-4">Your Rating</label>
                <div className="flex gap-2">
                  {renderStars(userRating, true, setUserRating)}
                  {userRating > 0 && (
                    <span className="ml-4 text-sm text-gray-600 flex items-center">
                      {userRating === 5 ? 'Excellent!' : userRating === 4 ? 'Great!' : userRating === 3 ? 'Good' : userRating === 2 ? 'Fair' : 'Poor'}
                    </span>
                  )}
                </div>
              </div>

              <div className="mb-8">
                <label className="block text-base font-bold text-black mb-4">Your Review</label>
                <textarea
                  value={newComment}
                  onChange={(e) => setNewComment(e.target.value)}
                  placeholder="Share details about your repair experience. What did you love? What could be improved?"
                  className="w-full px-5 py-4 border-2 border-gray-200 rounded-2xl focus:outline-none focus:ring-2 focus:ring-black focus:border-transparent text-black resize-none bg-white transition-all"
                  rows={6}
                />
              </div>

              <div className="mb-8">
                <label className="block text-base font-bold text-black mb-4">Photos (Optional)</label>
                <p className="text-sm text-gray-600 mb-4">Share photos of your repaired shoes to help others</p>
                <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
                  {imageUploadGroups.map((group) => (
                    <div key={group.id} className="relative group/photo">
                      {group.preview ? (
                        <div className="relative">
                          <img
                            src={group.preview}
                            alt="Review photo"
                            className="w-full h-32 object-cover rounded-xl border-2 border-gray-200 shadow-sm"
                          />
                          <div className="absolute inset-0 bg-black/60 opacity-0 group-hover/photo:opacity-100 transition-opacity rounded-xl flex items-center justify-center gap-2">
                            {imageUploadGroups.length < 5 && (
                              <button
                                onClick={addImageUploadBox}
                                className="w-10 h-10 bg-black hover:bg-gray-800 rounded-full flex items-center justify-center text-white transition-all shadow-lg hover:scale-110"
                                type="button"
                                title="Add more photos"
                              >
                                <span className="text-xl font-bold">+</span>
                              </button>
                            )}
                            <button
                              onClick={() => removeImageBox(group.id)}
                              className="w-10 h-10 bg-red-600 hover:bg-red-700 rounded-full flex items-center justify-center text-white transition-all shadow-lg hover:scale-110"
                              type="button"
                              title="Remove photo"
                            >
                              <span className="text-xl font-bold">×</span>
                            </button>
                          </div>
                        </div>
                      ) : (
                        <label className="w-full h-32 border-2 border-dashed border-gray-300 rounded-xl flex flex-col items-center justify-center cursor-pointer hover:border-black hover:bg-gray-50 transition-all bg-white">
                          <input
                            type="file"
                            accept="image/*"
                            onChange={(e) => handleImageUpload(group.id, e)}
                            className="hidden"
                            aria-label="Upload review photo"
                          />
                          <svg xmlns="http://www.w3.org/2000/svg" className="w-8 h-8 text-gray-400 mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                            <polyline points="17 8 12 3 7 8" />
                            <line x1="12" y1="3" x2="12" y2="15" />
                          </svg>
                          <span className="text-xs text-gray-500 font-medium">Upload</span>
                        </label>
                      )}
                    </div>
                  ))}
                </div>
              </div>

              <button
                onClick={handleSubmitReview}
                className="bg-black text-white px-10 py-4 rounded-2xl hover:bg-gray-900 active:scale-[0.98] transition-all font-bold text-lg shadow-lg hover:shadow-xl"
                type="button"
              >
                Submit Review
              </button>
            </div>

            {/* Reviews List */}
            <div className="space-y-6">
              {mockReviews.map((review) => (
                <div key={review.id} className="bg-gradient-to-br from-white to-gray-50 rounded-2xl p-8 border border-gray-100 hover:shadow-lg transition-all">
                  <div className="flex items-start gap-6">
                    <div className="w-20 h-20 rounded-2xl bg-gradient-to-br from-gray-100 to-gray-200 border-2 border-gray-200 overflow-hidden flex-shrink-0 cursor-pointer hover:scale-105 transition-transform shadow-md" onClick={() => setEnlargedImage('/images/shop/shop1.jpg')}>
                      <img
                        src="/images/shop/shop1.jpg"
                        alt={`${review.userName}'s repair photo`}
                        className="w-full h-full object-cover"
                      />
                    </div>
                    <div className="flex-1">
                      <div className="flex items-center gap-3 mb-3 flex-wrap">
                        <h4 className="font-bold text-black text-xl">{review.userName}</h4>
                      </div>
                      <div className="flex items-center gap-4 mb-4">
                        <div className="flex gap-1">
                          {renderStars(review.rating)}
                        </div>
                        <span className="text-sm text-gray-500 font-medium">
                          {new Date(review.date).toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric'
                          })}
                        </span>
                      </div>
                      <p className="text-gray-700 leading-relaxed text-base">{review.comment}</p>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>

        {/* Image Lightbox Modal */}
        {enlargedImage && (
          <div
            className="fixed inset-0 z-50 bg-black/90 backdrop-blur-sm flex items-center justify-center p-6 animate-fadeIn"
            onClick={() => setEnlargedImage(null)}
          >
            <div
              className="relative w-full max-w-6xl"
              onClick={(e) => e.stopPropagation()}
            >
              <img
                src={enlargedImage}
                alt="Enlarged review"
                className="w-full max-h-[85vh] object-contain rounded-2xl shadow-2xl"
              />
              <button
                onClick={() => setEnlargedImage(null)}
                className="absolute -top-4 -right-4 w-12 h-12 bg-white rounded-full flex items-center justify-center hover:bg-gray-100 active:scale-95 transition-all shadow-xl"
                type="button"
                title="Close"
              >
                <svg xmlns="http://www.w3.org/2000/svg" className="w-6 h-6 text-black" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <line x1="18" y1="6" x2="6" y2="18" />
                  <line x1="6" y1="6" x2="18" y2="18" />
                </svg>
              </button>
            </div>
          </div>
        )}
    </>
  );
};

export default RepairShow;
