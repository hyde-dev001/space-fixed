import React from 'react';
import { Link } from '@inertiajs/react';
import Navigation from './Navigation';

export default function PaymentFailed() {
  return (
    <div className="min-h-screen bg-gray-50">
      <Navigation />
      
      <div className="max-w-2xl mx-auto px-4 py-16 sm:px-6 lg:px-8">
        <div className="bg-white rounded-lg shadow-lg p-8 text-center">
          {/* Error Icon */}
          <div className="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-red-100 mb-6">
            <svg
              className="h-10 w-10 text-red-600"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth="2"
                d="M6 18L18 6M6 6l12 12"
              />
            </svg>
          </div>

          {/* Error Message */}
          <h1 className="text-3xl font-bold text-gray-900 mb-2">
            Payment Failed
          </h1>
          
          <p className="text-gray-600 mb-6">
            We couldn't process your payment. Please try again or use a different payment method.
          </p>

          {/* Possible Reasons */}
          <div className="bg-gray-50 rounded-lg p-6 mb-8 text-left">
            <h2 className="text-lg font-semibold text-gray-900 mb-4">Common reasons for payment failure:</h2>
            <ul className="space-y-2 text-sm text-gray-600">
              <li className="flex items-start">
                <span className="mr-2">•</span>
                <span>Insufficient funds in your account</span>
              </li>
              <li className="flex items-start">
                <span className="mr-2">•</span>
                <span>Incorrect card details or OTP</span>
              </li>
              <li className="flex items-start">
                <span className="mr-2">•</span>
                <span>Payment timeout or connection issues</span>
              </li>
              <li className="flex items-start">
                <span className="mr-2">•</span>
                <span>Card restrictions or daily limits exceeded</span>
              </li>
            </ul>
          </div>

          {/* Action Buttons */}
          <div className="flex flex-col sm:flex-row gap-4 justify-center mt-8">
            <Link
              href="/checkout"
              className="inline-flex items-center justify-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-black hover:bg-gray-800 transition-colors"
            >
              Try Again
            </Link>
            <Link
              href="/products"
              className="inline-flex items-center justify-center px-6 py-3 border border-gray-300 text-base font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors"
            >
              Continue Shopping
            </Link>
          </div>

          {/* Support Info */}
          <div className="mt-8 pt-8 border-t border-gray-200">
            <p className="text-sm text-gray-600">
              Need help? <Link href="/contact" className="text-black font-semibold hover:underline">Contact Support</Link>
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
