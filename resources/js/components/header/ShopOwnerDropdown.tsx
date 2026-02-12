import { useState } from "react";
import { usePage, router } from "@inertiajs/react";
import { Dropdown } from "../ui/dropdown/Dropdown";
import Swal from "sweetalert2";

export default function ShopOwnerDropdown() {
  const { auth } = usePage().props as any;
  const [isOpen, setIsOpen] = useState(false);

  const shopOwner = auth?.shop_owner;
  
  if (!shopOwner) return null;

  const userName = shopOwner?.name || shopOwner?.first_name || "Shop Owner";
  const userEmail = shopOwner?.email || "owner@solespace.com";

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
