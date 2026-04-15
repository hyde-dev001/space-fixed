import React, { useState, useEffect, useMemo, useRef } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import Swal from '@/Pages/UserSide/Shared/UserModal';
import { buildRepairBreakdown } from '../../../utils/repairPricing';

const REPAIR_VAT_RATE_PERCENT = 12;

interface RepairService {
  id: number;
  title: string;
  price: string | number;
  description: string;
  category?: string;
  duration?: string;
}

interface RepairPackage {
  id: number;
  name: string;
  description?: string | null;
  package_price: number;
  effective_package_price?: number;
  proposed_package_price?: number;
  old_package_price?: number | null;
  approval_status?: string | null;
  service_count: number;
  services_total_price: number;
  savings_amount: number;
  services: Array<{
    id: number;
    name: string;
    category: string;
    price: number;
    duration: string;
  }>;
}

interface ShopDetails {
  id: number;
  name: string;
  location: string;
  address?: string;
  shop_address?: string;
  business_address?: string;
  city_state?: string;
  postal_code?: string;
  country?: string;
  latitude?: number | string | null;
  longitude?: number | string | null;
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

const DEFAULT_SHIPPING_REGION = 'Cavite';

interface CityOption {
  value: string;
  label: string;
  aliases?: string[];
}

const PH_CITY_OPTIONS: CityOption[] = [
  { value: 'Bacoor', label: 'Bacoor' },
  { value: 'Cavite City', label: 'Cavite City', aliases: ['City of Cavite'] },
  { value: 'Dasmariñas', label: 'Dasmariñas', aliases: ['Dasmarinas'] },
  { value: 'General Trias', label: 'General Trias' },
  { value: 'Imus', label: 'Imus' },
  { value: 'Tagaytay', label: 'Tagaytay' },
  { value: 'Trece Martires', label: 'Trece Martires' },
  { value: 'Alfonso', label: 'Alfonso' },
  { value: 'Amadeo', label: 'Amadeo' },
  { value: 'Carmona', label: 'Carmona' },
  { value: 'Gen. Emilio Aguinaldo', label: 'Gen. Emilio Aguinaldo', aliases: ['General Emilio Aguinaldo', 'Gen Emilio Aguinaldo'] },
  { value: 'Gen. Mariano Alvarez', label: 'Gen. Mariano Alvarez', aliases: ['General Mariano Alvarez', 'Gen Mariano Alvarez', 'GMA'] },
  { value: 'Indang', label: 'Indang' },
  { value: 'Kawit', label: 'Kawit' },
  { value: 'Magallanes', label: 'Magallanes' },
  { value: 'Maragondon', label: 'Maragondon' },
  { value: 'Mendez', label: 'Mendez' },
  { value: 'Naic', label: 'Naic' },
  { value: 'Noveleta', label: 'Noveleta' },
  { value: 'Rosario', label: 'Rosario' },
  { value: 'Silang', label: 'Silang' },
  { value: 'Tanza', label: 'Tanza' },
];

const normalizeCityKey = (value: string) => value
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .replace(/\./g, '')
  .replace(/\s+/g, ' ')
  .trim()
  .toLowerCase();

const CITY_OPTION_LOOKUP = PH_CITY_OPTIONS.reduce<Map<string, string>>((lookup, option) => {
  const candidates = [option.value, option.label, ...(option.aliases || [])];

  candidates.forEach((candidate) => {
    const key = normalizeCityKey(candidate);
    if (key && !lookup.has(key)) {
      lookup.set(key, option.value);
    }
  });

  return lookup;
}, new Map<string, string>());

const normalizeCitySelection = (city?: string | null): string => {
  const normalizedCityKey = normalizeCityKey(city || '');
  if (!normalizedCityKey) return '';
  return CITY_OPTION_LOOKUP.get(normalizedCityKey) || '';
};

const getEffectivePackagePrice = (pkg: RepairPackage): number => {
  const effective = Number((pkg as any).effective_package_price);
  if (Number.isFinite(effective)) {
    return effective;
  }

  const fallback = Number(pkg.package_price);
  return Number.isFinite(fallback) ? fallback : 0;
};

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
  const preSelectedPackageId = Number(urlParams.get('package') || 0) || null;
  const shopId = urlParams.get('shop');

  // Retrieve stored services from localStorage
  const [repairServices, setRepairServices] = useState<RepairService[]>([]);
  const [repairPackages, setRepairPackages] = useState<RepairPackage[]>([]);
  const [isLoadingPackages, setIsLoadingPackages] = useState(true);
  const [shopDetails, setShopDetails] = useState<ShopDetails | null>(null);
  const [isLoadingServices, setIsLoadingServices] = useState(true);
  const [selectedPackageId, setSelectedPackageId] = useState<number | null>(null);

  useEffect(() => {
    const loadServices = async () => {
      const timestamp = Date.now();
      setIsLoadingServices(true);
      setIsLoadingPackages(true);

      const storedShop = localStorage.getItem('shopDetails');

      if (storedShop) {
        setShopDetails(JSON.parse(storedShop));
      }

      // Always fetch from API so data is fresh and consistent.
      // URL params determine shop scoping; localStorage is only for lightweight context.
      try {
        const apiUrl = shopId
          ? `/api/repair-services?shop_id=${shopId}&_ts=${timestamp}`
          : `/api/repair-services?_ts=${timestamp}`;

        const response = await fetch(apiUrl, { cache: 'no-store' });
        const data = await response.json();

        if (data.success && Array.isArray(data.data)) {
          const formattedServices = data.data.map((service: any) => ({
            id: service.id,
            title: service.name,
            price: parseFloat(service.price),
            description: service.description || '',
            category: service.category,
            duration: service.duration,
          }));
          setRepairServices(formattedServices);

          if (data.shop) {
            setShopDetails({
              id: Number(data.shop.id || shopId || 0),
              name: data.shop.name || 'Repair Shop',
              location: data.shop.location || data.shop.address || '',
              address: data.shop.address || '',
            });
          }
        } else {
          setRepairServices([]);
        }
      } catch (error) {
        console.error('Failed to fetch services:', error);
        setRepairServices([]);
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: 'Failed to load repair services',
          confirmButtonColor: '#000000',
        });
      }

      try {
        const packageApiUrl = shopId
          ? `/api/repair-packages/public?shop_id=${shopId}&_ts=${timestamp}`
          : `/api/repair-packages/public?_ts=${timestamp}`;
        const packageResponse = await fetch(packageApiUrl, { cache: 'no-store' });
        const packageData = await packageResponse.json();
        if (packageData.success && Array.isArray(packageData.data)) {
          setRepairPackages(packageData.data);
        } else {
          setRepairPackages([]);
        }
      } catch (error) {
        console.error('Failed to fetch repair packages:', error);
        setRepairPackages([]);
      } finally {
        setIsLoadingPackages(false);
        setIsLoadingServices(false);
      }
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
    serviceType: '', // intake: 'pickup' or 'walkin'
    returnDeliveryMethod: '', // return: 'walk_in' | 'shop_delivery'
    pickupAddressLine: '',
    pickupBarangay: '',
    pickupCity: '',
    pickupRegion: '',
    pickupPostalCode: '',
    returnAddressLine: '',
    returnBarangay: '',
    returnCity: '',
    returnRegion: '',
    returnPostalCode: '',
  });
  const [selectedServiceIds, setSelectedServiceIds] = useState<number[]>([]);
  const [selectedAddOnServiceIds, setSelectedAddOnServiceIds] = useState<number[]>([]);
  const [isShoeTypeOpen, setIsShoeTypeOpen] = useState(false);
  const [isReturnCityDropdownOpen, setIsReturnCityDropdownOpen] = useState(false);
  const [saveInfoForCheckout, setSaveInfoForCheckout] = useState(false);
  const shoeTypeDropdownRef = useRef<HTMLDivElement | null>(null);
  const returnCityDropdownRef = useRef<HTMLDivElement | null>(null);

  // Set selected services once repair services are loaded
  useEffect(() => {
    if (repairServices.length > 0 && preSelectedIds.length > 0) {
      setSelectedServiceIds(preSelectedIds);
    }
  }, [repairServices]);

  useEffect(() => {
    if (preSelectedPackageId && repairPackages.some((pkg) => pkg.id === preSelectedPackageId)) {
      setSelectedServiceIds([]);
      setSelectedAddOnServiceIds([]);
      setSelectedPackageId(preSelectedPackageId);
    }
  }, [preSelectedPackageId, repairPackages]);
  const [imageUploadGroups, setImageUploadGroups] = useState<Array<{id: string; file: File | null; preview: string}>>([{id: '0', file: null, preview: ''}]);
  const [isSubmitting, setIsSubmitting] = useState(false);

  // Shop capacity state
  const [shopCapacity, setShopCapacity] = useState<{ active_count: number; limit: number; is_full: boolean } | null>(null);

  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (shoeTypeDropdownRef.current && !shoeTypeDropdownRef.current.contains(event.target as Node)) {
        setIsShoeTypeOpen(false);
      }

      if (returnCityDropdownRef.current && !returnCityDropdownRef.current.contains(event.target as Node)) {
        setIsReturnCityDropdownOpen(false);
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
      const savedReturnCity = normalizeCitySelection(parsed.returnCity);
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
        pickupPostalCode: (parsed.pickupPostalCode || '').replace(/\D/g, ''),
        returnAddressLine: parsed.returnAddressLine || '',
        returnBarangay: parsed.returnBarangay || '',
        returnCity: savedReturnCity,
        returnRegion: parsed.returnRegion || (savedReturnCity ? DEFAULT_SHIPPING_REGION : ''),
        returnPostalCode: (parsed.returnPostalCode || '').replace(/\D/g, ''),
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
        returnAddressLine: formData.returnAddressLine,
        returnBarangay: formData.returnBarangay,
        returnCity: formData.returnCity,
        returnRegion: formData.returnRegion,
        returnPostalCode: formData.returnPostalCode,
      })
    );
  }, [saveInfoForCheckout, formData]);

  const servicesTotal = useMemo(() => {
    return selectedServiceIds.reduce((sum, serviceId) => {
      const service = repairServices.find((s) => s.id === serviceId);
      const price = typeof service?.price === 'string'
        ? parseFloat(service.price.replace(/[^0-9.]/g, '')) || 0
        : service?.price || 0;
      return sum + price;
    }, 0);
  }, [selectedServiceIds, repairServices]);

  const selectedPackage = useMemo(
    () => repairPackages.find((pkg) => pkg.id === selectedPackageId) || null,
    [repairPackages, selectedPackageId]
  );

  const selectedStandaloneServices = useMemo(
    () => repairServices.filter((service) => selectedServiceIds.includes(service.id)),
    [repairServices, selectedServiceIds]
  );

  const includedPackageServiceIds = useMemo(
    () => new Set((selectedPackage?.services || []).map((service) => service.id)),
    [selectedPackage]
  );

  const addOnsTotal = useMemo(() => {
    return selectedAddOnServiceIds.reduce((sum, serviceId) => {
      const service = repairServices.find((s) => s.id === serviceId);
      const price = typeof service?.price === 'string'
        ? parseFloat(service.price.replace(/[^0-9.]/g, '')) || 0
        : service?.price || 0;
      return sum + price;
    }, 0);
  }, [selectedAddOnServiceIds, repairServices]);

  const selectedAddOnServices = useMemo(
    () => repairServices.filter((service) => selectedAddOnServiceIds.includes(service.id)),
    [repairServices, selectedAddOnServiceIds]
  );

  const packageTotal = selectedPackage ? getEffectivePackagePrice(selectedPackage) : 0;

  const grandTotal = selectedPackage ? packageTotal + addOnsTotal : servicesTotal;
  const pricingBreakdown = useMemo(() => {
    return buildRepairBreakdown({
      finalTotal: grandTotal,
      vatRate: REPAIR_VAT_RATE_PERCENT,
      taxMode: 'vat_inclusive',
    });
  }, [grandTotal]);
  const subtotalBeforeVat = pricingBreakdown.netSubtotal;
  const vatAmount = pricingBreakdown.vatAmount;
  const grandTotalWithVat = pricingBreakdown.grandTotal;
  const isSubmitDisabled = isSubmitting || (selectedServiceIds.length === 0 && selectedPackageId === null);

  const shopAddressLine = (shopDetails?.shop_address || shopDetails?.business_address || shopDetails?.address || '').trim();
  const shopCityState = (shopDetails?.city_state || '').trim();
  const shopPostalCode = (shopDetails?.postal_code || '').trim();
  const shopCountry = (shopDetails?.country || '').trim();

  const shopAddressParts = [
    shopAddressLine,
    shopCityState,
    shopPostalCode,
    shopCountry,
  ].filter((part) => part.length > 0);

  const exactShopAddress = shopAddressParts.join(', ');

  const lat = Number(shopDetails?.latitude);
  const lng = Number(shopDetails?.longitude);
  const hasCoordinates = Number.isFinite(lat) && Number.isFinite(lng);

  const shopMapsUrl = hasCoordinates
    ? `https://www.google.com/maps?q=${lat},${lng}`
    : (exactShopAddress ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(exactShopAddress)}` : '');

  const orderSummaryCard = (
    <div className="border border-gray-300 rounded-xl bg-white p-5 xl:p-6">
      <h3 className="text-3xl xl:text-lg font-semibold text-black mb-4">Order Summary</h3>

      {selectedServiceIds.length === 0 ? (
        selectedPackage ? (
          <div className="border-b border-gray-200 pb-4 mb-4 space-y-2">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <p className="text-sm font-semibold text-black">{selectedPackage.name}</p>
                <p className="text-xs text-gray-600">{selectedPackage.description || 'Repair package selected'}</p>
              </div>
              <p className="text-sm font-semibold text-black whitespace-nowrap">₱{getEffectivePackagePrice(selectedPackage).toLocaleString()}</p>
            </div>
            <div>
              <p className="text-xs font-medium text-gray-700 mb-1">Included services</p>
              <ul className="list-disc pl-4 text-xs text-gray-600 space-y-1">
                {selectedPackage.services.map((service) => (
                  <li key={`pkg-svc-${service.id}`}>{service.name}</li>
                ))}
              </ul>
            </div>
            {selectedAddOnServices.length > 0 && (
              <div>
                <p className="text-xs font-medium text-gray-700 mb-1">Add-ons</p>
                <ul className="list-disc pl-4 text-xs text-gray-600 space-y-1">
                  {selectedAddOnServices.map((service) => (
                    <li key={`add-on-${service.id}`}>
                      {service.title} • ₱{typeof service.price === 'string' ? service.price.replace(/[^0-9.]/g, '') : Number(service.price || 0).toLocaleString()}
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        ) : (
          <div className="text-sm text-gray-600">No package or services selected yet.</div>
        )
      ) : (
        <div className="border-b border-gray-200 pb-4 mb-4 space-y-3">
          {selectedStandaloneServices.map((service) => (
            <div key={`standalone-${service.id}`} className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <p className="text-sm font-medium text-black truncate">{service.title}</p>
                <p className="text-xs text-gray-600">{service.description}</p>
              </div>
              <p className="text-sm font-semibold text-black whitespace-nowrap">
                {typeof service.price === 'string' ? service.price : service.price > 0 ? `₱${service.price}` : 'TBD'}
              </p>
            </div>
          ))}
        </div>
      )}

      <div className="space-y-3 mb-4">
        {selectedPackage ? (
          <>
            <div className="flex justify-between text-sm">
              <span className="text-gray-600">Package Price (VAT Inclusive)</span>
              <span className="text-black font-medium">₱{packageTotal.toLocaleString()}</span>
            </div>
            <div className="flex justify-between text-sm">
              <span className="text-gray-600">Add-ons (VAT Inclusive)</span>
              <span className="text-black font-medium">₱{addOnsTotal.toLocaleString()}</span>
            </div>
          </>
        ) : (
          <div className="flex justify-between text-sm">
            <span className="text-gray-600">Services Total (VAT Inclusive)</span>
            <span className="text-black font-medium">₱{servicesTotal.toLocaleString()}</span>
          </div>
        )}
        <div className="flex justify-between text-sm">
          <span className="text-gray-600">Subtotal (Before VAT)</span>
          <span className="text-black font-medium">₱{subtotalBeforeVat.toLocaleString()}</span>
        </div>
        <div className="flex justify-between text-sm">
          <span className="text-gray-600">VAT Included ({REPAIR_VAT_RATE_PERCENT}%)</span>
          <span className="text-black font-medium">₱{vatAmount.toLocaleString()}</span>
        </div>
      </div>

      <div className="border-t border-gray-200 pt-4">
        <div className="flex justify-between items-baseline">
          <span className="text-black">Total</span>
          <div className="flex items-baseline gap-1">
            <span className="text-xs text-gray-600">PHP</span>
            <span className="text-2xl xl:text-3xl font-bold text-black">₱{grandTotalWithVat.toLocaleString()}</span>
          </div>
        </div>
      </div>

      <div className="mt-4 rounded border border-gray-200 bg-gray-50 px-3 py-3 text-xs text-gray-700">
        You will not be charged yet. After submission, the shop will review your request and you can track status in Repairs.
      </div>
    </div>
  );

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) => {
    const { name, value } = e.target;
    const nextValue =
      name === 'pickupPostalCode' || name === 'returnPostalCode'
        ? value.replace(/\D/g, '')
        : value;

    setFormData(prev => ({ ...prev, [name]: nextValue }));
  };

  const handleReturnCityChange = (city: string) => {
    const selectedCity = normalizeCitySelection(city);
    setFormData((prev) => ({
      ...prev,
      returnCity: selectedCity,
      returnRegion: selectedCity ? DEFAULT_SHIPPING_REGION : '',
    }));
    setIsReturnCityDropdownOpen(false);
  };

  const getCityLabel = (city?: string | null): string => {
    const selectedCity = normalizeCitySelection(city);
    if (!selectedCity) return '';
    const option = PH_CITY_OPTIONS.find((cityOption) => cityOption.value === selectedCity);
    return option?.label || selectedCity;
  };

  const handleServiceToggle = (serviceId: number) => {
    setSelectedPackageId(null);
    setSelectedAddOnServiceIds([]);
    setSelectedServiceIds((prev) => (
      prev.includes(serviceId)
        ? prev.filter((id) => id !== serviceId)
        : [...prev, serviceId]
    ));
  };

  const handlePackageToggle = (packageId: number) => {
    setSelectedServiceIds([]);
    setSelectedAddOnServiceIds([]);
    setSelectedPackageId((prev) => (prev === packageId ? null : packageId));
  };

  const handleAddOnToggle = (serviceId: number) => {
    if (!selectedPackageId) {
      return;
    }

    setSelectedAddOnServiceIds((prev) => (
      prev.includes(serviceId)
        ? prev.filter((id) => id !== serviceId)
        : [...prev, serviceId]
    ));
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

    if (!formData.shoeType) {
      Swal.fire({
        title: 'Shoe Type Required',
        text: 'Please select your shoe type before submitting the repair request.',
        icon: 'warning',
        confirmButtonColor: '#000000',
      });
      return;
    }

    if (!selectedPackageId && selectedServiceIds.length === 0) {
      Swal.fire({
        title: 'No Selection Yet',
        text: 'Please select a repair package or at least one repair service',
        icon: 'warning',
        confirmButtonColor: '#000000',
      });
      return;
    }

    if (!formData.serviceType) {
      Swal.fire({
        title: 'Intake Method Required',
        text: 'Please select how you will deliver your shoes to the shop',
        icon: 'warning',
        confirmButtonColor: '#000000',
      });
      return;
    }

    if (!formData.returnDeliveryMethod) {
      Swal.fire({
        title: 'Return Method Required',
        text: 'Please select how you want to receive your repaired shoes.',
        icon: 'warning',
        confirmButtonColor: '#000000',
      });
      return;
    }

    if (formData.serviceType === 'pickup' && !shopDetails) {
      Swal.fire({
        title: 'Shop Address Unavailable',
        text: 'Unable to load the shop delivery address right now. Please refresh and try again.',
        icon: 'warning',
        confirmButtonColor: '#000000',
      });
      return;
    }

    if (formData.returnDeliveryMethod !== 'walk_in') {
      const normalizedReturnPostalCode = formData.returnPostalCode.replace(/\D/g, '');
      const missingReturnFields =
        !formData.returnAddressLine ||
        !formData.returnBarangay ||
        !formData.returnCity ||
        !normalizedReturnPostalCode;

      if (missingReturnFields) {
        Swal.fire({
          title: 'Return Address Required',
          text: 'Please complete the return address details.',
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
        <div class="user-swal2-summary">
          <p class="user-swal2-summary-intro">Please confirm your repair request details:</p>
          <div class="user-swal2-summary-list">
            <p class="user-swal2-summary-row"><span class="user-swal2-summary-label">Package</span><span class="user-swal2-summary-value">${selectedPackage ? selectedPackage.name : 'None'}</span></p>
            <p class="user-swal2-summary-row"><span class="user-swal2-summary-label">Services</span><span class="user-swal2-summary-value">${selectedPackage ? selectedPackage.service_count : selectedServiceIds.length}</span></p>
            <p class="user-swal2-summary-row"><span class="user-swal2-summary-label">Add-ons</span><span class="user-swal2-summary-value">${selectedPackage ? selectedAddOnServiceIds.length : 0}</span></p>
            <p class="user-swal2-summary-row"><span class="user-swal2-summary-label">Subtotal</span><span class="user-swal2-summary-value">₱${pricingBreakdown.netSubtotal.toLocaleString()}</span></p>
            <p class="user-swal2-summary-row"><span class="user-swal2-summary-label">VAT (${REPAIR_VAT_RATE_PERCENT}%)</span><span class="user-swal2-summary-value">₱${vatAmount.toLocaleString()}</span></p>
            <p class="user-swal2-summary-row"><span class="user-swal2-summary-label">To Shop (Intake)</span><span class="user-swal2-summary-value">${formData.serviceType === 'pickup' ? 'Customer Arranged Courier Delivery' : 'Customer Walk-in Drop-off'}</span></p>
            <p class="user-swal2-summary-row"><span class="user-swal2-summary-label">To Customer (Return)</span><span class="user-swal2-summary-value">${formData.returnDeliveryMethod === 'walk_in' ? 'Customer Pick-up at Shop' : 'Repairer Arranged Courier Delivery'}</span></p>
          </div>
          <p class="user-swal2-summary-total"><span>Total</span><strong>₱${grandTotalWithVat.toLocaleString()}</strong></p>
        </div>
      `,
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, Submit',
      cancelButtonText: 'Cancel',
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
      submitFormData.append('return_delivery_method', formData.returnDeliveryMethod);
      submitFormData.append('shop_owner_id', shopId || '');
      if (selectedPackageId) {
        submitFormData.append('repair_package_id', selectedPackageId.toString());
      }
      
      if (formData.returnDeliveryMethod !== 'walk_in') {
        const selectedReturnCity = normalizeCitySelection(formData.returnCity);
        const returnRegion = formData.returnRegion || (selectedReturnCity ? DEFAULT_SHIPPING_REGION : '');
        const normalizedReturnPostalCode = formData.returnPostalCode.replace(/\D/g, '');

        submitFormData.append('return_address_line', formData.returnAddressLine);
        submitFormData.append('return_barangay', formData.returnBarangay);
        submitFormData.append('return_city', selectedReturnCity || formData.returnCity);
        submitFormData.append('return_region', returnRegion);
        submitFormData.append('return_postal_code', normalizedReturnPostalCode);
      }
      
      // Add selected service IDs
      if (selectedPackageId) {
        selectedAddOnServiceIds.forEach((serviceId, index) => {
          submitFormData.append(`add_on_service_ids[${index}]`, serviceId.toString());
        });
      } else if (selectedServiceIds.length > 0) {
        selectedServiceIds.forEach((serviceId, index) => {
          submitFormData.append(`services[${index}]`, serviceId.toString());
        });
      }
      
      // Add images
      imageUploadGroups.forEach((group, index) => {
        if (group.file) {
          submitFormData.append(`images[${index}]`, group.file);
        }
      });
      
        // Persist VAT-inclusive final total.
        submitFormData.append('total', grandTotalWithVat.toString());

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
          text: `Your repair request has been submitted successfully. Total: ₱${grandTotalWithVat.toLocaleString()} (VAT included). We will contact you shortly.`,
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
            returnDeliveryMethod: '',
            pickupAddressLine: '',
            pickupBarangay: '',
            pickupCity: '',
            pickupRegion: '',
            pickupPostalCode: '',
            returnAddressLine: '',
            returnBarangay: '',
            returnCity: '',
            returnRegion: '',
            returnPostalCode: '',
          });
        }
        setSelectedServiceIds([]);
        setSelectedPackageId(null);
        setSelectedAddOnServiceIds([]);
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
      <style>{`
        .hide-scrollbar {
          -ms-overflow-style: none;
          scrollbar-width: none;
        }

        .hide-scrollbar::-webkit-scrollbar {
          width: 0;
          height: 0;
          display: none;
        }
      `}</style>
      <Head title="Request Repair Service" />
      <Navigation />

      <main className="pt-24 lg:pt-28 pb-0 xl:pb-0 xl:flex-1">
        <div className="max-w-7xl mx-auto py-6 xl:py-12 px-4 sm:px-6 text-black">
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
            <div className="xl:hidden border-b border-gray-200 px-1 pb-4 mb-6">
              <h1 className="text-3xl font-semibold text-black">Repair Summary</h1>
              <p className="text-sm text-gray-600 mt-1">Review your details and submit your repair request.</p>
            </div>

            <div className="grid grid-cols-1 xl:grid-cols-3 gap-8 items-start">
              <div className="xl:col-span-2">
                <div className="mb-8 border-b border-gray-200 py-5 xl:border-0 xl:py-0">
                  <h2 className="text-2xl xl:text-lg font-semibold text-black mb-4">Contact</h2>
                  <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
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
                      <label htmlFor="shoeType" className="block text-sm font-medium text-black mb-2">Shoe Type *</label>
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

                <div className="mb-8 border-b border-gray-200 py-5 xl:border-0 xl:py-0">
                  <h2 className="text-2xl xl:text-lg font-semibold text-black mb-4">Repair Details</h2>
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
                      <label className="block text-sm font-medium text-black mb-2">How will you deliver your shoes to the shop? *</label>
                      <p className="text-xs text-gray-600 mb-3">This determines how we'll receive your shoes for repair.</p>
                      <div className="grid grid-cols-1 xl:grid-cols-2 gap-3">
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
                            <span className="text-sm font-medium text-black">I'll Arrange Delivery (e.g., Lalamove)</span>
                            <p className="text-xs text-gray-600">I'll use a courier service to deliver my shoes to the shop.</p>
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
                            <span className="text-sm font-medium text-black">I'll Visit the Shop</span>
                            <p className="text-xs text-gray-600">
                              {shopDetails
                                ? `I'll bring my shoes to ${shopDetails.name}`
                                : 'I will bring my shoes to the shop in person'}
                            </p>
                          </div>
                        </label>
                      </div>
                    </div>

                    {formData.serviceType === 'pickup' && (
                      <div className="space-y-4 border border-gray-300 rounded p-4">
                        <p className="text-sm font-medium text-black">Courier should deliver your shoes to this shop address:</p>
                        <div className="rounded border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-800">
                          {exactShopAddress || (shopDetails?.location?.trim() ? shopDetails.location : 'Shop address unavailable')}
                        </div>
                        {hasCoordinates && (
                          <div className="rounded border border-gray-200 bg-white px-4 py-3 text-xs text-gray-700">
                            <span className="font-medium text-gray-900">Geo Location:</span>{' '}
                            {lat.toFixed(6)}, {lng.toFixed(6)}
                          </div>
                        )}
                        {!!shopMapsUrl && (
                          <a
                            href={shopMapsUrl}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 text-xs font-medium text-blue-700 hover:text-blue-800 underline"
                          >
                            Open shop location in map
                          </a>
                        )}
                        <p className="text-xs text-gray-600">
                          This address is set by the shop profile and cannot be edited here.
                        </p>
                      </div>
                    )}

                    <div>
                      <label className="block text-sm font-medium text-black mb-2">How should we return your repaired shoes? *</label>
                      <p className="text-xs text-gray-600 mb-3">This is separate from how you initially deliver the shoes to the shop.</p>
                      <div className="grid grid-cols-1 xl:grid-cols-2 gap-3">
                        <label className="flex items-start gap-3 p-3 border border-gray-300 rounded cursor-pointer h-full">
                          <input
                            type="radio"
                            name="returnDeliveryMethod"
                            value="walk_in"
                            checked={formData.returnDeliveryMethod === 'walk_in'}
                            onChange={handleInputChange}
                            className="w-4 h-4 mt-1"
                          />
                          <div>
                            <span className="text-sm font-medium text-black">Customer Pick-up at Shop</span>
                            <p className="text-xs text-gray-600">I will personally pick up my repaired shoes at the shop.</p>
                          </div>
                        </label>

                        <label className="flex items-start gap-3 p-3 border border-gray-300 rounded cursor-pointer h-full">
                          <input
                            type="radio"
                            name="returnDeliveryMethod"
                            value="shop_delivery"
                            checked={formData.returnDeliveryMethod === 'shop_delivery'}
                            onChange={handleInputChange}
                            className="w-4 h-4 mt-1"
                          />
                          <div>
                            <span className="text-sm font-medium text-black">Repairer Arranged Courier Delivery</span>
                            <p className="text-xs text-gray-600">The repair shop will arrange a courier to deliver your repaired shoes to your address.</p>
                          </div>
                        </label>
                      </div>
                    </div>

                    {formData.returnDeliveryMethod !== '' && formData.returnDeliveryMethod !== 'walk_in' && (
                      <div className="space-y-4 border border-gray-300 rounded p-4">
                        <p className="text-sm font-medium text-black">Return Address</p>
                        <div>
                          <label className="block text-sm font-medium text-black mb-2">Address line</label>
                          <input
                            type="text"
                            name="returnAddressLine"
                            value={formData.returnAddressLine}
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
                            name="returnBarangay"
                            value={formData.returnBarangay}
                            onChange={handleInputChange}
                            className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                            placeholder="Barangay"
                            required
                          />
                        </div>
                        <div>
                          <label className="block text-sm font-medium text-black mb-2">City</label>
                          <div ref={returnCityDropdownRef} className="relative">
                            <button
                              type="button"
                              onClick={() => setIsReturnCityDropdownOpen((prev) => !prev)}
                              className="flex w-full items-center justify-between rounded border border-gray-300 bg-white px-4 py-3 text-left"
                              title="City"
                              aria-label="City"
                              aria-haspopup="listbox"
                            >
                              <span className={formData.returnCity ? 'text-black' : 'text-gray-500'}>
                                {getCityLabel(formData.returnCity) || 'Select City'}
                              </span>
                              <svg className={`h-4 w-4 text-gray-500 transition-transform ${isReturnCityDropdownOpen ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                              </svg>
                            </button>

                            {isReturnCityDropdownOpen && (
                              <div className="hide-scrollbar absolute left-0 right-0 top-full z-30 mt-1 max-h-56 overflow-y-auto rounded border border-gray-300 bg-white shadow-lg">
                                <button
                                  type="button"
                                  onClick={() => handleReturnCityChange('')}
                                  className="w-full border-b border-gray-100 px-4 py-2 text-left text-sm text-gray-600 hover:bg-gray-50"
                                >
                                  Select City
                                </button>
                                {PH_CITY_OPTIONS.map((cityOption) => (
                                  <button
                                    key={cityOption.value}
                                    type="button"
                                    onClick={() => handleReturnCityChange(cityOption.value)}
                                    className={`w-full border-b border-gray-100 px-4 py-2 text-left text-sm hover:bg-gray-50 last:border-b-0 ${formData.returnCity === cityOption.value ? 'bg-gray-50 font-medium text-black' : 'text-black'}`}
                                  >
                                    {cityOption.label}
                                  </button>
                                ))}
                              </div>
                            )}
                          </div>
                        </div>
                        <div>
                          <label className="block text-sm font-medium text-black mb-2">Postal code</label>
                          <input
                            type="text"
                            name="returnPostalCode"
                            value={formData.returnPostalCode}
                            onChange={handleInputChange}
                            inputMode="numeric"
                            pattern="[0-9]*"
                            className="w-full px-4 py-3 border border-gray-300 rounded text-black bg-white"
                            placeholder="Postal code"
                            required
                          />
                        </div>
                      </div>
                    )}

                    <div>
                      <label className="block text-sm font-medium text-black mb-2">Select Repair Package</label>
                      {isLoadingPackages ? (
                        <div className="flex items-center justify-center rounded border border-gray-300 py-6 mb-4">
                          <div className="h-6 w-6 animate-spin rounded-full border-2 border-gray-300 border-t-black" />
                          <span className="ml-3 text-sm text-gray-600">Loading packages...</span>
                        </div>
                      ) : repairPackages.length === 0 ? (
                        <div className="rounded border border-gray-300 bg-gray-50 p-4 text-sm text-gray-600 mb-4">
                          No active packages available for this shop.
                        </div>
                      ) : (
                        <div className="space-y-2 mb-4">
                          {repairPackages.map((pkg) => {
                            const selected = selectedPackageId === pkg.id;
                            return (
                              <label
                                key={pkg.id}
                                className={`flex items-start justify-between gap-4 p-3 border rounded cursor-pointer transition-colors ${selected ? 'border-black bg-gray-50' : 'border-gray-300 hover:border-gray-400'}`}
                                onClick={(event) => {
                                  event.preventDefault();
                                  handlePackageToggle(pkg.id);
                                }}
                              >
                                <div className="flex items-start gap-3">
                                  <input
                                    type="radio"
                                    name="selectedRepairPackage"
                                    checked={selected}
                                    readOnly
                                    className="w-4 h-4 mt-1"
                                  />
                                  <div>
                                    <p className="text-sm font-semibold text-black">{pkg.name}</p>
                                    <p className="text-xs text-gray-600">{pkg.description || 'Package offer'}</p>
                                    <p className="text-xs text-gray-500 mt-1">
                                      Includes {pkg.service_count} service{pkg.service_count !== 1 ? 's' : ''} • Save ₱{Number(pkg.savings_amount || 0).toLocaleString()}
                                    </p>
                                  </div>
                                </div>
                                <span className="text-sm font-semibold text-black">₱{getEffectivePackagePrice(pkg).toLocaleString()}</span>
                              </label>
                            );
                          })}

                          {selectedPackageId !== null && (
                            <div className="flex justify-end">
                              <button
                                type="button"
                                onClick={() => handlePackageToggle(selectedPackageId)}
                                className="text-xs font-semibold text-gray-700 underline hover:text-black"
                              >
                                Unselect package
                              </button>
                            </div>
                          )}
                        </div>
                      )}

                      <label className="block text-sm font-medium text-black mb-2">
                        {selectedPackageId ? 'Select Optional Add-ons' : 'Select Repair Service(s) *'}
                      </label>
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
                                {selectedPackageId ? (
                                  <input
                                    type="checkbox"
                                    name={`repairAddOn-${service.id}`}
                                    checked={includedPackageServiceIds.has(service.id) || selectedAddOnServiceIds.includes(service.id)}
                                    onChange={() => handleAddOnToggle(service.id)}
                                    disabled={includedPackageServiceIds.has(service.id)}
                                    className="w-4 h-4 mt-1"
                                  />
                                ) : (
                                  <input
                                    type="checkbox"
                                    name={`selectedRepairService-${service.id}`}
                                    checked={selectedServiceIds.includes(service.id)}
                                    onChange={() => handleServiceToggle(service.id)}
                                    className="w-4 h-4 mt-1"
                                  />
                                )}
                                <div>
                                  <p className="text-sm font-medium text-black">{service.title}</p>
                                  <p className="text-xs text-gray-600">{service.description}</p>
                                  {selectedPackageId !== null && includedPackageServiceIds.has(service.id) && (
                                    <p className="text-xs text-emerald-700 mt-1">Included in the selected package.</p>
                                  )}
                                  {selectedPackageId !== null && !includedPackageServiceIds.has(service.id) && (
                                    <p className="text-xs text-gray-500 mt-1">Optional add-on service.</p>
                                  )}
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
                      <div className="grid grid-cols-2 xl:grid-cols-4 gap-3">
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

                <div className="mb-8 xl:hidden">
                  {orderSummaryCard}
                </div>

                <div className="hidden xl:block">
                  <button
                  type="submit"
                  disabled={isSubmitDisabled}
                  className={`w-full py-3 rounded-md font-semibold text-white mb-3 transition-colors ${
                    isSubmitDisabled ? 'bg-gray-400 cursor-not-allowed' : 'bg-gray-900 hover:bg-gray-800'
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
              </div>

              <aside className="hidden xl:block xl:col-span-1 xl:sticky xl:top-24">
                {orderSummaryCard}
              </aside>
            </div>

            <div className="xl:hidden sticky bottom-0 z-30 border-t border-gray-200 bg-white">
              <div className="mx-auto max-w-7xl px-4 sm:px-6 py-3 flex items-end justify-between gap-3">
                <div>
                  <p className="text-xs text-gray-600">Total</p>
                  <p className="text-4xl font-bold text-black leading-none">₱{grandTotalWithVat.toLocaleString()}</p>
                  <p className="text-[10px] text-gray-500 mt-1">Includes VAT ({REPAIR_VAT_RATE_PERCENT}%)</p>
                </div>
                <button
                  type="submit"
                  disabled={isSubmitDisabled}
                  className={`rounded-full px-6 py-3 text-sm font-semibold text-white transition-colors ${
                    isSubmitDisabled ? 'bg-gray-400 cursor-not-allowed' : 'bg-gray-900 hover:bg-gray-800'
                  }`}
                >
                  {isSubmitting ? 'Submitting...' : 'Continue'}
                </button>
              </div>
              <div className="mx-auto max-w-7xl px-4 sm:px-6 pb-3">
                <Link href="/repair-services" className="text-xs text-gray-600 underline">
                  Cancel and go back
                </Link>
              </div>
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
