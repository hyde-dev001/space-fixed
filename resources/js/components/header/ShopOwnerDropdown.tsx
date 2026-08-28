import { useState } from "react";
import { usePage, router } from "@inertiajs/react";
import { Dropdown } from "../ui/dropdown/Dropdown";
import Swal from "sweetalert2";
import { LogOut, Settings2, User } from "lucide-react";
import type { ErpActor, ErpUrls } from "../../types/erp";
import InlineAccountMenu from "./InlineAccountMenu";

const normalizePhotoPath = (photoPath: unknown): string | null => {
  if (typeof photoPath !== "string") return null;

  const trimmedPath = photoPath.trim();
  if (!trimmedPath) return null;

  if (/^(?:https?:)?\/\//i.test(trimmedPath) || trimmedPath.startsWith("/") || trimmedPath.startsWith("data:")) {
    return trimmedPath;
  }

  return `/storage/${trimmedPath.replace(/^\/+/, "")}`;
};

type ShopOwnerAvatarProps = {
  src: string | null;
  alt: string;
  className: string;
  iconClassName: string;
};

const ShopOwnerAvatar = ({ src, alt, className, iconClassName }: ShopOwnerAvatarProps) => {
  const [imageFailed, setImageFailed] = useState(false);
  const showPhoto = Boolean(src) && !imageFailed;

  return (
    <span
      data-testid={imageFailed || !src ? "shop-owner-avatar-fallback" : undefined}
      className={`flex items-center justify-center overflow-hidden rounded-full bg-gray-100 text-gray-900 dark:bg-purple-900 dark:text-purple-300 ${className}`}
    >
      {showPhoto && (
        <img
          src={src ?? ""}
          alt={alt}
          className="h-full w-full object-cover dark:hidden"
          onError={() => setImageFailed(true)}
        />
      )}
      <svg className={`${iconClassName}${showPhoto ? " hidden dark:block" : ""}`} fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
        <path fillRule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clipRule="evenodd" />
      </svg>
    </span>
  );
};

type ShopOwnerDropdownProps = {
  actor?: ErpActor;
  urls?: Partial<ErpUrls>;
  inline?: boolean;
};

export default function ShopOwnerDropdown({ actor, urls, inline = false }: ShopOwnerDropdownProps = {}) {
  const { auth } = usePage().props as any;
  const [isOpen, setIsOpen] = useState(false);

  const shopOwner = auth?.shop_owner;
  
  if (!shopOwner && !actor) return null;

  const userName = actor?.name || shopOwner?.business_name || shopOwner?.name || shopOwner?.first_name || "Shop Owner";
  const userEmail = shopOwner?.email || "owner@solespace.com";
  const profilePhoto = normalizePhotoPath(
    shopOwner?.profile_photo_url
      || shopOwner?.profile_photo
      || auth?.user?.shop_owner?.profile_photo_url
      || auth?.user?.shop_owner?.profile_photo,
  );
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
      router.post(urls?.logout || '/shop-owner/logout', {}, {
        preserveState: false,
      });
    }
  }

  if (inline) {
    return (
      <InlineAccountMenu
        name={userName}
        email={userEmail}
        role="Shop Owner"
        tone="neutral"
        avatarUrl={profilePhoto}
        actions={[
          {
            label: "Shop Profile",
            onClick: () => {
              closeDropdown();
              router.visit(urls?.profile || '/shop-owner/shop-profile');
            },
            icon: <User className="h-5 w-5" aria-hidden="true" />,
          },
          {
            label: "Settings",
            onClick: () => {
              closeDropdown();
              router.visit(urls?.settings || '/shop-owner/settings');
            },
            icon: <Settings2 className="h-5 w-5" aria-hidden="true" />,
          },
          {
            label: "Sign Out",
            onClick: handleLogout,
            icon: <LogOut className="h-5 w-5" aria-hidden="true" />,
            destructive: true,
          },
        ]}
      />
    );
  }

  return (
    <div className="relative">
      <button
        type="button"
        onClick={toggleDropdown}
        aria-label={`Open account menu for ${userName}`}
        aria-haspopup="menu"
        aria-expanded={isOpen}
        data-testid="shop-owner-account-trigger"
        className="dropdown-toggle inline-flex h-10 w-10 items-center justify-center rounded-full border border-gray-200 bg-white p-1 text-gray-900 transition-colors hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#111111] dark:h-auto dark:w-auto dark:gap-2 dark:rounded-lg dark:border-transparent dark:bg-transparent dark:px-3 dark:py-2 dark:text-gray-400 dark:hover:bg-gray-800 dark:focus-visible:ring-blue-300"
      >
        <ShopOwnerAvatar
          src={profilePhoto}
          alt={`${userName} profile photo`}
          className="h-full w-full dark:h-8 dark:w-8"
          iconClassName="h-5 w-5"
        />
        <span className="hidden dark:block">
          <span className="block text-sm font-semibold">{userName}</span>
          <span className="text-xs text-gray-500 dark:text-gray-400">Shop Owner</span>
        </span>
        <svg
          className={`hidden stroke-gray-500 transition-transform duration-200 dark:block dark:stroke-gray-400 ${
            isOpen ? "rotate-180" : ""
          }`}
          width="18"
          height="20"
          viewBox="0 0 18 20"
          fill="none"
          xmlns="http://www.w3.org/2000/svg"
          aria-hidden="true"
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
            <ShopOwnerAvatar
              src={profilePhoto}
              alt={`${userName} profile photo`}
              className="h-10 w-10 shrink-0"
              iconClassName="h-6 w-6"
            />
            <div className="flex-1 min-w-0">
              <p className="font-semibold text-gray-900 dark:text-white truncate">
                {userName}
              </p>
              <p className="text-xs text-gray-500 dark:text-gray-400 truncate">
                {userEmail}
              </p>
              <p className="mt-1 inline-block rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-900 dark:bg-purple-900 dark:text-purple-300">
                Shop Owner
              </p>
            </div>
          </div>
        </div>

        <button
          onClick={() => {
            closeDropdown();
            router.visit(urls?.profile || '/shop-owner/shop-profile');
          }}
          className="group mx-2 mt-2 flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700"
        >
          <svg
            className="h-5 w-5 text-gray-400 group-hover:text-gray-900 dark:group-hover:text-gray-200"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
            strokeWidth="2"
          >
            <circle cx="12" cy="12" r="9"></circle>
            <path d="M12 8a3 3 0 1 1 0 6 3 3 0 0 1 0-6z"></path>
            <path d="M6.5 17a6.5 6.5 0 0 1 11 0"></path>
          </svg>
          Shop Profile
        </button>

        <div className="mx-2 my-1 border-t border-gray-200 dark:border-gray-700" />

        <button
          onClick={() => {
            closeDropdown();
            router.visit(urls?.settings || '/shop-owner/settings');
          }}
          className="group mx-2 flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700"
        >
          <svg
            className="h-5 w-5 text-gray-400 group-hover:text-gray-900 dark:group-hover:text-gray-200"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
            strokeWidth="2"
          >
            <circle cx="12" cy="12" r="3"></circle>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
          </svg>
          Business Settings
        </button>

        <div className="mx-2 my-1 border-t border-gray-200 dark:border-gray-700" />

        <button
          onClick={handleLogout}
          className="group mx-2 mb-2 flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-red-900/20 dark:hover:text-red-400"
        >
          <svg
            className="h-5 w-5 text-gray-400 group-hover:text-gray-900 dark:group-hover:text-red-400"
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
