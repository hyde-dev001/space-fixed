import React from 'react';
import { Head, Link } from '@inertiajs/react';
import Navigation from './Navigation';

interface OrderItem {
    id: number;
    product_name: string;
    variant_name?: string;
    quantity: number;
    price_at_purchase: number;
    subtotal: number;
    product?: {
        product_image?: string;
    };
}

interface Order {
    id: number;
    order_number: string;
    customer_name: string;
    customer_email: string;
    total_amount: number;
    order_status: string;
    payment_status: string;
    paid_at: string;
    created_at: string;
    orderItems: OrderItem[];
}

interface Props {
    order?: Order;
}

const PaymentSuccess: React.FC<Props> = ({ order }) => {
    // Handle case when order data is not available
    if (!order) {
        return (
            <>
                <Head title="Payment Successful" />
                <Navigation />
                
                <div className="min-h-screen bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
                    <div className="max-w-3xl mx-auto">
                        <div className="bg-white rounded-lg shadow-lg p-8 mb-6">
                            <div className="text-center">
                                <div className="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-green-100 mb-4">
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
                                <h1 className="text-3xl font-bold text-gray-900 mb-2">
                                    Payment Processing
                                </h1>
                                <p className="text-lg text-gray-600 mb-6">
                                    Your payment is being processed. Please wait while we confirm your order.
                                </p>
                                <div className="mt-8">
                                    <Link
                                        href="/my-orders"
                                        className="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-black hover:bg-gray-800"
                                    >
                                        View My Orders
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Payment Successful" />
            <Navigation />
            
            <div className="min-h-screen bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
                <div className="max-w-3xl mx-auto">
                    {/* Success Message */}
                    <div className="bg-white rounded-lg shadow-lg p-8 mb-6">
                        <div className="text-center">
                            <div className="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-green-100 mb-4">
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
                            <h1 className="text-3xl font-bold text-gray-900 mb-2">
                                Payment Successful!
                            </h1>
                            <p className="text-lg text-gray-600 mb-6">
                                Thank you for your purchase. Your order has been received.
                            </p>
                            <div className="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                                <p className="text-sm text-gray-600">Order Number</p>
                                <p className="text-2xl font-bold text-blue-600">
                                    {order.order_number}
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* Order Details */}
                    <div className="bg-white rounded-lg shadow-lg p-8 mb-6">
                        <h2 className="text-xl font-bold text-gray-900 mb-4">
                            Order Details
                        </h2>
                        
                        <div className="border-t border-gray-200 pt-4">
                            <div className="grid grid-cols-2 gap-4 mb-4">
                                <div>
                                    <p className="text-sm text-gray-600">Customer Name</p>
                                    <p className="font-semibold">{order.customer_name}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-gray-600">Email</p>
                                    <p className="font-semibold">{order.customer_email}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-gray-600">Payment Status</p>
                                    <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                        {order.payment_status}
                                    </span>
                                </div>
                                <div>
                                    <p className="text-sm text-gray-600">Order Status</p>
                                    <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                                        {order.order_status}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Order Items */}
                    <div className="bg-white rounded-lg shadow-lg p-8 mb-6">
                        <h2 className="text-xl font-bold text-gray-900 mb-4">
                            Order Items
                        </h2>
                        
                        <div className="space-y-4">
                            {order.orderItems && order.orderItems.length > 0 ? (
                                order.orderItems.map((item) => (
                                    <div
                                        key={item.id}
                                        className="flex items-center gap-4 border-b border-gray-200 pb-4 last:border-0"
                                    >
                                        {item.product?.product_image && (
                                            <img
                                                src={`/storage/${item.product.product_image}`}
                                                alt={item.product_name}
                                                className="w-20 h-20 object-cover rounded"
                                            />
                                        )}
                                        <div className="flex-1">
                                            <h3 className="font-semibold text-gray-900">
                                                {item.product_name}
                                            </h3>
                                            {item.variant_name && (
                                                <p className="text-sm text-gray-600">
                                                    Variant: {item.variant_name}
                                                </p>
                                            )}
                                            <p className="text-sm text-gray-600">
                                                Quantity: {item.quantity}
                                            </p>
                                        </div>
                                        <div className="text-right">
                                            <p className="font-semibold text-gray-900">
                                                ₱{item.subtotal.toFixed(2)}
                                            </p>
                                            <p className="text-sm text-gray-600">
                                                @ ₱{item.price_at_purchase.toFixed(2)}
                                            </p>
                                        </div>
                                    </div>
                                ))
                            ) : (
                                <p className="text-gray-600">No items found</p>
                            )}
                        </div>

                        <div className="border-t border-gray-200 mt-6 pt-6">
                            <div className="flex justify-between items-center">
                                <span className="text-xl font-bold text-gray-900">
                                    Total Amount
                                </span>
                                <span className="text-2xl font-bold text-blue-600">
                                    ₱{parseFloat(order.total_amount).toFixed(2)}
                                </span>
                            </div>
                        </div>
                    </div>

                    {/* Action Buttons */}
                    <div className="flex gap-4 justify-center">
                        <Link
                            href="/"
                            className="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition"
                        >
                            Continue Shopping
                        </Link>
                        <Link
                            href="/orders"
                            className="px-6 py-3 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 transition"
                        >
                            View Orders
                        </Link>
                    </div>
                </div>
            </div>
        </>
    );
};
export default PaymentSuccess;