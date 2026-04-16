import React, { useEffect, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import Swal from '../Shared/UserModal';

const MAX_REFUND_IMAGE_SIZE_BYTES = 20 * 1024 * 1024;
const MAX_REFUND_VIDEO_SIZE_BYTES = 256 * 1024 * 1024;
const REFUND_ALLOWED_IMAGE_MIME_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
const REFUND_ALLOWED_VIDEO_MIME_TYPES = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/webm'];
const REFUND_ALLOWED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
const REFUND_ALLOWED_VIDEO_EXTENSIONS = ['mp4', 'mov', 'avi', 'mkv', 'webm'];
const REFUND_MEDIA_ACCEPT = '.jpg,.jpeg,.png,.webp,.mp4,.mov,.avi,.mkv,.webm';

const getFileExtension = (fileName: string): string => {
  const pieces = fileName.toLowerCase().split('.');
  return pieces.length > 1 ? pieces[pieces.length - 1] : '';
};

const isAllowedRefundImageFile = (file: File): boolean => {
  const mimeType = String(file.type || '').toLowerCase();
  const extension = getFileExtension(file.name);

  if (REFUND_ALLOWED_IMAGE_MIME_TYPES.includes(mimeType)) {
    return true;
  }

  if (mimeType.startsWith('image/')) {
    return REFUND_ALLOWED_IMAGE_EXTENSIONS.includes(extension);
  }

  return REFUND_ALLOWED_IMAGE_EXTENSIONS.includes(extension);
};

const isAllowedRefundVideoFile = (file: File): boolean => {
  const mimeType = String(file.type || '').toLowerCase();
  const extension = getFileExtension(file.name);

  if (REFUND_ALLOWED_VIDEO_MIME_TYPES.includes(mimeType)) {
    return true;
  }

  if (mimeType.startsWith('video/')) {
    return REFUND_ALLOWED_VIDEO_EXTENSIONS.includes(extension);
  }

  return REFUND_ALLOWED_VIDEO_EXTENSIONS.includes(extension);
};

type OrderItem = {
  id: number;
  product_name: string;
  product_slug: string;
  product_image: string;
  price: number;
  quantity: number;
  subtotal: number;
  size?: string;
  color?: string;
};

type Order = {
  id: number;
  order_number: string;
  status: string;
  review_submitted?: boolean;
  payment_status?: string;
  payment_method?: string;
  refund_status?: 'processing' | 'refunded' | null;
  refund_status_note?: string | null;
  total_amount: number;
  shipping_fee?: number;
  vat_amount?: number;
  vat_rate?: number;
  grand_total?: number;
  total_paid?: number;
  created_at: string;
  cancellation_refund_deadline_at?: string | null;
  cancellation_refund_deadline_passed?: boolean;
  cancellation_refund_window_minutes?: number;
  can_cancel?: boolean;
  can_request_refund?: boolean;
  cancellation_reason?: string | null;
  cancellation_note?: string | null;
  cancellation_other_reason_note?: string | null;
  shop_id?: number | null;
  shop_name: string;
  shop_address?: string | null;
  items_count: number;
  items: OrderItem[];
  shipping_address?: string;
  tracking_number?: string;
  carrier_company?: string;
  carrier_name?: string;
  tracking_link?: string;
  eta?: string;
  pickup_enabled?: boolean;
  refund_stage?: {
    id: number;
    status: string;
    reason_code?: string | null;
    reason_note?: string | null;
    other_reason_note?: string | null;
    shop_owner_status: string;
    finance_status: string;
    return_status: string;
    return_source?: string;
    customer_return_tracking_number?: string | null;
    customer_return_carrier?: string | null;
    customer_return_rider_name?: string | null;
    customer_return_rider_phone?: string | null;
    customer_return_tracking_link?: string | null;
    customer_return_shipped_at?: string | null;
    staff_return_tracking_number?: string | null;
    staff_return_carrier?: string | null;
    staff_return_rider_name?: string | null;
    staff_return_rider_phone?: string | null;
    staff_return_tracking_link?: string | null;
    staff_return_shipped_at?: string | null;
    return_arranged_by_staff_at?: string | null;
    return_confirmed_at?: string | null;
    refund_executed_at?: string | null;
    rejection_reason?: string | null;
    can_mark_return_shipped?: boolean;
    is_refunded?: boolean;
  } | null;
};

interface MyOrdersProps {
  orders: Order[];
  [key: string]: unknown;
}

type OrderTab = 'all' | 'pending' | 'processing' | 'shipped' | 'completed' | 'return_refund';

const MyOrders: React.FC = () => {
  const page = usePage();
  const initialOrders = ((page.props as any).orders ?? []) as Order[];
  const [orders, setOrders] = useState<Order[]>(initialOrders || []);
  const [selectedTab, setSelectedTab] = useState<OrderTab>('all');
  const [highlightOrderId, setHighlightOrderId] = useState<number | null>(null);
  const [showCancelModal, setShowCancelModal] = useState(false);
  const [cancelTargetOrderId, setCancelTargetOrderId] = useState<number | null>(null);
  const [cancelTargetOrderItemId, setCancelTargetOrderItemId] = useState<number | null>(null);
  const [selectedReason, setSelectedReason] = useState<string>('');
  const [cancelNote, setCancelNote] = useState<string>('');
  const [cancelOtherReasonNote, setCancelOtherReasonNote] = useState<string>('');
  
  // Refund modal states
  const [showRefundModal, setShowRefundModal] = useState(false);
  const [refundOrderId, setRefundOrderId] = useState<number | null>(null);
  const [refundStep, setRefundStep] = useState<number>(1);
  const [refundReason, setRefundReason] = useState<string>('');
  const [refundMedia, setRefundMedia] = useState<File[]>([]);
  const [refundRequestType, setRefundRequestType] = useState<'full' | 'partial'>('full');
  const [refundLineQtyByItemId, setRefundLineQtyByItemId] = useState<Record<number, number>>({});
  const [refundMethod, setRefundMethod] = useState<string>('original_payment_method');
  const [refundNote, setRefundNote] = useState<string>('');
  const [refundOtherReasonNote, setRefundOtherReasonNote] = useState<string>('');
  const [isSubmittingRefund, setIsSubmittingRefund] = useState(false);
  const [isSubmittingCancel, setIsSubmittingCancel] = useState(false);
  const [showReasonDetailsModal, setShowReasonDetailsModal] = useState(false);
  const [reasonDetailsOrder, setReasonDetailsOrder] = useState<Order | null>(null);
  const [showRefundRejectionModal, setShowRefundRejectionModal] = useState(false);
  const [refundRejectionOrder, setRefundRejectionOrder] = useState<Order | null>(null);

  const isOtherReason = (value?: string | null): boolean => String(value || '').trim().toLowerCase() === 'other';

  const hasReasonDetails = (order: Order): boolean => {
    const hasCancellationDetails = Boolean(
      String(order.cancellation_reason || '').trim()
      || String(order.cancellation_other_reason_note || '').trim()
      || String(order.cancellation_note || '').trim()
    );

    const stage = order.refund_stage;
    const hasRefundDetails = Boolean(
      String(stage?.reason_code || '').trim()
      || String(stage?.other_reason_note || '').trim()
      || String(stage?.reason_note || '').trim()
    );

    return hasCancellationDetails || hasRefundDetails;
  };

  const humanizeReasonCode = (value?: string | null): string => {
    const normalized = String(value || '').trim();
    if (!normalized) return '-';

    return normalized
      .replace(/[_-]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .replace(/\b\w/g, (char) => char.toUpperCase());
  };

  const isReturnRefundOrder = (order: Order): boolean => {
    const refundStatus = String(order.refund_status || '').toLowerCase();
    const paymentStatus = String(order.payment_status || '').toLowerCase();
    const stageStatus = String(order.refund_stage?.status || '').toLowerCase();

    return Boolean(order.refund_stage)
      || refundStatus === 'processing'
      || refundStatus === 'refunded'
      || paymentStatus === 'refunded'
      || ['requested', 'pending_approval', 'processing', 'succeeded', 'rejected'].includes(stageStatus);
  };

  const mapStatusToTab = (status: string): OrderTab => {
    switch (status) {
      case 'pending':
        return 'pending';
      case 'processing':
        return 'processing';
      case 'to_ship':
      case 'shipped':
        return 'shipped';
      case 'completed':
      case 'delivered':
        return 'completed';
      case 'cancelled':
        return 'all';
      default:
        return 'all';
    }
  };

  const mapTabParamToOrderTab = (rawValue: string | null): OrderTab | null => {
    if (!rawValue) return null;

    const value = rawValue.toLowerCase();

    if (value === 'to_pay' || value === 'pay') return 'pending';
    if (value === 'to_ship' || value === 'ship') return 'shipped';
    if (value === 'to_receive' || value === 'receive') return 'shipped';
    if (value === 'to_rate' || value === 'rate') return 'completed';
    if (value === 'return_refund' || value === 'return/refund' || value === 'return' || value === 'refund') return 'return_refund';

    if (value === 'all' || value === 'pending' || value === 'processing' || value === 'shipped' || value === 'completed' || value === 'return_refund') {
      return value;
    }

    return null;
  };

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const requestedTab = params.get('tab') || params.get('status');
    const mappedTab = mapTabParamToOrderTab(requestedTab);

    if (mappedTab) {
      setSelectedTab(mappedTab);
    }
  }, []);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const highlightParam = params.get('highlightOrder') || params.get('highlight');

    if (!highlightParam) {
      return;
    }

    const parsedId = Number(highlightParam);
    if (Number.isNaN(parsedId)) {
      return;
    }

    setHighlightOrderId(parsedId);
  }, []);

  useEffect(() => {
    if (!highlightOrderId || orders.length === 0) {
      return;
    }

    const targetOrder = orders.find((order) => order.id === highlightOrderId);
    if (!targetOrder) {
      return;
    }

    setSelectedTab(isReturnRefundOrder(targetOrder) ? 'return_refund' : mapStatusToTab(targetOrder.status));

    const scrollTimer = window.setTimeout(() => {
      const targetElement = document.querySelector(`[data-order-id="${highlightOrderId}"]`);
      if (targetElement) {
        targetElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }, 200);

    return () => window.clearTimeout(scrollTimer);
  }, [highlightOrderId, orders]);

  // Sync local state with page props when polling reloads data
  useEffect(() => {
    const newOrders = ((page.props as any).orders ?? []) as Order[];
    if (newOrders.length > 0 && JSON.stringify(newOrders) !== JSON.stringify(orders)) {
      setOrders(newOrders);
    }
  }, [page.props]);

  const hasProcessingRefund = orders.some(
    (order) => (order.status === 'cancelled' && order.refund_status === 'processing')
      || ['pending_approval', 'processing', 'requested'].includes(String(order.refund_stage?.status || '').toLowerCase())
  );

  useEffect(() => {
    if (!hasProcessingRefund) {
      return;
    }

    const timer = window.setInterval(() => {
      router.reload({
        only: ['orders'],
      });
    }, 15000);

    return () => window.clearInterval(timer);
  }, [hasProcessingRefund]);

  const confirmDelivery = async (orderId: number) => {
    const result = await Swal.fire({
      title: 'Confirm Order Delivery?',
      text: 'Please confirm that you have received this order in good condition.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, I received it',
      cancelButtonText: 'Not yet',
      confirmButtonColor: '#000000',
      cancelButtonColor: '#6b7280',
      reverseButtons: true,
    });

    if (!result.isConfirmed) return;

    try {
      const response = await fetch('/orders/confirm-delivery', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({ order_id: orderId }),
      });

      if (!response.ok) {
        const data = await response.json();
        throw new Error(data.message || 'Failed to confirm delivery');
      }

      // Update local state
      setOrders(prev => 
        prev.map(order => {
          if (order.id !== orderId) return order;

          const nowPassed = isDeadlinePassed(order);
          return {
            ...order,
            status: 'delivered',
            // Recompute locally so REFUND activates immediately without a full page refresh.
            can_request_refund: !nowPassed,
          };
        })
      );

      Swal.fire({
        icon: 'success',
        title: 'Delivery Confirmed!',
        text: 'Thank you for confirming your order delivery.',
        confirmButtonColor: '#000000',
      });
    } catch (error) {
      console.error('Error confirming delivery:', error);
      Swal.fire({
        icon: 'error',
        title: 'Failed',
        text: error instanceof Error ? error.message : 'Unable to confirm delivery. Please try again.',
        confirmButtonColor: '#000000',
      });
    }
  };

  const cancelOrder = async (
    orderId: number,
    reason?: string,
    note?: string,
    otherReasonNote?: string,
    orderItemId?: number | null,
  ) => {
    if (!reason) {
      Swal.fire({
        icon: 'warning',
        title: 'Please select a reason',
        confirmButtonColor: '#000000',
      });
      return;
    }

    try {
      const response = await fetch('/orders/cancel', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({
          order_id: orderId,
          order_item_id: orderItemId || null,
          reason: reason || null,
          note: note || null,
          other_reason_note: otherReasonNote || null,
        }),
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || 'Failed to cancel order');
      }

      // Update local state
      setOrders(prev =>
        prev.map(order => {
          if (order.id !== orderId) return order;

          const derivedRefundStatus = (() => {
            const status = String(data?.refund_status || '').toLowerCase();
            if (status === 'refunded' || status === 'already_refunded') return 'refunded' as const;
            if (status === 'processing' || status === 'already_processing') return 'processing' as const;
            if (data?.refund_required) return 'processing' as const;
            return order.refund_status ?? null;
          })();

          const derivedRefundNote = derivedRefundStatus
            ? String(data?.message || order.refund_status_note || '')
            : order.refund_status_note;

          if (!orderItemId) {
            return {
              ...order,
              status: 'cancelled',
              refund_status: derivedRefundStatus,
              refund_status_note: derivedRefundNote,
              cancellation_reason: reason || null,
              cancellation_note: note || null,
              cancellation_other_reason_note: otherReasonNote || null,
            };
          }

          const remainingItems = (order.items || []).filter(item => item.id !== orderItemId);
          const updatedTotal = remainingItems.reduce((sum, item) => sum + Number(item.subtotal), 0);

          return {
            ...order,
            items: remainingItems,
            items_count: remainingItems.length,
            total_amount: updatedTotal,
            status: remainingItems.length === 0 ? 'cancelled' : order.status,
            refund_status: remainingItems.length === 0 ? derivedRefundStatus : order.refund_status,
            refund_status_note: remainingItems.length === 0 ? derivedRefundNote : order.refund_status_note,
          };
        })
      );

      Swal.fire({
        icon: 'success',
        title: orderItemId
          ? 'Item Cancelled'
          : data?.refund_required
            ? 'Order Cancelled & Refund Started'
            : 'Order Cancelled',
        text: data.message || 'Your order has been cancelled and inventory has been restored.',
        confirmButtonColor: '#000000',
      });
    } catch (error) {
      console.error('Error cancelling order:', error);
      Swal.fire({
        icon: 'error',
        title: 'Failed',
        text: error instanceof Error ? error.message : 'Unable to cancel order. Please try again.',
        confirmButtonColor: '#000000',
      });
    }
  };

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'refunded':
        return 'rounded-full border border-green-300 bg-green-50 text-green-700';
      case 'refund_processing':
        return 'rounded-full border border-blue-300 bg-blue-50 text-blue-700';
      case 'refund_rejected':
        return 'rounded-full border border-red-300 bg-red-50 text-red-700';
      case 'delivered':
        return 'rounded-full border border-green-300 bg-green-50 text-green-700';
      case 'cancelled':
        return 'rounded-full border border-red-300 bg-red-50 text-red-700';
      case 'pending':
        return 'rounded-full border border-amber-300 bg-amber-50 text-amber-700';
      case 'processing':
        return 'rounded-full border border-blue-300 bg-blue-50 text-blue-700';
      case 'shipped':
      case 'to_ship':
        return 'rounded-full border border-indigo-300 bg-indigo-50 text-indigo-700';
      default:
        return 'rounded-full border border-gray-300 bg-gray-100 text-gray-700';
    }
  };

  const getStatusText = (status: string) => {
    switch (status) {
      case 'refunded':
        return 'Refunded';
      case 'refund_processing':
        return 'Refund Processing';
      case 'refund_rejected':
        return 'Refund Rejected';
      case 'to_ship':
        return 'To Ship';
      case 'shipped':
        return 'Shipped';
      case 'delivered':
        return 'Delivered';
      case 'pending':
        return 'Pending';
      case 'processing':
        return 'Processing';
      case 'cancelled':
        return 'Cancelled';
      default:
        return status;
    }
  };

  // Count functions for order statuses
  const getCountByStatus = (status: OrderTab) => {
    if (status === 'all') return orders.length;
    if (status === 'return_refund') return orders.filter(isReturnRefundOrder).length;
    if (status === 'shipped') return orders.filter(o => !isReturnRefundOrder(o) && (o.status === 'shipped' || o.status === 'to_ship')).length;
    if (status === 'completed') return orders.filter(o => !isReturnRefundOrder(o) && (o.status === 'completed' || o.status === 'delivered')).length;
    return orders.filter(o => !isReturnRefundOrder(o) && o.status === status).length;
  };

  const getTabIcon = (tab: OrderTab) => {
    switch (tab) {
      case 'all':
        return (
          <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
            <path strokeLinecap="round" strokeLinejoin="round" d="M4 6h16M4 12h16M4 18h16" />
          </svg>
        );
      case 'pending':
        return (
          <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
            <path strokeLinecap="round" strokeLinejoin="round" d="M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        );
      case 'processing':
        return (
          <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
            <path strokeLinecap="round" strokeLinejoin="round" d="M3 7h13l4 4v6a2 2 0 01-2 2h-1m-10 0h8m-8 0a2 2 0 11-4 0 2 2 0 014 0zm8 0a2 2 0 104 0 2 2 0 00-4 0z" />
          </svg>
        );
      case 'shipped':
        return (
          <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
            <path strokeLinecap="round" strokeLinejoin="round" d="M3 12h2l2 5h10l2-8H7m0 0L5.5 5H3" />
          </svg>
        );
      case 'completed':
        return (
          <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
            <path strokeLinecap="round" strokeLinejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.02 3.138a1 1 0 00.95.69h3.3c.969 0 1.371 1.24.588 1.81l-2.67 1.94a1 1 0 00-.364 1.118l1.02 3.138c.3.922-.755 1.688-1.54 1.118l-2.67-1.94a1 1 0 00-1.176 0l-2.67 1.94c-.784.57-1.838-.196-1.539-1.118l1.02-3.138a1 1 0 00-.364-1.118l-2.67-1.94c-.784-.57-.38-1.81.588-1.81h3.3a1 1 0 00.95-.69l1.02-3.138z" />
          </svg>
        );
      default:
        return (
          <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8">
            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        );
    }
  };

  const getTabLabel = (tab: OrderTab) => {
    switch (tab) {
      case 'all': return 'All Orders';
      case 'pending': return 'Pending';
      case 'processing': return 'Processing';
      case 'shipped': return 'Shipped';
      case 'completed': return 'Completed';
      case 'return_refund': return 'Return/Refund';
      default: return 'Status';
    }
  };

  const isOnlinePaymentOrder = (order: Order): boolean => {
    const paymentMethod = String(order.payment_method || '').toLowerCase();
    return !['cod', 'cash_on_delivery', 'cash on delivery'].includes(paymentMethod);
  };

  const isShopOwnerRejectedRefund = (order: Order): boolean => {
    return String(order.refund_stage?.shop_owner_status || '').toLowerCase() === 'rejected';
  };

  const getShopOwnerRejectionReason = (order: Order): string => {
    const rejectionReason = String(order.refund_stage?.rejection_reason || '').trim();
    if (rejectionReason) {
      return rejectionReason;
    }

    const fallbackReason = String(order.refund_status_note || '').trim();
    if (fallbackReason) {
      return fallbackReason;
    }

    return 'No rejection note provided.';
  };

  const getRefundStageText = (order: Order): string | null => {
    const stage = order.refund_stage;
    if (!stage) return null;

    const status = String(stage.status || '').toLowerCase();
    const shopOwnerStatus = String(stage.shop_owner_status || '').toLowerCase();
    const financeStatus = String(stage.finance_status || '').toLowerCase();
    const returnStatus = String(stage.return_status || '').toLowerCase();
    const returnSource = String(stage.return_source || 'customer').toLowerCase();

    if (order.payment_status === 'refunded' || stage.is_refunded || status === 'succeeded') return 'Refunded';
    if (status === 'rejected' || shopOwnerStatus === 'rejected' || financeStatus === 'rejected') return 'Refund Rejected';
    if (returnStatus === 'pending_staff_pickup') return 'Staff Pickup Scheduled';
    if (returnStatus === 'pending_customer_shipment') return 'Ship Defective Product';
    if (returnStatus === 'in_transit' && returnSource === 'staff') return 'Picked Up by Staff Rider';
    if (['in_transit', 'received'].includes(returnStatus) && shopOwnerStatus === 'approved' && financeStatus === 'approved') {
      return 'Awaiting Finance Refund Release';
    }
    if (returnStatus === 'in_transit') return 'Return In Transit';
    if (returnStatus === 'received') return 'Returned Item Received';
    if (shopOwnerStatus !== 'approved' || financeStatus !== 'approved') return 'Pending Approval';
    if (status === 'processing') return 'Refund Processing';
    return 'Refund Processing';
  };

  const isOrderRefunded = (order: Order): boolean => {
    const stageStatus = String(order.refund_stage?.status || '').toLowerCase();
    const paymentStatus = String(order.payment_status || '').toLowerCase();

    return (
      paymentStatus === 'refunded'
      || order.refund_status === 'refunded'
      || order.refund_stage?.is_refunded === true
      || stageStatus === 'succeeded'
    );
  };

  const getDisplayStatus = (order: Order): string => {
    if (isOrderRefunded(order)) {
      return 'refunded';
    }

    const refundStageText = getRefundStageText(order);
    if (refundStageText === 'Refund Rejected') {
      return 'refund_rejected';
    }

    if (isReturnRefundOrder(order)) {
      return 'refund_processing';
    }

    return order.status;
  };

  const parseAmount = (value: unknown): number => {
    const parsed = Number.parseFloat(String(value ?? 0).replace(/[^0-9.-]/g, ''));
    return Number.isFinite(parsed) ? parsed : 0;
  };

  const resolveOrderVatAmount = (order: Order): number | null => {
    const rawVatAmount = order.vat_amount as unknown;
    const numericVatAmount = Number(rawVatAmount);
    const hasExplicitVatAmount = rawVatAmount !== undefined && rawVatAmount !== null && Number.isFinite(numericVatAmount);

    if (hasExplicitVatAmount && numericVatAmount >= 0) {
      return numericVatAmount;
    }

    return null;
  };

  const resolveOrderVatRate = (order: Order): number | null => {
    const rawVatRate = Number(order.vat_rate);
    if (Number.isFinite(rawVatRate) && rawVatRate >= 0) {
      return rawVatRate;
    }

    return null;
  };

  const resolveOrderGrandTotal = (order: Order): number => {
    const parsedGrandTotal = parseAmount(order.grand_total);
    if (parsedGrandTotal > 0) {
      return parsedGrandTotal;
    }

    const subtotal = parseAmount(order.total_amount);
    const shipping = parseAmount(order.shipping_fee);
    const vat = resolveOrderVatAmount(order) ?? 0;

    return subtotal + shipping + vat;
  };

  const formatPeso = (value: unknown): string => {
    return `₱${parseAmount(value).toLocaleString()}`;
  };

  const isDeadlinePassed = (order: Order): boolean => {
    if (typeof order.cancellation_refund_deadline_passed === 'boolean') {
      return order.cancellation_refund_deadline_passed;
    }

    if (!order.cancellation_refund_deadline_at) {
      return false;
    }

    const deadlineTime = new Date(order.cancellation_refund_deadline_at).getTime();
    if (!Number.isFinite(deadlineTime)) {
      return false;
    }

    return Date.now() > deadlineTime;
  };

  const canCancelOrder = (order: Order): boolean => {
    if (typeof order.can_cancel === 'boolean') {
      return order.can_cancel;
    }

    return order.status === 'pending' && !isDeadlinePassed(order);
  };

  const canRequestRefund = (order: Order): boolean => {
    const isDeliveredOrCompleted = ['delivered', 'completed'].includes(order.status);
    if (!isDeliveredOrCompleted) {
      return false;
    }

    if (order.review_submitted) {
      return false;
    }

    const refundStatus = String(order.refund_status || '').toLowerCase();
    const refundStageStatus = String(order.refund_stage?.status || '').toLowerCase();
    const paymentStatus = String(order.payment_status || '').toLowerCase();

    const hasExistingRefundFlow = ['processing', 'refunded'].includes(refundStatus)
      || ['requested', 'pending_approval', 'approved', 'processing'].includes(refundStageStatus)
      || paymentStatus === 'refunded';

    if (hasExistingRefundFlow) {
      return false;
    }

    // Always honor local status+deadline so the button enables immediately after delivery confirmation.
    return !isDeadlinePassed(order);
  };

  const formatDeadline = (deadlineIso?: string | null): string => {
    if (!deadlineIso) return 'Not set';

    const deadline = new Date(deadlineIso);
    if (!Number.isFinite(deadline.getTime())) return 'Not set';

    return deadline.toLocaleString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      hour12: true,
    });
  };

  const parseServerDateTime = (value?: string | null): Date | null => {
    const raw = String(value || '').trim();
    if (!raw) return null;

    const normalized = raw.includes('T') ? raw : raw.replace(' ', 'T');
    const hasTimezone = /(Z|[+-]\d{2}:?\d{2})$/i.test(normalized);
    const iso = hasTimezone ? normalized : `${normalized}Z`;
    const parsed = new Date(iso);

    return Number.isFinite(parsed.getTime()) ? parsed : null;
  };

  const formatStaffPickupDateTime = (value?: string | null): string => {
    const parsed = parseServerDateTime(value);
    if (!parsed) return '-';

    return parsed.toLocaleString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      second: '2-digit',
      hour12: true,
      timeZone: 'Asia/Manila',
    });
  };

  const resolveOrderItemColor = (order: Order, item: OrderItem): string => {
    const explicitColor = String(item.color || '').trim();
    if (explicitColor) {
      return explicitColor;
    }

    const sameProductColors = (order.items || [])
      .filter((line) => String(line.product_name || '').trim().toLowerCase() === String(item.product_name || '').trim().toLowerCase())
      .map((line) => String(line.color || '').trim())
      .filter(Boolean);

    const uniqueColors = Array.from(new Set(sameProductColors));
    return uniqueColors.length === 1 ? uniqueColors[0] : '';
  };

  const filteredOrders = selectedTab === 'all' 
    ? orders 
    : orders.filter(order => {
        if (selectedTab === 'return_refund') return isReturnRefundOrder(order);
        if (isReturnRefundOrder(order)) return false;
        if (selectedTab === 'shipped') return order.status === 'to_ship' || order.status === 'shipped';
        if (selectedTab === 'completed') return order.status === 'completed' || order.status === 'delivered';
        return order.status === selectedTab;
      });

  const handleSubmitCancel = async () => {
    if (!cancelTargetOrderId) return;
    if (!selectedReason) {
      Swal.fire({ icon: 'warning', title: 'Please select a reason', confirmButtonColor: '#000000' });
      return;
    }
    if (isOtherReason(selectedReason) && !cancelOtherReasonNote.trim()) {
      Swal.fire({ icon: 'warning', title: 'Other Reason Note is required', confirmButtonColor: '#000000' });
      return;
    }

    setIsSubmittingCancel(true);
    try {
      // perform cancel with reason and optional note
      await cancelOrder(
        cancelTargetOrderId,
        selectedReason,
        cancelNote,
        cancelOtherReasonNote,
        cancelTargetOrderItemId,
      );
      setShowCancelModal(false);
      setCancelTargetOrderId(null);
      setCancelTargetOrderItemId(null);
      setSelectedReason('');
      setCancelNote('');
      setCancelOtherReasonNote('');
    } finally {
      setIsSubmittingCancel(false);
    }
  };

  const handleSubmitRefund = async () => {
    if (!refundOrderId) return;

    const effectiveRequestType = canChooseRefundScope ? refundRequestType : 'full';
    
    if (!refundReason) {
      Swal.fire({ icon: 'warning', title: 'Please select a reason', confirmButtonColor: '#000000' });
      return;
    }
    if (isOtherReason(refundReason) && !refundOtherReasonNote.trim()) {
      Swal.fire({ icon: 'warning', title: 'Other Reason Note is required', confirmButtonColor: '#000000' });
      return;
    }
    
    if (refundMedia.length === 0) {
      Swal.fire({ icon: 'warning', title: 'Please upload at least one photo or video', confirmButtonColor: '#000000' });
      return;
    }

    if (!isPartialRefundSelectionValid) {
      Swal.fire({
        icon: 'warning',
        title: 'Invalid Partial Refund Details',
        text: 'For partial refunds, choose at least one item qty and keep the auto-calculated amount less than the full order total.',
        confirmButtonColor: '#000000',
      });
      return;
    }

    // Show confirmation before submitting
    const result = await Swal.fire({
      title: 'Submit Refund Request?',
      text: 'Your refund will be returned to your original payment method after approval. Please review your details before submitting.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, Submit',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#000000',
      cancelButtonColor: '#6b7280',
      reverseButtons: true,
    });

    if (!result.isConfirmed) return;

    setIsSubmittingRefund(true);
    try {
      const formData = new FormData();
      formData.append('order_id', refundOrderId.toString());
      formData.append('reason', refundReason);
      formData.append('refund_method', refundMethod || 'original_payment_method');
      formData.append('request_type', effectiveRequestType);
      if (effectiveRequestType === 'partial') {
        formData.append('requested_amount', refundAmountToRequest.toFixed(2));
        refundSelectedLines.forEach((line, index) => {
          formData.append(`refund_lines[${index}][order_item_id]`, String(line.order_item_id));
          formData.append(`refund_lines[${index}][requested_qty]`, String(line.requested_qty));
        });
      }
      formData.append('note', refundNote);
      formData.append('other_reason_note', refundOtherReasonNote);
      
      // Append all media files
      refundMedia.forEach((file, index) => {
        formData.append(`media[${index}]`, file);
      });

      const response = await fetch('/orders/request-refund', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: formData,
      });

      const raw = await response.text();
      let data: any = null;

      try {
        data = raw ? JSON.parse(raw) : null;
      } catch {
        data = null;
      }

      if (!response.ok) {
        if (data?.message) {
          throw new Error(data.message);
        }

        if (response.status === 413) {
          throw new Error('Upload is too large. Please compress your video and try again.');
        }

        throw new Error(`Refund request failed (${response.status}). Please try again.`);
      }

      setShowRefundModal(false);
      setRefundOrderId(null);
      setRefundStep(1);
      setRefundReason('');
      setRefundMedia([]);
      setRefundRequestType('full');
      setRefundLineQtyByItemId({});
      setRefundNote('');
      setRefundOtherReasonNote('');

      setOrders((prev) =>
        prev.map((order) =>
          order.id === refundOrderId
            ? {
                ...order,
                refund_status: 'processing',
                refund_status_note: 'Refund request submitted and pending approvals.',
                refund_stage: data?.refund
                  ? {
                      id: Number(data.refund.id || 0),
                      status: String(data.refund.status || 'pending_approval'),
                      reason_code: String(data.refund.reason_code || refundReason),
                      reason_note: data.refund.reason_note ?? [refundReason, refundOtherReasonNote, refundNote].filter(Boolean).join('\n\n'),
                      other_reason_note: data.refund.other_reason_note ?? (refundOtherReasonNote || null),
                      shop_owner_status: String(data.refund.shop_owner_status || 'pending'),
                      finance_status: String(data.refund.finance_status || 'pending'),
                      return_status: String(data.refund.return_status || 'awaiting_approval'),
                    }
                  : order.refund_stage,
              }
            : order
        )
      );

      Swal.fire({
        icon: 'success',
        title: 'Refund Request Submitted',
        text: 'Your refund request has been submitted successfully. Your refund will be returned to your original payment method after approval.',
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
    } finally {
      setIsSubmittingRefund(false);
    }
  };

  const handleMediaUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files) {
      const filesArray = Array.from(e.target.files);
      const invalidFile = filesArray.find(
        (file) => !isAllowedRefundImageFile(file) && !isAllowedRefundVideoFile(file)
      );
      if (invalidFile) {
        Swal.fire({
          icon: 'warning',
          title: 'Invalid File Type',
          text: 'Only JPG, JPEG, PNG, WEBP images and MP4, MOV, AVI, MKV, WEBM videos are allowed.',
          confirmButtonColor: '#000000',
        });
        e.target.value = '';
        return;
      }

      const currentVideos = refundMedia.filter((file) => isAllowedRefundVideoFile(file));
      const currentImages = refundMedia.filter((file) => isAllowedRefundImageFile(file));
      
      const newVideos = filesArray.filter((file) => isAllowedRefundVideoFile(file));
      const newImages = filesArray.filter((file) => isAllowedRefundImageFile(file));
      
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

      const oversizedVideo = newVideos.find(file => file.size > MAX_REFUND_VIDEO_SIZE_BYTES);
      if (oversizedVideo) {
        Swal.fire({
          icon: 'warning',
          title: 'Video Too Large',
          text: 'Refund video must be 256MB or smaller.',
          confirmButtonColor: '#000000',
        });
        e.target.value = '';
        return;
      }

      const oversizedImage = newImages.find(file => file.size > MAX_REFUND_IMAGE_SIZE_BYTES);
      if (oversizedImage) {
        Swal.fire({
          icon: 'warning',
          title: 'Image Too Large',
          text: 'Each refund image must be 20MB or smaller.',
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

  const setRefundLineQty = (itemId: number, requestedQty: number, maxQty: number) => {
    const safeMax = Math.max(0, Number(maxQty || 0));
    const safeQty = Math.min(safeMax, Math.max(0, Number.isFinite(requestedQty) ? Math.floor(requestedQty) : 0));

    setRefundLineQtyByItemId((prev) => ({
      ...prev,
      [itemId]: safeQty,
    }));
  };

  const initializeRefundLineQty = (items: OrderItem[] = []) => {
    const next: Record<number, number> = {};
    items.forEach((item) => {
      next[item.id] = 0;
    });
    setRefundLineQtyByItemId(next);
  };

  const resolveRefundItemUnitPrice = (item: OrderItem): number => {
    const qty = Math.max(1, Number(item.quantity || 1));
    const subtotal = parseAmount(item.subtotal);

    if (subtotal > 0) {
      return subtotal / qty;
    }

    return parseAmount(item.price);
  };

  const isVideoFile = (file: File) => {
    return isAllowedRefundVideoFile(file);
  };

  const isMediaRequirementMet = () => {
    const videos = refundMedia.filter((file) => isAllowedRefundVideoFile(file));
    const images = refundMedia.filter((file) => isAllowedRefundImageFile(file));
    return images.length === 5 && videos.length === 1 && (images.length + videos.length) === refundMedia.length;
  };

  const tabButtonBaseClass =
    'relative inline-flex min-w-[112px] shrink-0 items-center justify-center gap-2 rounded-2xl border px-3 py-2.5 text-[10px] font-semibold uppercase tracking-[0.14em] transition-all duration-300 focus-visible:outline-none focus-visible:ring-2 sm:min-w-[128px] xl:min-w-0 xl:flex-1 xl:rounded-full xl:px-4 xl:text-[11px] xl:tracking-[0.16em]';
  const tabBadgeClass =
    'pointer-events-none absolute -right-1 top-1 z-10 min-w-[20px] h-[20px] rounded-full bg-red-600 flex items-center justify-center text-[8px] font-bold leading-none text-white';
  const actionButtonBaseClass =
    'inline-flex items-center justify-center gap-2 rounded-full border px-3 py-2 text-[10px] font-semibold uppercase tracking-[0.12em] transition-all duration-300 focus-visible:outline-none focus-visible:ring-2 sm:px-6 sm:py-2.5 sm:text-xs sm:tracking-[0.16em]';
  const actionButtonPrimaryClass =
    'border-[#16233b] bg-[#16233b] text-white hover:-translate-y-0.5 hover:bg-black focus-visible:ring-[#16233b]/45';
  const actionButtonSecondaryClass =
    'border-gray-300 bg-white text-gray-800 hover:-translate-y-0.5 hover:border-gray-400 hover:bg-gray-50 focus-visible:ring-gray-300';
  const actionButtonDangerClass =
    'border-red-600 bg-red-600 text-white hover:-translate-y-0.5 hover:bg-red-700 focus-visible:ring-red-300';
  const actionButtonDisabledClass = 'border-gray-300 bg-gray-200 text-gray-500 cursor-not-allowed';
  const refundTargetOrder = refundOrderId ? orders.find((order) => order.id === refundOrderId) : null;
  const refundLineCount = refundTargetOrder
    ? (refundTargetOrder.items || []).length
    : 0;
  const refundTotalUnits = refundTargetOrder
    ? (refundTargetOrder.items || []).reduce((sum, item) => sum + Math.max(0, Number(item.quantity || 0)), 0)
    : 0;
  // Partial refunds are valid when an order has multiple lines (e.g. same product with different color/size)
  // or when a single line contains multiple purchased units.
  const canChooseRefundScope = refundLineCount > 1 || refundTotalUnits > 1;
  const refundTargetOrderTotal = refundTargetOrder ? resolveOrderGrandTotal(refundTargetOrder) : 0;
  const refundSelectedLines = refundTargetOrder
    ? (refundTargetOrder.items || [])
      .map((item) => {
        const requestedQty = Math.max(0, Math.min(
          Math.max(0, Number(item.quantity || 0)),
          Math.floor(Number(refundLineQtyByItemId[item.id] || 0)),
        ));

        if (requestedQty <= 0) {
          return null;
        }

        const unitPrice = resolveRefundItemUnitPrice(item);

        return {
          order_item_id: item.id,
          requested_qty: requestedQty,
          line_amount: unitPrice * requestedQty,
        };
      })
      .filter((line): line is { order_item_id: number; requested_qty: number; line_amount: number } => line !== null)
    : [];
  const refundSelectedItemsTotal = refundSelectedLines.reduce((sum, line) => sum + line.line_amount, 0);
  const effectiveRefundRequestType = canChooseRefundScope ? refundRequestType : 'full';
  const refundAmountToRequest = effectiveRefundRequestType === 'full'
    ? refundTargetOrderTotal
    : Math.min(refundSelectedItemsTotal, refundTargetOrderTotal);
  const isPartialRefundSelectionValid = effectiveRefundRequestType !== 'partial'
    ? true
    : (
      refundSelectedLines.length > 0
      && refundSelectedItemsTotal > 0
      && refundAmountToRequest > 0
      && refundAmountToRequest < refundTargetOrderTotal
    );
  const isRefundSubmissionReady =
    !!refundReason
    && (!isOtherReason(refundReason) || !!refundOtherReasonNote.trim())
    && isMediaRequirementMet()
    && isPartialRefundSelectionValid;
  const mobileHeroFilterButtonBaseClass =
    'relative inline-flex min-w-[96px] shrink-0 flex-col items-center justify-center gap-1.5 overflow-visible rounded-2xl border pl-3 pr-5 py-3 text-[10px] font-semibold tracking-[0.01em] transition-all duration-300 focus-visible:outline-none focus-visible:ring-2';

  return (
    <div className="min-h-screen flex flex-col bg-[#f3f4f6] xl:bg-white">
      <Head title="My Purchases" />
      <Navigation />

      <main className="flex-1">
        <div className="w-full pb-16 pt-24 sm:px-6 xl:pt-32 xl:px-10 2xl:px-14">
          <div className="mb-5 hidden max-w-6xl select-none rounded-3xl border border-gray-200 bg-white px-4 py-5 shadow-[0_14px_40px_-30px_rgba(15,23,42,0.35)] sm:mb-8 sm:px-6 sm:py-7 xl:mb-10 mx-auto xl:rounded-none xl:border-0 xl:bg-transparent xl:px-0 xl:py-0 xl:shadow-none xl:block">
            <h1 className="text-3xl font-extrabold tracking-tight text-[#16233b] sm:text-5xl xl:text-center xl:text-6xl xl:font-bold">My Purchases</h1>
            <p className="max-w-2xl text-xs text-black/55 sm:text-base xl:mx-auto xl:mt-2 xl:text-center">
              Manage deliveries, returns, and refunds with clear real-time order progress.
            </p>
          </div>

          <div className="xl:hidden px-4 pb-2">
            <h1 className="mb-1 text-xl font-extrabold tracking-tight text-[#16233b]">My Purchases</h1>
            <p className="text-xs text-black/55">
              Manage deliveries, returns, and refunds with clear real-time order progress.
            </p>
          </div>

          <div className="flex w-full gap-2 overflow-x-auto pb-3 pl-4 pr-4 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden xl:hidden">
              {(['all', 'pending', 'processing', 'shipped', 'completed', 'return_refund'] as const).map((tab) => (
                <button
                  key={tab}
                  onClick={() => setSelectedTab(tab)}
                  className={`${mobileHeroFilterButtonBaseClass} ${
                    selectedTab === tab
                      ? 'border-[#16233b] bg-[#16233b] text-white shadow-[0_12px_28px_-18px_rgba(22,35,59,0.65)]'
                      : 'border-gray-200 bg-white text-black/70 hover:-translate-y-0.5 hover:border-gray-300 hover:text-black'
                  }`}
                >
                  {getTabIcon(tab)}
                  <span className="leading-none">{getTabLabel(tab)}</span>
                  {getCountByStatus(tab) > 0 && <span className={tabBadgeClass}>{getCountByStatus(tab)}</span>}
                </button>
              ))}
            </div>

          <div className="mx-auto max-w-6xl px-4 xl:px-0 mt-6">
          {/* Tabs */}
          <div className="mb-6 hidden w-full gap-2 overflow-x-auto pb-2 pt-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden xl:mb-12 xl:flex xl:gap-3 xl:pt-2">
            <button
              onClick={() => setSelectedTab('all')}
              className={`${tabButtonBaseClass} ${
                selectedTab === 'all'
                  ? 'border-[#16233b] bg-[#16233b] text-white shadow-[0_12px_28px_-18px_rgba(22,35,59,0.65)]'
                  : 'border-gray-200 bg-white text-black/70 hover:-translate-y-0.5 hover:border-gray-300 hover:text-black'
              }`}
            >
              ALL ORDERS
              {getCountByStatus('all') > 0 && (
                <span className={tabBadgeClass}>
                  {getCountByStatus('all')}
                </span>
              )}
            </button>
            <button
              onClick={() => setSelectedTab('pending')}
              className={`${tabButtonBaseClass} ${
                selectedTab === 'pending'
                  ? 'border-[#16233b] bg-[#16233b] text-white shadow-[0_12px_28px_-18px_rgba(22,35,59,0.65)]'
                  : 'border-gray-200 bg-white text-black/70 hover:-translate-y-0.5 hover:border-gray-300 hover:text-black'
              }`}
            >
              PENDING
              {getCountByStatus('pending') > 0 && (
                <span className={tabBadgeClass}>
                  {getCountByStatus('pending')}
                </span>
              )}
            </button>
            <button
              onClick={() => setSelectedTab('processing')}
              className={`${tabButtonBaseClass} ${
                selectedTab === 'processing'
                  ? 'border-[#16233b] bg-[#16233b] text-white shadow-[0_12px_28px_-18px_rgba(22,35,59,0.65)]'
                  : 'border-gray-200 bg-white text-black/70 hover:-translate-y-0.5 hover:border-gray-300 hover:text-black'
              }`}
            >
              PROCESSING
              {getCountByStatus('processing') > 0 && (
                <span className={tabBadgeClass}>
                  {getCountByStatus('processing')}
                </span>
              )}
            </button>
            <button
              onClick={() => setSelectedTab('shipped')}
              className={`${tabButtonBaseClass} ${
                selectedTab === 'shipped'
                  ? 'border-[#16233b] bg-[#16233b] text-white shadow-[0_12px_28px_-18px_rgba(22,35,59,0.65)]'
                  : 'border-gray-200 bg-white text-black/70 hover:-translate-y-0.5 hover:border-gray-300 hover:text-black'
              }`}
            >
              SHIPPED
              {getCountByStatus('shipped') > 0 && (
                <span className={tabBadgeClass}>
                  {getCountByStatus('shipped')}
                </span>
              )}
            </button>
            <button
              onClick={() => setSelectedTab('completed')}
              className={`${tabButtonBaseClass} ${
                selectedTab === 'completed'
                  ? 'border-[#16233b] bg-[#16233b] text-white shadow-[0_12px_28px_-18px_rgba(22,35,59,0.65)]'
                  : 'border-gray-200 bg-white text-black/70 hover:-translate-y-0.5 hover:border-gray-300 hover:text-black'
              }`}
            >
              COMPLETED
              {getCountByStatus('completed') > 0 && (
                <span className={tabBadgeClass}>
                  {getCountByStatus('completed')}
                </span>
              )}
            </button>
            <button
              onClick={() => setSelectedTab('return_refund')}
              className={`${tabButtonBaseClass} ${
                selectedTab === 'return_refund'
                  ? 'border-[#16233b] bg-[#16233b] text-white shadow-[0_12px_28px_-18px_rgba(22,35,59,0.65)]'
                  : 'border-gray-200 bg-white text-black/70 hover:-translate-y-0.5 hover:border-gray-300 hover:text-black'
              }`}
            >
              RETURN/REFUND
              {getCountByStatus('return_refund') > 0 && (
                <span className={tabBadgeClass}>
                  {getCountByStatus('return_refund')}
                </span>
              )}
            </button>
          </div>

          {/* Orders Display */}
          {filteredOrders.length === 0 ? (
            <div className="rounded-3xl border border-gray-200 bg-white px-5 py-14 text-center shadow-[0_20px_40px_-36px_rgba(15,23,42,0.7)] xl:rounded-none xl:border-0 xl:bg-gray-50 xl:py-20 xl:shadow-none">
              <div className="mb-6">
                <svg className="w-24 h-24 mx-auto text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                </svg>
              </div>
              <h3 className="text-2xl font-semibold text-gray-800 mb-2">No Orders Yet</h3>
              <p className="text-gray-500 mb-8">Start shopping to see your orders here!</p>
              <Link
                href="/products"
                className={`${actionButtonBaseClass} ${actionButtonPrimaryClass}`}
              >
                Browse Products
              </Link>
            </div>
          ) : (
            <div className="space-y-4 sm:space-y-7">
              {filteredOrders.map((order) => {
                  const orderItems = order.items || [];
                  const primaryItem = orderItems[0];
                  const mobileExtraItemsCount = Math.max(orderItems.length - 1, 0);
                  const orderSubtotal = parseAmount(order.total_amount);
                  const orderShipping = parseAmount(order.shipping_fee);
                  const orderVatAmount = resolveOrderVatAmount(order);
                  const orderVatRate = resolveOrderVatRate(order);
                  const orderGrandTotal = resolveOrderGrandTotal(order);
                  const orderTotalPaid = parseAmount(order.total_paid) > 0 ? parseAmount(order.total_paid) : orderGrandTotal;
                  const displayStatus = getDisplayStatus(order);
                  const refundStageText = getRefundStageText(order);
                  const deadlinePassed = isDeadlinePassed(order);
                  const reviewSubmitted = Boolean(order.review_submitted);
                  const canCancel = canCancelOrder(order);
                  const canRefund = canRequestRefund(order);
                  const shouldShowRefundDetailsIcon = displayStatus === 'refund_rejected'
                    && isOnlinePaymentOrder(order)
                    && isShopOwnerRejectedRefund(order)
                    && Boolean(String(order.refund_stage?.rejection_reason || order.refund_status_note || '').trim());

                  return (
                  <div
                    key={order.id}
                    data-order-id={order.id}
                    className={`border overflow-hidden transition-shadow duration-300 rounded-3xl bg-white shadow-[0_12px_35px_-32px_rgba(15,23,42,0.75)] hover:shadow-[0_18px_45px_-30px_rgba(15,23,42,0.65)] xl:rounded-none xl:shadow-none xl:hover:shadow-lg ${
                      highlightOrderId === order.id ? 'border-black bg-gray-50/40 xl:bg-gray-50/30' : 'border-gray-200'
                    }`}
                  >
                    {/* Order Header */}
                    <div className="border-b border-gray-100 bg-linear-to-r from-white via-white to-gray-50 px-3 py-3 sm:px-8 sm:py-5 xl:border-gray-200 xl:bg-white">
                      <div className="flex items-start justify-between gap-3 sm:items-center sm:gap-4">
                        <div className="flex min-w-0 flex-wrap items-center gap-3 sm:gap-8">
                          <div>
                            <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Order Date</p>
                            <p className="text-sm font-medium text-black">
                              {new Date(order.created_at).toLocaleDateString('en-US', {
                                year: 'numeric',
                                month: 'short',
                                day: 'numeric',
                              })}
                            </p>
                          </div>
                          {['delivered', 'completed'].includes(order.status) && (
                            <div>
                              <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Refund Deadline</p>
                              <p className={`text-xs sm:text-sm ${deadlinePassed ? 'text-red-600 font-medium' : 'text-black'}`}>
                                {formatDeadline(order.cancellation_refund_deadline_at)}
                              </p>
                            </div>
                          )}
                        </div>
                        <div className="ml-auto shrink-0 flex items-center">
                          {shouldShowRefundDetailsIcon && (
                            <button
                              type="button"
                              onClick={() => {
                                setRefundRejectionOrder(order);
                                setShowRefundRejectionModal(true);
                              }}
                              title="View rejection note"
                              aria-label="View rejection note"
                              className="mr-3 inline-flex h-8 w-8 items-center justify-center rounded-full border border-red-400 text-red-600 shadow-sm transition-all hover:-translate-y-0.5 hover:border-red-500 hover:text-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-300"
                            >
                              <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v4m0 4h.01M10.29 3.86l-8.17 14.14A1 1 0 003 19h18a1 1 0 00.88-1.5L13.71 3.86a1 1 0 00-1.72 0z" />
                              </svg>
                            </button>
                          )}
                          <span
                            className={`inline-flex items-center px-3 py-1 text-[9px] font-semibold tracking-[0.12em] uppercase sm:px-4 sm:py-1.5 sm:text-xs ${getStatusBadge(
                              displayStatus
                            )}`}
                          >
                            {getStatusText(displayStatus)}
                          </span>
                        </div>
                      </div>
                    </div>

                    {/* Order Item */}
                    <div className="p-3 sm:p-8">
                      {orderItems.length === 0 ? (
                        <p className="text-sm text-gray-500">No items found for this order.</p>
                      ) : (
                        <>
                          <div className="rounded-2xl border border-gray-200 bg-gray-50 p-3 xl:hidden">
                            {primaryItem && (
                              <div className="flex items-start gap-3">
                                <div className="h-14 w-14 shrink-0 overflow-hidden rounded-lg border border-gray-200 bg-white">
                                  {primaryItem.product_image ? (
                                    <img
                                      src={primaryItem.product_image}
                                      alt={primaryItem.product_name}
                                      className="h-full w-full object-cover"
                                    />
                                  ) : (
                                    <div className="flex h-full w-full items-center justify-center text-gray-300">
                                      <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                      </svg>
                                    </div>
                                  )}
                                </div>
                                <div className="min-w-0 grow">
                                  <h3 className="truncate text-sm font-semibold text-black">
                                    {primaryItem.product_slug ? (
                                      <Link href={`/products/${primaryItem.product_slug}`} className="hover:underline">
                                        {primaryItem.product_name}
                                      </Link>
                                    ) : (
                                      primaryItem.product_name
                                    )}
                                  </h3>
                                  <p className="mt-0.5 truncate text-xs text-gray-500">
                                    {primaryItem.size ? `Size: ${primaryItem.size}` : 'Size: -'}
                                    {' • '}
                                    {`Color: ${resolveOrderItemColor(order, primaryItem) || '-'}`}
                                  </p>
                                  <p className="mt-0.5 text-xs text-gray-500">Qty: {primaryItem.quantity}</p>
                                </div>
                                <div className="shrink-0 pl-2 text-right">
                                  <p className="text-xs text-gray-400">Item Total</p>
                                  <p className="text-sm font-semibold text-black">{formatPeso(primaryItem.subtotal)}</p>
                                </div>
                              </div>
                            )}
                            {mobileExtraItemsCount > 0 && (
                              <p className="mt-2 border-t border-gray-200 pt-2 text-xs text-gray-500">
                                +{mobileExtraItemsCount} more item{mobileExtraItemsCount > 1 ? 's' : ''}
                              </p>
                            )}
                          </div>

                          <div className="hidden space-y-3 xl:block">
                            {orderItems.map((item, idx) => {
                              const itemImage = item.product_image || primaryItem?.product_image || '';

                              return (
                                <div
                                  key={item.id ?? idx}
                                  className={`flex items-start gap-3 sm:gap-4 ${idx > 0 ? 'pt-3 border-t border-gray-200' : ''}`}
                                >
                                  <div className="h-16 w-16 overflow-hidden rounded-xl border border-gray-200 bg-white shrink-0 sm:h-20 sm:w-20 sm:rounded-none">
                                    {itemImage ? (
                                      <img
                                        src={itemImage}
                                        alt={item.product_name}
                                        className="w-full h-full object-cover"
                                      />
                                    ) : (
                                      <div className="w-full h-full flex items-center justify-center text-gray-300">
                                        <svg className="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                      </div>
                                    )}
                                  </div>
                                  <div className="grow">
                                    <h3 className="mb-1 text-sm font-semibold text-black sm:text-base">
                                      {item.product_slug ? (
                                        <Link href={`/products/${item.product_slug}`} className="hover:underline">
                                          {item.product_name}
                                        </Link>
                                      ) : (
                                        item.product_name
                                      )}
                                    </h3>
                                    {item.size && <p className="text-xs text-gray-500 sm:text-sm">Size: {item.size}</p>}
                                    <p className="mt-1 text-xs text-gray-500 sm:text-sm">Color: {resolveOrderItemColor(order, item) || '-'}</p>
                                    <p className="mt-1 text-xs text-gray-500 sm:text-sm">Qty: {item.quantity}</p>
                                  </div>
                                </div>
                              );
                            })}
                          </div>
                        </>
                      )}

                      {/* Order Total */}
                      <div className="mt-6 border-t border-gray-200 pt-5 sm:mt-8 sm:pt-6">
                        <div className="flex flex-col gap-6 sm:gap-4 sm:flex-row sm:items-start sm:justify-between">
                          <div className="flex-1">
                            <p className="text-sm text-gray-500 uppercase tracking-wider mb-1">Shop Location</p>
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
                            {order.shop_address && (
                              <p className="max-w-md text-sm text-gray-500">{order.shop_address}</p>
                            )}
                          </div>
                          
                          {/* Mobile: Breakdown on right */}
                          <div className="sm:hidden flex-1 text-right">
                            <div className="ml-auto w-29.5">
                              <div className="mb-2 space-y-1 text-xs text-gray-500">
                                <div className="grid grid-cols-[56px_54px] items-center gap-x-0">
                                  <span className="text-left">Subtotal</span>
                                  <span className="text-right text-gray-700">{formatPeso(orderSubtotal)}</span>
                                </div>
                                <div className="grid grid-cols-[56px_54px] items-center gap-x-0">
                                  <span className="text-left">Shipping</span>
                                  <span className="text-right text-gray-700">{formatPeso(orderShipping)}</span>
                                </div>
                                <div className="grid grid-cols-[56px_54px] items-center gap-x-0">
                                  <span className="text-left">{orderVatRate !== null ? `VAT (${orderVatRate}%)` : 'VAT'}</span>
                                  <span className="text-right text-gray-700">{orderVatAmount !== null ? formatPeso(orderVatAmount) : 'N/A'}</span>
                                </div>
                              </div>
                              <p className="mb-2 text-center text-[11px] text-gray-500 uppercase tracking-[0.16em]">Total Paid</p>
                              <div className="flex items-center justify-center text-black">
                                <span className="text-xl font-extrabold">
                                  {formatPeso(orderTotalPaid)}
                                </span>
                              </div>
                            </div>
                          </div>
                          
                          {/* Desktop: Full breakdown on right */}
                          <div className="hidden sm:block sm:flex-1 sm:text-right">
                            <div className="ml-auto w-29.5">
                              <div className="mb-2 space-y-1 text-xs text-gray-500">
                                <div className="grid grid-cols-[56px_54px] items-center gap-x-0">
                                  <span className="text-left">Subtotal</span>
                                  <span className="text-right text-gray-700">{formatPeso(orderSubtotal)}</span>
                                </div>
                                <div className="grid grid-cols-[56px_54px] items-center gap-x-0">
                                  <span className="text-left">Shipping</span>
                                  <span className="text-right text-gray-700">{formatPeso(orderShipping)}</span>
                                </div>
                                <div className="grid grid-cols-[56px_54px] items-center gap-x-0">
                                  <span className="text-left">{orderVatRate !== null ? `VAT (${orderVatRate}%)` : 'VAT'}</span>
                                  <span className="text-right text-gray-700">{orderVatAmount !== null ? formatPeso(orderVatAmount) : 'N/A'}</span>
                                </div>
                              </div>
                              <p className="mb-2 text-center text-[11px] text-gray-500 uppercase tracking-[0.16em]">Total Paid</p>
                              <div className="flex items-center justify-center text-black">
                                <span className="text-xl font-extrabold">
                                  {formatPeso(orderTotalPaid)}
                                </span>
                              </div>
                            </div>
                          </div>
                        </div>
                      </div>

                      {(() => {
                        const stage = order.refund_stage;
                        const isRefundedOrder = isOrderRefunded(order);
                        const isRefundProcessing = displayStatus === 'refund_processing';
                        const isCancelledRefundOrder = order.status === 'cancelled' && isRefundedOrder;
                        const hasShippingInfo = !isRefundProcessing && !isRefundedOrder && ['shipped', 'to_ship', 'delivered', 'completed'].includes(order.status);
                        const returnStatus = String(stage?.return_status || '').toLowerCase();
                        const returnSource = String(stage?.return_source || 'customer').toLowerCase();
                        const hasStaffPickupDetails = returnSource === 'staff' || returnStatus === 'pending_staff_pickup';
                        const hasStaffPickup = !isCancelledRefundOrder && (isRefundProcessing || hasStaffPickupDetails || (isRefundedOrder && Boolean(stage)));
                        const hasBothDetailSections = hasShippingInfo && hasStaffPickup;

                        if (!hasShippingInfo && !hasStaffPickup) return null;

                        return (
                          <div className="mt-6 border-t border-gray-200 pt-6">
                            <div className="grid grid-cols-1 xl:grid-cols-2 gap-8">
                              {/* Shipping Information */}
                              {hasShippingInfo && (
                                <div className={hasBothDetailSections ? '' : 'xl:col-span-2'}>
                                  <p className="text-sm text-gray-500 uppercase tracking-wider mb-3">Shipping Information</p>
                                  <div className="space-y-3 sm:grid sm:grid-cols-2 sm:gap-y-4 sm:gap-x-10 sm:space-y-0">
                                    <div className="flex items-start justify-between gap-3 sm:block">
                                      <p className="text-xs text-gray-400 uppercase tracking-wider sm:mb-1">Estimated Delivery Date </p>
                                      <p className="text-sm text-black font-medium text-right sm:text-left">{order.eta || '-'}</p>
                                    </div>
                                    <div className="flex items-start justify-between gap-3 sm:block">
                                      <p className="text-xs text-gray-400 uppercase tracking-wider sm:mb-1">Carrier Business </p>
                                      <p className="text-sm text-black font-medium text-right sm:text-left">{order.carrier_company || '-'}</p>
                                    </div>
                                    <div className="flex items-start justify-between gap-3 sm:block">
                                      <p className="text-xs text-gray-400 uppercase tracking-wider sm:mb-1">Carrier Name </p>
                                      <p className="text-sm text-black font-medium text-right sm:text-left">{order.carrier_name || '-'}</p>
                                    </div>
                                    <div className="flex items-start justify-between gap-3 sm:block">
                                      <p className="text-xs text-gray-400 uppercase tracking-wider sm:mb-1">Tracking Number </p>
                                      <p className="text-sm text-black font-medium text-right sm:text-left">{order.tracking_number || '-'}</p>
                                    </div>
                                    <div className="sm:col-span-2">
                                      <div className="flex items-start justify-between gap-3 sm:block">
                                        <p className="text-xs text-gray-400 uppercase tracking-wider sm:mb-1">Tracking Link</p>
                                        {order.tracking_link ? (
                                          <a
                                            href={order.tracking_link}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="max-w-[58%] text-right text-sm text-black underline break-all sm:max-w-none sm:text-left"
                                          >
                                            {order.tracking_link}
                                          </a>
                                        ) : (
                                          <p className="text-right text-sm text-black font-medium sm:text-left">-</p>
                                        )}
                                      </div>
                                    </div>
                                  </div>
                                </div>
                              )}

                              {/* Staff-Arranged Return Pickup */}
                              {hasStaffPickup && (
                                <div className={hasBothDetailSections ? '' : 'xl:col-span-2'}>
                                  <p className="text-sm text-gray-500 uppercase tracking-wider mb-3">Staff-Arranged Return Pickup</p>
                                  <div className="space-y-3 sm:grid sm:grid-cols-2 sm:gap-y-4 sm:gap-x-10 sm:space-y-0">
                                    <div className="flex items-start justify-between gap-3 sm:block">
                                      <p className="text-xs text-gray-400 uppercase tracking-wider sm:mb-1">Pickup Status</p>
                                      <p className="text-sm text-black font-medium text-right sm:text-left">{getRefundStageText(order) || '-'}</p>
                                    </div>
                                    <div className="flex items-start justify-between gap-3 sm:block">
                                      <p className="text-xs text-gray-400 uppercase tracking-wider sm:mb-1">Carrier Company</p>
                                      <p className="text-sm text-black font-medium text-right sm:text-left">{stage?.staff_return_carrier || '-'}</p>
                                    </div>
                                    <div className="flex items-start justify-between gap-3 sm:block">
                                      <p className="text-xs text-gray-400 uppercase tracking-wider sm:mb-1">Rider Name</p>
                                      <p className="text-sm text-black font-medium text-right sm:text-left">{stage?.staff_return_rider_name || '-'}</p>
                                    </div>
                                    <div className="flex items-start justify-between gap-3 sm:block">
                                      <p className="text-xs text-gray-400 uppercase tracking-wider sm:mb-1">Rider Phone</p>
                                      <p className="text-sm text-black font-medium text-right sm:text-left">{stage?.staff_return_rider_phone || '-'}</p>
                                    </div>
                                    <div className="flex items-start justify-between gap-3 sm:block">
                                      <p className="text-xs text-gray-400 uppercase tracking-wider sm:mb-1">Tracking Number</p>
                                      <p className="text-sm text-black font-medium text-right sm:text-left">{stage?.staff_return_tracking_number || '-'}</p>
                                    </div>
                                    <div className="flex items-start justify-between gap-3 sm:block">
                                      <p className="text-xs text-gray-400 uppercase tracking-wider sm:mb-1">Arranged At</p>
                                      <p className="text-sm text-black font-medium text-right sm:text-left">{formatStaffPickupDateTime(stage?.return_arranged_by_staff_at)}</p>
                                    </div>
                                    <div className="sm:col-span-2">
                                      <div className="flex items-start justify-between gap-3 sm:block">
                                        <p className="text-xs text-gray-400 uppercase tracking-wider sm:mb-1">Tracking Link</p>
                                        {stage?.staff_return_tracking_link ? (
                                          <a
                                            href={stage.staff_return_tracking_link}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="max-w-[58%] text-right text-sm text-black underline break-all sm:max-w-none sm:text-left"
                                          >
                                            {stage.staff_return_tracking_link}
                                          </a>
                                        ) : (
                                          <p className="text-right text-sm text-black font-medium sm:text-left">-</p>
                                        )}
                                      </div>
                                    </div>
                                  </div>
                                </div>
                              )}
                            </div>
                          </div>
                        );
                      })()}

                      {/* Order Actions */}
                      <div className="mt-4 flex flex-wrap justify-end gap-2 border-t border-gray-200 pt-4 sm:mt-6 sm:pt-6 sm:gap-3">
                        {order.status === 'pending' && (
                          <div className="w-full flex justify-end">
                            <button
                              disabled={!canCancel}
                              onClick={() => {
                                if (!canCancel) {
                                  return;
                                }
                                setCancelTargetOrderId(order.id);
                                setCancelTargetOrderItemId(null);
                                setSelectedReason('');
                                setCancelNote('');
                                setCancelOtherReasonNote('');
                                setShowCancelModal(true);
                              }}
                              title={canCancel ? 'Cancel this order' : 'Cancellation deadline has passed'}
                              className={`${actionButtonBaseClass} ${canCancel ? actionButtonDangerClass : actionButtonDisabledClass}`}
                            >
                              CANCEL ORDER
                            </button>
                          </div>
                        )}
                        {hasReasonDetails(order) && (
                          <button
                            onClick={() => {
                              setReasonDetailsOrder(order);
                              setShowReasonDetailsModal(true);
                            }}
                            className={`${actionButtonBaseClass} ${actionButtonSecondaryClass}`}
                          >
                            VIEW REASON DETAILS
                          </button>
                        )}
                        {(order.status === 'shipped' || order.status === 'to_ship') && (
                          <button
                            onClick={() => confirmDelivery(order.id)}
                            disabled={!order.pickup_enabled}
                            className={`${actionButtonBaseClass} ${
                              order.pickup_enabled
                                ? actionButtonPrimaryClass
                                : actionButtonDisabledClass
                            }`}
                            title={order.pickup_enabled ? 'Confirm you have received your order' : 'Waiting for shop to activate receive'}
                          >
                            {order.pickup_enabled ? 'RECEIVED' : 'RECEIVED'}
                          </button>
                        )}
                        {['delivered', 'completed'].includes(order.status) && (
                          <>
                            {!order.refund_stage && !reviewSubmitted ? (
                              <button
                                disabled={!canRefund}
                                onClick={() => {
                                  if (!canRefund) {
                                    return;
                                  }
                                  setRefundOrderId(order.id);
                                  setRefundStep(1);
                                  setRefundReason('');
                                  setRefundMedia([]);
                                  setRefundRequestType('full');
                                  initializeRefundLineQty(order.items || []);
                                  setRefundNote('');
                                  setRefundOtherReasonNote('');
                                  setShowRefundModal(true);
                                }}
                                title={canRefund ? 'Request refund' : 'Refund deadline has passed'}
                                className={`${actionButtonBaseClass} ${canRefund ? actionButtonSecondaryClass : actionButtonDisabledClass}`}
                              >
                                REFUND
                              </button>
                            ) : null}
                            {!reviewSubmitted ? (
                              <button
                                onClick={() => {
                                  if (primaryItem?.product_slug) {
                                    router.visit(`/products/${primaryItem.product_slug}#reviews`);
                                  }
                                }}
                                className={`${actionButtonBaseClass} ${actionButtonPrimaryClass}`}
                              >
                                REVIEW
                              </button>
                            ) : null}
                          </>
                        )}
                        {order.status === 'completed' && (
                          <Link
                            href={`/products`}
                            className={`${actionButtonBaseClass} ${actionButtonPrimaryClass}`}
                          >
                            ORDER AGAIN
                          </Link>
                        )}
                      </div>

                      {/* Status Guidance */}
                      {order.status === 'pending' && (
                        <p className="mt-3 text-center text-xs text-gray-500 sm:text-right">
                          You can't cancel the order once it gets processed.
                        </p>
                      )}

                      {['delivered', 'completed'].includes(order.status) && !reviewSubmitted && (
                        <p className={`mt-3 text-xs sm:text-right ${canRefund ? 'text-gray-500' : 'text-red-600 font-medium'}`}>
                          {canRefund
                            ? `You can request a refund until ${formatDeadline(order.cancellation_refund_deadline_at)}.`
                            : `Refund deadline passed on ${formatDeadline(order.cancellation_refund_deadline_at)}.`}
                        </p>
                      )}
                    </div>
                  </div>
                );
                })}
            </div>
          )}
        </div>
        {showCancelModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center">
            <div
              className="absolute inset-0 bg-black opacity-40"
              onClick={() => {
                setShowCancelModal(false);
                setCancelTargetOrderId(null);
                setCancelTargetOrderItemId(null);
                setSelectedReason('');
                setCancelNote('');
                setCancelOtherReasonNote('');
              }}
            ></div>
            <div className="bg-white rounded-lg shadow-xl z-50 max-w-lg w-full mx-4">
              <div className="px-6 py-4 border-b">
                <h3 className="text-lg font-semibold">Cancel Order</h3>
                <p className="text-sm text-gray-500">Select a reason for cancelling this order.</p>
              </div>
              <div className="px-6 py-4">
                <div className="space-y-3">
                  {[
                    'Need to change payment method',
                    'No longer needed',
                    'High delivery costs',
                    'Better price available',
                    'Need to change shipping address',
                    'Seller not responsive to inquiries',
                    'Seller requesting order cancellation',
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
                      <label className="block text-sm text-gray-600 mb-2">Other Reason Note <span className="text-red-500">*</span></label>
                      <textarea
                        value={cancelOtherReasonNote}
                        onChange={(e) => setCancelOtherReasonNote(e.target.value)}
                        className="w-full border border-gray-200 rounded p-2 text-sm"
                        rows={3}
                        placeholder="Please specify your reason..."
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
                    setCancelTargetOrderItemId(null);
                    setSelectedReason('');
                    setCancelNote('');
                    setCancelOtherReasonNote('');
                  }}
                  className={`${actionButtonBaseClass} ${actionButtonSecondaryClass}`}
                >
                  Close
                </button>
                <button
                  onClick={handleSubmitCancel}
                  disabled={!selectedReason || (isOtherReason(selectedReason) && !cancelOtherReasonNote.trim()) || isSubmittingCancel}
                  className={`${actionButtonBaseClass} ${!selectedReason || (isOtherReason(selectedReason) && !cancelOtherReasonNote.trim()) || isSubmittingCancel ? actionButtonDisabledClass : actionButtonDangerClass}`}
                >
                  {isSubmittingCancel ? (
                    <>
                      <svg className="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="2" opacity="0.25" />
                        <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" opacity="0.75" />
                      </svg>
                      Cancelling...
                    </>
                  ) : (
                    'Cancel Order'
                  )}
                </button>
              </div>
            </div>
          </div>
        )}
        {showRefundRejectionModal && refundRejectionOrder && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div
              className="absolute inset-0 bg-black opacity-40"
              onClick={() => {
                setShowRefundRejectionModal(false);
                setRefundRejectionOrder(null);
              }}
            ></div>
            <div className="bg-white rounded-lg shadow-xl z-50 max-w-xl w-full max-h-[90vh] overflow-y-auto">
              <div className="px-6 py-4 border-b">
                <h3 className="text-lg font-semibold">Shop Owner Rejection Note</h3>
                <p className="text-sm text-gray-500">Order #{refundRejectionOrder.order_number}</p>
              </div>
              <div className="px-6 py-5">
                <p className="text-sm font-medium text-gray-500 mb-2">Reason</p>
                <p className="text-sm text-gray-900 whitespace-pre-wrap">{getShopOwnerRejectionReason(refundRejectionOrder)}</p>
              </div>
              <div className="px-6 py-4 border-t flex justify-end">
                <button
                  onClick={() => {
                    setShowRefundRejectionModal(false);
                    setRefundRejectionOrder(null);
                  }}
                  className={`${actionButtonBaseClass} ${actionButtonSecondaryClass}`}
                >
                  Close
                </button>
              </div>
            </div>
          </div>
        )}
        {showReasonDetailsModal && reasonDetailsOrder && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div
              className="absolute inset-0 bg-black opacity-40"
              onClick={() => {
                setShowReasonDetailsModal(false);
                setReasonDetailsOrder(null);
              }}
            ></div>
            <div className="bg-white rounded-lg shadow-xl z-50 max-w-2xl w-full max-h-[90vh] overflow-y-auto">
              <div className="px-6 py-4 border-b">
                <h3 className="text-lg font-semibold">Reason Details</h3>
                <p className="text-sm text-gray-500">Order #{reasonDetailsOrder.order_number}</p>
              </div>
              <div className="px-6 py-4 space-y-6">
                {(String(reasonDetailsOrder.cancellation_reason || '').trim()
                  || String(reasonDetailsOrder.cancellation_other_reason_note || '').trim()
                  || String(reasonDetailsOrder.cancellation_note || '').trim()) && (
                  <section className="border border-gray-200 rounded-lg p-4">
                    <h4 className="text-sm font-semibold text-gray-900 uppercase tracking-wider mb-3">Cancellation Details</h4>
                    <dl className="space-y-2 text-sm">
                      <div>
                        <dt className="text-gray-500">Reason</dt>
                        <dd className="text-gray-900">{reasonDetailsOrder.cancellation_reason || '-'}</dd>
                      </div>
                      <div>
                        <dt className="text-gray-500">Other Reason Note</dt>
                        <dd className="text-gray-900">{reasonDetailsOrder.cancellation_other_reason_note || '-'}</dd>
                      </div>
                      <div>
                        <dt className="text-gray-500">Note</dt>
                        <dd className="text-gray-900 whitespace-pre-wrap">{reasonDetailsOrder.cancellation_note || '-'}</dd>
                      </div>
                    </dl>
                  </section>
                )}

                {(String(reasonDetailsOrder.refund_stage?.reason_code || '').trim()
                  || String(reasonDetailsOrder.refund_stage?.other_reason_note || '').trim()
                  || String(reasonDetailsOrder.refund_stage?.reason_note || '').trim()) && (
                  <section className="border border-gray-200 rounded-lg p-4">
                    <h4 className="text-sm font-semibold text-gray-900 uppercase tracking-wider mb-3">Refund Request Details</h4>
                    <dl className="space-y-2 text-sm">
                      <div>
                        <dt className="text-gray-500">Reason</dt>
                        <dd className="text-gray-900">{humanizeReasonCode(reasonDetailsOrder.refund_stage?.reason_code) || '-'}</dd>
                      </div>
                      <div>
                        <dt className="text-gray-500">Other Reason Note</dt>
                        <dd className="text-gray-900 whitespace-pre-wrap">{reasonDetailsOrder.refund_stage?.other_reason_note || '-'}</dd>
                      </div>
                      <div>
                        <dt className="text-gray-500">Reason Note</dt>
                        <dd className="text-gray-900 whitespace-pre-wrap">{reasonDetailsOrder.refund_stage?.reason_note || '-'}</dd>
                      </div>
                    </dl>
                  </section>
                )}
              </div>
              <div className="px-6 py-4 border-t flex justify-end">
                <button
                  onClick={() => {
                    setShowReasonDetailsModal(false);
                    setReasonDetailsOrder(null);
                  }}
                  className={`${actionButtonBaseClass} ${actionButtonSecondaryClass}`}
                >
                  Close
                </button>
              </div>
            </div>
          </div>
        )}
        {showRefundModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div
              className="absolute inset-0 bg-black opacity-40"
              onClick={() => {
                setShowRefundModal(false);
                setRefundOrderId(null);
                setRefundStep(1);
                setRefundReason('');
                setRefundMedia([]);
                setRefundRequestType('full');
                setRefundLineQtyByItemId({});
                setRefundNote('');
                setRefundOtherReasonNote('');
              }}
            ></div>
            <div className="bg-white rounded-lg shadow-xl z-50 max-w-5xl w-full max-h-[90vh] flex flex-col">
              <div className="px-8 py-4 border-b shrink-0">
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
                          'Product defective or damaged',
                          'Wrong item received',
                          'Item not as described',
                          'Missing parts or accessories',
                          'Quality issues',
                          'Changed my mind',
                          'Better price elsewhere',
                          'Other',
                        ].map((r) => (
                          <label key={r} className="flex items-center gap-3">
                            <input
                              type="radio"
                              name="refund_reason"
                              value={r}
                              checked={refundReason === r}
                              onChange={(e) => setRefundReason(e.target.value)}
                              className="form-radio h-4 w-4 text-black shrink-0"
                            />
                            <span className="text-sm text-gray-700">{r}</span>
                          </label>
                        ))}
                      </div>
                    </div>

                    {isOtherReason(refundReason) && (
                      <div>
                        <label className="block text-sm font-medium text-gray-700 mb-3">
                          Other Reason Note <span className="text-red-500">*</span>
                        </label>
                        <textarea
                          value={refundOtherReasonNote}
                          onChange={(e) => setRefundOtherReasonNote(e.target.value)}
                          className="w-full border-2 border-gray-200 rounded-lg p-3 text-sm focus:border-gray-400 focus:outline-none resize-none"
                          rows={4}
                          placeholder="Please specify your refund reason..."
                        />
                      </div>
                    )}

                    <div className="rounded-lg border border-gray-200 p-4">
                      <label className="block text-sm font-medium text-gray-700 mb-3">
                        Refund Scope <span className="text-red-500">*</span>
                      </label>
                      {canChooseRefundScope ? (
                        <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                          <label className="flex items-center gap-3 rounded-lg border border-gray-200 p-3">
                            <input
                              type="radio"
                              name="refund_scope"
                              value="full"
                              checked={refundRequestType === 'full'}
                              onChange={() => {
                                setRefundRequestType('full');
                              }}
                              className="form-radio h-4 w-4 text-black"
                            />
                            <div>
                              <p className="text-sm font-semibold text-gray-900">Full Refund</p>
                              <p className="text-xs text-gray-500">Refund whole order amount.</p>
                            </div>
                          </label>
                          <label className="flex items-center gap-3 rounded-lg border border-gray-200 p-3">
                            <input
                              type="radio"
                              name="refund_scope"
                              value="partial"
                              checked={refundRequestType === 'partial'}
                              onChange={() => {
                                setRefundRequestType('partial');
                                if (refundTargetOrder && Object.keys(refundLineQtyByItemId).length === 0) {
                                  initializeRefundLineQty(refundTargetOrder.items || []);
                                }
                              }}
                              className="form-radio h-4 w-4 text-black"
                            />
                            <div>
                              <p className="text-sm font-semibold text-gray-900">Partial Refund</p>
                              <p className="text-xs text-gray-500">Pick qty per item. Amount is auto-calculated.</p>
                            </div>
                          </label>
                        </div>
                      ) : (
                        <div className="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-800">
                          Single-unit order detected. Refund scope is automatically set to Full Refund.
                        </div>
                      )}

                      {canChooseRefundScope && refundRequestType === 'partial' && (
                        <div className="mt-4 space-y-4">
                          <div>
                            <p className="mb-2 text-sm font-medium text-gray-700">Affected Item Qty</p>
                            <div className="max-h-48 space-y-2 overflow-y-auto rounded-lg border border-gray-200 p-3">
                              {(refundTargetOrder?.items || []).map((item) => {
                                const maxQty = Math.max(0, Number(item.quantity || 0));
                                const selectedQty = Math.max(0, Math.min(maxQty, Math.floor(Number(refundLineQtyByItemId[item.id] || 0))));
                                const unitPrice = resolveRefundItemUnitPrice(item);

                                return (
                                  <div key={item.id} className="flex items-start justify-between gap-3 rounded-lg border border-gray-100 px-3 py-2">
                                    <div>
                                      <p className="text-sm font-medium text-gray-900">{item.product_name}</p>
                                      <p className="text-xs text-gray-500">Purchased Qty: {item.quantity}</p>
                                      <p className="text-xs text-gray-500">Unit Price: {formatPeso(unitPrice)}</p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                      <div className="w-24">
                                        <label className="mb-1 block text-xs text-gray-500">Refund Qty</label>
                                        <input
                                          type="number"
                                          min={0}
                                          max={maxQty}
                                          step={1}
                                          value={selectedQty}
                                          onChange={(event) => setRefundLineQty(item.id, Number(event.target.value), maxQty)}
                                          aria-label={`Refund quantity for ${item.product_name}`}
                                          title="Refund quantity"
                                          placeholder="0"
                                          className="w-full rounded-lg border border-gray-200 px-2 py-1 text-sm focus:border-gray-400 focus:outline-none"
                                        />
                                      </div>
                                      <p className="w-28 text-right text-sm font-semibold text-gray-900">{formatPeso(unitPrice * selectedQty)}</p>
                                    </div>
                                  </div>
                                );
                              })}
                            </div>
                          </div>

                          <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                              <label className="block text-sm font-medium text-gray-700 mb-2">
                                Selected Item Total
                              </label>
                              <div className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm font-semibold text-gray-900">
                                {formatPeso(refundSelectedItemsTotal)}
                              </div>
                            </div>
                            <div>
                              <label className="block text-sm font-medium text-gray-700 mb-2">
                                Refund Amount (Auto)
                              </label>
                              <div className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm font-semibold text-gray-900">
                                {formatPeso(refundAmountToRequest)}
                              </div>
                              <p className="mt-1 text-xs text-gray-500">
                                Computed from selected qty per line.
                              </p>
                            </div>
                          </div>
                        </div>
                      )}
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
                        <strong>Note:</strong> You must upload 5 images and 1 video. Supported files: JPG, JPEG, PNG, WEBP, MP4, MOV, AVI, MKV, WEBM. Images must be 20MB or smaller; video must be 256MB or smaller.
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
                              accept={REFUND_MEDIA_ACCEPT}
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
                          <span className="text-sm text-gray-700">Order Total:</span>
                          <span className="text-sm text-gray-900">{formatPeso(refundTargetOrderTotal)}</span>
                        </div>
                        {canChooseRefundScope && refundRequestType === 'partial' && (
                          <div className="flex justify-between items-center">
                            <span className="text-sm text-gray-700">Selected Item Total:</span>
                            <span className="text-sm text-gray-900">{formatPeso(refundSelectedItemsTotal)}</span>
                          </div>
                        )}
                        <div className="flex justify-between items-center">
                          <span className="text-sm font-bold text-gray-900">Refund Amount:</span>
                          <span className="text-sm font-bold text-green-600">{formatPeso(refundAmountToRequest)}</span>
                        </div>
                        <div className="flex justify-between items-center">
                          <span className="text-sm text-gray-700">Refund Type:</span>
                          <span className="text-sm text-gray-900 uppercase">{effectiveRefundRequestType}</span>
                        </div>
                      </div>
                    </div>

                    {/* Refund Method - Always Original Payment Method */}
                    <div>
                      <label className="block text-sm font-medium text-gray-700 mb-3">
                        Refund Method
                      </label>
                      
                      <div className="border border-green-300 rounded-lg p-6 bg-green-50">
                        <div className="flex items-center justify-between mb-4">
                          <h4 className="text-base font-semibold text-green-900">Secure Refund to Original Payment Method</h4>
                          <div className="flex items-center gap-2">
                            <img src="/images/payment-logo/visa.png" alt="Visa" className="h-6" />
                            <img src="/images/payment-logo/MAYA.png" alt="Maya" className="h-6" />
                            <img src="/images/payment-logo/GCASH.png" alt="GCash" className="h-6" />
                          </div>
                        </div>
                        <p className="text-sm text-gray-700">
                          <span className="font-semibold">Your refund will be processed securely to the same payment method you used for this order.</span> If you paid with GCash, Maya, or Credit Card, your refund will go back to that account within 2-4 business days after approval.
                        </p>
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
              <div className="px-8 py-4 border-t flex justify-between gap-3 shrink-0">
                <div>
                  {refundStep === 2 && !isSubmittingRefund && (
                    <button
                      onClick={() => setRefundStep(1)}
                      className={`${actionButtonBaseClass} ${actionButtonSecondaryClass}`}
                    >
                      Back
                    </button>
                  )}
                </div>
                <div className="flex gap-3">
                  {!isSubmittingRefund && (
                    <button
                      onClick={() => {
                        setShowRefundModal(false);
                        setRefundOrderId(null);
                        setRefundStep(1);
                        setRefundReason('');
                        setRefundMedia([]);
                        setRefundRequestType('full');
                        setRefundLineQtyByItemId({});
                        setRefundNote('');
                        setRefundOtherReasonNote('');
                      }}
                      className={`${actionButtonBaseClass} ${actionButtonSecondaryClass}`}
                    >
                      Close
                    </button>
                  )}
                  {refundStep === 1 ? (
                    <button
                      onClick={() => {
                        if (!refundReason) {
                          Swal.fire({ icon: 'warning', title: 'Please select a reason', confirmButtonColor: '#000000' });
                          return;
                        }
                        if (isOtherReason(refundReason) && !refundOtherReasonNote.trim()) {
                          Swal.fire({ icon: 'warning', title: 'Other Reason Note is required', confirmButtonColor: '#000000' });
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
                        if (!isPartialRefundSelectionValid) {
                          Swal.fire({
                            icon: 'warning',
                            title: 'Invalid Partial Refund Details',
                            text: 'Select at least one item qty and keep the auto-calculated amount below the full order total.',
                            confirmButtonColor: '#000000',
                          });
                          return;
                        }
                        setRefundStep(2);
                      }}
                      disabled={!isRefundSubmissionReady}
                      className={`${actionButtonBaseClass} ${
                        isRefundSubmissionReady
                          ? actionButtonPrimaryClass
                          : actionButtonDisabledClass
                      }`}
                    >
                      Next
                    </button>
                  ) : (
                    <button
                      onClick={handleSubmitRefund}
                      disabled={!isRefundSubmissionReady || isSubmittingRefund}
                      className={`${actionButtonBaseClass} ${
                        isRefundSubmissionReady && !isSubmittingRefund
                          ? actionButtonPrimaryClass
                          : actionButtonDisabledClass
                      }`}
                    >
                      {isSubmittingRefund ? 'Submitting...' : 'Submit Refund Request'}
                    </button>
                  )}
                </div>
              </div>
            </div>
          </div>
        )}
        </div>
      </main>
    </div>
  );
};

export default MyOrders;
