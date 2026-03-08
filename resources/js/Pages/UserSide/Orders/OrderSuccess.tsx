import React, { useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';

interface Order {
  id: number;
  order_number: string;
  total_amount: string;
  status: string;
  payment_status: string;
  created_at: string;
}

export default function OrderSuccess() {
  const [order,   setOrder]   = useState<Order | null>(null);
  const [loading, setLoading] = useState(true);
  const [failed,  setFailed]  = useState(false);
  const [message, setMessage] = useState('Verifying your payment with PayMongo...');

  useEffect(() => {
    const run = async () => {
      const urlParams         = new URLSearchParams(window.location.search);
      const isPaymongoSuccess = urlParams.get('paymongo_success') === '1';
      const isPaymongoFailed  = urlParams.get('paymongo_failed')  === '1';
      const pendingOrderId    = sessionStorage.getItem('pendingOrderId');

      window.history.replaceState({}, '', '/order-success');

      if (isPaymongoFailed) {
        sessionStorage.removeItem('pendingOrderId');
        setFailed(true);
        setLoading(false);
        setTimeout(() => router.visit('/my-orders'), 4000);
        return;
      }

      if (!pendingOrderId || !isPaymongoSuccess) {
        router.visit('/my-orders');
        return;
      }

      sessionStorage.removeItem('pendingOrderId');

      try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        // PayMongo redirects back before its own status update propagates.
        // Retry up to 6 times (12 seconds total) waiting for payment_status = 'paid'.
        const MAX_ATTEMPTS = 6;
        const RETRY_DELAY  = 2000;
        let data: any = null;

        for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
          const res = await fetch(`/api/orders/${pendingOrderId}/verify-payment`, {
            method: 'POST',
            credentials: 'include',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
              'X-CSRF-TOKEN': csrfToken || '',
            },
          });
          data = await res.json();

          if (data.success && data.payment_verified) break;

          if (res.status >= 500 || res.status === 404) break;

          if (attempt < MAX_ATTEMPTS) {
            await new Promise(resolve => setTimeout(resolve, RETRY_DELAY));
          }
        }

        if (data?.success && data?.payment_verified) {
          setOrder(data.order);
          setMessage('Payment confirmed!');
        } else {
          setMessage(data?.message || 'Payment could not be verified.');
          setFailed(true);
        }
      } catch (e) {
        setMessage('Could not reach the server to verify payment.');
        setFailed(true);
      } finally {
        setLoading(false);
      }
    };

    run();
  }, []);

  return (
    <div className="min-h-screen bg-gray-50">
      <Navigation />
      <div className="max-w-2xl mx-auto px-4 py-16 sm:px-6 lg:px-8">
        <div className="bg-white rounded-lg shadow-lg p-8 text-center">
          {loading ? (
            <div className="py-12">
              <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-black mx-auto"></div>
              <p className="mt-4 text-gray-600">{message}</p>
            </div>
          ) : failed ? (
            <>
              <div className="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-red-100 mb-6">
                <svg className="h-10 w-10 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </div>
              <h1 className="text-2xl font-bold text-gray-900 mb-2">Payment Not Completed</h1>
              <p className="text-gray-600 mb-6">{message}</p>
              <p className="text-sm text-gray-500 mb-6">Redirecting you to My Orders...</p>
              <Link href="/my-orders" className="inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-black hover:bg-gray-800 transition-colors">
                Go to My Orders
              </Link>
            </>
          ) : (
            <>
              <div className="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-green-100 mb-6">
                <svg className="h-10 w-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                </svg>
              </div>
              <h1 className="text-3xl font-bold text-gray-900 mb-2">Order Placed Successfully!</h1>
              {order && (
                <>
                  <p className="text-gray-600 mb-6">
                    Your order <span className="font-semibold text-black">{order.order_number}</span> has been received.
                  </p>
                  <div className="bg-gray-50 rounded-lg p-6 mb-8 text-left">
                    <h2 className="text-lg font-semibold text-gray-900 mb-4">Order Summary</h2>
                    <div className="space-y-3">
                      <div className="flex justify-between">
                        <span className="text-gray-600">Order Number:</span>
                        <span className="font-semibold text-gray-900">{order.order_number}</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-gray-600">Total Amount:</span>
                        <span className="font-semibold text-gray-900">&#x20B1;{parseFloat(order.total_amount).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</span>
                      </div>
                      <div className="flex justify-between">
                        <span className="text-gray-600">Payment Status:</span>
                        <span className="font-semibold text-green-600">Paid</span>
                      </div>
                    </div>
                  </div>
                  <div className="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
                    <p className="text-sm text-green-800">
                      <strong>Payment Confirmed!</strong> Your order will be processed shortly.
                    </p>
                  </div>
                </>
              )}
              <div className="flex flex-col sm:flex-row gap-4 justify-center mt-8">
                <Link href="/my-orders" className="inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-black hover:bg-gray-800 transition-colors">
                  View My Orders
                </Link>
                <Link href="/products" className="inline-flex items-center justify-center px-6 py-3 border border-gray-300 text-base font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                  Continue Shopping
                </Link>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
