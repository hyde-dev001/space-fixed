import React, { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import Navigation from './Navigation';
import Swal from 'sweetalert2';

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
  total_amount: number;
  created_at: string;
  shop_name: string;
  items_count: number;
  items: OrderItem[];
  shipping_address?: string;
  tracking_number?: string;
  carrier_company?: string;
  tracking_link?: string;
  eta?: string;
};

interface MyOrdersProps {
  orders: Order[];
}

const MyOrders: React.FC = () => {
  const { orders: initialOrders } = usePage<MyOrdersProps>().props;
  const [orders, setOrders] = useState<Order[]>(initialOrders || []);
  const [selectedTab, setSelectedTab] = useState<'all' | 'pending' | 'processing' | 'shipped' | 'completed' | 'cancelled'>('all');
  const [showCancelModal, setShowCancelModal] = useState(false);
  const [cancelTargetOrderId, setCancelTargetOrderId] = useState<number | null>(null);
  const [selectedReason, setSelectedReason] = useState<string>('');
  const [cancelNote, setCancelNote] = useState<string>('');

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
        prev.map(order => 
          order.id === orderId 
            ? { ...order, status: 'delivered' } 
            : order
        )
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

  const cancelOrder = async (orderId: number, reason?: string, note?: string) => {
    // If no reason provided, fall back to a simple confirmation (backwards-compatible)
    if (!reason) {
      const result = await Swal.fire({
        title: 'Cancel Order?',
        text: 'Are you sure you want to cancel this order? Inventory will be restored.',
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
      const response = await fetch('/orders/cancel', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({ order_id: orderId, reason: reason || null, note: note || null }),
      });

      if (!response.ok) {
        const data = await response.json();
        throw new Error(data.message || 'Failed to cancel order');
      }

      // Update local state
      setOrders(prev => 
        prev.map(order => 
          order.id === orderId 
            ? { ...order, status: 'cancelled' } 
            : order
        )
      );

      Swal.fire({
        icon: 'success',
        title: 'Order Cancelled',
        text: 'Your order has been cancelled and inventory has been restored.',
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
      case 'pending':
        return 'bg-yellow-50 text-yellow-700 border border-yellow-200';
      case 'processing':
        return 'bg-blue-50 text-blue-700 border border-blue-200';
      case 'shipped':
      case 'to_ship':
        return 'bg-purple-50 text-purple-700 border border-purple-200';
      case 'completed':
      case 'delivered':
        return 'bg-green-50 text-green-700 border border-green-200';
      case 'cancelled':
        return 'bg-red-50 text-red-700 border border-red-200';
      default:
        return 'bg-gray-50 text-gray-700 border border-gray-200';
    }
  };

  const getStatusText = (status: string) => {
    switch (status) {
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
  const getCountByStatus = (status: string) => {
    if (status === 'all') return orders.length;
    if (status === 'shipped') return orders.filter(o => o.status === 'shipped' || o.status === 'to_ship').length;
    return orders.filter(o => o.status === status).length;
  };

  const filteredOrders = selectedTab === 'all' 
    ? orders 
    : orders.filter(order => {
        if (selectedTab === 'to_ship') return order.status === 'to_ship' || order.status === 'pending' || order.status === 'processing';
        return order.status === selectedTab;
      });

  const handleSubmitCancel = async () => {
    if (!cancelTargetOrderId) return;
    if (!selectedReason) {
      Swal.fire({ icon: 'warning', title: 'Please select a reason', confirmButtonColor: '#000000' });
      return;
    }

    // perform cancel with reason and optional note
    await cancelOrder(cancelTargetOrderId, selectedReason, cancelNote);
    setShowCancelModal(false);
    setCancelTargetOrderId(null);
    setSelectedReason('');
    setCancelNote('');
  };

  return (
    <div className="min-h-screen flex flex-col bg-white">
      <Head title="My Orders" />
      <Navigation />

      <main className="flex-1">
        <div className="max-w-6xl mx-auto py-16 px-6">
          <h1 className="text-4xl font-bold mb-12 text-black">My Orders</h1>

          {/* Tabs */}
          <div className="flex gap-8 mb-12 border-b border-gray-200">
            <button
              onClick={() => setSelectedTab('all')}
              className={`pb-4 font-medium text-sm tracking-wide transition-all flex items-center gap-2 ${
                selectedTab === 'all'
                  ? 'border-b-2 border-black text-black'
                  : 'text-gray-400 hover:text-gray-600'
              }`}
            >
              ALL ORDERS
              {getCountByStatus('all') > 0 && (
                <span className="text-gray-800 px-2 py-0.5 text-xs font-semibold">
                  {getCountByStatus('all')}
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
              onClick={() => setSelectedTab('processing')}
              className={`pb-4 font-medium text-sm tracking-wide transition-all flex items-center gap-2 ${
                selectedTab === 'processing'
                  ? 'border-b-2 border-black text-black'
                  : 'text-gray-400 hover:text-gray-600'
              }`}
            >
              PROCESSING
              {getCountByStatus('processing') > 0 && (
                <span className="text-gray-800 px-2 py-0.5 text-xs font-semibold">
                  {getCountByStatus('processing')}
                </span>
              )}
            </button>
            <button
              onClick={() => setSelectedTab('shipped')}
              className={`pb-4 font-medium text-sm tracking-wide transition-all flex items-center gap-2 ${
                selectedTab === 'shipped'
                  ? 'border-b-2 border-black text-black'
                  : 'text-gray-400 hover:text-gray-600'
              }`}
            >
              SHIPPED
              {getCountByStatus('shipped') > 0 && (
                <span className="text-gray-800 px-2 py-0.5 text-xs font-semibold">
                  {getCountByStatus('shipped')}
                </span>
              )}
            </button>
            <button
              onClick={() => setSelectedTab('completed')}
              className={`pb-4 font-medium text-sm tracking-wide transition-all flex items-center gap-2 ${
                selectedTab === 'completed'
                  ? 'border-b-2 border-black text-black'
                  : 'text-gray-400 hover:text-gray-600'
              }`}
            >
              COMPLETED
              {getCountByStatus('completed') > 0 && (
                <span className="text-gray-800 px-2 py-0.5 text-xs font-semibold">
                  {getCountByStatus('completed')}
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
          </div>

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
                className="inline-block px-8 py-3 bg-black text-white font-medium hover:bg-gray-800 transition-colors"
              >
                Browse Products
              </Link>
            </div>
          ) : (
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
                      <div>
                        <span
                          className={`inline-flex items-center px-4 py-1.5 text-xs font-semibold tracking-wider uppercase ${getStatusBadge(
                            order.status
                          )}`}
                        >
                          {getStatusText(order.status)}
                        </span>
                      </div>
                    </div>
                  </div>

                  {/* Order Items */}
                  <div className="p-8">
                    <div className="space-y-6">
                      {order.items && order.items.map((item, idx) => (
                        <div key={idx} className="flex items-center gap-6">
                          <div className="w-24 h-24 bg-white border border-gray-200 overflow-hidden flex-shrink-0">
                            {item.product_image ? (
                              <img
                                src={item.product_image}
                                alt={item.product_name}
                                className="w-full h-full object-cover"
                              />
                            ) : (
                              <div className="w-full h-full flex items-center justify-center text-gray-300">
                                <svg className="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                              </div>
                            )}
                          </div>
                          <div className="flex-grow">
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
                            <p className="text-sm text-gray-500 mt-1">Qty: {item.quantity}</p>
                          </div>
                          <div className="text-right">
                            <p className="font-semibold text-black text-lg">₱{item.subtotal.toLocaleString()}</p>
                            <p className="text-xs text-gray-400 mt-1">₱{item.price.toLocaleString()} each</p>
                          </div>
                        </div>
                      ))}
                    </div>

                    {/* Order Total */}
                    <div className="mt-8 pt-6 border-t border-gray-200">
                      <div className="flex justify-between items-center">
                        <div>
                          <p className="text-sm text-gray-500 uppercase tracking-wider mb-1">Shop</p>
                          <p className="font-semibold text-black">{order.shop_name}</p>
                        </div>
                        <div className="text-right">
                          <p className="text-sm text-gray-500 uppercase tracking-wider mb-1">Order Total</p>
                          <p className="font-bold text-black text-2xl">₱{order.total_amount.toLocaleString()}</p>
                        </div>
                      </div>
                    </div>

                    {/* Order Actions */}
                    <div className="mt-6 flex justify-end gap-4">
                      {order.status === 'pending' && (
                        <button
                          onClick={() => {
                            setCancelTargetOrderId(order.id);
                            setSelectedReason('');
                            setCancelNote('');
                            setShowCancelModal(true);
                          }}
                          className="px-6 py-2.5 bg-red-600 text-white text-sm font-medium tracking-wide hover:bg-red-700 transition-colors"
                        >
                          CANCEL ORDER
                        </button>
                      )}
                      {(order.status === 'shipped' || order.status === 'to_ship') && (
                        <button
                          onClick={() => confirmDelivery(order.id)}
                          className="px-6 py-2.5 bg-black text-white text-sm font-medium tracking-wide hover:bg-gray-800 transition-colors"
                        >
                          CONFIRM RECEIVED
                        </button>
                      )}
                      {order.status === 'completed' && (
                        <Link
                          href={`/products`}
                          className="px-6 py-2.5 bg-black text-white text-sm font-medium tracking-wide hover:bg-gray-800 transition-colors inline-block"
                        >
                          ORDER AGAIN
                        </Link>
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
                  onClick={handleSubmitCancel}
                  disabled={!selectedReason}
                  className={`px-4 py-2 rounded text-white ${selectedReason ? 'bg-red-600 hover:bg-red-700' : 'bg-red-300 cursor-not-allowed'}`}
                >
                  Cancel Order
                </button>
              </div>
            </div>
          </div>
        )}
      </main>
    </div>
  );
};

export default MyOrders;
