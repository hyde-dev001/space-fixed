import React, { useState, useEffect } from 'react';
import { Head, Link } from '@inertiajs/react';
import Navigation from './Navigation';
import Swal from 'sweetalert2';
import axios from 'axios';

type RepairStatus = 'new_request' | 'assigned_to_repairer' | 'repairer_accepted' | 'waiting_customer_confirmation' | 'owner_approval_pending' | 'owner_approved' | 'owner_rejected' | 'in_progress' | 'awaiting_parts' | 'completed' | 'ready_for_pickup' | 'picked_up' | 'pending' | 'received' | 'cancelled' | 'rejected' | 'repairer_rejected';
type RepairTab = 'new_request' | 'repairer_accepted' | 'waiting_customer_confirmation' | 'owner_approval_pending' | 'in_progress' | 'completed' | 'ready_for_pickup' | 'picked_up' | 'pending' | 'received' | 'cancelled' | 'rejected';

type RepairOrder = {
  id: number;
  order_number: string;
  repair_type: string;
  description: string;
  status: RepairStatus;
  total_amount: number;
  created_at: string;
  estimated_completion?: string;
  duration?: string;
  completed_at?: string;
  shop_id?: number | null;
  shop_name: string;
  shop_address: string;
  image?: string;
  conversation_id?: number | null;
  delivery_method?: string;
  pickup_address?: string;
  payment_status?: string;
  payment_completed_at?: string | null;
  paymongo_link_id?: string | null;
  payment_enabled?: boolean;
  payment_enabled_at?: string | null;
};

type ConversationShop = {
  conversation_id?: number;
  unreadCount?: number;
};

// Static mock data for testing
const getStaticRepairOrders = (): RepairOrder[] => {
  return [
    {
      id: 1,
      order_number: 'REP-2026020201',
      repair_type: 'Sole Replacement',
      description: 'Replace worn out sole on Nike Air Max',
      status: 'new_request',
      total_amount: 1500,
      created_at: new Date('2026-02-02T10:30:00').toISOString(),
      estimated_completion: 'Feb 7, 2026',
      shop_id: 1,
      shop_name: 'SoleSpace Repair Center',
      shop_address: '123 Main St, Makati City',
      image: '/images/product/product-01.jpg',
    },
    {
      id: 2,
      order_number: 'REP-2026020101',
      repair_type: 'Heel Replacement',
      description: 'Replace broken heel on dress shoes',
      status: 'pending',
      total_amount: 2000,
      created_at: new Date('2026-02-01T16:45:00').toISOString(),
      estimated_completion: 'Feb 6, 2026',
      shop_id: 1,
      shop_name: 'SoleSpace Repair Center',
      shop_address: '123 Main St, Makati City',
      image: '/images/product/product-04.jpg',
    },
    {
      id: 3,
      order_number: 'REP-2026013101',
      repair_type: 'Shoe Cleaning',
      description: 'Deep cleaning for white sneakers',
      status: 'received',
      total_amount: 800,
      created_at: new Date('2026-01-31T14:20:00').toISOString(),
      estimated_completion: 'Feb 4, 2026',
      shop_id: 1,
      shop_name: 'SoleSpace Repair Center',
      shop_address: '123 Main St, Makati City',
      image: '/images/product/product-02.jpg',
    },
    {
      id: 4,
      order_number: 'REP-2026013001',
      repair_type: 'Stitching Repair',
      description: 'Fix torn stitching on leather boots',
      status: 'in_progress',
      total_amount: 1200,
      created_at: new Date('2026-01-30T09:15:00').toISOString(),
      estimated_completion: 'Feb 3, 2026',
      shop_id: 1,
      shop_name: 'SoleSpace Repair Center',
      shop_address: '123 Main St, Makati City',
      image: '/images/product/product-03.jpg',
    },
    {
      id: 5,
      order_number: 'REP-2026012901',
      repair_type: 'Sole Reglue',
      description: 'Re-glue sole on running shoes',
      status: 'ready_for_pickup',
      total_amount: 950,
      created_at: new Date('2026-01-29T11:05:00').toISOString(),
      completed_at: 'Feb 1, 2026',
      shop_id: 1,
      shop_name: 'SoleSpace Repair Center',
      shop_address: '123 Main St, Makati City',
      image: '/images/product/product-05.jpg',
    },
    {
      id: 6,
      order_number: 'REP-2026012801',
      repair_type: 'Lace Replacement',
      description: 'Replace worn laces on sneakers',
      status: 'cancelled',
      total_amount: 250,
      created_at: new Date('2026-01-28T13:40:00').toISOString(),
      shop_id: 1,
      shop_name: 'SoleSpace Repair Center',
      shop_address: '123 Main St, Makati City',
      image: '/images/product/product-06.jpg',
    },
    {
      id: 7,
      order_number: 'REP-2026012701',
      repair_type: 'Toe Cap Repair',
      description: 'Fix scuffed toe cap on leather shoes',
      status: 'rejected',
      total_amount: 700,
      created_at: new Date('2026-01-27T10:10:00').toISOString(),
      shop_id: 1,
      shop_name: 'SoleSpace Repair Center',
      shop_address: '123 Main St, Makati City',
      image: '/images/product/product-07.jpg',
    },
  ];
};

const MyRepairs: React.FC = () => {
  const [orders, setOrders] = useState<RepairOrder[]>([]);
  const [loading, setLoading] = useState(true);
  const [selectedTab, setSelectedTab] = useState<RepairTab>('new_request');
  const [showCancelModal, setShowCancelModal] = useState(false);
  const [cancelTargetOrderId, setCancelTargetOrderId] = useState<number | null>(null);
  const [selectedReason, setSelectedReason] = useState<string>('');
  const [cancelNote, setCancelNote] = useState<string>('');

  // Refund modal states
  const [showRefundModal, setShowRefundModal] = useState(false);
  const [refundOrderId, setRefundOrderId] = useState<number | null>(null);
  const [refundStep, setRefundStep] = useState<number>(1);
  const [refundReason, setRefundReason] = useState<string>('');
  const [refundMedia, setRefundMedia] = useState<File[]>([]);
  const [refundMethod, setRefundMethod] = useState<string>('');
  const [refundNote, setRefundNote] = useState<string>('');

  // Review modal states (Phase 10D)
  const [showReviewModal, setShowReviewModal] = useState(false);
  const [reviewOrderId, setReviewOrderId] = useState<number | null>(null);
  const [reviewRating, setReviewRating] = useState<number>(0);
  const [reviewText, setReviewText] = useState<string>('');
  const [reviewImages, setReviewImages] = useState<File[]>([]);
  const [hoveredRating, setHoveredRating] = useState<number>(0);
  const [processingPayment, setProcessingPayment] = useState(false);
  const [conversationUnreadCounts, setConversationUnreadCounts] = useState<Record<number, number>>({});

  const fetchConversationUnreadCounts = async () => {
    try {
      const response = await axios.get('/api/customer/conversations/shops');
      const unreadMap: Record<number, number> = {};

      (response.data as ConversationShop[]).forEach((shop) => {
        if (shop.conversation_id) {
          unreadMap[shop.conversation_id] = shop.unreadCount ?? 0;
        }
      });

      setConversationUnreadCounts(unreadMap);
    } catch (error) {
      console.error('Failed to fetch conversation unread counts:', error);
    }
  };

  const getUnreadCountForConversation = (conversationId?: number | null) => {
    if (!conversationId) return 0;
    return conversationUnreadCounts[conversationId] ?? 0;
  };

  useEffect(() => {
    // Check if user just returned from payment
    const checkPaymentReturn = async () => {
      const pendingRepairId = sessionStorage.getItem('pendingRepairId');
      const shouldSimulate = sessionStorage.getItem('simulatePayment');
      
      if (pendingRepairId) {
        // Clear the session storage first
        sessionStorage.removeItem('pendingRepairId');
        sessionStorage.removeItem('simulatePayment');
        
        if (shouldSimulate === 'true') {
          // Show processing message
          Swal.fire({
            icon: 'info',
            title: 'Processing Payment...',
            text: 'Please wait while we confirm your payment.',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => {
              Swal.showLoading();
            }
          });

          // Auto-complete the payment for testing
          try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            
            const response = await fetch(`/api/customer/repairs/${pendingRepairId}/simulate-payment`, {
              method: 'POST',
              credentials: 'include',
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken || '',
              },
            });

            if (!response.ok) {
              throw new Error('Failed to process test payment');
            }

            // Wait a bit for processing
            await new Promise(resolve => setTimeout(resolve, 1500));

            // Fetch updated data
            await fetchRepairs();

            // Show success
            Swal.fire({
              icon: 'success',
              title: 'Payment Successful!',
              text: 'Your payment has been received. Your repair will begin shortly.',
              confirmButtonColor: '#000000',
              timer: 3000,
              timerProgressBar: true,
            });
          } catch (error) {
            console.error('Auto-payment error:', error);
            Swal.fire({
              icon: 'error',
              title: 'Payment Error',
              text: 'There was an issue processing your payment. Please contact support.',
              confirmButtonColor: '#000000',
            });
            fetchRepairs();
          }
        } else {
          // Original webhook-based flow
          Swal.fire({
            icon: 'info',
            title: 'Processing Payment...',
            text: 'Please wait while we confirm your payment.',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => {
              Swal.showLoading();
            }
          });
          
          // Wait for webhook to process and retry until payment is confirmed
          let attempts = 0;
          const maxAttempts = 5;
          let paymentConfirmed = false;
          
          while (attempts < maxAttempts && !paymentConfirmed) {
            // Wait before checking (2 seconds first attempt, then 1.5 seconds)
            await new Promise(resolve => setTimeout(resolve, attempts === 0 ? 3000 : 1500));
            
            // Fetch the latest repair data
            try {
              const response = await axios.get('/api/customer/repairs');
              if (response.data.success) {
                setOrders(response.data.data);
                await fetchConversationUnreadCounts();
                
                // Check if payment was confirmed
                const updatedRepair = response.data.data.find((o: RepairOrder) => o.id === parseInt(pendingRepairId));
                if (updatedRepair && updatedRepair.payment_status === 'paid') {
                  paymentConfirmed = true;
                }
              }
            } catch (error) {
              console.error('Error fetching repairs:', error);
            }
            
            attempts++;
          }
          
          // Close processing message and show result
          if (paymentConfirmed) {
            Swal.fire({
              icon: 'success',
              title: 'Payment Successful!',
              text: 'Your payment has been received. Your repair will begin shortly.',
              confirmButtonColor: '#000000',
              timer: 3000,
              timerProgressBar: true,
            });
          } else {
            // Payment not confirmed yet, but show message anyway
            Swal.fire({
              icon: 'success',
              title: 'Payment Successful!',
              text: 'Your payment has been received. Your repair will begin shortly.',
              confirmButtonColor: '#000000',
            }).then(() => {
              // Force one more refresh
              fetchRepairs();
            });
          }
        }
      } else {
        // Normal fetch if not returning from payment
        fetchRepairs();
      }
    };
    
    checkPaymentReturn();
  }, []);

  useEffect(() => {
    const intervalId = window.setInterval(() => {
      fetchConversationUnreadCounts();
    }, 10000);

    return () => {
      window.clearInterval(intervalId);
    };
  }, []);

  const fetchRepairs = async () => {
    try {
      setLoading(true);
      const response = await axios.get('/api/customer/repairs');
      if (response.data.success) {
        setOrders(response.data.data);
        await fetchConversationUnreadCounts();
      }
    } catch (error) {
      console.error('Failed to fetch repairs:', error);
      Swal.fire({
        icon: 'error',
        title: 'Failed to load repairs',
        text: 'Please try refreshing the page',
        confirmButtonColor: '#000000',
      });
    } finally {
      setLoading(false);
    }
  };

  const confirmPickup = async (orderId: number) => {
    const result = await Swal.fire({
      title: 'Confirm Pickup?',
      text: 'Please confirm that you have picked up your repaired item.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, I picked it up',
      cancelButtonText: 'Not yet',
      confirmButtonColor: '#000000',
      cancelButtonColor: '#6b7280',
      reverseButtons: true,
    });

    if (!result.isConfirmed) return;

    try {
      const response = await axios.post(`/api/customer/repairs/${orderId}/confirm-pickup`);
      
      if (response.data.success) {
        // Update local state
        setOrders(prev => 
          prev.map(order => 
            order.id === orderId 
              ? { ...order, status: 'picked_up' as const } 
              : order
          )
        );

        await Swal.fire({
          title: 'Pickup Confirmed!',
          text: 'Thank you for confirming your pickup.',
          icon: 'success',
          confirmButtonText: 'OK',
          confirmButtonColor: '#000000',
        });
      }
    } catch (error) {
      console.error('Failed to confirm pickup:', error);
      await Swal.fire({
        icon: 'error',
        title: 'Failed to confirm pickup',
        text: 'Please try again',
        confirmButtonColor: '#000000',
      });
    }
  };

  const cancelRepair = async (orderId: number, reason?: string) => {
    if (!reason) {
      const result = await Swal.fire({
        title: 'Cancel Repair?',
        text: 'Are you sure you want to cancel this repair order?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, cancel it',
        cancelButtonText: 'Keep order',
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280',
        reverseButtons: true,
      });

      if (!result.isConfirmed) return;
    }

    try {
      const response = await axios.post(`/api/customer/repairs/${orderId}/cancel`);
      
      if (response.data.success) {
        setOrders(prev =>
          prev.map(order =>
            order.id === orderId
              ? { ...order, status: 'cancelled' as const }
              : order
          )
        );

        await Swal.fire({
          title: 'Repair Cancelled',
          text: reason ? 'Your repair order has been cancelled.' : 'Your repair order has been cancelled.',
          icon: 'success',
          confirmButtonText: 'OK',
          confirmButtonColor: '#000000',
        });
      }
    } catch (error) {
      console.error('Failed to cancel repair:', error);
      await Swal.fire({
        icon: 'error',
        title: 'Failed to cancel repair',
        text: 'Please try again',
        confirmButtonColor: '#000000',
      });
    }
  };

  const handlePayNow = async (orderId: number) => {
    const order = orders.find(o => o.id === orderId);
    if (!order) return;

    setProcessingPayment(true);

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      
      // Create PayMongo payment link for simulation
      const response = await fetch('/api/paymongo-proxy', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify({
          amount: order.total_amount,
          description: `SoleSpace Repair #${order.order_number} - ${order.repair_type}`,
        }),
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(errorData.error || 'Failed to create payment link');
      }

      const paymentData = await response.json();
      const checkoutUrl = paymentData.checkout_url;
      const linkId = paymentData.link_id;

      if (!checkoutUrl || !linkId) {
        throw new Error('Incomplete payment data received from PayMongo');
      }

      // Update repair order with PayMongo link ID
      await fetch(`/api/customer/repairs/${orderId}/update-payment-link`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
        },
        body: JSON.stringify({ paymongo_link_id: linkId }),
      });

      // Store repair info for auto-completion on return
      sessionStorage.setItem('pendingRepairId', orderId.toString());
      sessionStorage.setItem('simulatePayment', 'true');

      // Redirect to PayMongo payment page
      window.location.href = checkoutUrl;
    } catch (err: any) {
      console.error('Payment error:', err);
      const errorMessage = err?.message || 'Unable to process payment';
      setProcessingPayment(false);

      Swal.fire({
        icon: 'error',
        title: 'Payment Failed',
        text: errorMessage,
        confirmButtonColor: '#000000',
      });
    }
  };

  const handleSubmitRefund = async () => {
    if (!refundOrderId) return;
    
    if (!refundReason) {
      Swal.fire({ icon: 'warning', title: 'Please select a reason', confirmButtonColor: '#000000' });
      return;
    }
    
    if (refundMedia.length === 0) {
      Swal.fire({ icon: 'warning', title: 'Please upload at least one photo or video', confirmButtonColor: '#000000' });
      return;
    }
    
    if (!refundMethod) {
      Swal.fire({ icon: 'warning', title: 'Please select a refund method', confirmButtonColor: '#000000' });
      return;
    }

    // Show confirmation before submitting
    const result = await Swal.fire({
      title: 'Submit Refund Request?',
      text: 'Please review your refund details before submitting. Once submitted, our team will review your request.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, Submit',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#000000',
      cancelButtonColor: '#6b7280',
      reverseButtons: true,
    });

    if (!result.isConfirmed) return;

    try {
      const formData = new FormData();
      formData.append('repair_id', refundOrderId.toString());
      formData.append('reason', refundReason);
      formData.append('refund_method', refundMethod);
      formData.append('note', refundNote);
      
      // Append all media files
      refundMedia.forEach((file, index) => {
        formData.append(`media[${index}]`, file);
      });

      const response = await fetch('/repairs/request-refund', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: formData,
      });

      if (!response.ok) {
        const data = await response.json();
        throw new Error(data.message || 'Failed to submit refund request');
      }

      setShowRefundModal(false);
      setRefundOrderId(null);
      setRefundStep(1);
      setRefundReason('');
      setRefundMedia([]);
      setRefundMethod('');
      setRefundNote('');

      Swal.fire({
        icon: 'success',
        title: 'Refund Request Submitted',
        text: 'Your refund request has been submitted successfully. We will review it shortly.',
        confirmButtonColor: '#000000',
      });
    } catch (error) {
      console.error('Error submitting refund request:', error);
      Swal.fire({
        icon: 'error',
        title: 'Failed',
        text: error instanceof Error ? error.message : 'Unable to submit refund request. Please try again.',
        confirmButtonColor: '#000000',
      });
    }
  };

  const handleMediaUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files) {
      const filesArray = Array.from(e.target.files);
      const currentVideos = refundMedia.filter(file => isVideoFile(file));
      const currentImages = refundMedia.filter(file => !isVideoFile(file));
      
      const newVideos = filesArray.filter(file => file.type.startsWith('video/'));
      const newImages = filesArray.filter(file => !file.type.startsWith('video/'));
      
      // Check video limit (max 1)
      if (currentVideos.length + newVideos.length > 1) {
        Swal.fire({
          icon: 'warning',
          title: 'Video Limit Exceeded',
          text: 'You can only upload 1 video. Please remove the existing video before uploading a new one.',
          confirmButtonColor: '#000000',
        });
        e.target.value = '';
        return;
      }
      
      // Check image limit (max 5)
      if (currentImages.length + newImages.length > 5) {
        Swal.fire({
          icon: 'warning',
          title: 'Image Limit Exceeded',
          text: `You can only upload 5 images. You have ${5 - currentImages.length} slot(s) remaining.`,
          confirmButtonColor: '#000000',
        });
        e.target.value = '';
        return;
      }
      
      // Add files if within limits
      setRefundMedia(prev => [...prev, ...filesArray]);
    }
    
    // Reset the input so the same file can be selected again if needed
    e.target.value = '';
  };

  const removeMedia = (index: number) => {
    setRefundMedia(prev => prev.filter((_, i) => i !== index));
  };

  const isVideoFile = (file: File) => {
    return file.type.startsWith('video/');
  };

  const isMediaRequirementMet = () => {
    const videos = refundMedia.filter(file => isVideoFile(file));
    const images = refundMedia.filter(file => !isVideoFile(file));
    return images.length === 5 && videos.length === 1;
  };

  // Phase 10D - Review functions
  const openReviewModal = async (orderId: number) => {
    try {
      // Find the order to get the shop_id
      const order = orders.find(o => o.id === orderId);
      
      if (!order || !order.shop_id) {
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: 'Shop information not found',
          confirmButtonColor: '#000000',
        });
        return;
      }

      // Navigate to the shop page where they can leave a review
      window.location.href = `/repair-shop/${order.shop_id}`;
    } catch (error) {
      console.error('Error navigating to shop:', error);
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: 'Failed to navigate to shop page',
        confirmButtonColor: '#000000',
      });
    }
  };

  const submitReview = async () => {
    if (!reviewOrderId) return;

    if (reviewRating === 0) {
      Swal.fire({
        icon: 'warning',
        title: 'Rating Required',
        text: 'Please select a star rating',
        confirmButtonColor: '#000000',
      });
      return;
    }

    try {
      const formData = new FormData();
      formData.append('rating', reviewRating.toString());
      if (reviewText) {
        formData.append('review_text', reviewText);
      }
      
      // Append review images
      reviewImages.forEach((file, index) => {
        formData.append(`review_images[${index}]`, file);
      });

      const response = await axios.post(
        `/api/customer/repairs/${reviewOrderId}/review`,
        formData,
        {
          headers: {
            'Content-Type': 'multipart/form-data',
          },
        }
      );

      if (response.data.success) {
        setShowReviewModal(false);
        setReviewOrderId(null);
        setReviewRating(0);
        setReviewText('');
        setReviewImages([]);

        Swal.fire({
          icon: 'success',
          title: 'Review Submitted!',
          text: response.data.message || 'Thank you for your feedback!',
          confirmButtonColor: '#000000',
        });
      }
    } catch (error: any) {
      console.error('Error submitting review:', error);
      Swal.fire({
        icon: 'error',
        title: 'Failed to Submit Review',
        text: error.response?.data?.message || 'Please try again',
        confirmButtonColor: '#000000',
      });
    }
  };

  const handleReviewImageUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files) {
      const filesArray = Array.from(e.target.files);
      
      if (reviewImages.length + filesArray.length > 3) {
        Swal.fire({
          icon: 'warning',
          title: 'Image Limit',
          text: 'You can upload up to 3 images only',
          confirmButtonColor: '#000000',
        });
        return;
      }

      setReviewImages(prev => [...prev, ...filesArray]);
    }
    e.target.value = '';
  };

  const removeReviewImage = (index: number) => {
    setReviewImages(prev => prev.filter((_, i) => i !== index));
  };

  const getStatusColor = (status: RepairStatus) => {
    switch (status) {
      case 'cancelled':
      case 'rejected':
      case 'repairer_rejected':
      case 'owner_rejected':
        return 'text-red-700';
      case 'repairer_accepted':
      case 'waiting_customer_confirmation':
        return 'text-blue-700';
      case 'owner_approval_pending':
        return 'text-blue-700';
      case 'assigned_to_repairer':
      case 'awaiting_parts':
        return 'text-yellow-700';
      case 'in_progress':
        return 'text-purple-700';
      case 'pending':
      case 'received':
      case 'completed':
      case 'ready_for_pickup':
      case 'picked_up':
      case 'owner_approved':
        return 'text-green-700';
      default:
        return 'text-black';
    }
  };

  const getStatusText = (order: RepairOrder) => {
    if (
      order.status === 'repairer_accepted' &&
      Boolean(order.conversation_id) &&
      order.delivery_method !== 'walk_in' &&
      order.payment_status !== 'paid' &&
      Boolean(order.payment_enabled) &&
      !processingPayment
    ) {
      return 'PAY NOW';
    }

    switch (order.status) {
      case 'new_request':
        return 'New Request';
      case 'assigned_to_repairer':
        return 'Assigned to Repairer';
      case 'repairer_accepted':
        return 'Repairer Accepted - Contact for Details';
      case 'waiting_customer_confirmation':
        return 'Confirmed - Work Starting';
      case 'owner_approval_pending':
        return 'Payment Received - Pending Owner Approval';
      case 'owner_approved':
        return 'Approved - Work Starting';
      case 'owner_rejected':
        return 'Rejected by Shop Owner';
      case 'in_progress':
        return '🔧 Work In Progress';
      case 'awaiting_parts':
        return '⏳ Awaiting Parts';
      case 'completed':
        return '✅ Completed - QC Done';
      case 'ready_for_pickup':
        return '📦 Ready for Pickup';
      case 'picked_up':
        return '✅ Completed & Picked Up';
      case 'pending':
        return 'Payment Received - Ready to Start';
      case 'received':
        return 'Shoes Received - Work Starting Soon';
      case 'cancelled':
        return 'Cancelled';
      case 'rejected':
      case 'repairer_rejected':
        return 'Rejected';
      default:
        return order.status;
    }
  };

  const getRepairDuration = (order: RepairOrder) => {
    if (order.duration && order.duration.trim().length > 0) {
      return order.duration;
    }

    const startDate = new Date(order.created_at);
    const endDateValue = order.completed_at || order.estimated_completion;

    if (!endDateValue) {
      return '1-3 days';
    }

    const endDate = new Date(endDateValue);

    if (Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime())) {
      return '1-3 days';
    }

    const millisecondsPerDay = 1000 * 60 * 60 * 24;
    const totalDays = Math.max(1, Math.ceil((endDate.getTime() - startDate.getTime()) / millisecondsPerDay));

    return `${totalDays} day${totalDays > 1 ? 's' : ''}`;
  };

  // Count functions for repair statuses
  const getCountByStatus = (status: RepairTab) => {
    if (status === 'new_request') {
      // NEW REQUEST tab includes both new_request and assigned_to_repairer
      return orders.filter(order => 
        order.status === 'new_request' || order.status === 'assigned_to_repairer'
      ).length;
    }
    if (status === 'pending') {
      // PENDING tab includes pending, repairer_accepted, waiting_customer_confirmation, and owner_approval_pending
      return orders.filter(order => 
        order.status === 'pending' || 
        order.status === 'repairer_accepted' || 
        order.status === 'waiting_customer_confirmation' ||
        order.status === 'owner_approval_pending' ||
        order.status === 'owner_approved'
      ).length;
    }
    return orders.filter(order => order.status === status).length;
  };

  const filteredOrders = orders.filter(order => {
    if (selectedTab === 'new_request') {
      // NEW REQUEST tab shows new_request and assigned_to_repairer
      return order.status === 'new_request' || order.status === 'assigned_to_repairer';
    }
    if (selectedTab === 'pending') {
      // PENDING tab shows all confirmation/approval pending statuses
      return order.status === 'pending' || 
             order.status === 'repairer_accepted' || 
             order.status === 'waiting_customer_confirmation' ||
             order.status === 'owner_approval_pending' ||
             order.status === 'owner_approved';
    }
    return order.status === selectedTab;
  });
  const getRepairPaymentHref = (order: RepairOrder) => {
    const params = new URLSearchParams({
      source: 'repair',
      repair_id: String(order.id),
      order_number: order.order_number,
      repair_type: order.repair_type,
      total: String(order.total_amount),
    });

    return `/payment?${params.toString()}`;
  };

  return (
    <div className="min-h-screen flex flex-col bg-white">
      <Head title="My Repairs" />
      <Navigation />

      <main className="flex-1">
        <div className="max-w-6xl mx-auto py-16 px-6">
          <div className="flex items-center justify-between mb-12">
            <h1 className="text-4xl font-bold text-black">My Repairs</h1>
          </div>

          {/* Tabs */}
          <div className="flex gap-8 mb-12 border-b border-gray-200">
            <button
              onClick={() => setSelectedTab('new_request')}
              className={`pb-4 font-medium text-sm tracking-wide transition-all flex items-center gap-2 ${
                selectedTab === 'new_request'
                  ? 'border-b-2 border-black text-black'
                  : 'text-gray-400 hover:text-gray-600'
              }`}
            >
              NEW REQUEST
              {getCountByStatus('new_request') > 0 && (
                <span className="text-gray-800 px-2 py-0.5 text-xs font-semibold">
                  {getCountByStatus('new_request')}
                </span>
              )}
            </button>
            <button
              onClick={() => setSelectedTab('pending')}
              className={`pb-4 font-medium text-sm tracking-wide transition-all flex items-center gap-2 ${
                selectedTab === 'pending'
                  ? 'border-b-2 border-black text-black'
                  : 'text-gray-400 hover:text-gray-600'
              }`}
            >
              PENDING
              {getCountByStatus('pending') > 0 && (
                <span className="text-gray-800 px-2 py-0.5 text-xs font-semibold">
                  {getCountByStatus('pending')}
                </span>
              )}
            </button>
            <button
              onClick={() => setSelectedTab('received')}
              className={`pb-4 font-medium text-sm tracking-wide transition-all flex items-center gap-2 ${
                selectedTab === 'received'
                  ? 'border-b-2 border-black text-black'
                  : 'text-gray-400 hover:text-gray-600'
              }`}
            >
              RECEIVED
              {getCountByStatus('received') > 0 && (
                <span className="text-gray-800 px-2 py-0.5 text-xs font-semibold">
                  {getCountByStatus('received')}
                </span>
              )}
            </button>
            <button
              onClick={() => setSelectedTab('in_progress')}
              className={`pb-4 font-medium text-sm tracking-wide transition-all flex items-center gap-2 ${
                selectedTab === 'in_progress'
                  ? 'border-b-2 border-black text-black'
                  : 'text-gray-400 hover:text-gray-600'
              }`}
            >
              IN PROGRESS
              {getCountByStatus('in_progress') > 0 && (
                <span className="text-gray-800 px-2 py-0.5 text-xs font-semibold">
                  {getCountByStatus('in_progress')}
                </span>
              )}
            </button>
            <button
              onClick={() => setSelectedTab('ready_for_pickup')}
              className={`pb-4 font-medium text-sm tracking-wide transition-all flex items-center gap-2 ${
                selectedTab === 'ready_for_pickup'
                  ? 'border-b-2 border-black text-black'
                  : 'text-gray-400 hover:text-gray-600'
              }`}
            >
              READY FOR PICKUP
              {getCountByStatus('ready_for_pickup') > 0 && (
                <span className="text-gray-800 px-2 py-0.5 text-xs font-semibold">
                  {getCountByStatus('ready_for_pickup')}
                </span>
              )}
            </button>
            <button
              onClick={() => setSelectedTab('picked_up')}
              className={`pb-4 font-medium text-sm tracking-wide transition-all flex items-center gap-2 ${
                selectedTab === 'picked_up'
                  ? 'border-b-2 border-black text-black'
                  : 'text-gray-400 hover:text-gray-600'
              }`}
            >
              COMPLETED
              {getCountByStatus('picked_up') > 0 && (
                <span className="text-gray-800 px-2 py-0.5 text-xs font-semibold">
                  {getCountByStatus('picked_up')}
                </span>
              )}
            </button>
            <button
              onClick={() => setSelectedTab('cancelled')}
              className={`pb-4 font-medium text-sm tracking-wide transition-all flex items-center gap-2 ${
                selectedTab === 'cancelled'
                  ? 'border-b-2 border-black text-black'
                  : 'text-gray-400 hover:text-gray-600'
              }`}
            >
              CANCELLED
              {getCountByStatus('cancelled') > 0 && (
                <span className="text-gray-800 px-2 py-0.5 text-xs font-semibold">
                  {getCountByStatus('cancelled')}
                </span>
              )}
            </button>
            <button
              onClick={() => setSelectedTab('rejected')}
              className={`pb-4 font-medium text-sm tracking-wide transition-all flex items-center gap-2 ${
                selectedTab === 'rejected'
                  ? 'border-b-2 border-black text-black'
                  : 'text-gray-400 hover:text-gray-600'
              }`}
            >
              REJECTED
              {getCountByStatus('rejected') > 0 && (
                <span className="text-gray-800 px-2 py-0.5 text-xs font-semibold">
                  {getCountByStatus('rejected')}
                </span>
              )}
            </button>
          </div>

          {/* Loading State */}
          {loading && (
            <div className="text-center py-32">
              <div className="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-black"></div>
              <p className="mt-6 text-gray-500 text-sm">Loading your repairs...</p>
            </div>
          )}

          {/* Empty State */}
          {!loading && filteredOrders.length === 0 && (
            <div className="text-center py-20 bg-gray-50 rounded">
              <div className="mb-6">
                <svg className="w-24 h-24 mx-auto text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                </svg>
              </div>
              <h3 className="text-2xl font-semibold text-gray-800 mb-2">No Repairs Yet</h3>
              <p className="text-gray-500 mb-8">Book a repair service to see your repairs here!</p>
              <Link
                href="/repair-services"
                className="inline-block px-8 py-3 bg-black text-white font-medium hover:bg-gray-800 transition-colors"
              >
                Browse Repair Services
              </Link>
            </div>
          )}

          {/* Repair Orders List */}
          {!loading && filteredOrders.length > 0 && (
            <div className="space-y-8">
              {filteredOrders.map((order) => (
                <div
                  key={order.id}
                  className="border border-gray-200 overflow-hidden hover:shadow-lg transition-shadow duration-300"
                >
                  {/* Order Header */}
                  <div className="bg-white px-8 py-5 border-b border-gray-200">
                    <div className="flex flex-wrap items-center justify-between gap-4">
                      <div className="flex items-center gap-8">
                        <div>
                          <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Order Number</p>
                          <p className="font-semibold text-black text-lg">{order.order_number}</p>
                        </div>
                        <div className="h-10 w-px bg-gray-200"></div>
                        <div>
                          <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Order Date</p>
                          <p className="text-sm text-black">
                            {new Date(order.created_at).toLocaleDateString('en-US', {
                              year: 'numeric',
                              month: 'short',
                              day: 'numeric',
                            })}
                          </p>
                        </div>
                      </div>
                      <div className="flex items-center gap-3">
                        <span
                          className={`inline-flex items-center px-4 py-1.5 text-xs font-semibold tracking-wider uppercase ${getStatusColor(
                            order.status
                          )}`}
                        >
                          {getStatusText(order)}
                        </span>
                      </div>
                    </div>
                  </div>

                  {/* Order Details */}
                  <div className="p-8">
                    <div className="flex gap-6">
                      {/* Item Image */}
                      <div className="w-24 h-24 bg-white border border-gray-200 overflow-hidden flex-shrink-0">
                        {order.image ? (
                          <img
                            src={order.image}
                            alt={order.repair_type}
                            className="w-full h-full object-cover"
                          />
                        ) : (
                          <div className="w-full h-full flex items-center justify-center text-gray-300">
                            <svg className="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth={1}
                                d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"
                              />
                            </svg>
                          </div>
                        )}
                      </div>

                      {/* Item Details */}
                      <div className="flex-1">
                        <h3 className="font-bold text-black text-xl mb-2">{order.repair_type}</h3>
                        <p className="text-gray-600 mb-4">{order.description}</p>

                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6">
                          <div>
                            <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Shop Location</p>
                            {order.shop_id ? (
                              <Link
                                href={`/shop-profile/${order.shop_id}`}
                                className="text-sm text-black font-medium underline"
                              >
                                {order.shop_name}
                              </Link>
                            ) : (
                              <p className="text-sm text-black font-medium">{order.shop_name}</p>
                            )}
                            <p className="text-sm text-gray-500">{order.shop_address}</p>
                          </div>
                          <div>
                            <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Duration</p>
                            <p className="text-sm text-black font-medium">{getRepairDuration(order)}</p>
                            <p className="text-xs text-gray-500 mt-1">
                              Note: Duration starts once the status is IN PROGRESS.
                            </p>
                          </div>
                          {order.estimated_completion && (
                            <div>
                              <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">
                                {order.status === 'completed' || order.status === 'picked_up' ? 'Completed On' : 'Estimated Completion'}
                              </p>
                              <p className="text-sm text-black font-medium">
                                {order.completed_at || order.estimated_completion}
                              </p>
                            </div>
                          )}
                        </div>
                      </div>

                    </div>

                    {/* Repair Total */}
                    <div className="mt-8 pt-6 border-t border-gray-200">
                      <div className="flex justify-between items-center">
                        <div>
                          <p className="text-sm text-gray-500 uppercase tracking-wider mb-1">Shop</p>
                          {order.shop_id ? (
                            <Link
                              href={`/shop-profile/${order.shop_id}`}
                              className="font-semibold text-black underline"
                            >
                              {order.shop_name}
                            </Link>
                          ) : (
                            <p className="font-semibold text-black">{order.shop_name}</p>
                          )}
                        </div>
                        <div className="text-right">
                          <p className="text-sm text-gray-500 uppercase tracking-wider mb-1">Repair Total</p>
                          <p className="font-bold text-black text-2xl">₱{order.total_amount.toLocaleString()}</p>
                        </div>
                      </div>
                    </div>

                    {/* Action Buttons */}
                    <div className="mt-8 pt-6 border-t border-gray-200 flex justify-end gap-4">
                      {/* Chat with Repairer and Pay Now - For Pickup/Delivery Only (Not Walk-in) */}
                      {order.status === 'repairer_accepted' && order.conversation_id && order.delivery_method !== 'walk_in' && order.payment_status !== 'paid' && (
                        <>
                          <Link
                            href={`/customer/conversations?conversation_id=${order.conversation_id}`}
                            className="px-6 py-2.5 border border-gray-300 text-gray-700 text-sm font-medium tracking-wide hover:bg-gray-50 transition-colors rounded-md flex items-center gap-2"
                          >
                            <span className="relative inline-flex">
                              <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                              </svg>
                              {getUnreadCountForConversation(order.conversation_id) > 0 && (
                                <span className="absolute -top-2 -right-2 min-w-4 h-4 px-1 rounded-full bg-red-600 text-white text-[10px] font-semibold leading-4 text-center">
                                  {getUnreadCountForConversation(order.conversation_id) > 99 ? '99+' : getUnreadCountForConversation(order.conversation_id)}
                                </span>
                              )}
                            </span>
                            CHAT WITH REPAIRER
                          </Link>
                          <button
                            onClick={() => handlePayNow(order.id)}
                            disabled={!order.payment_enabled || processingPayment}
                            className={`px-6 py-2.5 text-sm font-medium tracking-wide rounded-md flex items-center gap-2 ${
                              order.payment_enabled && !processingPayment
                                ? 'bg-black text-white hover:bg-gray-800 transition-colors cursor-pointer'
                                : 'bg-gray-300 text-gray-500 cursor-not-allowed'
                            }`}
                          >
                            {processingPayment ? 'PROCESSING...' : 'PAY NOW'}
                          </button>
                          <button
                            onClick={() => {
                              setCancelTargetOrderId(order.id);
                              setSelectedReason('');
                              setCancelNote('');
                              setShowCancelModal(true);
                            }}
                            disabled={processingPayment}
                            className="px-6 py-2.5 bg-red-600 text-white text-sm font-medium tracking-wide hover:bg-red-700 transition-colors rounded-md"
                          >
                            CANCEL REQUEST
                          </button>
                        </>
                      )}
                      
                      {/* Walk-in Repairs - Just chat, no need to confirm */}
                      {order.status === 'received' && order.conversation_id && order.delivery_method === 'walk_in' && (
                        <>
                          <Link
                            href={`/customer/conversations?conversation_id=${order.conversation_id}`}
                            className="px-6 py-2.5 border border-gray-300 text-gray-700 text-sm font-medium tracking-wide hover:bg-gray-50 transition-colors rounded-md flex items-center gap-2"
                          >
                            <span className="relative inline-flex">
                              <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                              </svg>
                              {getUnreadCountForConversation(order.conversation_id) > 0 && (
                                <span className="absolute -top-2 -right-2 min-w-4 h-4 px-1 rounded-full bg-red-600 text-white text-[10px] font-semibold leading-4 text-center">
                                  {getUnreadCountForConversation(order.conversation_id) > 99 ? '99+' : getUnreadCountForConversation(order.conversation_id)}
                                </span>
                              )}
                            </span>
                            CHAT WITH REPAIRER
                          </Link>
                          <div className="px-6 py-2.5 bg-green-100 text-green-700 text-sm font-medium tracking-wide rounded-md flex items-center gap-2">
                            <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                              <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                            </svg>
                            SHOES RECEIVED
                          </div>
                        </>
                      )}
                      
                      {order.status === 'pending' && order.payment_status !== 'paid' && (
                        <>
                          <button
                            onClick={() => handlePayNow(order.id)}
                            disabled={!order.payment_enabled || processingPayment}
                            className={`px-6 py-2.5 text-sm font-medium tracking-wide rounded-md flex items-center gap-2 ${
                              order.payment_enabled && !processingPayment
                                ? 'bg-black text-white hover:bg-gray-800 transition-colors cursor-pointer'
                                : 'bg-gray-300 text-gray-500 cursor-not-allowed'
                            }`}
                          >
                            {processingPayment ? 'PROCESSING...' : 'PAY NOW'}
                          </button>
                          <button
                            onClick={() => {
                              setCancelTargetOrderId(order.id);
                              setSelectedReason('');
                              setCancelNote('');
                              setShowCancelModal(true);
                            }}
                            disabled={processingPayment}
                            className="px-6 py-2.5 bg-red-600 text-white text-sm font-medium tracking-wide hover:bg-red-700 transition-colors rounded-md"
                          >
                            CANCEL REPAIR
                          </button>
                        </>
                      )}
                      {order.status === 'ready_for_pickup' && (
                        <button
                          type="button"
                          disabled
                          className="px-6 py-2.5 bg-gray-300 text-gray-500 text-sm font-medium tracking-wide rounded-md cursor-not-allowed"
                        >
                          Received
                        </button>
                      )}
                      {order.status === 'picked_up' && (
                        <>
                          <button
                            onClick={() => {
                              setRefundOrderId(order.id);
                              setRefundStep(1);
                              setRefundReason('');
                              setRefundMedia([]);
                              setRefundMethod('');
                              setRefundNote('');
                              setShowRefundModal(true);
                            }}
                            className="px-6 py-2.5 border border-gray-300 text-gray-700 text-sm font-medium tracking-wide hover:bg-gray-50 transition-colors rounded-md"
                          >
                            REFUND
                          </button>
                          <button
                            onClick={() => openReviewModal(order.id)}
                            className="px-6 py-2.5 bg-black text-white text-sm font-medium tracking-wide hover:bg-gray-800 transition-colors rounded-md"
                          >
                            REVIEW
                          </button>
                        </>
                      )}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
        {showCancelModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center">
            <div className="absolute inset-0 bg-black opacity-40" onClick={() => setShowCancelModal(false)}></div>
            <div className="bg-white rounded-lg shadow-xl z-50 max-w-lg w-full mx-4">
              <div className="px-6 py-4 border-b">
                <h3 className="text-lg font-semibold">Cancel Repair</h3>
                <p className="text-sm text-gray-500">Select a reason for cancelling this repair.</p>
              </div>
              <div className="px-6 py-4">
                <div className="space-y-3">
                  {[
                    'Need to reschedule',
                    'No longer needed',
                    'Found another repair shop',
                    'Price is too high',
                    'Need to change item details',
                    'Shop not responsive',
                    'Other',
                  ].map((r) => (
                    <label key={r} className="flex items-center gap-3">
                      <input
                        type="radio"
                        name="cancel_reason"
                        value={r === 'Other' ? 'other' : r}
                        checked={selectedReason === (r === 'Other' ? 'other' : r)}
                        onChange={(e) => setSelectedReason(e.target.value)}
                        className="form-radio h-4 w-4 text-black"
                      />
                      <span className="text-sm text-gray-700">{r}</span>
                    </label>
                  ))}

                  {selectedReason === 'other' && (
                    <div>
                      <label className="block text-sm text-gray-600 mb-2">Note (optional)</label>
                      <textarea
                        value={cancelNote}
                        onChange={(e) => setCancelNote(e.target.value)}
                        className="w-full border border-gray-200 rounded p-2 text-sm"
                        rows={3}
                        placeholder="Add a note about the reason..."
                      />
                    </div>
                  )}
                </div>
              </div>
              <div className="px-6 py-4 border-t flex justify-end gap-3">
                <button
                  onClick={() => {
                    setShowCancelModal(false);
                    setCancelTargetOrderId(null);
                    setSelectedReason('');
                    setCancelNote('');
                  }}
                  className="px-4 py-2 bg-gray-100 text-gray-700 rounded hover:bg-gray-200"
                >
                  Close
                </button>
                <button
                  onClick={async () => {
                    if (!cancelTargetOrderId) return;
                    if (!selectedReason) {
                      Swal.fire({ icon: 'warning', title: 'Please select a reason', confirmButtonColor: '#000000' });
                      return;
                    }
                    await cancelRepair(cancelTargetOrderId, selectedReason);
                    setShowCancelModal(false);
                    setCancelTargetOrderId(null);
                    setSelectedReason('');
                    setCancelNote('');
                  }}
                  disabled={!selectedReason}
                  className={`px-4 py-2 rounded text-white ${selectedReason ? 'bg-red-600 hover:bg-red-700' : 'bg-red-300 cursor-not-allowed'}`}
                >
                  Cancel Repair
                </button>
              </div>
            </div>
          </div>
        )}
        {showRefundModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black opacity-40" onClick={() => setShowRefundModal(false)}></div>
            <div className="bg-white rounded-lg shadow-xl z-50 max-w-5xl w-full max-h-[90vh] flex flex-col">
              <div className="px-8 py-4 border-b flex-shrink-0">
                <h3 className="text-xl font-semibold">Request Refund {refundStep === 2 && '- Payment Details'}</h3>
                <p className="text-sm text-gray-500 mt-1">
                  {refundStep === 1 ? 'Please provide details for your refund request.' : 'Select your refund method and review details.'}
                </p>
              </div>
              <div className="px-8 py-6 overflow-y-auto flex-1">
                {refundStep === 1 ? (
                  <div className="space-y-6">
                    {/* Reason Selection */}
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-3">
                        Reason for Refund <span className="text-red-500">*</span>
                      </label>
                      <div className="grid grid-cols-2 gap-x-6 gap-y-3">
                        {[
                          'Service not satisfactory',
                          'Repair quality issues',
                          'Damage during repair',
                          'Item not properly repaired',
                          'Unfair pricing',
                          'Changed my mind',
                          'Better service elsewhere',
                          'Other',
                        ].map((r) => (
                          <label key={r} className="flex items-center gap-3">
                            <input
                              type="radio"
                              name="refund_reason"
                              value={r}
                              checked={refundReason === r}
                              onChange={(e) => setRefundReason(e.target.value)}
                              className="form-radio h-4 w-4 text-black flex-shrink-0"
                            />
                            <span className="text-sm text-gray-700">{r}</span>
                          </label>
                        ))}
                      </div>
                    </div>

                    {/* Media Upload (Photos & Videos) */}
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-1">
                        Upload Photos/Videos <span className="text-red-500">*</span>
                        {refundMedia.length > 0 && (
                          <span className="ml-2 text-xs text-gray-500">
                            ({refundMedia.filter(f => !isVideoFile(f)).length}/5 images, {refundMedia.filter(f => isVideoFile(f)).length}/1 video)
                          </span>
                        )}
                      </label>
                      <p className="text-xs text-gray-600 mb-3">
                        <strong>Note:</strong> You must upload 5 images and 1 video to complete your refund request.
                      </p>
                      
                      <div className="grid grid-cols-6 gap-3">
                        {/* Display uploaded media */}
                        {refundMedia.map((file, index) => (
                          <div key={index} className="relative group aspect-square">
                            {isVideoFile(file) ? (
                              <div className="w-full h-full flex flex-col items-center justify-center bg-gray-100 rounded-lg border-2 border-gray-200">
                                <svg className="w-8 h-8 text-gray-600 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                </svg>
                                <p className="text-xs text-gray-500 px-1 text-center truncate w-full">{file.name.split('.')[0]}</p>
                              </div>
                            ) : (
                              <img
                                src={URL.createObjectURL(file)}
                                alt={`Preview ${index + 1}`}
                                className="w-full h-full object-cover rounded-lg border-2 border-gray-200"
                              />
                            )}
                            <button
                              onClick={() => removeMedia(index)}
                              className="absolute top-2 right-2 bg-black bg-opacity-60 text-white rounded-full w-7 h-7 flex items-center justify-center text-lg hover:bg-opacity-80 opacity-0 group-hover:opacity-100 transition-opacity"
                            >
                              ×
                            </button>
                          </div>
                        ))}
                        
                        {/* Add more button */}
                        {refundMedia.length < 6 && (
                          <div className="relative aspect-square">
                            <input
                              type="file"
                              accept="image/*,video/*"
                              multiple
                              onChange={handleMediaUpload}
                              className="hidden"
                              id="media-upload"
                            />
                            <label
                              htmlFor="media-upload"
                              className="flex flex-col items-center justify-center w-full h-full border-2 border-dashed border-gray-300 rounded-lg cursor-pointer hover:border-gray-400 hover:bg-gray-50 transition-all group"
                            >
                              <div className="flex items-center justify-center w-12 h-12 rounded-full bg-gray-100 group-hover:bg-gray-200 transition-colors">
                                <svg className="w-6 h-6 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                                </svg>
                              </div>
                              <p className="text-xs text-gray-500 mt-2">Add Media</p>
                            </label>
                          </div>
                        )}
                      </div>
                    </div>
                  </div>
                ) : (
                  <div className="space-y-6">
                    {/* Refund Amount Display */}
                    <div className="bg-white border border-gray-200 rounded-lg p-6">
                      <h4 className="text-base font-bold mb-4">Refund Summary</h4>
                      <div className="space-y-3">
                        <div className="flex justify-between items-center">
                          <span className="text-sm text-gray-700">Repair Total:</span>
                          <span className="text-sm text-gray-900">₱{orders.find(o => o.id === refundOrderId)?.total_amount.toLocaleString()}</span>
                        </div>
                        <div className="flex justify-between items-center">
                          <span className="text-sm font-bold text-gray-900">Refund Amount:</span>
                          <span className="text-sm font-bold text-green-600">₱{orders.find(o => o.id === refundOrderId)?.total_amount.toLocaleString()}</span>
                        </div>
                      </div>
                    </div>

                    {/* Refund Method Selection */}
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-3">
                        Select Refund Method <span className="text-red-500">*</span>
                      </label>
                      
                      <div className="border border-gray-300 rounded-lg p-6 bg-white">
                        <div className="flex items-center justify-between mb-4">
                          <h4 className="text-base font-normal">Secure Refund Processing</h4>
                          <div className="flex items-center gap-2">
                            <img src="/images/payment-logo/visa.png" alt="Visa" className="h-6" />
                            <img src="/images/payment-logo/MAYA.png" alt="Maya" className="h-6" />
                            <img src="/images/payment-logo/GCASH.png" alt="GCash" className="h-6" />
                          </div>
                        </div>
                        <p className="text-sm text-gray-700 text-center">
                          Your refund will be processed securely through your selected payment method within 2-4 business days after approval.
                        </p>
                      </div>

                      <div className="grid grid-cols-2 gap-3 mt-4">
                        {[
                          { value: 'original_payment', label: 'Original Payment Method' },
                          { value: 'bank_transfer', label: 'Bank Transfer' },
                          { value: 'gcash', label: 'GCash' },
                          { value: 'paymongo', label: 'PayMongo Wallet' },
                        ].map((method) => (
                          <label
                            key={method.value}
                            className={`flex items-center gap-3 p-4 border-2 rounded-lg cursor-pointer transition-all ${
                              refundMethod === method.value
                                ? 'border-black bg-gray-50'
                                : 'border-gray-200 hover:border-gray-400'
                            }`}
                          >
                            <input
                              type="radio"
                              name="refund_method"
                              value={method.value}
                              checked={refundMethod === method.value}
                              onChange={(e) => setRefundMethod(e.target.value)}
                              className="form-radio h-4 w-4 text-black flex-shrink-0"
                            />
                            <span className="text-sm font-medium text-gray-900">{method.label}</span>
                          </label>
                        ))}
                      </div>
                    </div>

                    {/* Additional Note */}
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-3">
                        Additional Note (Optional)
                      </label>
                      <textarea
                        value={refundNote}
                        onChange={(e) => setRefundNote(e.target.value)}
                        className="w-full border-2 border-gray-200 rounded-lg p-3 text-sm focus:border-gray-400 focus:outline-none resize-none"
                        rows={4}
                        placeholder="Add any additional information about your refund request..."
                      />
                    </div>
                  </div>
                )}
              </div>
              <div className="px-8 py-4 border-t flex justify-between gap-3 flex-shrink-0">
                <div>
                  {refundStep === 2 && (
                    <button
                      onClick={() => setRefundStep(1)}
                      className="px-5 py-2.5 bg-gray-100 text-gray-700 rounded hover:bg-gray-200 font-medium"
                    >
                      Back
                    </button>
                  )}
                </div>
                <div className="flex gap-3">
                  <button
                    onClick={() => {
                      setShowRefundModal(false);
                      setRefundOrderId(null);
                      setRefundStep(1);
                      setRefundReason('');
                      setRefundMedia([]);
                      setRefundMethod('');
                      setRefundNote('');
                    }}
                    className="px-5 py-2.5 bg-gray-100 text-gray-700 rounded hover:bg-gray-200 font-medium"
                  >
                    Close
                  </button>
                  {refundStep === 1 ? (
                    <button
                      onClick={() => {
                        if (!refundReason) {
                          Swal.fire({ icon: 'warning', title: 'Please select a reason', confirmButtonColor: '#000000' });
                          return;
                        }
                        if (!isMediaRequirementMet()) {
                          const videos = refundMedia.filter(file => isVideoFile(file));
                          const images = refundMedia.filter(file => !isVideoFile(file));
                          Swal.fire({ 
                            icon: 'warning', 
                            title: 'Invalid Media Upload', 
                            text: `You must upload exactly 5 images and 1 video. Currently uploaded: ${images.length} image(s) and ${videos.length} video(s).`,
                            confirmButtonColor: '#000000' 
                          });
                          return;
                        }
                        setRefundStep(2);
                      }}
                      disabled={!refundReason || !isMediaRequirementMet()}
                      className={`px-5 py-2.5 rounded text-white font-medium ${
                        refundReason && isMediaRequirementMet()
                          ? 'bg-black hover:bg-gray-800'
                          : 'bg-gray-300 cursor-not-allowed'
                      }`}
                    >
                      Next
                    </button>
                  ) : (
                    <button
                      onClick={handleSubmitRefund}
                      disabled={!refundMethod}
                      className={`px-5 py-2.5 rounded text-white font-medium ${
                        refundMethod
                          ? 'bg-black hover:bg-gray-800'
                          : 'bg-gray-300 cursor-not-allowed'
                      }`}
                    >
                      Submit Refund Request
                    </button>
                  )}
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Phase 10D - Review Modal */}
        {showReviewModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center">
            <div className="absolute inset-0 bg-black opacity-40" onClick={() => setShowReviewModal(false)}></div>
            <div className="bg-white rounded-lg shadow-xl z-50 max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
              <div className="px-6 py-4 border-b sticky top-0 bg-white">
                <h3 className="text-lg font-semibold">Write a Review</h3>
                <p className="text-sm text-gray-600 mt-1">Share your experience with this repair service</p>
              </div>

              <div className="px-6 py-4 space-y-6">
                {/* Star Rating */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2">
                    Rating <span className="text-red-500">*</span>
                  </label>
                  <div className="flex gap-2">
                    {[1, 2, 3, 4, 5].map((star) => (
                      <button
                        key={star}
                        type="button"
                        onClick={() => setReviewRating(star)}
                        onMouseEnter={() => setHoveredRating(star)}
                        onMouseLeave={() => setHoveredRating(0)}
                        className="transition-transform hover:scale-110"
                      >
                        <svg
                          className={`w-10 h-10 ${
                            star <= (hoveredRating || reviewRating)
                              ? 'text-yellow-400 fill-yellow-400'
                              : 'text-gray-300'
                          }`}
                          fill="none"
                          stroke="currentColor"
                          viewBox="0 0 24 24"
                        >
                          <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeWidth={2}
                            d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"
                          />
                        </svg>
                      </button>
                    ))}
                  </div>
                  {reviewRating > 0 && (
                    <p className="text-sm text-gray-600 mt-2">
                      {reviewRating === 5 && '⭐ Excellent!'}
                      {reviewRating === 4 && '👍 Very Good!'}
                      {reviewRating === 3 && '👌 Good'}
                      {reviewRating === 2 && '😕 Could be better'}
                      {reviewRating === 1 && '😞 Needs improvement'}
                    </p>
                  )}
                </div>

                {/* Review Text */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2">
                    Your Review (Optional)
                  </label>
                  <textarea
                    value={reviewText}
                    onChange={(e) => setReviewText(e.target.value)}
                    placeholder="Tell us about your experience with the repair service..."
                    maxLength={1000}
                    rows={4}
                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-black resize-none"
                  />
                  <p className="text-xs text-gray-500 mt-1">
                    {reviewText.length}/1000 characters
                  </p>
                </div>

                {/* Image Upload */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2">
                    Photos (Optional)
                  </label>
                  <p className="text-xs text-gray-500 mb-2">Upload up to 3 images of the completed repair</p>
                  
                  <div className="flex flex-wrap gap-3">
                    {reviewImages.map((file, index) => (
                      <div key={index} className="relative group">
                        <img
                          src={URL.createObjectURL(file)}
                          alt={`Review ${index + 1}`}
                          className="w-24 h-24 object-cover rounded-lg"
                        />
                        <button
                          type="button"
                          onClick={() => removeReviewImage(index)}
                          className="absolute -top-2 -right-2 bg-red-500 text-white rounded-full p-1 opacity-0 group-hover:opacity-100 transition-opacity"
                        >
                          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                          </svg>
                        </button>
                      </div>
                    ))}
                    
                    {reviewImages.length < 3 && (
                      <label className="w-24 h-24 border-2 border-dashed border-gray-300 rounded-lg flex items-center justify-center cursor-pointer hover:border-gray-400 transition-colors">
                        <input
                          type="file"
                          accept="image/*"
                          multiple
                          onChange={handleReviewImageUpload}
                          className="hidden"
                        />
                        <svg className="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                        </svg>
                      </label>
                    )}
                  </div>
                </div>
              </div>

              <div className="px-6 py-4 border-t flex justify-end gap-3 sticky bottom-0 bg-white">
                <button
                  onClick={() => setShowReviewModal(false)}
                  className="px-5 py-2.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors"
                >
                  Cancel
                </button>
                <button
                  onClick={submitReview}
                  disabled={reviewRating === 0}
                  className={`px-5 py-2.5 rounded-lg text-white font-medium transition-colors ${
                    reviewRating > 0
                      ? 'bg-black hover:bg-gray-800'
                      : 'bg-gray-300 cursor-not-allowed'
                  }`}
                >
                  Submit Review
                </button>
              </div>
            </div>
          </div>
        )}
      </main>
    </div>
  );
};

export default MyRepairs;
