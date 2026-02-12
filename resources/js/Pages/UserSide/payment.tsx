import React, { useState, useEffect } from 'react';
import { Head, Link, usePage, router } from '@inertiajs/react';
import Navigation from './Navigation';
import Swal from 'sweetalert2';
import axios from 'axios';

interface CartItem {
  id: string;
  pid: number;
  name: string;
  price: number;
  size?: string;
  color?: string;
  qty: number;
  image?: string;
}

interface CheckoutData {
  items: CartItem[];
  total_amount: number;
  customer_name: string;
  customer_email: string;
  customer_phone: string;
  shipping_address: string;
  address_id?: number | null;
  shipping_region?: string | null;
  shipping_province?: string | null;
  shipping_city?: string | null;
  shipping_barangay?: string | null;
  shipping_postal_code?: string | null;
  shipping_address_line?: string | null;
  payment_method: string;
}

const Payment: React.FC = () => {
  const { auth } = usePage().props as any;
  const user = auth?.user;

  const [checkoutData, setCheckoutData] = useState<CheckoutData | null>(null);
  const [isProcessing, setIsProcessing] = useState(false);
  const [payError, setPayError] = useState<string | null>(null);
  const [billingAddressSame, setBillingAddressSame] = useState(true);

  // Local state for editable fields
  const [customerEmail, setCustomerEmail] = useState('');
  const [customerName, setCustomerName] = useState('');
  const [customerPhone, setCustomerPhone] = useState('');
  const [shippingAddressLine, setShippingAddressLine] = useState('');
  const [shippingBarangay, setShippingBarangay] = useState('');
  const [shippingPostalCode, setShippingPostalCode] = useState('');
  const [shippingCity, setShippingCity] = useState('');
  const [shippingRegion, setShippingRegion] = useState('');
  const [billingName, setBillingName] = useState('');
  const [billingPhone, setBillingPhone] = useState('');
  const [billingAddressLine, setBillingAddressLine] = useState('');
  const [billingBarangay, setBillingBarangay] = useState('');
  const [billingPostalCode, setBillingPostalCode] = useState('');
  const [billingCity, setBillingCity] = useState('');
  const [billingRegion, setBillingRegion] = useState('');

  useEffect(() => {
    const loadCheckoutData = async () => {

      // First, try to get data from sessionStorage
      const stored = sessionStorage.getItem('checkoutData');
      if (stored) {
        try {
          const data = JSON.parse(stored);
          setCheckoutData(data);
          // Sync local state with loaded data
          setCustomerEmail(data.customer_email || '');
          setCustomerName(data.customer_name || '');
          setCustomerPhone(data.customer_phone || '');
          setShippingAddressLine(data.shipping_address_line || '');
          setShippingBarangay(data.shipping_barangay || '');
          setShippingPostalCode(data.shipping_postal_code || '');
          setShippingCity(data.shipping_city || '');
          setShippingRegion(data.shipping_region || '');
          return;
        } catch (e) {
          console.error('Failed to parse checkout data:', e);
        }
      }

      // If no sessionStorage data, load from cart API
      try {
        let cartItems: CartItem[] = [];
        let totalAmount = 0;

        if (user) {
          // Load from database cart
          const response = await axios.get('/api/cart');
          if (response.data.items && response.data.items.length > 0) {
            cartItems = response.data.items.map((item: any) => {
              const options = item.options ? (typeof item.options === 'string' ? JSON.parse(item.options) : item.options) : {};
              return {
                id: String(item.id),
                pid: item.pid || item.product_id,
                name: item.name || '',
                price: item.price || 0,
                size: item.size,
                color: options.color || undefined,
                qty: item.quantity || item.qty || 1,
                image: item.image,
              };
            });
            totalAmount = cartItems.reduce((sum, item) => sum + item.price * item.qty, 0);
          }
        } else {
          // Load from localStorage for guests
          const raw = localStorage.getItem('ss_cart');
          if (raw) {
            const cart = JSON.parse(raw);
            cartItems = (cart || []).map((c: any) => {
              const price = (typeof c.price === 'number') ? c.price : (parseFloat(String(c.price).replace(/[^0-9.-]+/g, '')) || 0);
              return {
                id: String(c.id),
                pid: c.pid || c.product_id || parseInt(c.id),
                name: c.name || '',
                price,
                size: c.size || undefined,
                color: c.color || undefined,
                qty: Number(c.qty || 1),
                image: c.image || undefined,
              };
            });
            totalAmount = cartItems.reduce((sum, item) => sum + item.price * item.qty, 0);
          }
        }

        if (cartItems.length === 0) {
          router.visit('/checkout');
          return;
        }

        const data: CheckoutData = {
          items: cartItems,
          total_amount: totalAmount,
          customer_name: user?.name || '',
          customer_email: user?.email || '',
          customer_phone: user?.phone || '',
          shipping_address: '',
          address_id: null,
          shipping_region: null,
          shipping_province: null,
          shipping_city: null,
          shipping_barangay: null,
          shipping_postal_code: null,
          shipping_address_line: null,
          payment_method: 'paymongo',
        };

        setCheckoutData(data);
        setCustomerName(data.customer_name);
        setCustomerEmail(data.customer_email);
        setCustomerPhone(data.customer_phone);
      } catch (err) {
        console.error('Failed to load checkout data:', err);
        router.visit('/checkout');
      }
    };

    loadCheckoutData();
  }, []);

  const handlePayNow = async () => {
    if (!checkoutData) return;

    // Validate required fields
    if (!customerEmail || !customerName || !customerPhone) {
      setPayError('Please fill in all required contact information.');
      Swal.fire({
        icon: 'warning',
        title: 'Missing Information',
        text: 'Please fill in all required contact information.',
        confirmButtonColor: '#000000',
      });
      return;
    }

    if (!shippingAddressLine || !shippingBarangay || !shippingCity || !shippingRegion || !shippingPostalCode) {
      setPayError('Please fill in all required shipping address fields.');
      Swal.fire({
        icon: 'warning',
        title: 'Missing Address',
        text: 'Please fill in all required shipping address fields.',
        confirmButtonColor: '#000000',
      });
      return;
    }

    setIsProcessing(true);
    setPayError(null);

    try {
      // First, create the order
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      
      const orderData = {
        items: checkoutData.items,
        total_amount: checkoutData.total_amount,
        customer_name: customerName,
        customer_email: customerEmail,
        customer_phone: customerPhone,
        shipping_address: `${shippingAddressLine}, ${shippingBarangay}, ${shippingCity}, ${shippingRegion} ${shippingPostalCode}`,
        address_id: checkoutData.address_id ?? null,
        shipping_region: shippingRegion,
        shipping_province: null,
        shipping_city: shippingCity,
        shipping_barangay: shippingBarangay,
        shipping_postal_code: shippingPostalCode,
        shipping_address_line: shippingAddressLine,
        payment_method: 'paymongo',
      };

      console.log('Creating order with data:', orderData);

      const orderResponse = await fetch('/api/checkout/create-order', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
        },
        body: JSON.stringify(orderData),
      });

      if (!orderResponse.ok) {
        const errorData = await orderResponse.json();
        throw new Error(errorData.message || 'Failed to create order');
      }

      const orderResult = await orderResponse.json();
      console.log('Order created successfully:', orderResult);

      // Now create PayMongo payment link
      const response = await fetch('/api/paymongo-proxy', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify({
          amount: checkoutData.total_amount,
          description: `SoleSpace Order #${orderResult.order_number || orderResult.order?.order_number || ''} - ${checkoutData.items.map(item => item.name).join(', ')}`,
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

      // Update order with PayMongo link ID
      const orderId = orderResult.order?.id || orderResult.order_id;
      await fetch(`/api/orders/${orderId}/update-payment-link`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken || '',
        },
        body: JSON.stringify({ paymongo_link_id: linkId }),
      });

      // Store order info for tracking
      sessionStorage.setItem('pendingOrderId', orderId);

      // Redirect to PayMongo payment page
      window.location.href = checkoutUrl;
    } catch (err: any) {
      console.error('Payment error:', err);
      const errorMessage = err?.message || 'Unable to process payment';
      setPayError(errorMessage);
      setIsProcessing(false);

      Swal.fire({
        icon: 'error',
        title: 'Payment Failed',
        text: errorMessage,
        confirmButtonColor: '#000000',
      });
    }
  };

  if (!checkoutData) {
    return <div>Loading...</div>;
  }

  const subtotal = checkoutData.total_amount;
  const shipping = 0; // Will be updated based on shipping method
  const total = subtotal + shipping;

  return (
    <div className="min-h-screen flex flex-col bg-white">
      <Head title="Payment" />

      <Navigation />

      <main className="flex-1">
        <div className="max-w-7xl mx-auto py-12 px-6 text-black">
          <div className="grid grid-cols-1 md:grid-cols-3 gap-8 items-start">
            {/* Left: Payment Form (span 2 on md) */}
            <div className="md:col-span-2">
              {/* Contact Section */}
              <div className="mb-8">
                <div className="flex items-center justify-between mb-4">
                  <h2 className="text-lg font-semibold text-black">Contact</h2>
                  <button className="text-sm underline text-black">Sign in</button>
                </div>
                <input
                  type="email"
                  placeholder="Email"
                  value={customerEmail}
                  onChange={e => setCustomerEmail(e.target.value)}
                  className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                />
                <label className="mt-4 flex items-center gap-3">
                  <input type="checkbox" defaultChecked className="w-4 h-4" />
                  <span className="text-sm text-black">Email me with news and offers</span>
                </label>
              </div>

              {/* Delivery Section */}
              <div className="mb-8">
                <h2 className="text-lg font-semibold text-black mb-4">Delivery</h2>
                <div className="space-y-4">
                  {/* First Name & Last Name */}
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm font-medium text-black mb-2">First name</label>
                      <input
                        type="text"
                        placeholder="First name"
                        value={customerName.split(' ')[0]}
                        onChange={e => setCustomerName(e.target.value + (customerName.split(' ').slice(1).join(' ') ? ' ' + customerName.split(' ').slice(1).join(' ') : ''))}
                        className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-black mb-2">Last name</label>
                      <input
                        type="text"
                        placeholder="Last name"
                        value={customerName.split(' ').slice(1).join(' ')}
                        onChange={e => setCustomerName(customerName.split(' ')[0] + (e.target.value ? ' ' + e.target.value : ''))}
                        className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                      />
                    </div>
                  </div>

                  {/* Address Line */}
                  <div>
                    <label className="block text-sm font-medium text-black mb-2">House No., Street, Subdivision / Building</label>
                    <input
                      type="text"
                      placeholder="House No., Street, Subdivision / Building"
                      value={shippingAddressLine}
                      onChange={e => setShippingAddressLine(e.target.value)}
                      className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                    />
                  </div>

                  {/* Barangay */}
                  <div>
                    <label className="block text-sm font-medium text-black mb-2">Barangay, Landmarks, Optional (LBC Branch)</label>
                    <input
                      type="text"
                      placeholder="Barangay, Landmarks, Optional (LBC Branch)"
                      value={shippingBarangay}
                      onChange={e => setShippingBarangay(e.target.value)}
                      className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                    />
                  </div>

                  {/* Postal Code & City */}
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm font-medium text-black mb-2">Postal code</label>
                      <input
                        type="text"
                        placeholder="Postal code"
                        value={shippingPostalCode}
                        onChange={e => setShippingPostalCode(e.target.value)}
                        className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-black mb-2">City</label>
                      <input
                        type="text"
                        placeholder="City"
                        value={shippingCity}
                        onChange={e => setShippingCity(e.target.value)}
                        className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                      />
                    </div>
                  </div>

                  {/* Region */}
                  <div>
                    <label className="block text-sm text-black mb-2">Region</label>
                    <select className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white" value={shippingRegion || ''} onChange={e => setShippingRegion(e.target.value)}>
                      <option value="">Select Region</option>
                      <option value="Abra">Abra</option>
                      <option value="Agusan del Norte">Agusan del Norte</option>
                      <option value="Agusan del Sur">Agusan del Sur</option>
                      <option value="Aklan">Aklan</option>
                      <option value="Albay">Albay</option>
                      <option value="Antique">Antique</option>
                      <option value="Apayao">Apayao</option>
                      <option value="Aurora">Aurora</option>
                      <option value="Basilan">Basilan</option>
                      <option value="Bataan">Bataan</option>
                      <option value="Batanes">Batanes</option>
                      <option value="Batangas">Batangas</option>
                      <option value="Benguet">Benguet</option>
                      <option value="Biliran">Biliran</option>
                      <option value="Bohol">Bohol</option>
                      <option value="Bukidnon">Bukidnon</option>
                      <option value="Bulacan">Bulacan</option>
                      <option value="Cagayan">Cagayan</option>
                      <option value="Camarines Norte">Camarines Norte</option>
                      <option value="Camarines Sur">Camarines Sur</option>
                      <option value="Camiguin">Camiguin</option>
                      <option value="Capiz">Capiz</option>
                      <option value="Catanduanes">Catanduanes</option>
                      <option value="Cavite">Cavite</option>
                      <option value="Cebu">Cebu</option>
                      <option value="Cotabato">Cotabato</option>
                      <option value="Compostela Valley">Compostela Valley</option>
                      <option value="Davao del Norte">Davao del Norte</option>
                      <option value="Davao del Sur">Davao del Sur</option>
                      <option value="Davao Occidental">Davao Occidental</option>
                      <option value="Davao Oriental">Davao Oriental</option>
                      <option value="Dinagat Islands">Dinagat Islands</option>
                      <option value="Eastern Samar">Eastern Samar</option>
                      <option value="Guimaras">Guimaras</option>
                      <option value="Ifugao">Ifugao</option>
                      <option value="Ilocos Norte">Ilocos Norte</option>
                      <option value="Ilocos Sur">Ilocos Sur</option>
                      <option value="Iloilo">Iloilo</option>
                      <option value="Isabela">Isabela</option>
                      <option value="Kalinga">Kalinga</option>
                      <option value="La Union">La Union</option>
                      <option value="Laguna">Laguna</option>
                      <option value="Lanao del Norte">Lanao del Norte</option>
                      <option value="Lanao del Sur">Lanao del Sur</option>
                      <option value="Leyte">Leyte</option>
                      <option value="Maguindanao">Maguindanao</option>
                      <option value="Marinduque">Marinduque</option>
                      <option value="Masbate">Masbate</option>
                      <option value="Metro Manila">Metro Manila</option>
                      <option value="Misamis Occidental">Misamis Occidental</option>
                      <option value="Misamis Oriental">Misamis Oriental</option>
                      <option value="Mountain Province">Mountain Province</option>
                      <option value="Negros Occidental">Negros Occidental</option>
                      <option value="Negros Oriental">Negros Oriental</option>
                      <option value="Northern Samar">Northern Samar</option>
                      <option value="Nueva Ecija">Nueva Ecija</option>
                      <option value="Nueva Vizcaya">Nueva Vizcaya</option>
                      <option value="Occidental Mindoro">Occidental Mindoro</option>
                      <option value="Oriental Mindoro">Oriental Mindoro</option>
                      <option value="Palawan">Palawan</option>
                      <option value="Pampanga">Pampanga</option>
                      <option value="Pangasinan">Pangasinan</option>
                      <option value="Quezon">Quezon</option>
                      <option value="Quirino">Quirino</option>
                      <option value="Rizal">Rizal</option>
                      <option value="Romblon">Romblon</option>
                      <option value="Samar">Samar</option>
                      <option value="Sarangani">Sarangani</option>
                      <option value="Siquijor">Siquijor</option>
                      <option value="Sorsogon">Sorsogon</option>
                      <option value="South Cotabato">South Cotabato</option>
                      <option value="Southern Leyte">Southern Leyte</option>
                      <option value="Sultan Kudarat">Sultan Kudarat</option>
                      <option value="Sulu">Sulu</option>
                      <option value="Surigao del Norte">Surigao del Norte</option>
                      <option value="Surigao del Sur">Surigao del Sur</option>
                      <option value="Tarlac">Tarlac</option>
                      <option value="Tawi-Tawi">Tawi-Tawi</option>
                      <option value="Zambales">Zambales</option>
                      <option value="Zamboanga del Norte">Zamboanga del Norte</option>
                      <option value="Zamboanga del Sur">Zamboanga del Sur</option>
                      <option value="Zamboanga Sibugay">Zamboanga Sibugay</option>
                    </select>
                  </div>

                  {/* Phone */}
                  <div>
                    <label className="block text-sm font-medium text-black mb-2">Phone</label>
                    <input
                      type="tel"
                      placeholder="Phone"
                      value={customerPhone}
                      onChange={e => setCustomerPhone(e.target.value)}
                      className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                    />
                  </div>

                  {/* Save info */}
                  <div className="flex items-center gap-3">
                    <input type="checkbox" defaultChecked className="w-4 h-4" />
                    <span className="text-sm text-black">Save my information for a faster checkout</span>
                  </div>

                  {/* Text notification */}
                  <div className="flex items-center gap-3">
                    <input type="checkbox" className="w-4 h-4" />
                    <span className="text-sm text-black">Text me with news and offers</span>
                  </div>
                </div>
              </div>

              {/* Payment Section */}
              <div className="mb-8">
                <h3 className="text-lg font-semibold text-black mb-3">Payment</h3>
                <p className="text-sm text-gray-600 mb-4">All transactions are secure and encrypted.</p>

                <div className="border border-gray-400 rounded-t p-3 mb-0" style={{ borderBottom: 'none' }}>
                  <div className="flex items-center justify-between">
                    <span className="text-sm text-black">Secure Payments via PayMongo</span>
                    <div className="flex items-center gap-2">
                      {/* Visa */}
                      <span className="inline-flex items-center justify-center w-9 h-6 bg-white rounded border border-gray-200">
                        <img src="/images/payment-logo/visa.png" alt="Visa" style={{ width: '26px', height: '16px', objectFit: 'contain' }} />
                      </span>
                      {/* GCash */}
                      <span className="inline-flex items-center justify-center w-9 h-6 bg-white rounded border border-gray-200">
                        <img src="/images/payment-logo/GCASH.png" alt="GCash" style={{ width: '26px', height: '16px', objectFit: 'contain' }} />
                      </span>
                      {/* Maya */}
                      <span className="inline-flex items-center justify-center w-9 h-6 bg-white rounded border border-gray-200">
                        <img src="/images/payment-logo/MAYA.png" alt="Maya" style={{ width: '26px', height: '16px', objectFit: 'contain' }} />
                      </span>
                    </div>
                  </div>
                </div>
                <div className="border border-gray-400 border-t-0 rounded-b bg-gray-50 px-2 py-3 text-center text-sm text-black">
                  You'll be redirected to Secure Payments via PayMongo to complete your purchase.
                </div>
              </div>

              {/* Billing Address */}
              <div className="mb-8">
                <h2 className="text-lg font-semibold text-black mb-4">Billing address</h2>

                <label className="flex items-start gap-3 p-4 border border-gray-300 rounded mb-4 cursor-pointer">
                  <input
                    type="radio"
                    name="billing"
                    checked={billingAddressSame}
                    onChange={() => setBillingAddressSame(true)}
                    className="w-4 h-4 mt-1"
                  />
                  <span className="text-black font-medium">Same as shipping address</span>
                </label>

                <label className="flex items-start gap-3 p-4 border border-gray-300 rounded cursor-pointer">
                  <input
                    type="radio"
                    name="billing"
                    checked={!billingAddressSame}
                    onChange={() => setBillingAddressSame(false)}
                    className="w-4 h-4 mt-1"
                  />
                  <span className="text-black">Use a different billing address</span>
                </label>

                {!billingAddressSame && (
                  <div className="mt-4 space-y-4">
                    <div>
                      <label className="block text-sm font-medium text-black mb-2">Full name</label>
                      <input
                        type="text"
                        placeholder="Full name"
                        value={billingName}
                        onChange={e => setBillingName(e.target.value)}
                        className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-black mb-2">Phone</label>
                      <input
                        type="tel"
                        placeholder="Phone"
                        value={billingPhone}
                        onChange={e => setBillingPhone(e.target.value)}
                        className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-black mb-2">Address line</label>
                      <input
                        type="text"
                        placeholder="House no., street, building"
                        value={billingAddressLine}
                        onChange={e => setBillingAddressLine(e.target.value)}
                        className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-black mb-2">Barangay</label>
                      <input
                        type="text"
                        placeholder="Barangay"
                        value={billingBarangay}
                        onChange={e => setBillingBarangay(e.target.value)}
                        className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                      />
                    </div>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                      <div>
                        <label className="block text-sm font-medium text-black mb-2">City</label>
                        <input
                          type="text"
                          placeholder="City"
                          value={billingCity}
                          onChange={e => setBillingCity(e.target.value)}
                          className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                        />
                      </div>
                      <div>
                        <label className="block text-sm font-medium text-black mb-2">Region</label>
                        <input
                          type="text"
                          placeholder="Region"
                          value={billingRegion}
                          onChange={e => setBillingRegion(e.target.value)}
                          className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                        />
                      </div>
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-black mb-2">Postal code</label>
                      <input
                        type="text"
                        placeholder="Postal code"
                        value={billingPostalCode}
                        onChange={e => setBillingPostalCode(e.target.value)}
                        className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                      />
                    </div>
                  </div>
                )}
              </div>

              {/* Pay Now Button */}
              <button
                onClick={handlePayNow}
                disabled={isProcessing}
                className={`w-full py-3 rounded-md font-semibold text-white mb-8 transition-colors ${
                  isProcessing ? 'bg-gray-400 cursor-not-allowed' : 'bg-gray-900 hover:bg-gray-800'
                }`}
              >
                {isProcessing ? 'Processing...' : 'Pay now'}
              </button>

              {payError && (
                <div className="p-4 bg-red-50 border border-red-200 rounded text-red-600 text-sm mb-4">
                  {payError}
                </div>
              )}

              {/* Footer Links */}
              <div className="flex gap-6 justify-center text-xs text-gray-600 border-t border-gray-200 pt-6">
                <a href="#" className="underline">Refund policy</a>
                <a href="#" className="underline">Privacy policy</a>
                <a href="#" className="underline">Terms of service</a>
                <a href="#" className="underline">Cancellations</a>
                <a href="#" className="underline">Contact</a>
              </div>
            </div>

            {/* Right: Order Summary (sticky on md) */}
            <aside className="md:col-span-1 md:sticky md:top-4">
              <div className="border border-gray-300 rounded-lg p-6 bg-white">
                {/* Product Items */}
                <div className="border-b border-gray-200 pb-4 mb-4">
                  {checkoutData.items.map((item) => (
                    <div key={item.id} className="flex gap-4 mb-4 last:mb-0">
                      <div className="w-16 h-16 bg-gray-100 rounded overflow-hidden flex-shrink-0">
                        {item.image && <img src={item.image} alt={item.name} className="w-full h-full object-cover" />}
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className="text-sm font-medium text-black truncate">{item.name}</p>
                        <p className="text-xs text-gray-600 mt-1">Qty: {item.qty}</p>
                        {item.size && <p className="text-xs text-gray-600">Size: {item.size}</p>}
                        {item.color && <p className="text-xs text-gray-600">Color: {item.color}</p>}
                      </div>
                      <div className="text-right">
                        <p className="text-sm font-semibold text-black">₱{(item.price * item.qty).toLocaleString()}</p>
                      </div>
                    </div>
                  ))}
                </div>

                {/* Discount Code */}
                <div className="flex gap-2 mb-4">
                  <input
                    type="text"
                    placeholder="Discount code or gift card"
                    className="flex-1 px-3 py-2 border border-gray-300 rounded text-sm text-black placeholder-gray-400"
                  />
                  <button className="px-4 py-2 text-sm text-black border border-gray-300 rounded hover:bg-gray-50">
                    Apply
                  </button>
                </div>

                {/* Summary */}
                <div className="space-y-3 mb-4">
                  <div className="flex justify-between text-sm">
                    <span className="text-gray-600">Subtotal</span>
                    <span className="text-black font-medium">₱{subtotal.toLocaleString()}</span>
                  </div>
                  <div className="flex justify-between text-sm">
                    <span className="text-gray-600">Shipping</span>
                    <span className="text-black">Enter shipping address</span>
                  </div>
                </div>

                {/* Total */}
                <div className="border-t border-gray-200 pt-4">
                  <div className="flex justify-between items-baseline">
                    <span className="text-black">Total</span>
                    <div className="flex items-baseline gap-1">
                      <span className="text-xs text-gray-600">PHP</span>
                      <span className="text-2xl font-bold text-black">₱{total.toLocaleString()}</span>
                    </div>
                  </div>
                </div>
              </div>
            </aside>
          </div>
        </div>
      </main>

      {/* Footer */}
      <footer className="mt-12 bg-gray-100 text-slate-900">
        <div className="max-w-7xl mx-auto px-6 py-8">
          <div className="border-t border-gray-300 pt-6 text-xs text-slate-700 flex items-center justify-between">
            <div>© 2024 SOLESPACE. All rights reserved.</div>
            <div className="flex gap-6">
              <a href="#" className="hover:underline">Privacy</a>
              <a href="#" className="hover:underline">Terms</a>
              <a href="#" className="hover:underline">Cookies</a>
            </div>
          </div>
        </div>
      </footer>
    </div>
  );
};

export default Payment;
