import React, { useState, useEffect, useMemo } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import Navigation from './Navigation';
import Swal from 'sweetalert2';

// Service definitions with prices
const repairServices = [
  { id: 1, title: 'Sole Replacement', price: 450, description: 'Complete sole replacement with premium materials' },
  { id: 2, title: 'Heel Repair', price: 300, description: 'Professional heel repair and restoration' },
  { id: 3, title: 'Stitching & Patch', price: 250, description: 'Expert stitching and patching services' },
  { id: 4, title: 'Deep Clean & Condition', price: 200, description: 'Deep cleaning and leather conditioning' },
  { id: 5, title: 'Zipper Replacement', price: 350, description: 'High-quality zipper replacement' },
  { id: 6, title: 'Color Restoration', price: 400, description: 'Professional color restoration and touch-up' },
  { id: 7, title: 'Stretching', price: 150, description: 'Professional shoe stretching service' },
  { id: 8, title: 'Other', price: 0, description: 'Custom repair service (price varies)' },
];

const RepairProcess: React.FC = () => {
  // Get URL params for pre-selected services
  const urlParams = new URLSearchParams(window.location.search);
  const preSelectedIds = urlParams.get('services')?.split(',').map(Number).filter(Boolean) || [];

  const [formData, setFormData] = useState({
    customerName: '',
    email: '',
    phone: '',
    shoeType: '',
    brand: '',
    description: '',
  });
  const [selectedServices, setSelectedServices] = useState<number[]>(preSelectedIds);
  const [imageUploadGroups, setImageUploadGroups] = useState<Array<{id: string; file: File | null; preview: string}>>([{id: '0', file: null, preview: ''}]);
  const [isSubmitting, setIsSubmitting] = useState(false);


  // Calculate totals
  const servicesTotal = useMemo(() => {
    return selectedServices.reduce((sum, serviceId) => {
      const service = repairServices.find(s => s.id === serviceId);
      return sum + (service?.price || 0);
    }, 0);
  }, [selectedServices]);

  const grandTotal = servicesTotal;

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) => {
    const { name, value } = e.target;
    setFormData(prev => ({ ...prev, [name]: value }));
  };

  const handleServiceToggle = (serviceId: number) => {
    setSelectedServices(prev => 
      prev.includes(serviceId)
        ? prev.filter(id => id !== serviceId)
        : [...prev, serviceId]
    );
  };

  const handleImageUpload = (id: string, e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      const reader = new FileReader();
      reader.onloadend = () => {
        setImageUploadGroups(prev => 
          prev.map(group => 
            group.id === id 
              ? {id, file, preview: reader.result as string}
              : group
          )
        );
      };
      reader.readAsDataURL(file);
    }
  };

  const addImageUploadBox = () => {
    const totalImages = imageUploadGroups.filter(g => g.file).length;
    if (totalImages >= 5) {
      Swal.fire({
        title: 'Too Many Images',
        text: 'You can upload a maximum of 5 images',
        icon: 'warning',
        confirmButtonColor: '#000000',
      });
      return;
    }
    const newId = Date.now().toString();
    setImageUploadGroups(prev => [...prev, {id: newId, file: null, preview: ''}]);
  };

  const removeImageBox = (id: string) => {
    setImageUploadGroups(prev => prev.filter(group => group.id !== id));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    
    // Validation
    if (!formData.customerName || !formData.email || !formData.phone) {
      Swal.fire({
        title: 'Missing Information',
        text: 'Please fill in all required fields',
        icon: 'warning',
        confirmButtonColor: '#000000',
      });
      return;
    }

    if (selectedServices.length === 0) {
      Swal.fire({
        title: 'No Services Selected',
        text: 'Please select at least one repair service',
        icon: 'warning',
        confirmButtonColor: '#000000',
      });
      return;
    }

    if (images.length === 0) {
      const hasImages = imageUploadGroups.some(g => g.file);
      if (!hasImages) {
        Swal.fire({
          title: 'No Images',
          text: 'Please upload at least one image of your shoes',
          icon: 'warning',
          confirmButtonColor: '#000000',
        });
        return;
      }
    }

    setIsSubmitting(true);

    // Simulate form submission
      setTimeout(async () => {
      setIsSubmitting(false);
      
        const result = await Swal.fire({
          title: 'Request Submitted!',
          text: `Your repair request has been submitted successfully. Total: ₱${grandTotal.toLocaleString()}. We will contact you shortly.`,
          icon: 'success',
          confirmButtonColor: '#000000',
        });

        if (result.isConfirmed) {
          // Reset form
          setFormData({
            customerName: '',
            email: '',
            phone: '',
            shoeType: '',
            brand: '',
            description: '',
          });
        setSelectedServices([]);
        setImageUploadGroups([{id: '0', file: null, preview: ''}]);
      }
    }, 1500);
  };

  return (
    <div className="min-h-screen flex flex-col bg-white">
      <Head title="Request Repair Service" />
      <Navigation />

      <main className="flex-1">
        <div className="max-w-7xl mx-auto py-12 px-6">
          {/* Header */}
          <div className="mb-10">
            <h1 className="text-5xl font-bold mb-4 text-black">Request Repair Service</h1>
            <p className="text-gray-600 text-lg">Fill out the form below and upload images of your shoes to get started</p>
          </div>

          <form onSubmit={handleSubmit}>
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
              {/* Left Column - Form Fields (2/3 width) */}
              <div className="lg:col-span-2 space-y-8">
                {/* Customer Information */}
                <div className="bg-white rounded-2xl border-2 border-gray-200 p-8 shadow-sm hover:shadow-md transition-shadow">
                  <div className="flex items-center gap-3 mb-6">
                    <div className="w-10 h-10 bg-black rounded-xl flex items-center justify-center">
                      <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                      </svg>
                    </div>
                    <h2 className="text-2xl font-bold text-black">Customer Information</h2>
                  </div>
              
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                      <label className="block text-sm font-bold text-black mb-2">
                        Full Name *
                      </label>
                      <input
                        type="text"
                        name="customerName"
                        value={formData.customerName}
                        onChange={handleInputChange}
                        className="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-black focus:outline-none text-black transition-colors"
                        placeholder="Juan Dela Cruz"
                        required
                      />
                    </div>

                    <div>
                      <label className="block text-sm font-bold text-black mb-2">
                        Email Address *
                      </label>
                      <input
                        type="email"
                        name="email"
                        value={formData.email}
                        onChange={handleInputChange}
                        className="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-black focus:outline-none text-black transition-colors"
                        placeholder="juan@example.com"
                        required
                      />
                    </div>

                    <div>
                      <label className="block text-sm font-bold text-black mb-2">
                        Phone Number *
                      </label>
                      <input
                        type="tel"
                        name="phone"
                        value={formData.phone}
                        onChange={handleInputChange}
                        className="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-black focus:outline-none text-black transition-colors"
                        placeholder="+63 912 345 6789"
                        required
                      />
                    </div>

                    {/* Urgency removed per request */}
                  </div>
                </div>

                {/* Shoe Details */}
                <div className="bg-white rounded-2xl border-2 border-gray-200 p-8 shadow-sm hover:shadow-md transition-shadow">
                  <div className="flex items-center gap-3 mb-6">
                    <div className="w-10 h-10 bg-black rounded-xl flex items-center justify-center">
                      <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                      </svg>
                    </div>
                    <h2 className="text-2xl font-bold text-black">Shoe Details</h2>
                  </div>
              
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                      <label className="block text-sm font-bold text-black mb-2">
                        Shoe Type
                      </label>
                      <input
                        type="text"
                        name="shoeType"
                        value={formData.shoeType}
                        onChange={handleInputChange}
                        className="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-black focus:outline-none text-black transition-colors"
                        placeholder="e.g., Sneakers, Boots, Loafers"
                      />
                    </div>

                    <div>
                      <label className="block text-sm font-bold text-black mb-2">
                        Brand
                      </label>
                      <input
                        type="text"
                        name="brand"
                        value={formData.brand}
                        onChange={handleInputChange}
                        className="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-black focus:outline-none text-black transition-colors"
                        placeholder="e.g., Nike, Adidas"
                      />
                    </div>

                    <div className="md:col-span-2">
                      <label className="block text-sm font-bold text-black mb-2">
                        Description
                      </label>
                      <textarea
                        name="description"
                        value={formData.description}
                        onChange={handleInputChange}
                        rows={4}
                        className="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-black focus:outline-none text-black resize-none transition-colors"
                        placeholder="Describe the issue or repair needed..."
                      />
                    </div>
                  </div>
                </div>

                {/* Repair Services Selection */}
                <div className="bg-white rounded-2xl border-2 border-gray-200 p-8 shadow-sm hover:shadow-md transition-shadow">
                  <div className="flex items-center gap-3 mb-6">
                    <div className="w-10 h-10 bg-black rounded-xl flex items-center justify-center">
                      <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <path d="M9 11l3 3L22 4"></path>
                        <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                      </svg>
                    </div>
                    <h2 className="text-2xl font-bold text-black">Select Repair Services *</h2>
                  </div>
                  
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {repairServices.map((service) => (
                      <div
                        key={service.id}
                        onClick={() => handleServiceToggle(service.id)}
                        className={`border-2 rounded-xl p-5 cursor-pointer transition-all ${
                          selectedServices.includes(service.id)
                            ? 'border-black bg-gray-50 shadow-md'
                            : 'border-gray-200 hover:border-gray-300 bg-white'
                        }`}
                      >
                        <div className="flex items-start justify-between mb-3">
                          <div className="flex-1">
                            <h3 className="font-bold text-black text-lg mb-1">{service.title}</h3>
                            <p className="text-sm text-gray-600 mb-2">{service.description}</p>
                            <div className="text-xl font-bold text-black">
                              {service.price > 0 ? `₱${service.price}` : 'Price varies'}
                            </div>
                          </div>
                          <div className={`w-6 h-6 rounded-full border-2 flex items-center justify-center flex-shrink-0 transition-all ${
                            selectedServices.includes(service.id)
                              ? 'border-black bg-black'
                              : 'border-gray-300'
                          }`}>
                            {selectedServices.includes(service.id) && (
                              <svg className="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                              </svg>
                            )}
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>

                {/* Image Upload */}
                <div className="bg-white rounded-2xl border-2 border-gray-200 p-8 shadow-sm hover:shadow-md transition-shadow">
                  <div className="flex items-center gap-3 mb-6">
                    <div className="w-10 h-10 bg-black rounded-xl flex items-center justify-center">
                      <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                        <polyline points="21 15 16 10 5 21"></polyline>
                      </svg>
                    </div>
                    <h2 className="text-2xl font-bold text-black">Upload Images *</h2>
                  </div>
                  <p className="text-sm text-gray-600 mb-6">Upload up to 5 images of your shoes (front, back, damaged areas)</p>

                  <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                    {imageUploadGroups.map((group, index) => (
                      <div key={group.id} className="relative group">
                        {group.preview ? (
                          <div className="relative inline-block w-full">
                            <img 
                              src={group.preview} 
                              alt={`Preview ${index + 1}`} 
                              className="w-full h-32 object-cover border-2 border-gray-200 rounded-xl"
                            />
                            <div className="absolute inset-0 bg-black/0 group-hover:bg-black/60 transition-all flex items-center justify-center gap-2 opacity-0 group-hover:opacity-100 rounded-xl">
                              <button
                                type="button"
                                onClick={addImageUploadBox}
                                className="bg-white hover:bg-gray-100 text-black rounded-full p-2 transition-all shadow-lg"
                                title="Add another image"
                              >
                                <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                                </svg>
                              </button>
                              {imageUploadGroups.length > 1 && (
                                <button
                                  type="button"
                                  onClick={() => removeImageBox(group.id)}
                                  className="bg-red-600 hover:bg-red-700 text-white rounded-full p-2 transition-all shadow-lg"
                                  title="Remove image"
                                >
                                  <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                  </svg>
                                </button>
                              )}
                            </div>
                          </div>
                        ) : (
                          <div className="border-2 border-dashed border-gray-300 hover:border-black rounded-xl p-4 text-center h-32 flex flex-col items-center justify-center transition-colors">
                            <input
                              type="file"
                              accept="image/*"
                              onChange={(e) => handleImageUpload(group.id, e)}
                              className="hidden"
                              id={`image-upload-${group.id}`}
                            />
                            <label htmlFor={`image-upload-${group.id}`} className="cursor-pointer block w-full h-full flex flex-col items-center justify-center">
                              <svg className="w-8 h-8 text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                              </svg>
                              <p className="text-xs text-gray-600 font-medium">Upload</p>
                            </label>
                          </div>
                        )}
                      </div>
                    ))}
                  </div>
                </div>
              </div>

              {/* Right Column - Order Summary (1/3 width, Sticky) */}
              <div className="lg:col-span-1">
                <div className="sticky top-6">
                  <div className="bg-gradient-to-br from-gray-50 to-white rounded-2xl border-2 border-gray-200 p-8 shadow-lg">
                    <div className="flex items-center gap-3 mb-6">
                      <div className="w-10 h-10 bg-black rounded-xl flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                          <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                        </svg>
                      </div>
                      <h3 className="text-2xl font-bold text-black">Order Summary</h3>
                    </div>

                    {selectedServices.length === 0 ? (
                      <div className="text-center py-8">
                        <svg xmlns="http://www.w3.org/2000/svg" className="w-16 h-16 text-gray-300 mx-auto mb-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
                          <path d="M9 11l3 3L22 4"></path>
                          <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                        </svg>
                        <p className="text-gray-500 font-medium">No services selected</p>
                        <p className="text-sm text-gray-400 mt-2">Select repair services to see pricing</p>
                      </div>
                    ) : (
                      <div>
                        <div className="space-y-4 mb-6">
                          <div className="text-sm font-bold text-gray-500 uppercase tracking-wider">Selected Services</div>
                          {selectedServices.map(serviceId => {
                            const service = repairServices.find(s => s.id === serviceId);
                            if (!service) return null;
                            return (
                              <div key={service.id} className="flex justify-between items-start py-3 border-b border-gray-200">
                                <div className="flex-1">
                                  <div className="font-bold text-black text-sm">{service.title}</div>
                                  <div className="text-xs text-gray-500 mt-1">{service.description}</div>
                                </div>
                                <div className="font-bold text-black ml-4">
                                  {service.price > 0 ? `₱${service.price}` : 'TBD'}
                                </div>
                              </div>
                            );
                          })}
                        </div>

                        <div className="border-t-2 border-gray-300 pt-6 space-y-3">
                          <div className="flex justify-between text-base">
                            <span className="text-gray-700">Services Subtotal</span>
                            <span className="font-bold text-black">₱{servicesTotal.toLocaleString()}</span>
                          </div>
                          {/* Labor fee removed */}
                          <div className="flex justify-between text-xl pt-4 border-t-2 border-gray-300">
                            <span className="font-bold text-black">Grand Total</span>
                            <span className="font-bold text-black">₱{grandTotal.toLocaleString()}</span>
                          </div>
                        </div>

                        <div className="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-xl">
                          <div className="flex items-start gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                              <circle cx="12" cy="12" r="10"></circle>
                              <line x1="12" y1="16" x2="12" y2="12"></line>
                              <line x1="12" y1="8" x2="12.01" y2="8"></line>
                            </svg>
                            <p className="text-xs text-blue-900">
                              Final pricing may vary based on actual shoe condition. You'll receive a detailed quote after inspection.
                            </p>
                          </div>
                        </div>
                      </div>
                    )}
                  </div>

                  {/* Submit Buttons */}
                  <div className="mt-6 space-y-3">
                    <button
                      type="submit"
                      disabled={isSubmitting || selectedServices.length === 0}
                      className={`w-full bg-black text-white px-8 py-4 rounded-2xl font-bold text-lg transition-all shadow-lg ${
                        isSubmitting || selectedServices.length === 0
                          ? 'opacity-50 cursor-not-allowed' 
                          : 'hover:bg-gray-800 hover:shadow-xl active:scale-[0.98]'
                      }`}
                    >
                      {isSubmitting ? 'Submitting...' : 'Submit Request'}
                    </button>
                    <Link
                      href="/repair-services"
                      className="block w-full border-2 border-gray-300 text-gray-700 px-8 py-4 rounded-2xl text-center font-bold text-lg hover:border-black hover:text-black transition-all"
                    >
                      Cancel
                    </Link>
                  </div>
                </div>
              </div>
            </div>
          </form>
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

export default RepairProcess;
