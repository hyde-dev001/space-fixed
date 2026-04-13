import React, { useState, useEffect, useRef } from 'react';
import { Head, usePage, Link } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import ReportShopModal from '../../../components/ReportShopModal';
import { navigateBackOr } from '../Shared/backNavigation';

interface ShopHours {
  day: string;
  open: string | null;
  close: string | null;
  is_closed: boolean;
}

interface Shop {
  id: number;
  name: string;
  location: string;
  rating: number;
  image: string;
  description: string;
  repair_payment_policy?: 'deposit_50' | 'full_upfront';
  hours: ShopHours[] | null;
  phone: string;
  email: string;
  address?: string;
}

interface RepairService {
  id: number;
  title: string;
  price: string;
  description: string;
  category: string;
  duration: string;
}

interface RepairPackage {
  id: number;
  name: string;
  description?: string | null;
  package_price: number;
  service_count: number;
  services_total_price: number;
  savings_amount: number;
  services: Array<{
    id: number;
    name: string;
    category: string;
    price: number;
    duration: string;
  }>;
}

interface Review {
  id: number;
  user_name: string;
  rating: number;
  comment: string;
  images: string[];
  created_at: string;
  verified: boolean;
}

interface ReviewStats {
  average_rating: number;
  total_reviews: number;
  rating_distribution: {
    [key: number]: {
      count: number;
      percentage: number;
    };
  };
}

interface Props {
  shop: Shop;
  repairServices: RepairService[];
  repairPackages: RepairPackage[];
  auth?: {
    user?: any;
  };
}

const RepairShow: React.FC<Props> = ({ shop, repairServices, repairPackages }) => {
  const { auth } = usePage().props as any;
  const isAuthenticated = !!auth?.user;
  
  const [showReportModal, setShowReportModal] = useState(false);
  const [newComment, setNewComment] = useState('');
  const [userRating, setUserRating] = useState(0);
  const [hoverRating, setHoverRating] = useState(0);
  const [selectedServices, setSelectedServices] = useState<number[]>([]);
  const [selectedPackageId, setSelectedPackageId] = useState<number | null>(null);
  const [showMoreActions, setShowMoreActions] = useState(false);
  const [enlargedImage, setEnlargedImage] = useState<string | null>(null);
  const [imageUploadGroups, setImageUploadGroups] = useState<Array<{id: string; file: File | null; preview: string}>>([{id: '0', file: null, preview: ''}]);
  const actionMenuRef = useRef<HTMLDivElement | null>(null);
  
  // Review system state
  const [reviews, setReviews] = useState<Review[]>([]);
  const [reviewStats, setReviewStats] = useState<ReviewStats>({ 
    average_rating: 0, 
    total_reviews: 0, 
    rating_distribution: {} 
  });
  const [canReview, setCanReview] = useState(false);
  const [reviewEligibility, setReviewEligibility] = useState<any>(null);
  const [isSubmittingReview, setIsSubmittingReview] = useState(false);

  const createImageUploadGroup = () => ({
    id: Math.random().toString(36).slice(2, 11),
    file: null,
    preview: '',
  });

  // Fetch reviews and check eligibility on mount
  useEffect(() => {
    fetchReviews();
    if (isAuthenticated) {
      checkReviewEligibility();
    }
  }, [shop.id, isAuthenticated]);

  useEffect(() => {
    const handleOutsideClick = (event: MouseEvent) => {
      if (actionMenuRef.current && !actionMenuRef.current.contains(event.target as Node)) {
        setShowMoreActions(false);
      }
    };

    document.addEventListener('mousedown', handleOutsideClick);
    return () => {
      document.removeEventListener('mousedown', handleOutsideClick);
    };
  }, []);

  const fetchReviews = async () => {
    try {
      const response = await fetch(`/api/shops/${shop.id}/reviews`);
      const data = await response.json();
      if (data.success) {
        setReviews(data.reviews || []);
        setReviewStats(data.statistics || { 
          average_rating: 0, 
          total_reviews: 0, 
          rating_distribution: {} 
        });
      }
    } catch (error) {
      console.error('Failed to fetch reviews:', error);
    }
  };

  const checkReviewEligibility = async () => {
    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      
  
      const response = await fetch(`/api/shops/${shop.id}/reviews/check-eligibility`, {
        credentials: 'include',
        headers: {
          'X-CSRF-TOKEN': csrfToken || '',
          'Accept': 'application/json',
        },
      });
      const data = await response.json();
 
      if (data.success) {
        setCanReview(data.can_review);
        setReviewEligibility(data);
      }
    } catch (error) {
      console.error('Failed to check review eligibility:', error);
    }
  };

  const handleServiceToggle = (serviceId: number) => {
    setSelectedPackageId(null);
    setSelectedServices(prev => {
      if (prev.includes(serviceId)) {
        return prev.filter(id => id !== serviceId);
      }

      return [...prev, serviceId];
    });
  };

  const handlePackageToggle = (packageId: number) => {
    setSelectedServices([]);
    setSelectedPackageId((prev) => (prev === packageId ? null : packageId));
  };

  const handleRequestRepair = () => {
    // Store selected service details in localStorage
    const selectedServiceDetails = repairServices.filter(service => 
      selectedServices.includes(service.id)
    );
    localStorage.setItem('selectedRepairServices', JSON.stringify(selectedServiceDetails));
    localStorage.setItem('shopDetails', JSON.stringify({
      id: shop.id,
      name: shop.name,
      location: shop.location,
      address: shop.address || shop.location,
    }));
  };

  const handleImageUpload = (id: string, e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      const reader = new FileReader();
      reader.onloadend = () => {
        setImageUploadGroups((prev) => {
          const updatedGroups = prev.map((group) =>
            group.id === id ? { id, file, preview: reader.result as string } : group
          );

          const hasEmptySlot = updatedGroups.some((group) => !group.preview);
          if (!hasEmptySlot && updatedGroups.length < 5) {
            return [...updatedGroups, createImageUploadGroup()];
          }

          return updatedGroups;
        });
      };
      reader.readAsDataURL(file);
    }
  };

  const addImageUploadBox = () => {
    if (imageUploadGroups.length < 5) {
      setImageUploadGroups((prev) => [...prev, createImageUploadGroup()]);
    }
  };

  const removeImageBox = (id: string) => {
    setImageUploadGroups((prev) => {
      const filtered = prev.filter((group) => group.id !== id);
      if (filtered.length === 0) {
        return [{ id: '0', file: null, preview: '' }];
      }

      const hasEmptySlot = filtered.some((group) => !group.preview);
      if (!hasEmptySlot && filtered.length < 5) {
        return [...filtered, createImageUploadGroup()];
      }

      return filtered;
    });
  };

  // Check if shop is currently open
  const checkIfOpen = () => {
    if (!shop.hours || shop.hours.length === 0) {
      return { isOpen: false, message: 'Hours not available' };
    }

    const now = new Date();
    const dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    const currentDay = dayNames[now.getDay()];
    
    const todaySchedule = shop.hours.find(h => h.day === currentDay);
    
    if (!todaySchedule || todaySchedule.is_closed) {
      return { isOpen: false, message: 'Closed today', currentDay };
    }

    // Parse current time
    const currentTime = now.getHours() * 60 + now.getMinutes();
    
    // Parse opening and closing times
    const parseTime = (timeStr: string) => {
      const [time, period] = timeStr.split(' ');
      let [hours, minutes] = time.split(':').map(Number);
      if (period === 'PM' && hours !== 12) hours += 12;
      if (period === 'AM' && hours === 12) hours = 0;
      return hours * 60 + minutes;
    };

    const openTime = parseTime(todaySchedule.open!);
    const closeTime = parseTime(todaySchedule.close!);

    if (currentTime >= openTime && currentTime < closeTime) {
      return { isOpen: true, message: 'Open now', currentDay, closingTime: todaySchedule.close };
    } else if (currentTime < openTime) {
      return { isOpen: false, message: `Opens at ${todaySchedule.open}`, currentDay };
    } else {
      return { isOpen: false, message: 'Closed', currentDay };
    }
  };

  const shopStatus = checkIfOpen();
  const normalizedRepairPaymentPolicy = shop.repair_payment_policy === 'full_upfront' ? 'full_upfront' : 'deposit_50';
  const paymentPolicyLabel = normalizedRepairPaymentPolicy === 'full_upfront'
    ? 'Full Payment Upfront'
    : '50% Deposit + 50% on Pickup';
  const paymentPolicyHint = normalizedRepairPaymentPolicy === 'full_upfront'
    ? 'Customer pays full amount before service starts.'
    : 'Customer pays half upfront and half when claiming repaired shoes.';
  const requestRepairHref = `/repair-process?shop=${shop.id}${selectedPackageId ? `&package=${selectedPackageId}` : ''}${selectedServices.length > 0 ? `&services=${selectedServices.join(',')}` : ''}`;
  const selectionSummary = selectedPackageId
    ? '(1 package selected)'
    : selectedServices.length > 0
      ? `(${selectedServices.length} service${selectedServices.length !== 1 ? 's' : ''} selected)`
      : '';

  const getServiceDescriptionLines = (description: string) => {
    const normalized = description
      .replace(/\s*-\s*/g, "|")
      .replace(/\n+/g, "|")
      .replace(/\s*•\s*/g, "|");

    const chunks = normalized
      .split("|")
      .map((part) => part.trim())
      .filter(Boolean);

    if (chunks.length > 0) {
      return chunks;
    }

    return [description.trim()].filter(Boolean);
  };

  const handleSubmitReview = async () => {
    if (!newComment.trim() || userRating === 0) {
      alert('Please provide both a rating and a comment');
      return;
    }

    if (!isAuthenticated) {
      alert('Please log in to write a review');
      return;
    }

    if (!canReview) {
      alert(reviewEligibility?.message || 'You are not eligible to review this shop');
      return;
    }

    setIsSubmittingReview(true);

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

      const formData = new FormData();
      formData.append('rating', userRating.toString());
      formData.append('comment', newComment);

      // Add images if any
      imageUploadGroups.forEach((group) => {
        if (group.file) {
          formData.append('images[]', group.file);
        }
      });

      const response = await fetch(`/api/shops/${shop.id}/reviews`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'X-CSRF-TOKEN': csrfToken || '',
          'Accept': 'application/json',
        },
        body: formData,
      });

      const data = await response.json();

      if (data.success) {
        setNewComment('');
        setUserRating(0);
        setImageUploadGroups([{id: '0', file: null, preview: ''}]);
        
        // Refresh reviews and eligibility
        await fetchReviews();
        await checkReviewEligibility();
      } else {
        alert(data.message || 'Failed to submit review. Please try again.');
      }
    } catch (error) {
      console.error('Failed to submit review:', error);
      alert('Failed to submit review. Please try again.');
    } finally {
      setIsSubmittingReview(false);
    }
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
            aria-label={interactive ? `Rate ${star} star${star !== 1 ? 's' : ''}` : `${rating} star rating`}
            title={interactive ? `Rate ${star} star${star !== 1 ? 's' : ''}` : `${rating} star rating`}
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

        <div className="max-w-6xl mx-auto px-4 xl:px-12 pt-18 xl:pt-24 pb-28 xl:py-20">
          {/* Shop Cover Image - Full Width Hero */}
          <div className="relative h-52 xl:h-80 bg-gray-200 overflow-hidden rounded-2xl mb-6 xl:mb-8 shadow-lg">
            <button
              type="button"
              onClick={() => navigateBackOr('/repair')}
              className="xl:hidden absolute top-3 left-3 z-30 inline-flex items-center justify-center px-2 py-1 text-[30px] text-white font-normal leading-none drop-shadow-[0_2px_4px_rgba(0,0,0,0.45)] hover:text-gray-200 transition-colors"
              aria-label="Go back"
            >
              {'<'}
            </button>

            <img
              src={shop.image}
              alt={shop.name}
              className="w-full h-full object-cover"
              onError={(e) => {
                const target = e.target as HTMLImageElement;
                target.src = '/images/shop/shop.jpg';
              }}
            />
            <div className="absolute inset-0 bg-linear-to-t from-black/50 to-transparent"></div>
            
            {/* Shop Name Overlay */}
            <div className="absolute bottom-4 left-4 right-4 xl:bottom-8 xl:left-8 xl:right-auto">
              <Link href={`/shop-profile/${shop.id}`} className="text-3xl xl:text-5xl leading-[0.95] font-bold text-white hover:text-gray-200 transition-colors inline-block drop-shadow-lg wrap-break-word max-w-full">
                {shop.name}
              </Link>
              <div className="flex items-start xl:items-center gap-2 text-white mt-2 xl:mt-3 max-w-full">
                <svg xmlns="http://www.w3.org/2000/svg" className="w-4 h-4 xl:w-5 xl:h-5 mt-0.5 xl:mt-0 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" /><circle cx="12" cy="10" r="3" />
                </svg>
                <span className="text-sm xl:text-lg font-medium leading-snug wrap-break-word">{shop.location}</span>
              </div>
              <div className="mt-3 inline-flex items-center gap-2 rounded-full border border-white/55 bg-black/35 px-3 py-1.5 text-[11px] xl:text-xs font-semibold text-white backdrop-blur-sm">
                <span className="inline-flex h-5 w-5 items-center justify-center rounded-full bg-white/20">
                  <svg xmlns="http://www.w3.org/2000/svg" className="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <circle cx="12" cy="12" r="10" />
                    <line x1="12" y1="6" x2="12" y2="12" />
                    <line x1="12" y1="16" x2="12.01" y2="16" />
                  </svg>
                </span>
                <span>Payment Policy: {paymentPolicyLabel}</span>
              </div>
            </div>

            {/* Rating Badge (Top Right) - removed */}
          </div>

          {/* Shop Description */}
          <div className="mb-8 xl:mb-12">
            <p className="text-base xl:text-lg text-gray-700 leading-relaxed max-w-3xl">{shop.description}</p>
          </div>

          {/* Info Grid */}
          <div className="grid grid-cols-1 xl:grid-cols-2 gap-4 xl:gap-6 mb-8 xl:mb-10">
            {/* Shop Information */}
            <div className="bg-linear-to-br from-gray-50 to-white rounded-3xl p-4 sm:p-5 xl:p-8 border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5 xl:mb-6">
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 xl:w-12 xl:h-12 bg-black rounded-xl flex items-center justify-center shadow-md">
                    <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5 xl:w-6 xl:h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                      <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" /><polyline points="9 22 9 12 15 12 15 22" />
                    </svg>
                  </div>
                  <h3 className="text-xl xl:text-2xl font-bold text-black">Shop Information</h3>
                </div>
                <div className="flex items-center gap-2 w-full sm:w-auto">
                  <Link
                    href={`/message/${shop.id}`}
                    className="px-4 xl:px-6 py-2.5 border border-gray-300 rounded-lg text-sm font-semibold text-black hover:bg-black hover:text-white hover:border-black transition-all flex-1 sm:flex-none text-center"
                  >
                    Message
                  </Link>
                  {isAuthenticated && (
                    <div className="relative" ref={actionMenuRef}>
                      <button
                        type="button"
                        onClick={() => setShowMoreActions((prev) => !prev)}
                        className="h-10.5 w-10.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-black hover:text-white hover:border-black transition-all inline-flex items-center justify-center"
                        aria-label="More actions"
                        aria-haspopup="menu"
                      >
                        <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                          <circle cx="12" cy="5" r="1.5" />
                          <circle cx="12" cy="12" r="1.5" />
                          <circle cx="12" cy="19" r="1.5" />
                        </svg>
                      </button>

                      {showMoreActions && (
                        <div className="absolute right-0 top-full mt-2 w-36 rounded-xl border border-gray-200 bg-white shadow-lg p-1.5 z-20">
                          <button
                            type="button"
                            onClick={() => {
                              setShowReportModal(true);
                              setShowMoreActions(false);
                            }}
                            className="w-full px-3 py-2 text-left text-sm font-semibold text-gray-700 hover:bg-gray-100 rounded-lg transition-colors"
                          >
                            Report
                          </button>
                        </div>
                      )}
                    </div>
                  )}
                </div>
              </div>
              <div className="space-y-3 xl:space-y-4 text-sm text-gray-700">
                <div className="flex items-start gap-3 rounded-2xl border border-gray-100 bg-white/80 p-3.5 xl:p-4">
                  <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5 text-black mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" /><circle cx="12" cy="10" r="3" />
                  </svg>
                  <div className="min-w-0">
                    <div className="font-bold text-black mb-1">Location</div>
                    <div className={`leading-6 wrap-break-word ${shop.location === 'Location not specified' || shop.location === 'Not specified' ? 'text-gray-400 italic' : 'text-gray-600'}`}>
                      {shop.location || 'Location not specified'}
                    </div>
                  </div>
                </div>
                <div className="flex items-start gap-3 rounded-2xl border border-blue-100 bg-blue-50/70 p-3.5 xl:p-4">
                  <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5 text-blue-700 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M12 1v22" />
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7H14a3.5 3.5 0 0 1 0 7H6" />
                  </svg>
                  <div className="min-w-0">
                    <div className="font-bold text-blue-900 mb-1">Repair Payment Policy</div>
                    <div className="text-blue-900 font-semibold leading-6">{paymentPolicyLabel}</div>
                    <p className="text-blue-800/90 text-xs sm:text-sm mt-1 leading-5">{paymentPolicyHint}</p>
                  </div>
                </div>
                <div className="flex items-start gap-3 rounded-2xl border border-gray-100 bg-white/80 p-3.5 xl:p-4">
                  <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5 text-black mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" />
                  </svg>
                  <div className="flex-1">
                    <div className="flex items-start sm:items-center justify-between gap-2 mb-2.5">
                      <div className="font-bold text-black">Hours</div>
                      {shop.hours && shop.hours.length > 0 && (
                        <span className={`text-[11px] sm:text-xs font-semibold px-2.5 sm:px-3 py-1 rounded-full whitespace-nowrap ${
                          shopStatus.isOpen 
                            ? 'bg-green-100 text-green-800' 
                            : 'bg-gray-200 text-gray-700'
                        }`}>
                          {shopStatus.message}
                        </span>
                      )}
                    </div>
                    {shop.hours && shop.hours.length > 0 ? (
                      <div className="space-y-2">
                        {shop.hours.map((schedule, index) => (
                          <div 
                            key={index} 
                            className={`grid grid-cols-[84px_minmax(0,1fr)] sm:grid-cols-[100px_minmax(0,1fr)] items-start gap-2 text-[13px] sm:text-sm ${
                              schedule.day === shopStatus.currentDay ? 'font-semibold' : ''
                            }`}
                          >
                            <span className={`leading-5 ${
                              schedule.day === shopStatus.currentDay 
                                ? 'text-black' 
                                : 'text-gray-700'
                            }`}>
                              {schedule.day}
                              {schedule.day === shopStatus.currentDay && (
                                <span className="ml-1 text-blue-600">•</span>
                              )}
                            </span>
                            {schedule.is_closed ? (
                              <span className="text-gray-400 italic leading-5">Closed</span>
                            ) : (
                              <span className={`leading-5 wrap-break-word ${schedule.day === shopStatus.currentDay ? 'text-blue-600' : 'text-gray-600'}`}>
                                {schedule.open} - {schedule.close}
                              </span>
                            )}
                          </div>
                        ))}
                      </div>
                    ) : (
                      <div className="text-gray-400 italic text-sm">Not specified</div>
                    )}
                  </div>
                </div>
                <div className="flex items-start gap-3 rounded-2xl border border-gray-100 bg-white/80 p-3.5 xl:p-4">
                  <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5 text-black mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" />
                  </svg>
                  <div className="min-w-0">
                    <div className="font-bold text-black mb-1">Phone</div>
                    <a href={`tel:${shop.phone}`} className="text-black hover:text-gray-600 transition-colors underline break-all leading-6">{shop.phone}</a>
                  </div>
                </div>
                <div className="flex items-start gap-3 rounded-2xl border border-gray-100 bg-white/80 p-3.5 xl:p-4">
                  <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5 text-black mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <rect x="2" y="4" width="20" height="16" rx="2" /><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
                  </svg>
                  <div className="min-w-0">
                    <div className="font-bold text-black mb-1">Email</div>
                    <a href={`mailto:${shop.email}`} className="text-black hover:text-gray-600 transition-colors underline break-all leading-6">{shop.email}</a>
                  </div>
                </div>
              </div>
            </div>

            {/* Shop Rating */}
            <div className="bg-linear-to-br from-yellow-50 to-white rounded-2xl p-5 xl:p-8 border border-yellow-100 shadow-sm hover:shadow-md transition-shadow">
              <div className="flex items-center gap-3 mb-6">
                <div className="w-10 h-10 xl:w-12 xl:h-12 bg-yellow-400 rounded-xl flex items-center justify-center shadow-md">
                  <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5 xl:w-6 xl:h-6 text-white fill-white" viewBox="0 0 24 24">
                    <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                  </svg>
                </div>
                <h3 className="text-xl xl:text-2xl font-bold text-black">Customer Rating</h3>
              </div>
              {reviewStats.total_reviews > 0 ? (
                <div className="flex flex-col sm:flex-row sm:items-center gap-4 xl:gap-8">
                  <div>
                    <div className="flex items-baseline gap-3 mb-2">
                      <span className="text-5xl xl:text-6xl font-bold text-black">
                        {reviewStats.average_rating.toFixed(1)}
                      </span>
                      <span className="text-3xl xl:text-4xl text-yellow-400">⭐</span>
                    </div>
                    <span className="text-sm text-gray-600">
                      Based on {reviewStats.total_reviews} review{reviewStats.total_reviews !== 1 ? 's' : ''}
                    </span>
                  </div>
                  <div className="flex flex-col gap-1">
                    {renderStars(reviewStats.average_rating)}
                    <span className="text-xs text-gray-500 mt-1">Excellent Service</span>
                  </div>
                </div>
              ) : (
                <div className="text-center py-4">
                  <p className="text-gray-400 italic mb-2">No reviews yet</p>
                  <p className="text-sm text-gray-500">Be the first to review this shop!</p>
                </div>
              )}
            </div>
          </div>

          {/* Repair Services Section */}
          <div className="mb-10 xl:mb-12">
            <div className="mb-8">
              <h2 className="text-2xl xl:text-3xl font-bold text-black mb-5">Packages</h2>
              {repairPackages && repairPackages.length > 0 ? (
                <div className="flex xl:grid xl:grid-cols-3 gap-4 xl:gap-6 overflow-x-auto xl:overflow-visible pb-2 xl:pb-0 snap-x snap-mandatory xl:snap-none">
                  {repairPackages.map((pkg) => {
                    const isSelected = selectedPackageId === pkg.id;

                    return (
                      <button
                        key={pkg.id}
                        type="button"
                        onClick={() => handlePackageToggle(pkg.id)}
                        className={`w-75 min-w-75 sm:w-85 sm:min-w-85 xl:w-full xl:min-w-0 h-62.5 sm:h-65 xl:h-full shrink-0 bg-white rounded-2xl p-5 xl:p-6 border-2 transition-all cursor-pointer text-left snap-start ${
                          isSelected
                            ? 'border-black shadow-md'
                            : 'border-gray-200 hover:border-gray-300 hover:shadow-lg'
                        }`}
                      >
                        <div className="flex flex-col h-full">
                          <div className="flex items-start justify-between gap-2 xl:gap-3 mb-3">
                            <div className="flex-1 min-w-0">
                              <span className="text-xs px-2 py-1 rounded-full bg-gray-100 text-gray-600 font-medium inline-block mb-2">
                                Package
                              </span>
                              <h3 className="text-base xl:text-lg font-bold text-black leading-snug wrap-break-word">{pkg.name}</h3>
                              <p className="text-sm text-gray-600 mt-1 leading-5 wrap-break-word">{pkg.description || 'Repair package offer'}</p>
                            </div>
                            <div className={`w-6 h-6 rounded-full border-2 flex items-center justify-center shrink-0 transition-all ${
                              isSelected
                                ? 'border-black bg-black'
                                : 'border-gray-300'
                            }`}>
                              {isSelected && <span className="block w-2 h-2 rounded-full bg-white" />}
                            </div>
                          </div>

                          <div className="text-sm text-gray-600 space-y-1">
                            <p>Includes {pkg.service_count} service{pkg.service_count !== 1 ? 's' : ''}</p>
                            <p>Save ₱{Number(pkg.savings_amount || 0).toLocaleString()}</p>
                          </div>

                          <div className="mt-auto pt-4 border-t border-gray-200 flex items-center justify-between gap-3">
                            <span className="text-xl xl:text-2xl font-bold text-black">₱{Number(pkg.package_price || 0).toLocaleString()}</span>
                            <span className="text-xs text-gray-500 text-right">Bundle offer</span>
                          </div>
                        </div>
                      </button>
                    );
                  })}
                </div>
              ) : (
                <div className="text-center py-8 bg-white rounded-2xl border border-gray-200 text-gray-600">
                  No active packages available.
                </div>
              )}
            </div>

            <div>
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 sm:gap-3 mb-6 xl:mb-8">
                <h2 className="text-2xl xl:text-3xl font-bold text-black">Individual Services</h2>
                <span className="inline-flex items-center gap-2 text-[11px] xl:text-xs font-semibold uppercase tracking-[0.08em] text-gray-500">
                  Choose one or more
                </span>
              </div>

              {repairServices && repairServices.length > 0 ? (
                <div className="flex xl:grid xl:grid-cols-3 gap-4 xl:gap-6 overflow-x-auto xl:overflow-visible pb-2 xl:pb-0 snap-x snap-mandatory xl:snap-none">
                  {repairServices.map((service) => {
                    const descriptionLines = getServiceDescriptionLines(service.description);
                    const isSelected = selectedServices.includes(service.id);

                    return (
                      <div
                        key={service.id}
                        className={`w-75 min-w-75 sm:w-85 sm:min-w-85 xl:w-full xl:min-w-0 h-62.5 sm:h-65 xl:h-full shrink-0 bg-white rounded-2xl p-5 xl:p-6 border-2 transition-all snap-start ${
                          isSelected
                            ? 'border-black shadow-md'
                            : 'border-gray-200 hover:border-gray-300 hover:shadow-lg cursor-pointer'
                        }`}
                        onClick={() => handleServiceToggle(service.id)}
                      >
                        <div className="flex flex-col h-full">
                          <div className="flex items-start justify-between gap-2 xl:gap-3 mb-3">
                            <div className="flex-1 min-w-0">
                              <div className="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-2 mb-2">
                                <h3 className="text-base xl:text-lg font-bold text-black leading-snug wrap-break-word min-w-0">{service.title}</h3>
                                <span className="text-[11px] xl:text-xs px-2 py-1 rounded-full bg-gray-100 text-gray-600 font-medium whitespace-nowrap shrink-0">
                                  {service.category}
                                </span>
                              </div>
                            </div>
                            <div className={`w-6 h-6 rounded-full border-2 flex items-center justify-center shrink-0 transition-all ${
                              isSelected
                                ? 'border-black bg-black'
                                : 'border-gray-300'
                            }`}>
                              {isSelected && (
                                <svg className="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                  <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                </svg>
                              )}
                            </div>
                          </div>

                          <ul className="text-sm text-gray-600 space-y-1 list-disc pl-5">
                            {descriptionLines.map((line, index) => (
                              <li key={`${service.id}-desc-${index}`} className="leading-5">
                                {line}
                              </li>
                            ))}
                          </ul>

                          <div className="mt-auto pt-4 border-t border-gray-200">
                            <div className="flex items-center justify-between gap-3">
                              <div className="text-xl xl:text-2xl font-bold text-black">{service.price}</div>
                              <div className="text-xs text-gray-500 flex items-center gap-1 whitespace-nowrap">
                                <svg xmlns="http://www.w3.org/2000/svg" className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                  <circle cx="12" cy="12" r="10" />
                                  <polyline points="12 6 12 12 16 14" />
                                </svg>
                                {service.duration}
                              </div>
                            </div>
                          </div>
                        </div>
                      </div>
                    );
                  })}
                </div>
              ) : (
                <div className="text-center py-12 bg-white rounded-2xl border border-gray-200">
                  <svg xmlns="http://www.w3.org/2000/svg" className="w-16 h-16 mx-auto text-gray-400 mb-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                    <polyline points="9 22 9 12 15 12 15 22" />
                  </svg>
                  <p className="text-gray-600 text-lg">No repair services available at the moment</p>
                </div>
              )}
            </div>
          </div>

          {/* Request Service Button */}
          <Link
            href={requestRepairHref}
            onClick={handleRequestRepair}
            className="hidden xl:block w-full bg-black text-white py-5 rounded-2xl hover:bg-gray-900 active:scale-[0.98] transition-all font-bold text-xl shadow-xl hover:shadow-2xl text-center mb-16"
          >
            Request Repair Service {selectionSummary}
          </Link>

          {/* Reviews and Comments Section */}
          <div id="reviews" className="border-t border-gray-200 pt-12 xl:pt-16">
            <div className="mb-10 xl:mb-12">
              <h2 className="text-3xl xl:text-4xl font-bold text-black mb-5 xl:mb-6">Customer Reviews</h2>
              <div className="flex items-center gap-6">
                <div className="flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-4">
                  <span className="text-5xl xl:text-6xl font-bold text-black leading-none">
                    {reviewStats.average_rating > 0 ? reviewStats.average_rating.toFixed(1) : shop.rating}
                  </span>
                  <div>
                    <div className="flex gap-1 mb-2">
                      {renderStars(reviewStats.average_rating > 0 ? reviewStats.average_rating : shop.rating)}
                    </div>
                    <span className="text-sm text-gray-600">
                      Based on {reviewStats.total_reviews} review{reviewStats.total_reviews !== 1 ? 's' : ''}
                    </span>
                  </div>
                </div>
              </div>
            </div>

            {/* Write a Review Section */}
            <div className="bg-linear-to-br from-gray-50 to-white rounded-2xl p-5 xl:p-10 mb-10 xl:mb-12 border border-gray-100 shadow-sm hover:shadow-md transition-all">
              <div className="flex items-center gap-3 mb-6 xl:mb-8">
                <div className="w-10 h-10 xl:w-12 xl:h-12 bg-black rounded-xl flex items-center justify-center shadow-md">
                  <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5 xl:w-6 xl:h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                    <circle cx="12" cy="7" r="4"></circle>
                  </svg>
                </div>
                <h3 className="text-2xl xl:text-3xl font-bold text-black">Share Your Experience</h3>
              </div>

              {/* Eligibility Messages */}
              {!isAuthenticated ? (
                <div className="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-xl">
                  <p className="text-blue-800">
                    Please <Link href="/login" className="font-bold underline hover:text-blue-600">log in</Link> to write a review
                  </p>
                </div>
              ) : isAuthenticated && canReview ? (
                <div className="mb-6 p-4 bg-green-50 border border-green-200 rounded-xl">
                  <p className="text-green-800 flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                      <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                      <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                    You are eligible to review this shop
                  </p>
                </div>
              ) : isAuthenticated && reviewEligibility && !canReview ? (
                <div className="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-xl">
                  <p className="text-amber-800">
                    {reviewEligibility.message || "You can only review shops where you have completed a purchase or repair service"}
                    {reviewEligibility.reason === 'already_reviewed' && reviewEligibility.existing_review && (
                      <span className="block mt-2 text-sm">
                        You submitted a review on {new Date(reviewEligibility.existing_review.created_at).toLocaleDateString('en-US', {
                          year: 'numeric',
                          month: 'long',
                          day: 'numeric'
                        })}
                      </span>
                    )}
                  </p>
                </div>
              ) : isAuthenticated && !reviewEligibility ? (
                <div className="mb-6 p-4 bg-amber-50 border border-amber-200 rounded-xl">
                  <p className="text-amber-800">
                    You can only review shops where you have completed a purchase or repair service
                  </p>
                </div>
              ) : null}

              <div className="mb-8">
                <label className="block text-base font-bold text-black mb-4">Your Rating</label>
                <div className="flex flex-wrap gap-2">
                  {renderStars(userRating, true, setUserRating)}
                  {userRating > 0 && (
                    <span className="text-sm text-gray-600 flex items-center">
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
                <p className="text-xs text-gray-500 mb-3">Tip: After you upload one photo, another upload slot appears automatically (up to 5).</p>
                <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4">
                  {imageUploadGroups.map((group) => (
                    <div key={group.id} className="relative group/photo">
                      {group.preview ? (
                        <div className="relative">
                          <img
                            src={group.preview}
                            alt="Review photo"
                            className="w-full h-24 md:h-28 xl:h-32 object-cover rounded-xl border-2 border-gray-200 shadow-sm"
                          />
                          <div className="absolute inset-0 bg-black/60 opacity-100 xl:opacity-0 xl:group-hover/photo:opacity-100 transition-opacity rounded-xl flex items-center justify-center gap-2">
                            {imageUploadGroups.length < 5 && (
                              <button
                                onClick={addImageUploadBox}
                                className="w-11 h-11 md:w-12 md:h-12 bg-black hover:bg-gray-800 rounded-full flex items-center justify-center text-white transition-all shadow-lg hover:scale-110"
                                type="button"
                                title="Add more photos"
                              >
                                <span className="text-xl font-bold">+</span>
                              </button>
                            )}
                            <button
                              onClick={() => removeImageBox(group.id)}
                              className="w-11 h-11 md:w-12 md:h-12 bg-gray-800 hover:bg-black rounded-full flex items-center justify-center text-white transition-all shadow-lg hover:scale-110"
                              type="button"
                              title="Remove photo"
                            >
                              <span className="text-xl font-bold">×</span>
                            </button>
                          </div>
                        </div>
                      ) : (
                        <label className="w-full h-24 md:h-28 xl:h-32 border-2 border-dashed border-gray-300 rounded-xl flex flex-col items-center justify-center cursor-pointer hover:border-black hover:bg-gray-50 transition-all bg-white">
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
                          <span className="text-xs text-gray-500 font-medium">Tap to upload</span>
                        </label>
                      )}
                    </div>
                  ))}
                </div>
              </div>

              <button
                onClick={handleSubmitReview}
                disabled={!canReview || isSubmittingReview}
                className={`w-full sm:w-auto px-8 xl:px-10 py-4 rounded-2xl font-bold text-base xl:text-lg shadow-lg transition-all ${
                  canReview && !isSubmittingReview
                    ? 'bg-black text-white hover:bg-gray-900 hover:shadow-xl active:scale-[0.98] cursor-pointer'
                    : 'bg-gray-300 text-gray-500 cursor-not-allowed'
                }`}
                type="button"
              >
                {isSubmittingReview ? 'Submitting...' : 'Submit Review'}
              </button>
            </div>

            {/* Reviews List */}
            {reviews.length === 0 ? (
              <div className="text-center py-12 bg-gray-50 rounded-2xl">
                <svg xmlns="http://www.w3.org/2000/svg" className="w-16 h-16 text-gray-300 mx-auto mb-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                </svg>
                <p className="text-gray-500 text-lg">No reviews yet. Be the first to review this shop!</p>
              </div>
            ) : (
              <div className="space-y-4 xl:space-y-6">
                {reviews.map((review) => (
                  <div key={review.id} className="bg-linear-to-br from-white to-gray-50 rounded-2xl p-5 xl:p-8 border border-gray-100 hover:shadow-lg transition-all">
                    <div className="flex items-start gap-4 xl:gap-6">
                      {/* User Avatar */}
                      <div className="w-14 h-14 xl:w-20 xl:h-20 rounded-2xl bg-linear-to-br from-gray-100 to-gray-200 border-2 border-gray-200 overflow-hidden shrink-0 flex items-center justify-center shadow-md">
                        <span className="text-xl xl:text-2xl font-bold text-gray-600">
                          {review.user_name.charAt(0).toUpperCase()}
                        </span>
                      </div>
                      
                      <div className="flex-1">
                        <div className="flex items-center gap-3 mb-3 flex-wrap">
                          <h4 className="font-bold text-black text-lg xl:text-xl">{review.user_name}</h4>
                          {review.verified && (
                            <span className="inline-flex items-center gap-1 px-3 py-1 bg-green-100 text-green-800 text-xs font-semibold rounded-full">
                              <svg xmlns="http://www.w3.org/2000/svg" className="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                <polyline points="22 4 12 14.01 9 11.01"></polyline>
                              </svg>
                              Verified Service
                            </span>
                          )}
                        </div>
                        
                        <div className="flex items-center gap-4 mb-4">
                          <div className="flex gap-1">
                            {renderStars(review.rating)}
                          </div>
                          <span className="text-sm text-gray-500 font-medium">
                            {new Date(review.created_at).toLocaleDateString('en-US', {
                              year: 'numeric',
                              month: 'long',
                              day: 'numeric'
                            })}
                          </span>
                        </div>
                        
                        <p className="text-gray-700 leading-relaxed text-sm xl:text-base mb-4">{review.comment}</p>
                        
                        {/* Review Images */}
                        {review.images && review.images.length > 0 && (
                          <div className="flex gap-2 mt-4 overflow-x-auto pb-1">
                            {review.images.map((image, index) => (
                              <img
                                key={index}
                                src={image}
                                alt={`Review photo ${index + 1}`}
                                className="w-20 h-20 xl:w-24 xl:h-24 object-cover rounded-lg border-2 border-gray-200 cursor-pointer hover:scale-105 transition-transform shadow-sm shrink-0"
                                onClick={() => setEnlargedImage(image)}
                                onError={(e) => {
                                  const target = e.target as HTMLImageElement;
                                  target.style.display = 'none';
                                }}
                              />
                            ))}
                          </div>
                        )}
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>

        <div className="fixed bottom-0 left-0 right-0 z-40 flex items-stretch border-t border-gray-200 bg-white shadow-[0_-4px_20px_-4px_rgba(0,0,0,0.12)] xl:hidden">
          <Link
            href={`/shop-profile/${shop.id}`}
            className="flex w-15 shrink-0 flex-col items-center justify-center gap-0.5 py-2.5 text-gray-600 hover:text-black transition-colors"
            aria-label="Visit shop"
          >
            <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M9 22V12h6v10" />
            </svg>
            <span className="text-[10px] font-medium">Shop</span>
          </Link>

          <Link
            href={isAuthenticated ? `/message/${shop.id}` : '/login'}
            className="flex w-15 shrink-0 flex-col items-center justify-center gap-0.5 border-l border-gray-200 py-2.5 text-gray-600 hover:text-black transition-colors"
            aria-label="Message"
          >
            <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M7 8h10M7 12h6m-8 7l3.5-2H19a3 3 0 003-3V7a3 3 0 00-3-3H5a3 3 0 00-3 3v7a3 3 0 003 3h1l1 2z" />
            </svg>
            <span className="text-[10px] font-medium">Chat</span>
          </Link>

          <Link
            href={requestRepairHref}
            onClick={handleRequestRepair}
            className="flex-1 border-l border-gray-200 bg-black text-white py-3.5 px-4 text-center text-[11px] font-bold uppercase tracking-wider hover:bg-gray-900 transition-colors"
          >
            Request Repair {selectedPackageId ? '(Package)' : selectedServices.length > 0 ? `(${selectedServices.length})` : ''}
          </Link>
        </div>

        {/* Image Lightbox Modal */}
        {enlargedImage && (
          <div
            className="fixed inset-0 z-50 bg-black/90 backdrop-blur-sm flex items-center justify-center p-4 xl:p-6 animate-fadeIn"
            onClick={() => setEnlargedImage(null)}
          >
            <div
              className="relative w-full max-w-6xl"
              onClick={(e) => e.stopPropagation()}
            >
              <img
                src={enlargedImage}
                alt="Enlarged review"
                className="w-full max-h-[80vh] xl:max-h-[85vh] object-contain rounded-2xl shadow-2xl"
              />
              <button
                onClick={() => setEnlargedImage(null)}
                className="absolute top-2 right-2 xl:-top-4 xl:-right-4 w-10 h-10 xl:w-12 xl:h-12 bg-white rounded-full flex items-center justify-center hover:bg-gray-100 active:scale-95 transition-all shadow-xl"
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

      <ReportShopModal
        shopId={shop.id}
        shopName={shop.name}
        isOpen={showReportModal}
        onClose={() => setShowReportModal(false)}
      />
    </>
  );
};

export default RepairShow;
