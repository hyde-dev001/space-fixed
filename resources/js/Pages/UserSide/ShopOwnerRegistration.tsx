import { useState } from "react";
import { Head, router } from "@inertiajs/react";
import { route } from 'ziggy-js';
import Swal from 'sweetalert2';
import Navigation from "./Navigation";
import ComponentCard from "../../components/common/ComponentCard";
import Label from "../../components/form/Label";
import Input from "../../components/form/input/InputField";
import Select from "../../components/form/Select";
import Radio from "../../components/form/input/Radio";
import DropzoneComponent from "../../components/form/form-elements/DropZone";


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

  const businessTypeOptions = [
    { value: "retail", label: "Retail" },
    { value: "repair", label: "Repair" },
    { value: "both (retail & repair)", label: "both (retail & repair)" },
  ];

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
    setFormData(prev => ({ ...prev, [name]: value }));
  };

  const handleSelectChange = (value: string) => {
    setFormData(prev => ({ ...prev, businessType: value }));
  };

  const handleRegistrationTypeChange = (value: string) => {
    setFormData(prev => ({ ...prev, registrationType: value }));
  };


  const validateStep = (step: number): boolean => {
    if (step === 1) {
      return !!(formData.firstName && formData.lastName && formData.email && formData.phone);
    } else if (step === 2) {
      return !!(formData.businessName && formData.businessAddress && formData.businessType);
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

    // Check if all documents are uploaded
    if (!uploadedDocuments.dti.file) {
      return { valid: false, message: 'Business Registration (DTI) is required' };
    }
    if (!uploadedDocuments.mayors_permit.file) {
      return { valid: false, message: "Mayor's Permit / Business Permit is required" };
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
      Swal.fire({
        icon: 'error',
        title: 'Missing Information',
        text: 'Please fill in all required fields before proceeding.',
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
          onSuccess: () => {
            setIsSubmitting(false);
            setShowSuccessModal(true);
          },
          onError: (errors) => {
            setIsSubmitting(false);
            const errorMessages = Object.values(errors).flat().join('\n');
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
                      {step === 2 && 'Business Info'}
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
                    />
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
                    />
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
                    />
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
                    />
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
              <ComponentCard title="Business Information">
                <div className="space-y-6">
                  <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div>
                      <Label htmlFor="businessName">Business Name</Label>
                      <Input
                        type="text"
                        id="businessName"
                        name="businessName"
                        value={formData.businessName}
                        onChange={handleInputChange}
                        placeholder="Enter business name"
                      />
                    </div>
                    <div>
                      <Label htmlFor="businessAddress">Business Address</Label>
                      <Input
                        type="text"
                        id="businessAddress"
                        name="businessAddress"
                        value={formData.businessAddress}
                        onChange={handleInputChange}
                        placeholder="Enter business address"
                      />
                    </div>
                  </div>
                  <div>
                    <Label>Business Type</Label>
                    <Select
                      options={businessTypeOptions}
                      placeholder="Select business type"
                      onChange={handleSelectChange}
                    />
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
                        label="Registered as Company"
                      />
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

            {currentStep === 3 && (
              <ComponentCard title="Document Upload">
                <div className="space-y-4">
                  <Label>Business Permits & Credentials</Label>
                  <p className="text-sm text-gray-600">
                    Upload your business license, permits, or other relevant credentials for verification.
                  </p>
                  <div className="mb-6">
                    <h4 className="text-lg font-semibold text-gray-800 mb-4">Document Submission Instructions</h4>
                    <p className="text-sm text-gray-600 mb-4">
                      Please upload clear photos of the following documents:
                    </p>
                    <ul className="list-disc list-inside text-sm text-gray-600 mb-4 space-y-1">
                      <li>Business Registration (DTI/SEC)</li>
                      <li>Mayor's Permit / Business Permit</li>
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
                      <Label>Business Registration (DTI) {uploadedDocuments.dti.file && <span className="text-green-600 font-bold ml-2">✓ Uploaded</span>}</Label>
                      <DropzoneComponent
                        onDrop={(files) => {
                          console.log('DTI files dropped:', files);
                          if (files && files.length > 0) {
                            const file = files[0];
                            console.log('DTI file:', file.name);
                            setUploadedDocuments(prev => ({
                              ...prev,
                              dti: { file: file, fileName: file.name }
                            }));

                            console.log('About to show Swal');
                            Swal.fire({
                              icon: 'info',
                              title: 'File Attached',
                              html: '<p><strong>' + file.name + '</strong> was added to <strong>Business Registration (DTI)</strong>.</p><p class="text-sm text-gray-600">Please ensure the correct document is uploaded in this section.</p>',
                              confirmButtonText: 'OK',
                            }).then(() => {
                              console.log('Swal closed');
                            });
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
                    </div>
                    <div>
                      <Label>Mayor's Permit / Business Permit {uploadedDocuments.mayors_permit.file && <span className="text-green-600 font-bold ml-2">✓ Uploaded</span>}</Label>
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
                              html: `<p><strong>${file.name}</strong> was added to <strong>Mayor's Permit / Business Permit</strong>.</p><p class="text-sm text-gray-600">Please ensure the correct document is uploaded in this section.</p>`,
                              confirmButtonText: 'OK',
                            });
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
                            });
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
                            });
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
                        <li>• Our team verifies all documents and business details</li>
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
                      <p className="text-sm text-gray-700">We verify business registration details</p>
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
                      <span className="text-sm text-gray-700">Business Registration (DTI)</span>
                    </div>
                    <div className="flex items-center space-x-2">
                      <svg className="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                      </svg>
                      <span className="text-sm text-gray-700">Mayor's Permit / Business Permit</span>
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
