import React, { useEffect, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import Swal from '../Shared/UserModal';

const MAX_REFUND_IMAGE_SIZE_BYTES = 20 * 1024 * 1024;
const MAX_REFUND_VIDEO_SIZE_BYTES = 256 * 1024 * 1024;

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
  const [refundMethod, setRefundMethod] = useState<string>('original_payment_method');
  const [refundNote, setRefundNote] = useState<string>('');
  const [refundOtherReasonNote, setRefundOtherReasonNote] = useState<string>('');
  const [isSubmittingRefund, setIsSubmittingRefund] = useState(false);
  const [isSubmittingCancel, setIsSubmittingCancel] = useState(false);
  const [showReasonDetailsModal, setShowReasonDetailsModal] = useState(false);
  const [reasonDetailsOrder, setReasonDetailsOrder] = useState<Order | null>(null);

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

  const isOnlinePaymentOrder = (order: Order): boolean => {
    const paymentMethod = String(order.payment_method || '').toLowerCase();
    return !['cod', 'cash_on_delivery', 'cash on delivery'].includes(paymentMethod);
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

    const refundStatus = String(order.refund_status || '').toLowerCase();
    const refundStageStatus = String(order.refund_stage?.status || '').toLowerCase();
    const paymentStatus = String(order.payment_status || '').toLowerCase();

    const hasExistingRefundFlow = Boolean(order.refund_stage)
      || ['processing', 'refunded'].includes(refundStatus)
      || ['requested', 'pending_approval', 'processing', 'succeeded', 'rejected'].includes(refundStageStatus)
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
      timeZoneName: 'short',
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

  const isVideoFile = (file: File) => {
    return file.type.startsWith('video/');
  };

  const isMediaRequirementMet = () => {
    const videos = refundMedia.filter(file => isVideoFile(file));
    const images = refundMedia.filter(file => !isVideoFile(file));
    return images.length === 5 && videos.length === 1;
  };

  const tabButtonBaseClass =
    'relative inline-flex min-w-[132px] shrink-0 items-center justify-center gap-2 rounded-full border px-4 py-2.5 text-[11px] font-semibold uppercase tracking-[0.16em] transition-all duration-300 focus-visible:outline-none focus-visible:ring-2 lg:min-w-0 lg:flex-1';
  const tabBadgeClass =
    'absolute right-1 top-1 min-w-4 h-4 px-1 rounded-full bg-red-600 text-white text-[10px] font-semibold leading-4 text-center';
  const actionButtonBaseClass =
    'inline-flex items-center justify-center gap-2 rounded-full border px-6 py-2.5 text-xs font-semibold uppercase tracking-[0.16em] transition-all duration-300 focus-visible:outline-none focus-visible:ring-2';
  const actionButtonPrimaryClass =
    'border-[#16233b] bg-[#16233b] text-white hover:-translate-y-0.5 hover:bg-black focus-visible:ring-[#16233b]/45';
  const actionButtonSecondaryClass =
    'border-gray-300 bg-white text-gray-800 hover:-translate-y-0.5 hover:border-gray-400 hover:bg-gray-50 focus-visible:ring-gray-300';
  const actionButtonDangerClass =
    'border-red-600 bg-red-600 text-white hover:-translate-y-0.5 hover:bg-red-700 focus-visible:ring-red-300';
  const actionButtonDisabledClass = 'border-gray-300 bg-gray-200 text-gray-500 cursor-not-allowed';
  const refundTargetOrder = refundOrderId ? orders.find((order) => order.id === refundOrderId) : null;

  return (
    <div className="min-h-screen flex flex-col bg-white">
      <Head title="My Purchases" />
      <Navigation />

      <main className="flex-1">
        <div className="w-full px-6 pb-16 pt-28 lg:pt-32 xl:px-10 2xl:px-14">
          <div className="mx-auto mb-10 max-w-6xl select-none text-center">
            <h1 className="text-4xl font-bold tracking-tight text-[#16233b] sm:text-5xl">My Purchases</h1>
            <p className="mx-auto mt-2 max-w-2xl text-sm text-black/55 sm:text-base">
              Manage deliveries, returns, and refunds with clear real-time order progress.
            </p>
          </div>

          {/* Tabs */}
          <div className="mb-12 flex w-full gap-3 overflow-x-auto pb-2 pt-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
            <button
              onClick={() => setSelectedTab('all')}
              className={`${tabButtonBaseClass} ${
                selectedTab === 'all'
                  ? 'border-[#16233b] bg-[#16233b] text-white shadow-[0_12px_28px_-18px_rgba(22,35,59,0.65)]'
                  : 'border-gray-300 bg-white text-black/70 hover:-translate-y-0.5 hover:border-gray-400 hover:text-black'
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
                  : 'border-gray-300 bg-white text-black/70 hover:-translate-y-0.5 hover:border-gray-400 hover:text-black'
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
                  : 'border-gray-300 bg-white text-black/70 hover:-translate-y-0.5 hover:border-gray-400 hover:text-black'
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
                  : 'border-gray-300 bg-white text-black/70 hover:-translate-y-0.5 hover:border-gray-400 hover:text-black'
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
                  : 'border-gray-300 bg-white text-black/70 hover:-translate-y-0.5 hover:border-gray-400 hover:text-black'
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
                  : 'border-gray-300 bg-white text-black/70 hover:-translate-y-0.5 hover:border-gray-400 hover:text-black'
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

          <div className="mx-auto max-w-6xl">

          {/* Orders Display */}
          {filteredOrders.length === 0 ? (
            <div className="text-center py-20 bg-gray-50 rounded">
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
            <div className="space-y-8">
              {filteredOrders.map((order) => {
                  const orderItems = order.items || [];
                  const primaryItem = orderItems[0];
                  const orderSubtotal = parseAmount(order.total_amount);
                  const orderShipping = parseAmount(order.shipping_fee);
                  const orderVatAmount = resolveOrderVatAmount(order);
                  const orderVatRate = resolveOrderVatRate(order);
                  const orderGrandTotal = resolveOrderGrandTotal(order);
                  const orderTotalPaid = parseAmount(order.total_paid) > 0 ? parseAmount(order.total_paid) : orderGrandTotal;
                  const displayStatus = getDisplayStatus(order);
                  const refundStageText = getRefundStageText(order);
                  const deadlinePassed = isDeadlinePassed(order);
                  const canCancel = canCancelOrder(order);
                  const canRefund = canRequestRefund(order);
                  const shouldShowRefundDetails = displayStatus === 'refund_rejected'
                    && isOnlinePaymentOrder(order)
                    && Boolean(order.refund_stage?.rejection_reason || order.refund_status_note);

                  return (
                  <div
                    key={order.id}
                    data-order-id={order.id}
                    className={`border overflow-hidden hover:shadow-lg transition-shadow duration-300 ${
                      highlightOrderId === order.id ? 'border-black bg-gray-50/30' : 'border-gray-200'
                    }`}
                  >
                    {/* Order Header */}
                    <div className="bg-white px-8 py-5 border-b border-gray-200">
                      <div className="flex flex-wrap items-center justify-between gap-4">
                        <div className="flex items-center gap-8">
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
                          {['delivered', 'completed'].includes(order.status) && (
                            <div>
                              <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Refund Deadline</p>
                              <p className={`text-sm ${deadlinePassed ? 'text-red-600 font-medium' : 'text-black'}`}>
                                {formatDeadline(order.cancellation_refund_deadline_at)}
                              </p>
                            </div>
                          )}
                        </div>
                        <div>
                          <span
                            className={`inline-flex items-center px-4 py-1.5 text-xs font-semibold tracking-wider uppercase ${getStatusBadge(
                              displayStatus
                            )}`}
                          >
                            {getStatusText(displayStatus)}
                          </span>
                          {shouldShowRefundDetails && (
                            <p className="mt-2 text-right text-xs text-gray-500">
                              {order.refund_stage?.rejection_reason || order.refund_status_note || refundStageText}
                            </p>
                          )}
                        </div>
                      </div>
                    </div>

                    {/* Order Item */}
                    <div className="p-8">
                      {orderItems.length === 0 ? (
                        <p className="text-sm text-gray-500">No items found for this order.</p>
                      ) : (
                        <div className="space-y-3">
                          {orderItems.map((item, idx) => {
                            const itemImage = item.product_image || primaryItem?.product_image || '';

                            return (
                              <div
                                key={item.id ?? idx}
                                className={`flex items-start gap-4 ${idx > 0 ? 'pt-3 border-t border-gray-200' : ''}`}
                              >
                                <div className="w-20 h-20 bg-white border border-gray-200 overflow-hidden shrink-0">
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
                                  <h3 className="font-semibold text-black text-base mb-1">
                                    {item.product_slug ? (
                                      <Link href={`/products/${item.product_slug}`} className="hover:underline">
                                        {item.product_name}
                                      </Link>
                                    ) : (
                                      item.product_name
                                    )}
                                  </h3>
                                  {item.size && <p className="text-sm text-gray-500">Size: {item.size}</p>}
                                  <p className="text-sm text-gray-500 mt-1">Color: {resolveOrderItemColor(order, item) || '-'}</p>
                                  <p className="text-sm text-gray-500 mt-1">Qty: {item.quantity}</p>
                                </div>
                              </div>
                            );
                          })}
                        </div>
                      )}

                      {/* Order Total */}
                      <div className="mt-8 pt-6 border-t border-gray-200">
                        <div className="flex justify-between items-center">
                          <div>
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
                              <p className="text-sm text-gray-500">{order.shop_address}</p>
                            )}
                          </div>
                          <div className="text-right">
                            <div className="space-y-1 mb-2 text-xs text-gray-500">
                              <div className="flex items-center justify-end gap-3">
                                <span>Subtotal</span>
                                <span className="text-gray-700">{formatPeso(orderSubtotal)}</span>
                              </div>
                              <div className="flex items-center justify-end gap-3">
                                <span>Shipping</span>
                                <span className="text-gray-700">{formatPeso(orderShipping)}</span>
                              </div>
                              <div className="flex items-center justify-end gap-3">
                                <span>{orderVatRate !== null ? `VAT (${orderVatRate}%)` : 'VAT'}</span>
                                <span className="text-gray-700">{orderVatAmount !== null ? formatPeso(orderVatAmount) : 'N/A'}</span>
                              </div>
                            </div>
                            <p className="text-sm text-gray-500 uppercase tracking-wider mb-2">Total Paid</p>
                            <div className="flex items-center justify-end text-black">
                              <span className="font-semibold text-lg">
                                {formatPeso(orderTotalPaid)}
                              </span>
                            </div>
                          </div>
                        </div>
                      </div>

                      {['shipped', 'to_ship', 'delivered', 'completed'].includes(order.status) && (
                        <div className="mt-6 pt-6 border-t border-gray-200">
                          <p className="text-sm text-gray-500 uppercase tracking-wider mb-3">Shipping Information</p>
                          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                              <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Estimated Delivery Date </p>
                              <p className="text-sm text-black font-medium">{order.eta || '-'}</p>
                            </div>
                            <div>
                              <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Carrier Business </p>
                              <p className="text-sm text-black font-medium">{order.carrier_company || '-'}</p>
                            </div>
                            <div>
                              <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Carrier Name </p>
                              <p className="text-sm text-black font-medium">{order.carrier_name || '-'}</p>
                            </div>
                            <div>
                              <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Tracking Number </p>
                              <p className="text-sm text-black font-medium">{order.tracking_number || '-'}</p>
                            </div>
                            <div className="md:col-span-2">
                              <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Tracking Link</p>
                              {order.tracking_link ? (
                                <a
                                  href={order.tracking_link}
                                  target="_blank"
                                  rel="noreferrer"
                                  className="text-sm text-black underline break-all"
                                >
                                  {order.tracking_link}
                                </a>
                              ) : (
                                <p className="text-sm text-black font-medium">-</p>
                              )}
                            </div>
                          </div>
                        </div>
                      )}

                      {(() => {
                        const stage = order.refund_stage;
                        if (!stage) return null;

                        const returnStatus = String(stage.return_status || '').toLowerCase();
                        const returnSource = String(stage.return_source || 'customer').toLowerCase();
                        const hasStaffPickupDetails = returnSource === 'staff' || returnStatus === 'pending_staff_pickup';

                        if (!hasStaffPickupDetails) return null;

                        return (
                          <div className="mt-6 pt-6 border-t border-gray-200">
                            <p className="text-sm text-gray-500 uppercase tracking-wider mb-3">Staff-Arranged Return Pickup</p>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                              <div>
                                <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Pickup Status</p>
                                <p className="text-sm text-black font-medium">{getRefundStageText(order) || '-'}</p>
                              </div>
                              <div>
                                <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Carrier Company</p>
                                <p className="text-sm text-black font-medium">{stage.staff_return_carrier || '-'}</p>
                              </div>
                              <div>
                                <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Rider Name</p>
                                <p className="text-sm text-black font-medium">{stage.staff_return_rider_name || '-'}</p>
                              </div>
                              <div>
                                <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Rider Phone</p>
                                <p className="text-sm text-black font-medium">{stage.staff_return_rider_phone || '-'}</p>
                              </div>
                              <div>
                                <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Tracking Number</p>
                                <p className="text-sm text-black font-medium">{stage.staff_return_tracking_number || '-'}</p>
                              </div>
                              <div>
                                <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Arranged At</p>
                                <p className="text-sm text-black font-medium">{stage.return_arranged_by_staff_at ? new Date(stage.return_arranged_by_staff_at).toLocaleString() : '-'}</p>
                              </div>
                              <div className="md:col-span-2">
                                <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Tracking Link</p>
                                {stage.staff_return_tracking_link ? (
                                  <a
                                    href={stage.staff_return_tracking_link}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="text-sm text-black underline break-all"
                                  >
                                    {stage.staff_return_tracking_link}
                                  </a>
                                ) : (
                                  <p className="text-sm text-black font-medium">-</p>
                                )}
                              </div>
                            </div>
                          </div>
                        );
                      })()}

                      {/* Order Actions */}
                      <div className="mt-6 pt-6 border-t border-gray-200 flex justify-end gap-4">
                        {order.status === 'pending' && (
                          <>
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
                          </>
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
                            {!order.refund_stage ? (
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
                                  setRefundNote('');
                                  setRefundOtherReasonNote('');
                                  setShowRefundModal(true);
                                }}
                                title={canRefund ? 'Request refund' : 'Refund deadline has passed'}
                                className={`${actionButtonBaseClass} ${canRefund ? actionButtonSecondaryClass : actionButtonDisabledClass}`}
                              >
                                REFUND
                              </button>
                            ) : (
                              <button
                                disabled
                                className={`${actionButtonBaseClass} ${actionButtonDisabledClass}`}
                              >
                                {getRefundStageText(order) || 'REFUND PROCESSING'}
                              </button>
                            )}
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
                        <p className="mt-3 text-xs text-gray-500 text-right">
                          You can't cancel the order once it gets processed.
                        </p>
                      )}

                      {['delivered', 'completed'].includes(order.status) && (
                        <p className={`mt-3 text-xs text-right ${canRefund ? 'text-gray-500' : 'text-red-600 font-medium'}`}>
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
                        <strong>Note:</strong> You must upload 5 images and 1 video. Images must be 20MB or smaller; video must be 256MB or smaller.
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
                          <span className="text-sm text-gray-700">Order Total:</span>
                          <span className="text-sm text-gray-900">{refundTargetOrder ? formatPeso(resolveOrderGrandTotal(refundTargetOrder)) : formatPeso(0)}</span>
                        </div>
                        <div className="flex justify-between items-center">
                          <span className="text-sm font-bold text-gray-900">Refund Amount:</span>
                          <span className="text-sm font-bold text-green-600">{refundTargetOrder ? formatPeso(resolveOrderGrandTotal(refundTargetOrder)) : formatPeso(0)}</span>
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
                        setRefundStep(2);
                      }}
                      disabled={!refundReason || (isOtherReason(refundReason) && !refundOtherReasonNote.trim()) || !isMediaRequirementMet()}
                      className={`${actionButtonBaseClass} ${
                        refundReason && (!isOtherReason(refundReason) || !!refundOtherReasonNote.trim()) && isMediaRequirementMet()
                          ? actionButtonPrimaryClass
                          : actionButtonDisabledClass
                      }`}
                    >
                      Next
                    </button>
                  ) : (
                    <button
                      onClick={handleSubmitRefund}
                      disabled={!refundReason || (isOtherReason(refundReason) && !refundOtherReasonNote.trim()) || !isMediaRequirementMet() || isSubmittingRefund}
                      className={`${actionButtonBaseClass} ${
                        refundReason && (!isOtherReason(refundReason) || !!refundOtherReasonNote.trim()) && isMediaRequirementMet() && !isSubmittingRefund
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
