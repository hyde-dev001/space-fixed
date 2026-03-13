import React, { useState, useEffect, useMemo, useRef } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import Swal from '@/Pages/UserSide/Shared/UserModal';

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

interface RepairProcessPageProps {
  auth?: {
    user?: {
      id: number;
      name?: string;
      email?: string;
    } | null;
  };
}

const REGION_OPTIONS = [
  'Abra',
  'Agusan del Norte',
  'Agusan del Sur',
  'Aklan',
  'Albay',
  'Antique',
  'Apayao',
  'Aurora',
  'Basilan',
  'Bataan',
  'Batanes',
  'Batangas',
  'Benguet',
  'Biliran',
  'Bohol',
  'Bukidnon',
  'Bulacan',
  'Cagayan',
  'Camarines Norte',
  'Camarines Sur',
  'Camiguin',
  'Capiz',
  'Catanduanes',
  'Cavite',
  'Cebu',
  'Cotabato',
  'Compostela Valley',
  'Davao del Norte',
  'Davao del Sur',
  'Davao Occidental',
  'Davao Oriental',
  'Dinagat Islands',
  'Eastern Samar',
  'Guimaras',
  'Ifugao',
  'Ilocos Norte',
  'Ilocos Sur',
  'Iloilo',
  'Isabela',
  'Kalinga',
  'La Union',
  'Laguna',
  'Lanao del Norte',
  'Lanao del Sur',
  'Leyte',
  'Maguindanao',
  'Marinduque',
  'Masbate',
  'Metro Manila',
  'Misamis Occidental',
  'Misamis Oriental',
  'Mountain Province',
  'Negros Occidental',
  'Negros Oriental',
  'Northern Samar',
  'Nueva Ecija',
  'Nueva Vizcaya',
  'Occidental Mindoro',
  'Oriental Mindoro',
  'Palawan',
  'Pampanga',
  'Pangasinan',
  'Quezon',
  'Quirino',
  'Rizal',
  'Romblon',
  'Samar',
  'Sarangani',
  'Siquijor',
  'Sorsogon',
  'South Cotabato',
  'Southern Leyte',
  'Sultan Kudarat',
  'Sulu',
  'Surigao del Norte',
  'Surigao del Sur',
  'Tarlac',
  'Tawi-Tawi',
  'Zambales',
  'Zamboanga del Norte',
  'Zamboanga del Sur',
  'Zamboanga Sibugay',
];

const SHOE_TYPE_OPTIONS = [
  'Sneakers',
  'Running Shoes',
  'Training Shoes',
  'Basketball Shoes',
  'Tennis Shoes',
  'Football Cleats',
  'Soccer Cleats',
  'Golf Shoes',
  'Skate Shoes',
  'Hiking Shoes',
  'Trail Shoes',
  'Boots',
  'Work Boots',
  'Chelsea Boots',
  'Combat Boots',
  'Dress Shoes',
  'Oxfords',
  'Derbies',
  'Loafers',
  'Brogues',
  'Monk Strap Shoes',
  'Flats',
  'Heels',
  'Pumps',
  'Wedges',
  'Sandals',
  'Slides',
  'Slippers',
  'Flip-flops',
  'Mules',
  'Clogs',
  'Espadrilles',
  'Boat Shoes',
  'Canvas Shoes',
  'High-top Shoes',
  'Low-top Shoes',
  'School Shoes',
  'Baby Shoes',
  'Other',
];

const CHECKOUT_INFO_STORAGE_KEY = 'repair_process_checkout_info';

const RepairProcess: React.FC = () => {
  const page = usePage<RepairProcessPageProps>();
  const authUser = page.props.auth?.user ?? null;
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
  const [isShoeTypeOpen, setIsShoeTypeOpen] = useState(false);
  const [isPickupRegionOpen, setIsPickupRegionOpen] = useState(false);
  const [saveInfoForCheckout, setSaveInfoForCheckout] = useState(false);
  const shoeTypeDropdownRef = useRef<HTMLDivElement | null>(null);
  const pickupRegionDropdownRef = useRef<HTMLDivElement | null>(null);

  // Set selected services once repair services are loaded
  useEffect(() => {
    if (repairServices.length > 0 && preSelectedIds.length > 0) {
      setSelectedServices([preSelectedIds[0]]);
    }
  }, [repairServices]);
  const [imageUploadGroups, setImageUploadGroups] = useState<Array<{id: string; file: File | null; preview: string}>>([{id: '0', file: null, preview: ''}]);
  const [isSubmitting, setIsSubmitting] = useState(false);

  // Shop capacity state
  const [shopCapacity, setShopCapacity] = useState<{ active_count: number; limit: number; is_full: boolean } | null>(null);

  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (shoeTypeDropdownRef.current && !shoeTypeDropdownRef.current.contains(event.target as Node)) {
        setIsShoeTypeOpen(false);
      }
      if (pickupRegionDropdownRef.current && !pickupRegionDropdownRef.current.contains(event.target as Node)) {
        setIsPickupRegionOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, []);

  // Fetch shop capacity when shopId is known
  useEffect(() => {
    if (!shopId) return;
    let cancelled = false;
    fetch(`/api/customer/shop/${shopId}/repair-capacity`, { headers: { Accept: 'application/json' } })
      .then(r => r.json())
      .then(data => {
        if (!cancelled && data.success) {
          setShopCapacity({ active_count: data.active_count, limit: data.limit, is_full: data.is_full });
        }
      })
      .catch(() => { /* ignore capacity fetch errors */ });
    return () => { cancelled = true; };
  }, [shopId]);

  useEffect(() => {
    const savedCheckoutInfo = localStorage.getItem(CHECKOUT_INFO_STORAGE_KEY);
    if (!savedCheckoutInfo) return;

    try {
      const parsed = JSON.parse(savedCheckoutInfo) as Partial<typeof formData>;
      setFormData((prev) => ({
        ...prev,
        customerName: parsed.customerName || '',
        email: parsed.email || '',
        phone: parsed.phone || '',
        shoeType: parsed.shoeType || '',
        brand: parsed.brand || '',
        pickupAddressLine: parsed.pickupAddressLine || '',
        pickupBarangay: parsed.pickupBarangay || '',
        pickupCity: parsed.pickupCity || '',
        pickupRegion: parsed.pickupRegion || '',
        pickupPostalCode: parsed.pickupPostalCode || '',
      }));
      setSaveInfoForCheckout(true);
    } catch {
      localStorage.removeItem(CHECKOUT_INFO_STORAGE_KEY);
    }
  }, []);

  useEffect(() => {
    if (!saveInfoForCheckout) {
      localStorage.removeItem(CHECKOUT_INFO_STORAGE_KEY);
      return;
    }

    localStorage.setItem(
      CHECKOUT_INFO_STORAGE_KEY,
      JSON.stringify({
        customerName: formData.customerName,
        email: formData.email,
        phone: formData.phone,
        shoeType: formData.shoeType,
        brand: formData.brand,
        pickupAddressLine: formData.pickupAddressLine,
        pickupBarangay: formData.pickupBarangay,
        pickupCity: formData.pickupCity,
        pickupRegion: formData.pickupRegion,
        pickupPostalCode: formData.pickupPostalCode,
      })
    );
  }, [saveInfoForCheckout, formData]);

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
    setSelectedServices([serviceId]);
  };

  const readFileAsDataUrl = (file: File): Promise<string> => {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onloadend = () => resolve(reader.result as string);
      reader.onerror = () => reject(new Error('Failed to read file'));
      reader.readAsDataURL(file);
    });
  };

  const handleImageUpload = async (id: string, e: React.ChangeEvent<HTMLInputElement>) => {
    const selectedFiles = Array.from(e.target.files || []);
    if (selectedFiles.length === 0) return;

    const uploadedCount = imageUploadGroups.filter((group) => group.file).length;
    const remainingSlots = 5 - uploadedCount;

    if (remainingSlots <= 0) {
      Swal.fire({
        title: 'Too Many Images',
        text: 'You can upload a maximum of 5 images',
        icon: 'warning',
        confirmButtonColor: '#000000',
      });
      e.target.value = '';
      return;
    }

    const filesToUse = selectedFiles.slice(0, remainingSlots);
    if (selectedFiles.length > filesToUse.length) {
      Swal.fire({
        title: 'Some Images Were Skipped',
        text: `Only ${remainingSlots} more image${remainingSlots > 1 ? 's are' : ' is'} allowed (max 5).`,
        icon: 'info',
        confirmButtonColor: '#000000',
      });
    }

    const previews = await Promise.all(filesToUse.map(readFileAsDataUrl));
    const nextGroups = filesToUse.map((file, index) => ({
      id: `${Date.now()}-${index}`,
      file,
      preview: previews[index],
    }));

    setImageUploadGroups((prev) => {
      const targetIndex = prev.findIndex((group) => group.id === id);
      if (targetIndex === -1) return prev;

      const firstGroup = nextGroups[0];
      const additionalGroups = nextGroups.slice(1);

      const updated = [...prev];
      updated[targetIndex] = firstGroup;
      updated.splice(targetIndex + 1, 0, ...additionalGroups);

      const totalUploaded = updated.filter((group) => group.file).length;
      const hasEmptySlot = updated.some((group) => !group.file);
      if (totalUploaded < 5 && !hasEmptySlot) {
        updated.push({ id: `empty-${Date.now()}`, file: null, preview: '' });
      }

      return updated.slice(0, 6);
    });

    e.target.value = '';
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

    if (!authUser) {
      await Swal.fire({
        title: 'Login Required',
        text: 'Please log in first before submitting a repair request.',
        icon: 'warning',
        confirmButtonColor: '#000000',
      });
      window.location.href = `/login?redirect=${encodeURIComponent(window.location.pathname + window.location.search)}`;
      return;
    }
    
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

    const confirmSubmit = await Swal.fire({
      title: 'Confirm Submit Request',
      html: `
        <div style="text-align:left;">
          <p style="margin-bottom:8px;">Please confirm your repair request details:</p>
          <p><strong>Services:</strong> ${selectedServices.length}</p>
          <p><strong>Service Type:</strong> ${formData.serviceType === 'pickup' ? 'Pick Up' : 'Walk In'}</p>
          <p><strong>Total:</strong> ₱${grandTotal.toLocaleString()}</p>
        </div>
      `,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, Submit',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#000000',
      cancelButtonColor: '#6b7280',
    });

    if (!confirmSubmit.isConfirmed) {
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

      if (response.status === 401) {
        setIsSubmitting(false);
        await Swal.fire({
          title: 'Login Required',
          text: data.message || 'Please log in first before submitting a repair request.',
          icon: 'warning',
          confirmButtonColor: '#000000',
        });
        window.location.href = `/login?redirect=${encodeURIComponent(window.location.pathname + window.location.search)}`;
        return;
      }
      
      if (data.success) {
        setIsSubmitting(false);
        
        await Swal.fire({
          title: 'Request Submitted!',
          text: `Your repair request has been submitted successfully. Total: ₱${grandTotal.toLocaleString()}. We will contact you shortly.`,
          icon: 'success',
          confirmButtonColor: '#000000',
        });

        // Clear localStorage
        localStorage.removeItem('selectedRepairServices');
        localStorage.removeItem('shopDetails');
        
        // Reset form when customer did not choose to save details for next checkout
        if (!saveInfoForCheckout) {
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
        }
        setSelectedServices([]);
        setImageUploadGroups([{id: '0', file: null, preview: ''}]);
        
        // Redirect to My Repairs page
        window.location.href = '/my-repairs';
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

      <main className="flex-1 pt-24 lg:pt-28">
        <div className="max-w-7xl mx-auto py-12 px-6 text-black">
          {shopCapacity?.is_full && (
            <div className="mb-8 flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
              <svg className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
              </svg>
              <div>
                <p className="font-semibold">This shop is currently at full workload capacity ({shopCapacity.active_count}/{shopCapacity.limit} active repairs).</p>
                <p className="text-amber-800">You can still submit your request and it will be queued once capacity opens up.</p>
              </div>
            </div>
          )}

          <form onSubmit={handleSubmit}>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-8 items-start">
              <div className="md:col-span-2">
                <div className="mb-8">
                  <h2 className="text-lg font-semibold text-black mb-4">Contact</h2>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm font-medium text-black mb-2">Full Name *</label>
                      <input
                        type="text"
                        name="customerName"
                        value={formData.customerName}
                        onChange={handleInputChange}
                        className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                        placeholder="Juan Dela Cruz"
                        required
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-black mb-2">Email Address *</label>
                      <input
                        type="email"
                        name="email"
                        value={formData.email}
                        onChange={handleInputChange}
                        className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                        placeholder="juan@example.com"
                        required
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-black mb-2">Phone Number *</label>
                      <input
                        type="tel"
                        name="phone"
                        value={formData.phone}
                        onChange={handleInputChange}
                        className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                        placeholder="+63 912 345 6789"
                        required
                      />
                    </div>
                    <div>
                      <label htmlFor="shoeType" className="block text-sm font-medium text-black mb-2">Shoe Type</label>
                      <div className="relative" ref={shoeTypeDropdownRef}>
                        <button
                          type="button"
                          id="shoeType"
                          className="w-full px-4 py-3 border border-gray-300 rounded text-left bg-white flex items-center justify-between"
                          onClick={() => setIsShoeTypeOpen((prev) => !prev)}
                        >
                          <span className={formData.shoeType ? 'text-black' : 'text-gray-500'}>
                            {formData.shoeType || 'Select shoe type'}
                          </span>
                          <svg className={`h-4 w-4 text-gray-500 transition-transform ${isShoeTypeOpen ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                          </svg>
                        </button>

                        {isShoeTypeOpen && (
                          <div className="absolute left-0 top-full z-30 mt-1 w-full rounded border border-gray-300 bg-white shadow-lg">
                            <ul className="max-h-56 overflow-y-auto py-1">
                              <li>
                                <button
                                  type="button"
                                  className="w-full px-4 py-2 text-left text-sm text-gray-600 hover:bg-gray-100"
                                  onClick={() => {
                                    setFormData((prev) => ({ ...prev, shoeType: '' }));
                                    setIsShoeTypeOpen(false);
                                  }}
                                >
                                  Select shoe type
                                </button>
                              </li>
                              {SHOE_TYPE_OPTIONS.map((shoeType) => (
                                <li key={shoeType}>
                                  <button
                                    type="button"
                                    className={`w-full px-4 py-2 text-left text-sm hover:bg-gray-100 ${formData.shoeType === shoeType ? 'bg-gray-100 text-black font-medium' : 'text-black'}`}
                                    onClick={() => {
                                      setFormData((prev) => ({ ...prev, shoeType }));
                                      setIsShoeTypeOpen(false);
                                    }}
                                  >
                                    {shoeType}
                                  </button>
                                </li>
                              ))}
                            </ul>
                          </div>
                        )}
                      </div>
                    </div>
                  </div>
                  <label className="mt-4 inline-flex items-center gap-2 text-sm text-black">
                    <input
                      type="checkbox"
                      checked={saveInfoForCheckout}
                      onChange={(e) => setSaveInfoForCheckout(e.target.checked)}
                      className="h-4 w-4"
                    />
                    <span>Save my information for a faster checkout</span>
                  </label>
                </div>

                <div className="mb-8">
                  <h2 className="text-lg font-semibold text-black mb-4">Repair Details</h2>
                  <div className="space-y-4">
                    <div>
                      <label className="block text-sm font-medium text-black mb-2">Description</label>
                      <textarea
                        name="description"
                        value={formData.description}
                        onChange={handleInputChange}
                        rows={4}
                        className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white resize-none"
                        placeholder="Describe the issue or repair needed..."
                      />
                    </div>

                    <div>
                      <label className="block text-sm font-medium text-black mb-2">Service Type *</label>
                      <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <label className="flex items-start gap-3 p-3 border border-gray-300 rounded cursor-pointer h-full">
                          <input
                            type="radio"
                            name="serviceType"
                            value="pickup"
                            checked={formData.serviceType === 'pickup'}
                            onChange={(e) => setFormData(prev => ({ ...prev, serviceType: e.target.value }))}
                            className="w-4 h-4 mt-1"
                          />
                          <div>
                            <span className="text-sm font-medium text-black">Pick Up</span>
                            <p className="text-xs text-gray-600">Shipping fee is shouldered by the customer.</p>
                          </div>
                        </label>
                        <label className="flex items-start gap-3 p-3 border border-gray-300 rounded cursor-pointer h-full">
                          <input
                            type="radio"
                            name="serviceType"
                            value="walkin"
                            checked={formData.serviceType === 'walkin'}
                            onChange={(e) => setFormData(prev => ({ ...prev, serviceType: e.target.value }))}
                            className="w-4 h-4 mt-1"
                          />
                          <div>
                            <span className="text-sm font-medium text-black">Walk In</span>
                            <p className="text-xs text-gray-600">
                              {shopDetails
                                ? `Bring to ${shopDetails.name} - ${shopDetails.location}`
                                : 'I will bring my shoes to the shop'}
                            </p>
                          </div>
                        </label>
                      </div>
                    </div>

                    {formData.serviceType === 'pickup' && (
                      <div className="space-y-4 border border-gray-300 rounded p-4">
                        <p className="text-sm font-medium text-black">Pickup Address</p>
                        <div>
                          <label className="block text-sm font-medium text-black mb-2">Address line</label>
                          <input
                            type="text"
                            name="pickupAddressLine"
                            value={formData.pickupAddressLine}
                            onChange={handleInputChange}
                            className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                            placeholder="House no., street, building"
                            required
                          />
                        </div>
                        <div>
                          <label className="block text-sm font-medium text-black mb-2">Barangay</label>
                          <input
                            type="text"
                            name="pickupBarangay"
                            value={formData.pickupBarangay}
                            onChange={handleInputChange}
                            className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                            placeholder="Barangay"
                            required
                          />
                        </div>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                          <div>
                            <label className="block text-sm font-medium text-black mb-2">City</label>
                            <input
                              type="text"
                              name="pickupCity"
                              value={formData.pickupCity}
                              onChange={handleInputChange}
                              className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                              placeholder="City"
                              required
                            />
                          </div>
                          <div>
                            <label htmlFor="pickupRegion" className="block text-sm font-medium text-black mb-2">Region</label>
                            <div className="relative" ref={pickupRegionDropdownRef}>
                              <button
                                type="button"
                                id="pickupRegion"
                                className="w-full px-4 py-3 border border-gray-300 rounded text-left bg-white flex items-center justify-between"
                                onClick={() => setIsPickupRegionOpen((prev) => !prev)}
                              >
                                <span className={formData.pickupRegion ? 'text-black' : 'text-gray-500'}>
                                  {formData.pickupRegion || 'Select Region'}
                                </span>
                                <svg className={`h-4 w-4 text-gray-500 transition-transform ${isPickupRegionOpen ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                                </svg>
                              </button>

                              {isPickupRegionOpen && (
                                <div className="absolute left-0 top-full z-30 mt-1 w-full rounded border border-gray-300 bg-white shadow-lg">
                                  <ul className="max-h-56 overflow-y-auto py-1">
                                    <li>
                                      <button
                                        type="button"
                                        className="w-full px-4 py-2 text-left text-sm text-gray-600 hover:bg-gray-100"
                                        onClick={() => {
                                          setFormData((prev) => ({ ...prev, pickupRegion: '' }));
                                          setIsPickupRegionOpen(false);
                                        }}
                                      >
                                        Select Region
                                      </button>
                                    </li>
                                    {REGION_OPTIONS.map((region) => (
                                      <li key={region}>
                                        <button
                                          type="button"
                                          className={`w-full px-4 py-2 text-left text-sm hover:bg-gray-100 ${formData.pickupRegion === region ? 'bg-gray-100 text-black font-medium' : 'text-black'}`}
                                          onClick={() => {
                                            setFormData((prev) => ({ ...prev, pickupRegion: region }));
                                            setIsPickupRegionOpen(false);
                                          }}
                                        >
                                          {region}
                                        </button>
                                      </li>
                                    ))}
                                  </ul>
                                </div>
                              )}
                            </div>
                          </div>
                        </div>
                        <div>
                          <label className="block text-sm font-medium text-black mb-2">Postal code</label>
                          <input
                            type="text"
                            name="pickupPostalCode"
                            value={formData.pickupPostalCode}
                            onChange={handleInputChange}
                            className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                            placeholder="Postal code"
                            required
                          />
                        </div>
                      </div>
                    )}

                    <div>
                      <label className="block text-sm font-medium text-black mb-2">Select Repair Service *</label>
                      {isLoadingServices ? (
                        <div className="flex items-center justify-center rounded border border-gray-300 py-8">
                          <div className="h-7 w-7 animate-spin rounded-full border-2 border-gray-300 border-t-black" />
                          <span className="ml-3 text-sm text-gray-600">Loading services...</span>
                        </div>
                      ) : repairServices.length === 0 ? (
                        <div className="rounded border border-gray-300 bg-gray-50 p-4 text-sm text-gray-600">No repair services available.</div>
                      ) : (
                        <div className="space-y-2">
                          {repairServices.map((service) => (
                            <label key={service.id} className="flex items-start justify-between gap-4 p-3 border border-gray-300 rounded cursor-pointer">
                              <div className="flex items-start gap-3">
                                <input
                                  type="radio"
                                  name="selectedRepairService"
                                  checked={selectedServices[0] === service.id}
                                  onChange={() => handleServiceToggle(service.id)}
                                  className="w-4 h-4 mt-1"
                                />
                                <div>
                                  <p className="text-sm font-medium text-black">{service.title}</p>
                                  <p className="text-xs text-gray-600">{service.description}</p>
                                </div>
                              </div>
                              <span className="text-sm font-semibold text-black">
                                {typeof service.price === 'string' ? service.price : service.price > 0 ? `₱${service.price}` : 'Price varies'}
                              </span>
                            </label>
                          ))}
                        </div>
                      )}
                    </div>

                    <div>
                      <label className="block text-sm font-medium text-black mb-2">Upload Images *</label>
                      <p className="text-xs text-gray-600 mb-3">Upload up to 5 images of your shoes (front, back, and damaged areas).</p>
                      <p className="text-xs text-gray-500 mb-3">{imageUploadGroups.filter((group) => group.file).length}/5 photos selected</p>
                      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                        {imageUploadGroups.map((group, index) => (
                          <div key={group.id} className="relative group">
                            {group.preview ? (
                              <div className="relative">
                                <img src={group.preview} alt={`Preview ${index + 1}`} className="w-full h-24 object-cover rounded-lg border border-gray-200" />
                                <div className="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity rounded-lg flex items-center justify-center gap-2">
                                  {imageUploadGroups.length < 5 && (
                                    <button
                                      type="button"
                                      onClick={addImageUploadBox}
                                      className="w-8 h-8 bg-black hover:bg-gray-800 rounded-full flex items-center justify-center text-white transition-colors"
                                      title="Add more photos"
                                    >
                                      <span className="text-lg leading-none">+</span>
                                    </button>
                                  )}
                                  {imageUploadGroups.length > 1 && (
                                    <button
                                      type="button"
                                      onClick={() => removeImageBox(group.id)}
                                      className="w-8 h-8 bg-red-600 hover:bg-red-700 rounded-full flex items-center justify-center text-white transition-colors"
                                      title="Remove photo"
                                    >
                                      <span className="text-lg leading-none">x</span>
                                    </button>
                                  )}
                                </div>
                              </div>
                            ) : (
                              <label htmlFor={`image-upload-${group.id}`} className="w-full h-24 border-2 border-dashed border-gray-300 rounded-lg flex flex-col items-center justify-center cursor-pointer hover:border-gray-400 transition-colors bg-gray-50/50 text-center px-2">
                                <input
                                  type="file"
                                  accept="image/jpeg,image/jpg,image/png,image/webp"
                                  multiple
                                  onChange={(e) => handleImageUpload(group.id, e)}
                                  className="hidden"
                                  id={`image-upload-${group.id}`}
                                />
                                <svg xmlns="http://www.w3.org/2000/svg" className="w-5 h-5 text-gray-400 mb-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                  <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                  <polyline points="17 8 12 3 7 8" />
                                  <line x1="12" y1="3" x2="12" y2="15" />
                                </svg>
                                <span className="text-xs text-gray-500">Click to upload</span>
                              </label>
                            )}
                          </div>
                        ))}
                      </div>
                    </div>
                  </div>
                </div>

                <button
                  type="submit"
                  disabled={isSubmitting || selectedServices.length === 0}
                  className={`w-full py-3 rounded-md font-semibold text-white mb-3 transition-colors ${
                    isSubmitting || selectedServices.length === 0 ? 'bg-gray-400 cursor-not-allowed' : 'bg-gray-900 hover:bg-gray-800'
                  }`}
                >
                  {isSubmitting ? 'Submitting...' : 'Submit Request'}
                </button>
                <Link
                  href="/repair-services"
                  className="block w-full py-3 rounded-md font-semibold text-center border border-gray-300 text-black hover:bg-gray-50"
                >
                  Cancel
                </Link>
              </div>

              <aside className="md:col-span-1 md:sticky md:top-4">
                <div className="border border-gray-300 rounded-lg p-6 bg-white">
                  <h3 className="text-lg font-semibold text-black mb-4">Order Summary</h3>

                  {selectedServices.length === 0 ? (
                    <div className="text-sm text-gray-600">No services selected yet.</div>
                  ) : (
                    <div className="border-b border-gray-200 pb-4 mb-4 space-y-3">
                      {selectedServices.map((serviceId) => {
                        const service = repairServices.find((s) => s.id === serviceId);
                        if (!service) return null;
                        return (
                          <div key={service.id} className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                              <p className="text-sm font-medium text-black truncate">{service.title}</p>
                              <p className="text-xs text-gray-600">{service.description}</p>
                            </div>
                            <p className="text-sm font-semibold text-black whitespace-nowrap">
                              {typeof service.price === 'string' ? service.price : service.price > 0 ? `₱${service.price}` : 'TBD'}
                            </p>
                          </div>
                        );
                      })}
                    </div>
                  )}

                  <div className="space-y-3 mb-4">
                    <div className="flex justify-between text-sm">
                      <span className="text-gray-600">Services Subtotal</span>
                      <span className="text-black font-medium">₱{servicesTotal.toLocaleString()}</span>
                    </div>
                  </div>

                  <div className="border-t border-gray-200 pt-4">
                    <div className="flex justify-between items-baseline">
                      <span className="text-black">Total</span>
                      <div className="flex items-baseline gap-1">
                        <span className="text-xs text-gray-600">PHP</span>
                        <span className="text-2xl font-bold text-black">₱{grandTotal.toLocaleString()}</span>
                      </div>
                    </div>
                  </div>

                  <div className="mt-4 rounded border border-gray-200 bg-gray-50 px-3 py-3 text-xs text-gray-700">
                    You will not be charged yet. After submission, the shop will review your request and you can track status in Repairs.
                  </div>
                </div>
              </aside>
            </div>
          </form>
        </div>
      </main>

      <footer className="mt-12 bg-gray-100 text-slate-900">
        <div className="max-w-7xl mx-auto px-6 py-8">
          <div className="border-t border-gray-300 pt-6 text-xs text-slate-700 flex items-center justify-between">
            <div>© 2026 SOLESPACE. All rights reserved.</div>
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

export default RepairProcess;
