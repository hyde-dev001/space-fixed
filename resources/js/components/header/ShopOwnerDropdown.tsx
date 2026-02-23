import { useState } from "react";
import { usePage, router } from "@inertiajs/react";
import { Dropdown } from "../ui/dropdown/Dropdown";
import Swal from "sweetalert2";
import { AlertCircle, Building2, User, Store, Wrench } from "lucide-react";

export default function ShopOwnerDropdown() {
  const { auth } = usePage().props as any;
  const [isOpen, setIsOpen] = useState(false);

  const shopOwner = auth?.shop_owner;
  
  if (!shopOwner) return null;

  const userName = shopOwner?.name || shopOwner?.first_name || "Shop Owner";
  const userEmail = shopOwner?.email || "owner@solespace.com";
  const isIndividual = !!shopOwner?.is_individual;
  const isCompany = !!shopOwner?.is_company;
  const businessType = shopOwner?.business_type;

  const getBusinessTypeInfo = () => {
    if (businessType === "retail") {
      return { icon: <Store className="w-4 h-4" />, label: "Retail Shop", color: "blue" };
    }
    if (businessType === "repair") {
      return { icon: <Wrench className="w-4 h-4" />, label: "Repair Services", color: "green" };
    }
    return { icon: <Store className="w-4 h-4" />, label: "Retail & Repair", color: "purple" };
  };

  const businessTypeInfo = getBusinessTypeInfo();

  function toggleDropdown() {
    setIsOpen(!isOpen);
  }

  function closeDropdown() {
    setIsOpen(false);
  }

  async function handleLogout() {
    closeDropdown();
    
    const result = await Swal.fire({
      title: "Sign Out",
      text: "Are you sure you want to sign out?",
      icon: "warning",
      showCancelButton: true,
      confirmButtonColor: "#ef4444",
      cancelButtonColor: "#6b7280",
      confirmButtonText: "Yes, sign out",
      cancelButtonText: "Cancel",
    });

    if (result.isConfirmed) {
      router.post('/shop-owner/logout', {}, {
        preserveState: false,
        onSuccess: () => {
          setTimeout(() => { router.visit('/user/login'); }, 200);
        },
        onError: () => {
          router.visit('/user/login');
        }
      });
    }
  }

  return (
    <div className="relative">
      <button
        onClick={toggleDropdown}
        className="flex items-center gap-2 px-3 py-2 text-gray-700 rounded-lg transition dropdown-toggle dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800"
      >
        <div className="flex items-center justify-center w-8 h-8 bg-purple-100 rounded-full dark:bg-purple-900">
          <svg
            className="w-5 h-5 text-purple-600 dark:text-purple-300"
            fill="currentColor"
            viewBox="0 0 20 20"
          >
            <path fillRule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clipRule="evenodd" />
          </svg>
        </div>
        <div className="hidden sm:block">
          <span className="block font-semibold text-sm">{userName}</span>
          <span className="text-xs text-gray-500 dark:text-gray-400">Shop Owner</span>
        </div>
        <svg
          className={`stroke-gray-500 dark:stroke-gray-400 transition-transform duration-200 ${
            isOpen ? "rotate-180" : ""
          }`}
          width="18"
          height="20"
          viewBox="0 0 18 20"
          fill="none"
          xmlns="http://www.w3.org/2000/svg"
        >
          <path
            d="M4.3125 8.65625L9 13.3437L13.6875 8.65625"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        </svg>
      </button>

      <Dropdown
        isOpen={isOpen}
        onClose={closeDropdown}
        className="absolute right-0 mt-2 flex w-72 flex-col rounded-xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-800"
      >
        <div className="px-4 py-4 border-b border-gray-200 dark:border-gray-700">
          <div className="flex items-center gap-3">
            <div className="flex items-center justify-center w-10 h-10 bg-purple-100 rounded-full dark:bg-purple-900">
              <svg
                className="w-6 h-6 text-purple-600 dark:text-purple-300"
                fill="currentColor"
                viewBox="0 0 20 20"
              >
                <path fillRule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clipRule="evenodd" />
              </svg>
            </div>
            <div className="flex-1 min-w-0">
              <p className="font-semibold text-gray-900 dark:text-white truncate">
                {userName}
              </p>
              <p className="text-xs text-gray-500 dark:text-gray-400 truncate">
                {userEmail}
              </p>
              <p className="mt-1 inline-block text-xs font-semibold px-2 py-0.5 bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300 rounded-full">
                Shop Owner
              </p>
            </div>
          </div>
        </div>

        <div className="px-3 py-3 space-y-3 border-b border-gray-200 dark:border-gray-700">
          <div className={`rounded-lg border p-3 ${
            isIndividual
              ? "bg-blue-50 border-blue-200 dark:bg-blue-900/20 dark:border-blue-800"
              : "bg-purple-50 border-purple-200 dark:bg-purple-900/20 dark:border-purple-800"
          }`}>
            <div className="flex items-start gap-2.5">
              <div className={`p-1.5 rounded-lg ${
                isIndividual ? "bg-blue-100 dark:bg-blue-800" : "bg-purple-100 dark:bg-purple-800"
              }`}>
                {isIndividual ? (
                  <User className="w-4 h-4 text-blue-600 dark:text-blue-300" />
                ) : (
                  <Building2 className="w-4 h-4 text-purple-600 dark:text-purple-300" />
                )}
              </div>
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap">
                  <h4 className={`font-semibold text-sm ${
                    isIndividual ? "text-blue-900 dark:text-blue-100" : "text-purple-900 dark:text-purple-100"
                  }`}>
                    {isIndividual ? "Individual Account" : "Company Account"}
                  </h4>
                  <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium ${
                    businessTypeInfo.color === "blue"
                      ? "bg-blue-100 text-blue-700 dark:bg-blue-800 dark:text-blue-200"
                      : businessTypeInfo.color === "green"
                      ? "bg-green-100 text-green-700 dark:bg-green-800 dark:text-green-200"
                      : "bg-purple-100 text-purple-700 dark:bg-purple-800 dark:text-purple-200"
                  }`}>
                    {businessTypeInfo.icon}
                    {businessTypeInfo.label}
                  </span>
                </div>
                <p className="text-xs text-gray-600 dark:text-gray-400 mt-1 truncate">
                  {shopOwner?.business_name || "Business"}
                </p>
                <div className="mt-2 flex flex-wrap gap-2 text-xs">
                  <div className="flex items-center gap-1.5">
                    <span className={`inline-block w-2 h-2 rounded-full ${
                      isCompany ? "bg-green-500" : "bg-gray-400"
                    }`}></span>
                    <span className={isCompany ? "text-gray-700 dark:text-gray-300" : "text-gray-500 dark:text-gray-500"}>
                      Staff Management
                    </span>
                  </div>
                  <div className="flex items-center gap-1.5">
                    <span className={`inline-block w-2 h-2 rounded-full ${
                      shopOwner?.max_locations === null ? "bg-green-500" : "bg-yellow-500"
                    }`}></span>
                    <span className="text-gray-700 dark:text-gray-300">
                      {shopOwner?.max_locations === null ? "Unlimited Locations" : `${shopOwner?.max_locations} Location Max`}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          {isIndividual && (
            <div className="rounded-lg border border-yellow-200 bg-yellow-50 p-3 dark:bg-yellow-900/20 dark:border-yellow-800">
              <div className="flex items-start gap-2.5">
                <AlertCircle className="w-4 h-4 text-yellow-600 dark:text-yellow-400 shrink-0 mt-0.5" />
                <div className="flex-1">
                  <h4 className="font-medium text-sm text-yellow-900 dark:text-yellow-100">
                    Individual Account Limitations
                  </h4>
                  <p className="text-xs text-yellow-800 dark:text-yellow-200 mt-1">
                    Your account is limited to <strong>1 shop location</strong> and <strong>cannot add staff members</strong>.
                  </p>
                  <button
                    type="button"
                    className="mt-2.5 inline-flex items-center gap-1.5 px-3 py-1.5 bg-yellow-600 hover:bg-yellow-700 text-white text-xs font-medium rounded-lg transition-colors"
                    onClick={() => {
                      alert("Upgrade to Company feature coming soon!");
                    }}
                  >
                    <Building2 className="w-3.5 h-3.5" />
                    Upgrade to Company
                  </button>
                </div>
              </div>
            </div>
          )}
        </div>

        <button
          onClick={handleLogout}
          className="flex items-center gap-3 px-3 py-2.5 mt-2 mb-2 font-medium text-gray-700 rounded-lg group text-sm hover:bg-red-50 hover:text-red-700 w-full dark:text-gray-300 dark:hover:bg-red-900/20 dark:hover:text-red-400 transition mx-2"
        >
          <svg
            className="w-5 h-5 text-gray-400 group-hover:text-red-600 dark:group-hover:text-red-400"
            fill="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              fillRule="evenodd"
              clipRule="evenodd"
              d="M15.1007 19.247C14.6865 19.247 14.3507 18.9112 14.3507 18.497L14.3507 14.245H12.8507V18.497C12.8507 19.7396 13.8581 20.747 15.1007 20.747H18.5007C19.7434 20.747 20.7507 19.7396 20.7507 18.497L20.7507 5.49609C20.7507 4.25345 19.7433 3.24609 18.5007 3.24609H15.1007C13.8581 3.24609 12.8507 4.25345 12.8507 5.49609V9.74501L14.3507 9.74501V5.49609C14.3507 5.08188 14.6865 4.74609 15.1007 4.74609L18.5007 4.74609C18.9149 4.74609 19.2507 5.08188 19.2507 5.49609L19.2507 18.497C19.2507 18.9112 18.9149 19.247 18.5007 19.247H15.1007ZM3.25073 11.9984C3.25073 12.2144 3.34204 12.4091 3.48817 12.546L8.09483 17.1556C8.38763 17.4485 8.86251 17.4487 9.15549 17.1559C9.44848 16.8631 9.44863 16.3882 9.15583 16.0952L5.81116 12.7484L16.0007 12.7484C16.4149 12.7484 16.7507 12.4127 16.7507 11.9984C16.7507 11.5842 16.4149 11.2484 16.0007 11.2484L5.81528 11.2484L9.15585 7.90554C9.44864 7.61255 9.44847 7.13767 9.15547 6.84488C8.86248 6.55209 8.3876 6.55226 8.09481 6.84525L3.52309 11.4202C3.35673 11.5577 3.25073 11.7657 3.25073 11.9984Z"
              fill="currentColor"
            />
          </svg>
          Sign Out
        </button>
      </Dropdown>
    </div>
  );
}
