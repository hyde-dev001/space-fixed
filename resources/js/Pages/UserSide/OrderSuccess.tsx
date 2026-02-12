import React, { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import Navigation from './Navigation';

interface Order {
  id: number;
  order_number: string;
  total_amount: string;
  status: string;
  payment_status: string;
  created_at: string;
}

export default function OrderSuccess() {
  const [order, setOrder] = useState<Order | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // Get pending order ID from session storage
    const pendingOrderId = sessionStorage.getItem('pendingOrderId');
    
    if (pendingOrderId) {
      // Fetch order details
      fetch(`/api/orders/${pendingOrderId}/details`, {
        credentials: 'include',
      })
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            setOrder(data.order);
          }
          setLoading(false);
          // Clear the pending order ID
          sessionStorage.removeItem('pendingOrderId');
        })
        .catch(err => {
          console.error('Failed to fetch order:', err);
          setLoading(false);
        });
    } else {
      setLoading(false);
    }
  }, []);

  return (
    <div className="min-h-screen bg-gray-50">
      <Navigation />
      
      <div className="max-w-2xl mx-auto px-4 py-16 sm:px-6 lg:px-8">
        <div className="bg-white rounded-lg shadow-lg p-8 text-center">
          {loading ? (
            <div className="py-12">
              <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-black mx-auto"></div>
              <p className="mt-4 text-gray-600">Loading order details...</p>
            </div>
          ) : (
            <>
              {/* Success Icon */}
              <div className="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-green-100 mb-6">
                <svg
                  className="h-10 w-10 text-green-600"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeWidth="2"
                    d="M5 13l4 4L19 7"
                  />
                </svg>
              </div>

              {/* Success Message */}
              <h1 className="text-3xl font-bold text-gray-900 mb-2">
                Order Placed Successfully!
              </h1>
              
              {order ? (
                <>
                  <p className="text-gray-600 mb-6">
                    Your order <span className="font-semibold text-black">{order.order_number}</span> has been received.
                  </p>

                  {/* Order Details */}
                  <div className="bg-gray-50 rounded-lg p-6 mb-8 text-left">
                    <h2 className="text-lg font-semibold text-gray-900 mb-4">Order Summary</h2>
                    <div className="space-y-3">
                      <div className="flex justify-between">
                        <span className="text-gray-600">Order Number:</span>
                        <span className="font-semibold text-gray-900">{order.order_number}</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-gray-600">Total Amount:</span>
                        <span className="font-semibold text-gray-900">₱{parseFloat(order.total_amount).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-gray-600">Payment Status:</span>
                        <span className={`font-semibold ${
                          order.payment_status === 'paid' ? 'text-green-600' : 
                          order.payment_status === 'pending' ? 'text-yellow-600' : 
                          'text-gray-600'
                        }`}>
                          {order.payment_status === 'paid' ? 'Paid' : 
                           order.payment_status === 'pending' ? 'Processing' : 
                           order.payment_status}
                        </span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-gray-600">Order Status:</span>
                        <span className="font-semibold text-gray-900 capitalize">{order.status}</span>
                      </div>
                    </div>
                  </div>

                  {order.payment_status === 'pending' && (
                    <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                      <p className="text-sm text-yellow-800">
                        <strong>Note:</strong> Your payment is being processed. 
                        You'll receive a confirmation email once the payment is confirmed.
                      </p>
                    </div>
                  )}

                  {order.payment_status === 'paid' && (
                    <div className="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
                      <p className="text-sm text-green-800">
                        <strong>Payment Confirmed!</strong> Your order will be processed shortly. 
                        You'll receive updates via email.
                      </p>
                    </div>
                  )}
                </>
              ) : (
                <p className="text-gray-600 mb-6">
                  Thank you for your order! We're processing your payment.
                </p>
              )}

              {/* Action Buttons */}
              <div className="flex flex-col sm:flex-row gap-4 justify-center mt-8">
                <Link
                  href="/my-orders"
                  className="inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-black hover:bg-gray-800 transition-colors"
                >
                  View My Orders
                </Link>
                <Link
                  href="/products"
                  className="inline-flex items-center justify-center px-6 py-3 border border-gray-300 text-base font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors"
                >
                  Continue Shopping
                </Link>
              </div>

              {/* Additional Info */}
              <div className="mt-8 pt-8 border-t border-gray-200">
                <h3 className="text-sm font-semibold text-gray-900 mb-2">What happens next?</h3>
                <ul className="text-sm text-gray-600 space-y-2 text-left max-w-md mx-auto">
                  <li className="flex items-start">
                    <span className="mr-2">1.</span>
                    <span>You'll receive an order confirmation email</span>
                  </li>
                  <li className="flex items-start">
                    <span className="mr-2">2.</span>
                    <span>The shop will process and prepare your order</span>
                  </li>
                  <li className="flex items-start">
                    <span className="mr-2">3.</span>
                    <span>You'll receive shipping updates via email</span>
                  </li>
                  <li className="flex items-start">
                    <span className="mr-2">4.</span>
                    <span>Track your order status in "My Orders"</span>
                  </li>
                </ul>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
