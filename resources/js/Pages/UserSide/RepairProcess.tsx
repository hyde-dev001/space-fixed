import React, { useState, useEffect, useMemo } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import Navigation from './Navigation';
import Swal from 'sweetalert2';

interface RepairService {
  id: number;
  title: string;
  price: string | number;
  description: string;
  category?: string;
  duration?: string;
}

interface ShopDetails {
  id: number;
  name: string;
  location: string;
}

const RepairProcess: React.FC = () => {
  // Get URL params for pre-selected services
  const urlParams = new URLSearchParams(window.location.search);
  const preSelectedIds = urlParams.get('services')?.split(',').map(Number).filter(Boolean) || [];
  const shopId = urlParams.get('shop');

  // Retrieve stored services from localStorage
  const [repairServices, setRepairServices] = useState<RepairService[]>([]);
  const [shopDetails, setShopDetails] = useState<ShopDetails | null>(null);
  const [isLoadingServices, setIsLoadingServices] = useState(true);

  useEffect(() => {
    const loadServices = async () => {
      setIsLoadingServices(true);
      
      // First, try to load from localStorage (from shop page)
      const storedServices = localStorage.getItem('selectedRepairServices');
      const storedShop = localStorage.getItem('shopDetails');
      
      if (storedServices) {
        console.log('Loading services from localStorage');
        const services = JSON.parse(storedServices);
        // Convert price to number and ensure proper format
        const formattedServices = services.map((s: any) => ({
          ...s,
          title: s.title || s.name, // Handle both title and name fields
          price: typeof s.price === 'string' ? parseFloat(s.price.replace(/[^0-9.]/g, '')) : s.price,
        }));
        setRepairServices(formattedServices);
        console.log('Loaded services from localStorage:', formattedServices);
      } else {
        // If no localStorage data, fetch from API
        // Fetch for specific shop if shopId provided, otherwise fetch all services
        try {
          const apiUrl = shopId 
            ? `/api/repair-services?shop_id=${shopId}` 
            : '/api/repair-services';
          
          console.log('Fetching services from API:', apiUrl);
          const response = await fetch(apiUrl);
          const data = await response.json();
          
          console.log('API response:', data);
          
          if (data.success && data.data && data.data.length > 0) {
            const formattedServices = data.data.map((service: any) => ({
              id: service.id,
              title: service.name,
              price: parseFloat(service.price),
              description: service.description || 'Professional repair service',
              category: service.category,
              duration: service.duration,
            }));
            setRepairServices(formattedServices);
            console.log('Loaded services from API:', formattedServices);
          } else {
            console.log('No services found in API response');
          }
        } catch (error) {
          console.error('Failed to fetch services:', error);
          Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Failed to load repair services',
            confirmButtonColor: '#000000',
          });
        }
      }
      
      if (storedShop) {
        setShopDetails(JSON.parse(storedShop));
      }
      
      setIsLoadingServices(false);
    };
    
    loadServices();
  }, [shopId]);

  const [formData, setFormData] = useState({
    customerName: '',
    email: '',
    phone: '',
    shoeType: '',
    brand: '',
    description: '',
    serviceType: '', // 'pickup' or 'walkin'
    pickupAddressLine: '',
    pickupBarangay: '',
    pickupCity: '',
    pickupRegion: '',
    pickupPostalCode: '',
  });
  const [selectedServices, setSelectedServices] = useState<number[]>([]);

  // Set selected services once repair services are loaded
  useEffect(() => {
    if (repairServices.length > 0 && preSelectedIds.length > 0) {
      setSelectedServices(preSelectedIds);
    }
  }, [repairServices]);
  const [imageUploadGroups, setImageUploadGroups] = useState<Array<{id: string; file: File | null; preview: string}>>([{id: '0', file: null, preview: ''}]);
  const [isSubmitting, setIsSubmitting] = useState(false);


  // Calculate totals
  const servicesTotal = useMemo(() => {
    return selectedServices.reduce((sum, serviceId) => {
      const service = repairServices.find(s => s.id === serviceId);
      const price = typeof service?.price === 'string' 
        ? parseFloat(service.price.replace(/[^0-9.]/g, '')) || 0
        : service?.price || 0;
      return sum + price;
    }, 0);
  }, [selectedServices, repairServices]);

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

    if (!formData.serviceType) {
      Swal.fire({
        title: 'Service Type Required',
        text: 'Please select whether you prefer Pick up or Walk-in service',
        icon: 'warning',
        confirmButtonColor: '#000000',
      });
      return;
    }

    if (formData.serviceType === 'pickup') {
      const missingPickupFields =
        !formData.pickupAddressLine ||
        !formData.pickupBarangay ||
        !formData.pickupCity ||
        !formData.pickupRegion ||
        !formData.pickupPostalCode;

      if (missingPickupFields) {
        Swal.fire({
          title: 'Pickup Address Required',
          text: 'Please complete the pickup address details',
          icon: 'warning',
          confirmButtonColor: '#000000',
        });
        return;
      }
    }

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

    setIsSubmitting(true);

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      
      // Prepare form data for submission
      const submitFormData = new FormData();
      submitFormData.append('customer_name', formData.customerName);
      submitFormData.append('email', formData.email);
      submitFormData.append('phone', formData.phone);
      submitFormData.append('shoe_type', formData.shoeType);
      submitFormData.append('brand', formData.brand);
      submitFormData.append('description', formData.description);
      submitFormData.append('service_type', formData.serviceType);
      submitFormData.append('shop_owner_id', shopId || '');
      
      // Only add pickup address fields if service type is pickup
      if (formData.serviceType === 'pickup') {
        submitFormData.append('pickup_address_line', formData.pickupAddressLine);
        submitFormData.append('pickup_barangay', formData.pickupBarangay);
        submitFormData.append('pickup_city', formData.pickupCity);
        submitFormData.append('pickup_region', formData.pickupRegion);
        submitFormData.append('pickup_postal_code', formData.pickupPostalCode);
      }
      
      // Add selected service IDs
      selectedServices.forEach((serviceId, index) => {
        submitFormData.append(`services[${index}]`, serviceId.toString());
      });
      
      // Add images
      imageUploadGroups.forEach((group, index) => {
        if (group.file) {
          submitFormData.append(`images[${index}]`, group.file);
        }
      });
      
      // Calculate total
      submitFormData.append('total', grandTotal.toString());

      const response = await fetch('/api/repair-requests', {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrfToken || '',
          'Accept': 'application/json',
        },
        body: submitFormData,
      });

      const data = await response.json();
      
      if (data.success) {
        setIsSubmitting(false);
        
        const result = await Swal.fire({
          title: 'Request Submitted!',
          text: `Your repair request has been submitted successfully. Total: ₱${grandTotal.toLocaleString()}. We will contact you shortly.`,
          icon: 'success',
          confirmButtonColor: '#000000',
        });

        if (result.isConfirmed) {
          // Clear localStorage
          localStorage.removeItem('selectedRepairServices');
          localStorage.removeItem('shopDetails');
          
          // Reset form
          setFormData({
            customerName: '',
            email: '',
            phone: '',
            shoeType: '',
            brand: '',
            description: '',
            serviceType: '',
            pickupAddressLine: '',
            pickupBarangay: '',
            pickupCity: '',
            pickupRegion: '',
            pickupPostalCode: '',
          });
          setSelectedServices([]);
          setImageUploadGroups([{id: '0', file: null, preview: ''}]);
          
          // Redirect to home or repair services page
          window.location.href = '/repair-services';
        }
      } else {
        // Show validation errors if available
        setIsSubmitting(false);
        
        let errorMessage = data.message || 'Failed to submit request';
        
        // If there are specific validation errors, show them
        if (data.errors) {
          const errorList = Object.entries(data.errors)
            .map(([field, messages]: [string, any]) => {
              const fieldName = field.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
              return `${fieldName}: ${Array.isArray(messages) ? messages.join(', ') : messages}`;
            })
            .join('\n');
          errorMessage = errorList;
        }
        
        await Swal.fire({
          title: 'Validation Failed',
          html: `<pre style="text-align: left; font-size: 12px; max-height: 300px; overflow-y: auto;">${errorMessage}</pre>`,
          icon: 'error',
          confirmButtonColor: '#000000',
        });
        return;
      }
    } catch (error: any) {
      console.error('Failed to submit repair request:', error);
      setIsSubmitting(false);
      
      Swal.fire({
        title: 'Submission Failed',
        text: error.message || 'Failed to submit repair request. Please try again.',
        icon: 'error',
        confirmButtonColor: '#000000',
      });
    }
  };

  return (
    <div className="min-h-screen flex flex-col bg-white">
      <Head title="Request Repair Service" />
      <Navigation />

      <main className="flex-1">
        <div className="py-12 px-20">
          {/* Header */}
          <div className="mb-12">
            <h1 className="text-3xl font-bold text-black mb-2">Request Repair Service</h1>
            <p className="text-sm text-gray-600">Fill out the form below and upload images of your shoes to get started</p>
          </div>

          <form onSubmit={handleSubmit}>
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-12">
              {/* Left Column - Form Fields (2/3 width) */}
              <div className="lg:col-span-2 space-y-8">
                {/* Customer Information */}
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

                  {/* Urgency removed per request */}
                </div>

                {/* Shoe Details */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
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

                    {/* Service Type Selection - Pick Up or Walk In */}
                    <div className="md:col-span-2">
                      <label className="block text-sm font-bold text-black mb-3">
                        Service Type *
                      </label>
                      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {/* Pick Up Option */}
                        <div 
                          onClick={() => setFormData(prev => ({ ...prev, serviceType: 'pickup' }))}
                          className={`relative p-6 border-2 rounded-xl cursor-pointer transition-all ${
                            formData.serviceType === 'pickup' 
                              ? 'border-black bg-black/5' 
                              : 'border-gray-200 hover:border-gray-300'
                          }`}
                        >
                          <div className="flex items-center gap-3">
                            <div className={`w-6 h-6 rounded-full border-2 flex items-center justify-center flex-shrink-0 ${
                              formData.serviceType === 'pickup' 
                                ? 'border-black' 
                                : 'border-gray-300'
                            }`}>
                              {formData.serviceType === 'pickup' && (
                                <div className="w-3 h-3 bg-black rounded-full"></div>
                              )}
                            </div>
                            <div>
                              <p className="font-bold text-black">Pick Up</p>
                              <p className="text-sm text-gray-600">Shipping fee is shouldered by the customer</p>
                            </div>
                          </div>
                        </div>

                        {/* Walk In Option */}
                        <div 
                          onClick={() => setFormData(prev => ({ ...prev, serviceType: 'walkin' }))}
                          className={`relative p-6 border-2 rounded-xl cursor-pointer transition-all ${
                            formData.serviceType === 'walkin' 
                              ? 'border-black bg-black/5' 
                              : 'border-gray-200 hover:border-gray-300'
                          }`}
                        >
                          <div className="flex items-center gap-3">
                            <div className={`w-6 h-6 rounded-full border-2 flex items-center justify-center flex-shrink-0 ${
                              formData.serviceType === 'walkin' 
                                ? 'border-black' 
                                : 'border-gray-300'
                            }`}>
                              {formData.serviceType === 'walkin' && (
                                <div className="w-3 h-3 bg-black rounded-full"></div>
                              )}
                            </div>
                            <div>
                              <p className="font-bold text-black">Walk In</p>
                              <p className="text-sm text-gray-600">
                                {shopDetails
                                  ? `Bring to ${shopDetails.name} - ${shopDetails.location}`
                                  : 'I will bring my shoes to the shop'}
                              </p>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>

                    {formData.serviceType === 'pickup' && (
                      <div className="md:col-span-2">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                          <div className="md:col-span-2">
                            <label className="block text-sm font-bold text-black mb-2">
                              Address line
                            </label>
                            <input
                              type="text"
                              name="pickupAddressLine"
                              value={formData.pickupAddressLine}
                              onChange={handleInputChange}
                              className="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-black focus:outline-none text-black transition-colors"
                              placeholder="House no., street, building"
                              required
                            />
                          </div>

                          <div className="md:col-span-2">
                            <label className="block text-sm font-bold text-black mb-2">
                              Barangay
                            </label>
                            <input
                              type="text"
                              name="pickupBarangay"
                              value={formData.pickupBarangay}
                              onChange={handleInputChange}
                              className="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-black focus:outline-none text-black transition-colors"
                              placeholder="Barangay"
                              required
                            />
                          </div>

                          <div>
                            <label className="block text-sm font-bold text-black mb-2">
                              City
                            </label>
                            <input
                              type="text"
                              name="pickupCity"
                              value={formData.pickupCity}
                              onChange={handleInputChange}
                              className="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-black focus:outline-none text-black transition-colors"
                              placeholder="City"
                              required
                            />
                          </div>

                          <div>
                            <label className="block text-sm font-bold text-black mb-2">
                              Region
                            </label>
                            <input
                              type="text"
                              name="pickupRegion"
                              value={formData.pickupRegion}
                              onChange={handleInputChange}
                              className="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-black focus:outline-none text-black transition-colors"
                              placeholder="Region"
                              required
                            />
                          </div>

                          <div className="md:col-span-2">
                            <label className="block text-sm font-bold text-black mb-2">
                              Postal code
                            </label>
                            <input
                              type="text"
                              name="pickupPostalCode"
                              value={formData.pickupPostalCode}
                              onChange={handleInputChange}
                              className="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-black focus:outline-none text-black transition-colors"
                              placeholder="Postal code"
                              required
                            />
                          </div>
                        </div>
                      </div>
                    )}

                    {/* Repair Services Selection */}
                    <div className="md:col-span-2">
                      <label className="block text-sm font-bold text-black mb-3">
                        Select Repair Services *
                      </label>
                      
                      {isLoadingServices ? (
                        <div className="flex items-center justify-center py-8">
                          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-black"></div>
                          <span className="ml-3 text-gray-600 text-sm">Loading services...</span>
                        </div>
                      ) : repairServices.length === 0 ? (
                        <div className="text-center py-8 bg-gray-50 rounded-xl">
                          <p className="text-gray-500 font-medium text-sm">No repair services available</p>
                          <p className="text-xs text-gray-400 mt-2">Please contact the shop for assistance</p>
                        </div>
                      ) : (
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                          {repairServices.map((service) => (
                          <div
                            key={service.id}
                            onClick={() => handleServiceToggle(service.id)}
                            className={`border-2 rounded-lg p-4 cursor-pointer transition-all ${
                              selectedServices.includes(service.id)
                                ? 'border-black bg-gray-50 shadow-sm'
                                : 'border-gray-200 hover:border-gray-300 bg-white'
                            }`}
                          >
                            <div className="flex items-start justify-between">
                              <div className="flex-1">
                                <h3 className="font-bold text-black text-sm mb-1">{service.title}</h3>
                                <p className="text-xs text-gray-600 mb-1">{service.description}</p>
                                <div className="text-lg font-bold text-black">
                                  {typeof service.price === 'string' ? service.price : service.price > 0 ? `₱${service.price}` : 'Price varies'}
                                </div>
                              </div>
                              <div className={`w-5 h-5 rounded-full border-2 flex items-center justify-center flex-shrink-0 transition-all ${
                                selectedServices.includes(service.id)
                                  ? 'border-black bg-black'
                                  : 'border-gray-300'
                              }`}>
                                {selectedServices.includes(service.id) && (
                                  <svg className="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                                    <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                  </svg>
                                )}
                              </div>
                            </div>
                          </div>
                        ))}
                        </div>
                      )}
                    </div>

                    {/* Image Upload */}
                    <div className="md:col-span-2">
                      <label className="block text-sm font-bold text-black mb-3">
                        Upload Images *
                      </label>
                      <p className="text-sm text-gray-600 mb-4">Upload up to 5 images of your shoes (front, back, damaged areas)</p>

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

              </div>

              {/* Right Column - Order Summary (1/3 width) */}
              <div className="lg:col-span-1">
                <div>
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
                                  {typeof service.price === 'string' ? service.price : service.price > 0 ? `₱${service.price}` : 'TBD'}
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

                        <div className="mt-6 p-4 border border-gray-200 rounded-xl">
                          <div className="flex items-start gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5 text-gray-700 flex-shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                              <circle cx="12" cy="12" r="10"></circle>
                              <line x1="12" y1="16" x2="12" y2="12"></line>
                              <line x1="12" y1="8" x2="12.01" y2="8"></line>
                            </svg>
                            <p className="text-sm text-gray-800 leading-relaxed">
                              You will not be charged at the moment. Once submitted, wait for the shop to accept your request. You can check the status on the Repair page under the people icon thank you for choosing Solespace as your safe space!.
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
