import { useEffect, useRef, useState } from "react";
import { Head, router } from "@inertiajs/react";
import { route } from 'ziggy-js';
import Swal from 'sweetalert2';
import 'leaflet/dist/leaflet.css';
import Navigation from "../Shared/Navigation";
import ComponentCard from "../../../components/common/ComponentCard";
import Label from "../../../components/form/Label";
import Input from "../../../components/form/input/InputField";
import Select from "../../../components/form/Select";
import Radio from "../../../components/form/input/Radio";
import DropzoneComponent from "../../../components/form/form-elements/DropZone";

export default function ShopOwnerRegistration() {
  const [currentStep, setCurrentStep] = useState(1);
  const [formData, setFormData] = useState({
    firstName: "",
    lastName: "",
    email: "",
    phone: "",
    businessName: "",
    businessAddress: "",
    businessType: "",
    registrationType: "individual",
  });


  const [uploadedDocuments, setUploadedDocuments] = useState({
    dti: { file: null as File | null, fileName: '' },
    mayors_permit: { file: null as File | null, fileName: '' },
    bir: { file: null as File | null, fileName: '' },
    valid_id: { file: null as File | null, fileName: '' },
  });

  const [isSubmitting, setIsSubmitting] = useState(false);
  const [showSuccessModal, setShowSuccessModal] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  // Geofence state
  const [geoLat, setGeoLat] = useState('14.59950000');
  const [geoLng, setGeoLng] = useState('120.98420000');
  const [geoAddress, setGeoAddress] = useState('');
  const [geoRadius, setGeoRadius] = useState<number>(90);
  const [gettingGPS, setGettingGPS] = useState(false);
  const [geoError, setGeoError] = useState('');
  const mapRef = useRef<HTMLDivElement>(null);
  const leafletMapRef = useRef<any>(null);
  const markerRef = useRef<any>(null);
  const circleRef = useRef<any>(null);

  const businessTypeOptions = [
    { value: "retail", label: "Retail" },
    { value: "repair", label: "Repair" },
    { value: "both (retail & repair)", label: "both (retail & repair)" },
  ];

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
    setFormData(prev => ({ ...prev, [name]: value }));
    // Clear error when user starts typing
    if (errors[name]) {
      setErrors(prev => ({ ...prev, [name]: '' }));
    }
  };

  const handleSelectChange = (value: string) => {
    setFormData(prev => ({ ...prev, businessType: value }));
    // Clear error when user selects a value
    if (errors.businessType) {
      setErrors(prev => ({ ...prev, businessType: '' }));
    }
  };

  const handleRegistrationTypeChange = (value: string) => {
    setFormData(prev => ({ ...prev, registrationType: value }));
  };

  const hasValidGeoCoordinates = () => {
    const lat = parseFloat(geoLat);
    const lng = parseFloat(geoLng);
    return Number.isFinite(lat) && Number.isFinite(lng);
  };

  useEffect(() => {
    if (currentStep !== 2 || !mapRef.current) return;

    let cancelled = false;

    import('leaflet').then((L) => {
      if (cancelled || !mapRef.current) return;

      delete (L.Icon.Default.prototype as any)._getIconUrl;
      L.Icon.Default.mergeOptions({
        iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
        iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
        shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
      });

      const initLat = parseFloat(geoLat) || 14.5995;
      const initLng = parseFloat(geoLng) || 120.9842;

      const map = L.map(mapRef.current).setView([initLat, initLng], 16);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
      }).addTo(map);

      const marker = L.marker([initLat, initLng], { draggable: true }).addTo(map);
      const circle = L.circle([initLat, initLng], {
        radius: geoRadius,
        color: '#2563eb',
        fillOpacity: 0.08,
      }).addTo(map);

      marker.on('dragend', () => {
        const pos = marker.getLatLng();
        setGeoLat(pos.lat.toFixed(8));
        setGeoLng(pos.lng.toFixed(8));
        circle.setLatLng(pos);
      });

      map.on('click', (e: any) => {
        marker.setLatLng(e.latlng);
        circle.setLatLng(e.latlng);
        setGeoLat(e.latlng.lat.toFixed(8));
        setGeoLng(e.latlng.lng.toFixed(8));
      });

      leafletMapRef.current = map;
      markerRef.current = marker;
      circleRef.current = circle;
      window.setTimeout(() => map.invalidateSize(), 0);
    });

    return () => {
      cancelled = true;
      if (leafletMapRef.current) {
        leafletMapRef.current.remove();
      }
      leafletMapRef.current = null;
      markerRef.current = null;
      circleRef.current = null;
    };
  }, [currentStep]);

  useEffect(() => {
    if (circleRef.current) {
      circleRef.current.setRadius(geoRadius);
    }
  }, [geoRadius]);

  useEffect(() => {
    const lat = parseFloat(geoLat);
    const lng = parseFloat(geoLng);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
    if (!leafletMapRef.current || !markerRef.current || !circleRef.current) return;

    leafletMapRef.current.setView([lat, lng], 16);
    markerRef.current.setLatLng([lat, lng]);
    circleRef.current.setLatLng([lat, lng]);
  }, [geoLat, geoLng]);

  const handleUseMyGPS = () => {
    if (!navigator.geolocation) {
      setGeoError('Geolocation is not supported by your browser.');
      return;
    }

    setGettingGPS(true);
    setGeoError('');

    navigator.geolocation.getCurrentPosition(
      async (pos) => {
        const lat = pos.coords.latitude.toFixed(8);
        const lng = pos.coords.longitude.toFixed(8);
        setGeoLat(lat);
        setGeoLng(lng);

        try {
          const res = await fetch(
            `https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json`,
            { headers: { 'User-Agent': 'SoleSpace/1.0' } },
          );
          const data = await res.json();
          if (data.display_name) {
            setGeoAddress(data.display_name);
            setFormData(prev => ({ ...prev, businessAddress: data.display_name }));
          }
        } catch {
          // Keep coordinates even if reverse geocoding fails.
        }

        setGettingGPS(false);
      },
      () => {
        setGeoError('Could not get your location. Please allow location access.');
        setGettingGPS(false);
      },
      { enableHighAccuracy: true },
    );
  };

  const validateStep = (step: number): boolean => {
    if (step === 1) {
      return !!(formData.firstName && formData.lastName && formData.email && formData.phone);
    } else if (step === 2) {
      const hasGeo = hasValidGeoCoordinates();
      return !!(formData.businessName && formData.businessAddress && formData.businessType && hasGeo);
    } else if (step === 3) {
      return !!(uploadedDocuments.dti.file && uploadedDocuments.mayors_permit.file &&
                uploadedDocuments.bir.file && uploadedDocuments.valid_id.file);
    }
    return true;
  };

  const validateForm = () => {
    const requiredFields = [
      'firstName', 'lastName', 'email', 'phone',
      'businessName', 'businessAddress', 'businessType'
    ];
    for (const field of requiredFields) {
      if (!formData[field as keyof typeof formData]) {
        return { valid: false, message: 'Please fill in all required fields before submitting.' };
      }
    }

    if (!hasValidGeoCoordinates()) {
      return { valid: false, message: 'Please set a valid geofence location (latitude and longitude).'};
    }

    // Check if all documents are uploaded
    if (!uploadedDocuments.dti.file) {
      return { valid: false, message: 'Shop Registration (DTI) is required' };
    }
    if (!uploadedDocuments.mayors_permit.file) {
      return { valid: false, message: "Mayor's Permit / Shop Permit is required" };
    }
    if (!uploadedDocuments.bir.file) {
      return { valid: false, message: 'BIR Certificate of Registration (COR) is required' };
    }
    if (!uploadedDocuments.valid_id.file) {
      return { valid: false, message: 'Valid ID of Owner is required.' };
    }

    return { valid: true, message: '' };
  };

  const handleNext = () => {
    if (validateStep(currentStep)) {
      setCurrentStep(currentStep + 1);
    } else {
      const geofenceMessage = currentStep === 2 && !hasValidGeoCoordinates()
        ? 'Please place your shop pin on the map or enter valid coordinates before proceeding.'
        : 'Please fill in all required fields before proceeding.';

      Swal.fire({
        icon: 'error',
        title: 'Missing Information',
        text: geofenceMessage,
        confirmButtonColor: '#3085d6',
      });
    }
  };

  const handlePrev = () => {
    setCurrentStep(currentStep - 1);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    const validation = validateForm();
    if (!validation.valid) {
      Swal.fire({
        icon: 'error',
        title: 'Missing Required Information',
        text: validation.message,
        confirmButtonColor: '#3085d6',
      });
      return;
    }

    const result = await Swal.fire({
      title: 'Confirm Submission',
      text: 'Are you sure you want to submit your registration? All documents will be reviewed within 3-7 business days.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, Submit',
      cancelButtonText: 'Cancel',
    });

    if (result.isConfirmed) {
      setIsSubmitting(true);

      try {
        // Prepare form data for submission
        const submitData = new FormData();
        
        // Add basic fields
        submitData.append('first_name', formData.firstName);
        submitData.append('last_name', formData.lastName);
        submitData.append('email', formData.email);
        submitData.append('phone', formData.phone);
        submitData.append('business_name', formData.businessName);
        submitData.append('business_address', formData.businessAddress);
        submitData.append('business_type', formData.businessType);
        submitData.append('registration_type', formData.registrationType);
        submitData.append('attendance_geofence_enabled', '1');
        submitData.append('shop_latitude', geoLat);
        submitData.append('shop_longitude', geoLng);
        submitData.append('shop_address', geoAddress || formData.businessAddress);
        submitData.append('shop_geofence_radius', String(geoRadius));

        // Operating hours removed — nothing to append for operating hours

        // Add document files
        if (uploadedDocuments.dti.file) {
          submitData.append('dti_registration', uploadedDocuments.dti.file);
        }
        if (uploadedDocuments.mayors_permit.file) {
          submitData.append('mayors_permit', uploadedDocuments.mayors_permit.file);
        }
        if (uploadedDocuments.bir.file) {
          submitData.append('bir_certificate', uploadedDocuments.bir.file);
        }
        if (uploadedDocuments.valid_id.file) {
          submitData.append('valid_id', uploadedDocuments.valid_id.file);
        }

        // Submit to backend
        router.post(route('shop-owner.register'), submitData, {
          forceFormData: true,
          onSuccess: (page) => {
            setIsSubmitting(false);
            // Check if response contains redirect to verification
            const props = page.props as any;
            if (props.success) {
              Swal.fire({
                icon: 'success',
                title: 'Registration Successful!',
                html: `
                  <p>Thank you for registering!</p>
                  <p class="mt-3">We've sent a verification email to:</p>
                  <p class="font-semibold text-blue-600 mt-2">${formData.email}</p>
                  <p class="text-sm text-gray-600 mt-3">Please check your inbox and click the verification link to complete your registration.</p>
                `,
                confirmButtonText: 'OK',
                confirmButtonColor: '#3085d6',
              });
              // Router will automatically handle redirect to verification.notice
            } else {
              setShowSuccessModal(true);
            }
          },
          onError: (backendErrors) => {
            setIsSubmitting(false);
            // Map backend errors to state
            const mapped: Record<string, string> = {};
            Object.entries(backendErrors || {}).forEach(([key, val]) => {
              mapped[key] = Array.isArray(val) ? val[0] : String(val);
            });
            setErrors(mapped);
            
            const errorMessages = Object.values(mapped).join('\n');
            Swal.fire({
              icon: 'error',
              title: 'Registration Failed',
              text: errorMessages || 'Please check your information and try again.',
              confirmButtonColor: '#3085d6',
            });
          },
        });
      } catch (error) {
        setIsSubmitting(false);
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: 'An unexpected error occurred. Please try again.',
          confirmButtonColor: '#3085d6',
        });
      }
    }
  };

  return (
    <>
      <Head title="Shop Owner Registration" />
      <div className="min-h-screen bg-gradient-to-br from-slate-50 to-slate-100">
        <Navigation />
        <div className="max-w-6xl mx-auto px-4 lg:px-8 pt-12 pb-12">
          {/* Header Section */}
          <div className="text-center mb-12">
            <h1 className="text-4xl lg:text-5xl font-bold text-gray-900 mb-4 tracking-tight">
              Shop Owner Registration
            </h1>
            <p className="text-lg text-gray-600 max-w-2xl mx-auto mb-2">
              Join our platform and reach more customers
            </p>
            <p className="text-sm text-gray-500">
              Complete your registration to start selling products and services
            </p>
          </div>

          {/* Progress Indicator */}
          <div className="mb-8 bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <div className="flex items-center justify-between">
              {[1, 2, 3, 4].map((step, index) => (
                <div key={step} className="flex items-center">
                  <div className="flex items-center space-x-2">
                    <div className={`flex items-center justify-center w-8 h-8 rounded-full text-sm font-semibold ${
                      currentStep >= step ? 'bg-blue-600 text-white' : 'bg-gray-300 text-gray-600'
                    }`}>
                      {step}
                    </div>
                    <span className={`text-sm font-medium ${
                      currentStep >= step ? 'text-gray-900' : 'text-gray-500'
                    }`}>
                      {step === 1 && 'Personal Info'}
                      {step === 2 && 'Shop Info'}
                      {step === 3 && 'Documents'}
                      {step === 4 && 'Review & Submit'}
                    </span>
                  </div>
                  {index < 3 && (
                    <div className={`flex-1 mx-4 h-1 rounded-full ${
                      currentStep > step ? 'bg-blue-600' : 'bg-gray-200'
                    }`}></div>
                  )}
                </div>
              ))}
            </div>
          </div>

          <div className="space-y-6">
            {currentStep === 1 && (
              <ComponentCard title="Personal Information">
                <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                  <div>
                    <Label htmlFor="firstName">First Name</Label>
                    <Input
                      type="text"
                      id="firstName"
                      name="firstName"
                      value={formData.firstName}
                      onChange={handleInputChange}
                      placeholder="Enter first name"
                      className={errors.first_name ? 'border-red-500' : ''}
                    />
                    {errors.first_name && <p className="mt-1 text-sm text-red-600">{errors.first_name}</p>}
                  </div>
                  <div>
                    <Label htmlFor="lastName">Last Name</Label>
                    <Input
                      type="text"
                      id="lastName"
                      name="lastName"
                      value={formData.lastName}
                      onChange={handleInputChange}
                      placeholder="Enter last name"
                      className={errors.last_name ? 'border-red-500' : ''}
                    />
                    {errors.last_name && <p className="mt-1 text-sm text-red-600">{errors.last_name}</p>}
                  </div>
                  <div>
                    <Label htmlFor="email">Email</Label>
                    <Input
                      type="email"
                      id="email"
                      name="email"
                      value={formData.email}
                      onChange={handleInputChange}
                      placeholder="Enter email address"
                      className={errors.email ? 'border-red-500' : ''}
                    />
                    {errors.email && <p className="mt-1 text-sm text-red-600">{errors.email}</p>}
                  </div>
                  <div>
                    <Label htmlFor="phone">Phone Number</Label>
                    <Input
                      type="tel"
                      id="phone"
                      name="phone"
                      value={formData.phone}
                      onChange={handleInputChange}
                      placeholder="Enter phone number"
                      className={errors.phone ? 'border-red-500' : ''}
                    />
                    {errors.phone && <p className="mt-1 text-sm text-red-600">{errors.phone}</p>}
                  </div>
                </div>
                <div className="flex justify-end pt-4">
                  <button
                    type="button"
                    onClick={handleNext}
                    className="px-6 py-3 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors"
                  >
                    Next
                  </button>
                </div>
              </ComponentCard>
            )}

            {currentStep === 2 && (
              <ComponentCard title="Shop Information">
                <div className="space-y-6">
                  <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div>
                      <Label htmlFor="businessName">Shop Name</Label>
                      <Input
                        type="text"
                        id="businessName"
                        name="businessName"
                        value={formData.businessName}
                        onChange={handleInputChange}
                        placeholder="Enter shop name"
                        className={errors.business_name ? 'border-red-500' : ''}
                      />
                      {errors.business_name && <p className="mt-1 text-sm text-red-600">{errors.business_name}</p>}
                    </div>
                    <div>
                      <Label htmlFor="businessAddress">Shop Address</Label>
                      <div className="mt-1 flex flex-col gap-2 sm:flex-row sm:items-stretch">
                        <div className="flex-1">
                          <Input
                            type="text"
                            id="businessAddress"
                            name="businessAddress"
                            value={formData.businessAddress}
                            onChange={handleInputChange}
                            placeholder="Enter shop address"
                            className={`w-full ${errors.business_address ? 'border-red-500' : ''}`}
                          />
                        </div>
                        <button
                          type="button"
                          onClick={handleUseMyGPS}
                          disabled={gettingGPS}
                          className="shrink-0 px-4 py-2 border border-blue-600 text-blue-600 font-semibold text-sm hover:bg-blue-50 transition-colors disabled:opacity-50 whitespace-nowrap"
                        >
                          {gettingGPS ? 'Getting GPS...' : 'Use My GPS'}
                        </button>
                      </div>
                      {errors.business_address && <p className="mt-1 text-sm text-red-600">{errors.business_address}</p>}
                    </div>
                  </div>
                  <div>
                    <Label>Shop Type</Label>
                    <Select
                      options={businessTypeOptions}
                      placeholder="Select shop type"
                      onChange={handleSelectChange}
                    />
                    {errors.business_type && <p className="mt-1 text-sm text-red-600">{errors.business_type}</p>}
                  </div>

                  <div>
                    <Label>Registration Type</Label>
                    <div className="flex flex-wrap items-center gap-8">
                      <Radio
                        id="individual"
                        name="registrationType"
                        value="individual"
                        checked={formData.registrationType === "individual"}
                        onChange={handleRegistrationTypeChange}
                        label="Registered as Individual"
                      />
                      <Radio
                        id="company"
                        name="registrationType"
                        value="company"
                        checked={formData.registrationType === "company"}
                        onChange={handleRegistrationTypeChange}
                        label="Registered as Business"
                      />
                    </div>
                  </div>

                  <div className="rounded-xl border border-gray-200 bg-white p-4 md:p-5 space-y-5">
                    {geoAddress && (
                      <p className="rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-xs text-green-800">
                        <span className="font-semibold">Detected address:</span> {geoAddress}. If this is wrong, drag the pin on the map.
                      </p>
                    )}

                    <div>
                      <p className="mb-2 text-sm font-medium text-gray-700">
                        Shop Location <span className="font-normal text-gray-500">(drag pin or click map to adjust)</span>
                      </p>
                      <div ref={mapRef} className="h-72 w-full rounded-xl border border-gray-200 overflow-hidden z-0" />
                    </div>

                    {geoError && <p className="text-sm text-red-600">{geoError}</p>}
                    {errors.shop_latitude && <p className="text-sm text-red-600">{errors.shop_latitude}</p>}
                    {errors.shop_longitude && <p className="text-sm text-red-600">{errors.shop_longitude}</p>}
                  </div>
                </div>
                <div className="flex justify-between pt-4">
                  <button
                    type="button"
                    onClick={handlePrev}
                    className="px-6 py-3 bg-gray-200 text-gray-700 font-semibold uppercase tracking-wider text-sm hover:bg-gray-300 transition-colors"
                  >
                    Previous
                  </button>
                  <button
                    type="button"
                    onClick={handleNext}
                    className="px-6 py-3 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors"
                  >
                    Next
                  </button>
                </div>
              </ComponentCard>
            )}

            {currentStep === 3 && (
              <ComponentCard title="Document Upload">
                <div className="space-y-4">
                  <Label>Shop Permits & Credentials</Label>
                  <p className="text-sm text-gray-600">
                    Upload your shop license, permits, or other relevant credentials for verification.
                  </p>
                  <div className="mb-6">
                    <h4 className="text-lg font-semibold text-gray-800 mb-4">Document Submission Instructions</h4>
                    <p className="text-sm text-gray-600 mb-4">
                      Please upload clear photos of the following documents:
                    </p>
                    <ul className="list-disc list-inside text-sm text-gray-600 mb-4 space-y-1">
                      <li>Shop Registration (DTI/SEC)</li>
                      <li>Mayor's Permit / Shop Permit</li>
                      <li>BIR Certificate of Registration (COR)</li>
                    </ul>
                    <p className="text-sm font-semibold text-gray-800 mb-2">Guidelines for your photos:</p>
                    <ul className="list-disc list-inside text-sm text-gray-600 space-y-1">
                      <li>Take a photo of the entire document, ensure all text and details are visible.</li>
                      <li>Do not cut any part of the document.</li>
                      <li>Make sure the photo is clear and in focus, no blurry or dark areas.</li>
                      <li>Only submit image files: JPG or PNG (no WebP, SVG, PDF, or other formats).</li>
                      <li>Avoid shadows or glare that can hide details.</li>
                      <li>Ensure all edges of the document are visible in the photo.</li>
                      <li>If the document is large, take a single photo that captures the full page, not multiple cropped images.</li>
                      <li>No edits or filters—the document must be authentic and readable.</li>
                    </ul>
                  </div>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <Label>Shop Registration (DTI) {uploadedDocuments.dti.file && <span className="text-green-600 font-bold ml-2">✓ Uploaded</span>}</Label>
                      <DropzoneComponent
                        onDrop={(files) => {
                          if (files && files.length > 0) {
                            const file = files[0];
                            setUploadedDocuments(prev => ({
                              ...prev,
                              dti: { file: file, fileName: file.name }
                            }));

                            Swal.fire({
                              icon: 'info',
                              title: 'File Attached',
                              html: `<p><strong>${file.name}</strong> was added to <strong>Shop Registration (DTI)</strong>.</p><p class="text-sm text-gray-600">Please ensure the correct document is uploaded in this section.</p>`,
                              confirmButtonText: 'OK',
                              confirmButtonColor: '#3085d6',
                            });

                            // Clear error when file is uploaded
                            if (errors.dti_registration) {
                              setErrors(prev => ({ ...prev, dti_registration: '' }));
                            }
                          }
                        }}
                        isUploaded={!!uploadedDocuments.dti.file}
                        fileName={uploadedDocuments.dti.fileName}
                      />
                      {uploadedDocuments.dti.file && (
                        <p className="mt-2 text-sm text-green-600 font-semibold flex items-center">
                          <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                            <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                          </svg>
                          Document uploaded successfully
                        </p>
                      )}
                      {errors.dti_registration && <p className="mt-1 text-sm text-red-600">{errors.dti_registration}</p>}
                    </div>
                    <div>
                      <Label>Mayor's Permit / Shop Permit {uploadedDocuments.mayors_permit.file && <span className="text-green-600 font-bold ml-2">✓ Uploaded</span>}</Label>
                      <DropzoneComponent
                        onDrop={(files) => {
                          if (files && files.length > 0) {
                            const file = files[0];
                            setUploadedDocuments(prev => ({
                              ...prev,
                              mayors_permit: { file: file, fileName: file.name }
                            }));
                            Swal.fire({
                              icon: 'info',
                              title: 'File Attached',
                              html: `<p><strong>${file.name}</strong> was added to <strong>Mayor's Permit / Shop Permit</strong>.</p><p class="text-sm text-gray-600">Please ensure the correct document is uploaded in this section.</p>`,
                              confirmButtonText: 'OK',
                              confirmButtonColor: '#3085d6',
                            });

                            // Clear error when file is uploaded
                            if (errors.mayors_permit) {
                              setErrors(prev => ({ ...prev, mayors_permit: '' }));
                            }
                          }
                        }}
                        isUploaded={!!uploadedDocuments.mayors_permit.file}
                        fileName={uploadedDocuments.mayors_permit.fileName}
                      />
                      {uploadedDocuments.mayors_permit.file && (
                        <p className="mt-2 text-sm text-green-600 font-semibold flex items-center">
                          <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                            <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                          </svg>
                          Document uploaded successfully
                        </p>
                      )}
                      {errors.mayors_permit && <p className="mt-1 text-sm text-red-600">{errors.mayors_permit}</p>}
                    </div>
                    <div>
                      <Label>BIR Certificate of Registration (COR) {uploadedDocuments.bir.file && <span className="text-green-600 font-bold ml-2">✓ Uploaded</span>}</Label>
                      <DropzoneComponent
                        onDrop={(files) => {
                          if (files && files.length > 0) {
                            const file = files[0];
                            setUploadedDocuments(prev => ({
                              ...prev,
                              bir: { file: file, fileName: file.name }
                            }));
                            Swal.fire({
                              icon: 'info',
                              title: 'File Attached',
                              html: `<p><strong>${file.name}</strong> was added to <strong>BIR Certificate of Registration (COR)</strong>.</p><p class="text-sm text-gray-600">Please ensure the correct document is uploaded in this section.</p>`,
                              confirmButtonText: 'OK',
                              confirmButtonColor: '#3085d6',
                            });

                            // Clear error when file is uploaded
                            if (errors.bir_certificate) {
                              setErrors(prev => ({ ...prev, bir_certificate: '' }));
                            }
                          }
                        }}
                        isUploaded={!!uploadedDocuments.bir.file}
                        fileName={uploadedDocuments.bir.fileName}
                      />
                      {uploadedDocuments.bir.file && (
                        <p className="mt-2 text-sm text-green-600 font-semibold flex items-center">
                          <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                            <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                          </svg>
                          Document uploaded successfully
                        </p>
                      )}
                      {errors.bir_certificate && <p className="mt-1 text-sm text-red-600">{errors.bir_certificate}</p>}
                    </div>
                    <div>
                      <Label>Valid ID of Owner {uploadedDocuments.valid_id.file && <span className="text-green-600 font-bold ml-2">✓ Uploaded</span>}</Label>
                      <DropzoneComponent
                        onDrop={(files) => {
                          if (files && files.length > 0) {
                            const file = files[0];
                            setUploadedDocuments(prev => ({
                              ...prev,
                              valid_id: { file: file, fileName: file.name }
                            }));
                            Swal.fire({
                              icon: 'info',
                              title: 'File Attached',
                              html: `<p><strong>${file.name}</strong> was added to <strong>Valid ID of Owner</strong>.</p><p class="text-sm text-gray-600">Please ensure the correct document is uploaded in this section.</p>`,
                              confirmButtonText: 'OK',
                              confirmButtonColor: '#3085d6',
                            });

                            // Clear error when file is uploaded
                            if (errors.valid_id) {
                              setErrors(prev => ({ ...prev, valid_id: '' }));
                            }
                          }
                        }}
                        isUploaded={!!uploadedDocuments.valid_id.file}
                        fileName={uploadedDocuments.valid_id.fileName}
                      />
                      {uploadedDocuments.valid_id.file && (
                        <p className="mt-2 text-sm text-green-600 font-semibold flex items-center">
                          <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                            <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                          </svg>
                          Document uploaded successfully
                        </p>
                      )}
                      {errors.valid_id && <p className="mt-1 text-sm text-red-600">{errors.valid_id}</p>}
                    </div>
                  </div>
                </div>
                <div className="flex justify-between pt-4">
                  <button
                    type="button"
                    onClick={handlePrev}
                    className="px-6 py-3 bg-gray-200 text-gray-700 font-semibold uppercase tracking-wider text-sm hover:bg-gray-300 transition-colors"
                  >
                    Previous
                  </button>
                  <button
                    type="button"
                    onClick={handleNext}
                    className="px-6 py-3 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors"
                  >
                    Next
                  </button>
                </div>
              </ComponentCard>
            )}

            {currentStep === 4 && (
              <>
                {/* Review Timeline */}
                <div className="bg-blue-50 border border-blue-200 rounded-lg p-6">
                  <div className="flex gap-4">
                    <div className="flex-shrink-0">
                      <svg className="w-6 h-6 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
                      </svg>
                    </div>
                    <div>
                      <h4 className="font-semibold text-gray-900 mb-2">Review Timeline</h4>
                      <ul className="text-sm text-gray-700 space-y-1">
                        <li>• Review period: 3 to 7 business days</li>
                        <li>• Our team verifies all documents and shop details</li>
                        <li>• You'll receive status updates via email</li>
                      </ul>
                    </div>
                  </div>
                </div>

                {/* Submit Button Section */}
                <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-8">
                  <div className="flex justify-between items-center">
                    <button
                      type="button"
                      onClick={handlePrev}
                      className="px-6 py-3 bg-gray-200 text-gray-700 font-semibold uppercase tracking-wider text-sm hover:bg-gray-300 transition-colors"
                    >
                      Previous
                    </button>
                    <div className="text-center">
                      <h3 className="text-lg font-semibold text-gray-900 mb-1">Ready to Submit?</h3>
                      <p className="text-sm text-gray-600">
                        Review all information before submitting your application for approval.
                      </p>
                    </div>
                    <button
                      type="submit"
                      onClick={handleSubmit}
                      className="px-6 py-3 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors"
                    >
                      Submit Registration
                    </button>
                  </div>
                </div>
              </>
            )}
          </div>
        </div>
      </div>

      {/* Success Modal */}
      {showSuccessModal && (
        <>
          {/* Backdrop */}
          <div className="fixed inset-0 bg-black/70 backdrop-blur-sm z-50" />

          {/* Modal */}
          <div className="fixed inset-0 flex items-center justify-center z-50 p-4">
            <div className="bg-white rounded-2xl shadow-2xl max-w-lg w-full mx-4">
              {/* Header Section */}
              <div className="px-8 pt-8 pb-6 text-center">
                <div className="flex justify-center mb-4">
                  <div className="w-16 h-16 bg-green-50 rounded-full flex items-center justify-center">
                    <svg className="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                    </svg>
                  </div>
                </div>
                <h2 className="text-2xl font-bold text-gray-900 mb-2">
                  Documents submitted successfully
                </h2>
                <p className="text-gray-600">
                  Your registration is now under review
                </p>
              </div>

              {/* Divider */}
              <div className="border-t border-gray-100"></div>

              {/* Content Section */}
              <div className="px-8 py-6 space-y-6">
                {/* Review Info Block */}
                <div className="bg-blue-50 rounded-lg p-4 border border-blue-100">
                  <div className="flex items-center space-x-2">
                    <svg className="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span className="text-sm font-medium text-blue-900">Review time: 3–7 business days</span>
                  </div>
                </div>

                {/* What Happens Next */}
                <div>
                  <h3 className="text-lg font-semibold text-gray-900 mb-4">What happens next</h3>
                  <div className="space-y-3">
                    <div className="flex items-start space-x-3">
                      <div className="flex-shrink-0 w-6 h-6 bg-blue-100 rounded-full flex items-center justify-center">
                        <span className="text-xs font-semibold text-blue-600">1</span>
                      </div>
                      <p className="text-sm text-gray-700">Our team checks document clarity and completeness</p>
                    </div>
                    <div className="flex items-start space-x-3">
                      <div className="flex-shrink-0 w-6 h-6 bg-blue-100 rounded-full flex items-center justify-center">
                        <span className="text-xs font-semibold text-blue-600">2</span>
                      </div>
                      <p className="text-sm text-gray-700">We verify shop registration details</p>
                    </div>
                    <div className="flex items-start space-x-3">
                      <div className="flex-shrink-0 w-6 h-6 bg-blue-100 rounded-full flex items-center justify-center">
                        <span className="text-xs font-semibold text-blue-600">3</span>
                      </div>
                      <p className="text-sm text-gray-700">You receive a status update through your email</p>
                    </div>
                  </div>
                </div>

                {/* Submitted Documents */}
                <div className="bg-gray-50 rounded-lg p-4 border border-gray-200">
                  <h4 className="text-sm font-semibold text-gray-900 mb-3">Submitted Documents</h4>
                  <div className="space-y-2">
                    <div className="flex items-center space-x-2">
                      <svg className="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                      </svg>
                      <span className="text-sm text-gray-700">Shop Registration (DTI)</span>
                    </div>
                    <div className="flex items-center space-x-2">
                      <svg className="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                      </svg>
                      <span className="text-sm text-gray-700">Mayor's Permit / Shop Permit</span>
                    </div>
                    <div className="flex items-center space-x-2">
                      <svg className="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                      </svg>
                      <span className="text-sm text-gray-700">BIR Certificate of Registration</span>
                    </div>
                    <div className="flex items-center space-x-2">
                      <svg className="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                      </svg>
                      <span className="text-sm text-gray-700">Valid ID of Owner</span>
                    </div>
                  </div>
                </div>
              </div>

              {/* Footer */}
              <div className="border-t border-gray-100 px-8 py-6">
                <button
                  onClick={() => {
                    setShowSuccessModal(false);
                    router.visit(route('services'));
                  }}
                  className="w-full bg-blue-600 text-white font-semibold py-3 px-6 rounded-lg hover:bg-blue-700 transition-colors duration-200"
                >
                  Got it
                </button>
              </div>
            </div>
          </div>
        </>
      )}
    </>
  );
}
