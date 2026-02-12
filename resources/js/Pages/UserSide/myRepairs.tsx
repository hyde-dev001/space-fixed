import React, { useState, useEffect } from 'react';
import { Head, Link } from '@inertiajs/react';
import Navigation from './Navigation';
import Swal from 'sweetalert2';

type RepairStatus = 'pending' | 'in_progress' | 'completed' | 'ready_for_pickup' | 'picked_up' | 'cancelled';

type RepairOrder = {
  id: number;
  order_number: string;
  repair_type: string;
  description: string;
  status: RepairStatus;
  total_amount: number;
  created_at: string;
  estimated_completion?: string;
  completed_at?: string;
  shop_name: string;
  shop_address: string;
  image?: string;
};

// Static mock data for testing
const getStaticRepairOrders = (): RepairOrder[] => {
  return [
    {
      id: 1,
      order_number: 'REP-2026020201',
      repair_type: 'Sole Replacement',
      description: 'Replace worn out sole on Nike Air Max',
      status: 'in_progress',
      total_amount: 1500,
      created_at: new Date('2026-02-01T10:30:00').toISOString(),
      estimated_completion: 'Feb 5, 2026',
      shop_name: 'SoleSpace Repair Center',
      shop_address: '123 Main St, Makati City',
      image: '/images/product/product-01.jpg',
    },
    {
      id: 2,
      order_number: 'REP-2026013101',
      repair_type: 'Shoe Cleaning',
      description: 'Deep cleaning for white sneakers',
      status: 'ready_for_pickup',
      total_amount: 800,
      created_at: new Date('2026-01-31T14:20:00').toISOString(),
      completed_at: 'Feb 1, 2026',
      shop_name: 'SoleSpace Repair Center',
      shop_address: '123 Main St, Makati City',
      image: '/images/product/product-02.jpg',
    },
    {
      id: 3,
      order_number: 'REP-2026012901',
      repair_type: 'Stitching Repair',
      description: 'Fix torn stitching on leather boots',
      status: 'picked_up',
      total_amount: 1200,
      created_at: new Date('2026-01-29T09:15:00').toISOString(),
      completed_at: 'Jan 31, 2026',
      shop_name: 'SoleSpace Repair Center',
      shop_address: '123 Main St, Makati City',
      image: '/images/product/product-03.jpg',
    },
    {
      id: 4,
      order_number: 'REP-2026012801',
      repair_type: 'Heel Replacement',
      description: 'Replace broken heel on dress shoes',
      status: 'pending',
      total_amount: 2000,
      created_at: new Date('2026-01-28T16:45:00').toISOString(),
      estimated_completion: 'Feb 3, 2026',
      shop_name: 'SoleSpace Repair Center',
      shop_address: '123 Main St, Makati City',
      image: '/images/product/product-04.jpg',
    },
  ];
};

const MyRepairs: React.FC = () => {
  const [orders, setOrders] = useState<RepairOrder[]>([]);
  const [loading, setLoading] = useState(true);
  const [selectedTab, setSelectedTab] = useState<'all' | 'pending' | 'in_progress' | 'ready' | 'completed'>('all');

  useEffect(() => {
    // Load static test orders immediately
    const staticOrders = getStaticRepairOrders();
    setOrders(staticOrders);
    setLoading(false);
  }, []);

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

    // Update order status to picked_up
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
  };

  const cancelRepair = async (orderId: number) => {
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

    // Update order status to cancelled
    setOrders(prev => 
      prev.map(order => 
        order.id === orderId 
          ? { ...order, status: 'cancelled' as const } 
          : order
      )
    );

    await Swal.fire({
      title: 'Repair Cancelled',
      text: 'Your repair order has been cancelled.',
      icon: 'success',
      confirmButtonText: 'OK',
      confirmButtonColor: '#000000',
    });
  };

  const getStatusColor = (status: RepairStatus) => {
    switch (status) {
      case 'pending':
        return 'bg-yellow-50 text-yellow-700 border border-yellow-200';
      case 'in_progress':
        return 'bg-white text-black border border-black';
      case 'completed':
      case 'ready_for_pickup':
        return 'bg-purple-50 text-purple-700 border border-purple-200';
      case 'picked_up':
        return 'bg-green-50 text-green-700 border border-green-200';
      case 'cancelled':
        return 'bg-red-50 text-red-700 border border-red-200';
      default:
        return 'bg-gray-50 text-gray-700 border border-gray-200';
    }
  };

  const getStatusText = (status: RepairStatus) => {
    switch (status) {
      case 'pending':
        return 'Pending';
      case 'in_progress':
        return 'In Progress';
      case 'completed':
        return 'Completed';
      case 'ready_for_pickup':
        return 'Ready for Pickup';
      case 'picked_up':
        return 'Picked Up';
      case 'cancelled':
        return 'Cancelled';
      default:
        return status;
    }
  };

  // Count functions for repair statuses
  const getCountByStatus = (status: string) => {
    if (status === 'all') return orders.length;
    if (status === 'ready') return orders.filter(o => o.status === 'ready_for_pickup').length;
    if (status === 'completed') return orders.filter(o => o.status === 'picked_up').length;
    return orders.filter(o => o.status === status).length;
  };

  const filteredOrders = selectedTab === 'all' 
    ? orders 
    : orders.filter(order => {
        if (selectedTab === 'ready') return order.status === 'ready_for_pickup';
        if (selectedTab === 'completed') return order.status === 'picked_up';
        return order.status === selectedTab;
      });

  return (
    <div className="min-h-screen flex flex-col bg-white">
      <Head title="My Repairs" />
      <Navigation />

      <main className="flex-1">
        <div className="max-w-6xl mx-auto py-16 px-6">
          <h1 className="text-4xl font-bold mb-12 text-black">My Repairs</h1>

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
              ALL REPAIRS
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
              onClick={() => setSelectedTab('ready')}
              className={`pb-4 font-medium text-sm tracking-wide transition-all flex items-center gap-2 ${
                selectedTab === 'ready'
                  ? 'border-b-2 border-black text-black'
                  : 'text-gray-400 hover:text-gray-600'
              }`}
            >
              READY
              {getCountByStatus('ready') > 0 && (
                <span className="text-gray-800 px-2 py-0.5 text-xs font-semibold">
                  {getCountByStatus('ready')}
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
            <div className="text-center py-32">
              <svg
                className="mx-auto h-20 w-20 text-gray-300"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={1}
                  d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"
                />
              </svg>
              <h2 className="mt-6 text-2xl font-semibold text-black">No repair orders yet</h2>
              <p className="mt-3 text-gray-500">Book a repair service to see your orders here</p>
              <Link
                href="/repair-services"
                className="mt-8 inline-block bg-black text-white px-8 py-3 text-sm font-medium tracking-wide hover:bg-gray-800 transition-colors"
              >
                BROWSE REPAIR SERVICES
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
                          <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Repair Order</p>
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
                          className={`inline-flex items-center px-4 py-1.5 text-xs font-semibold tracking-wider uppercase ${getStatusColor(
                            order.status
                          )}`}
                        >
                          {getStatusText(order.status)}
                        </span>
                      </div>
                    </div>
                  </div>

                  {/* Order Details */}
                  <div className="p-8">
                    <div className="flex gap-6">
                      {/* Item Image */}
                      <div className="w-32 h-32 bg-white border border-gray-200 overflow-hidden flex-shrink-0">
                        {order.image ? (
                          <img
                            src={order.image}
                            alt={order.repair_type}
                            className="w-full h-full object-cover"
                          />
                        ) : (
                          <div className="w-full h-full flex items-center justify-center text-gray-300">
                            <svg className="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
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

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
                          <div>
                            <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Shop Location</p>
                            <p className="text-sm text-black font-medium">{order.shop_name}</p>
                            <p className="text-sm text-gray-500">{order.shop_address}</p>
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

                      {/* Price */}
                      <div className="text-right">
                        <p className="text-xs text-gray-400 uppercase tracking-wider mb-1">Amount</p>
                        <p className="text-2xl font-bold text-black">₱{order.total_amount.toLocaleString()}</p>
                      </div>
                    </div>

                    {/* Action Buttons */}
                    <div className="mt-8 pt-6 border-t border-gray-200 flex justify-end gap-4">
                      {order.status === 'pending' && (
                        <button
                          onClick={() => cancelRepair(order.id)}
                          className="px-6 py-2.5 bg-red-600 text-white text-sm font-medium tracking-wide hover:bg-red-700 transition-colors"
                        >
                          CANCEL REPAIR
                        </button>
                      )}
                      {order.status === 'in_progress' && (
                        <div className="px-6 py-2.5 border border-black text-black text-sm font-medium tracking-wide">
                          REPAIR IN PROGRESS
                        </div>
                      )}
                      {order.status === 'ready_for_pickup' && (
                        <button
                          onClick={() => confirmPickup(order.id)}
                          className="px-6 py-2.5 bg-black text-white text-sm font-medium tracking-wide hover:bg-gray-800 transition-colors"
                        >
                          CONFIRM PICKUP
                        </button>
                      )}
                      {order.status === 'picked_up' && (
                        <Link
                          href="/repair-services"
                          className="px-6 py-2.5 bg-black text-white text-sm font-medium tracking-wide hover:bg-gray-800 transition-colors inline-block"
                        >
                          BOOK AGAIN
                        </Link>
                      )}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </main>

      <footer className="mt-32 bg-white border-t border-gray-100">
        <div className="max-w-6xl mx-auto px-6 py-16">
          <div className="grid grid-cols-1 md:grid-cols-3 gap-12">
            <div>
              <div className="text-2xl font-bold mb-6 text-black">SoleSpace</div>
              <p className="text-sm text-gray-500 leading-relaxed max-w-sm">
                Your premier destination for premium footwear and expert repair services.
              </p>
            </div>
            <div className="flex flex-col">
              <h3 className="text-xs uppercase text-gray-400 font-semibold tracking-wider mb-6">Quick Links</h3>
              <nav className="flex flex-col gap-4 text-sm text-gray-700">
                <Link href="/products" className="hover:text-black transition-colors">Products</Link>
                <Link href="/repair-services" className="hover:text-black transition-colors">Repair Services</Link>
                <Link href="/my-orders" className="hover:text-black transition-colors">My Orders</Link>
              </nav>
            </div>
            <div className="flex flex-col">
              <h3 className="text-xs uppercase text-gray-400 font-semibold tracking-wider mb-6">Services</h3>
              <nav className="flex flex-col gap-4 text-sm text-gray-700">
                <a href="#" className="hover:text-black transition-colors">Shoe Repair</a>
                <a href="#" className="hover:text-black transition-colors">Custom Fitting</a>
                <a href="#" className="hover:text-black transition-colors">Maintenance</a>
              </nav>
            </div>
          </div>
          <div className="border-t border-gray-100 mt-12 pt-8 text-xs text-gray-400 flex items-center justify-between">
            <div>© 2026 SoleSpace. All rights reserved.</div>
          </div>
        </div>
      </footer>
    </div>
  );
};

export default MyRepairs;
