import React, { useRef, useEffect } from "react";

let scrollLocks = 0;
let bodyStyles = { overflow: '', paddingRight: '' };

interface ModalProps {
  isOpen: boolean;
  onClose: () => void;
  className?: string;
  children: React.ReactNode;
  showCloseButton?: boolean; // New prop to control close button visibility
  isFullscreen?: boolean; // Default to false for backwards compatibility
  size?: 'sm' | 'md' | 'lg' | 'xl' | '2xl' | '3xl' | '4xl' | '5xl' | '6xl' | '7xl'; // Modal size
  zIndex?: number;
  ariaLabel?: string;
}

export const Modal: React.FC<ModalProps> = ({
  isOpen,
  onClose,
  children,
  className,
  showCloseButton = true, // Default to true for backwards compatibility
  isFullscreen = false,
  size = 'md',
  zIndex,
  ariaLabel,
}) => {
  const modalRef = useRef<HTMLDivElement>(null);
  const layerRef = useRef<HTMLDivElement>(null);
  const closeRef = useRef(onClose);
  closeRef.current = onClose;

  useEffect(() => {
    if (!isOpen || !ariaLabel || !modalRef.current || !layerRef.current) return;
    const dialog = modalRef.current;
    const layer = layerRef.current;
    const trigger = document.activeElement;
    const background: Element[] = [];
    let branch: Element = layer;
    while (branch.parentElement) {
      for (const sibling of branch.parentElement.children) {
        if (sibling !== branch && !sibling.hasAttribute('inert') && !sibling.classList.contains('swal2-container')) {
          sibling.setAttribute('inert', '');
          background.push(sibling);
        }
      }
      if (branch.parentElement === document.body) break;
      branch = branch.parentElement;
    }
    const controls = () => Array.from(dialog.querySelectorAll<HTMLElement>(
      'button:not(:disabled),a[href],input:not(:disabled),select:not(:disabled),textarea:not(:disabled),summary,[tabindex]:not([tabindex="-1"])',
    )).filter(element => {
      if (element.closest('[inert],[hidden]') || getComputedStyle(element).display === 'none') return false;
      const closed = element.closest('details:not([open])');
      return !closed || element === closed.querySelector('summary');
    });
    const first = () => controls()[0] ?? dialog;
    const isActive = () => !layer.closest('[inert]') && !document.querySelector('.swal2-container:not([style*="display: none"])');
    const keyboard = (event: KeyboardEvent) => {
      if (!isActive()) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        closeRef.current();
      }
      if (event.key === 'Tab') {
        const items = controls();
        const end = items.at(-1) ?? dialog;
        if (!items.length || (!event.shiftKey && document.activeElement === end)) {
          event.preventDefault(); first().focus();
        } else if (event.shiftKey && document.activeElement === first()) {
          event.preventDefault(); end.focus();
        }
      }
    };
    const keepFocus = (event: FocusEvent) => {
      if (isActive() && event.target instanceof Node && !dialog.contains(event.target)) first().focus();
    };
    first().focus();
    document.addEventListener('keydown', keyboard);
    document.addEventListener('focusin', keepFocus);
    return () => {
      document.removeEventListener('keydown', keyboard);
      document.removeEventListener('focusin', keepFocus);
      background.forEach(element => element.removeAttribute('inert'));
      if (trigger instanceof HTMLElement && trigger.isConnected && !trigger.closest('[inert]')) trigger.focus();
    };
  }, [isOpen, ariaLabel]);

  useEffect(() => {
    const handleEscape = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        onClose();
      }
    };

    if (isOpen && !ariaLabel) {
      document.addEventListener("keydown", handleEscape);
    }

    return () => {
      document.removeEventListener("keydown", handleEscape);
    };
  }, [isOpen, onClose, ariaLabel]);

  useEffect(() => {
    if (!isOpen) return;

    if (scrollLocks++ === 0) {
      bodyStyles = { overflow: document.body.style.overflow, paddingRight: document.body.style.paddingRight };
      const scrollbarWidth = Math.max(0, window.innerWidth - document.documentElement.clientWidth);
      document.body.style.overflow = 'hidden';
      if (scrollbarWidth > 0) {
        const currentPaddingRight = Number.parseFloat(window.getComputedStyle(document.body).paddingRight) || 0;
        document.body.style.paddingRight = `${currentPaddingRight + scrollbarWidth}px`;
      }
    }

    return () => {
      if (--scrollLocks === 0) {
        document.body.style.overflow = bodyStyles.overflow;
        document.body.style.paddingRight = bodyStyles.paddingRight;
      }
    };
  }, [isOpen]);

  if (!isOpen) return null;

  const sizeClasses = {
    'sm': 'max-w-sm',
    'md': 'max-w-md',
    'lg': 'max-w-lg',
    'xl': 'max-w-xl',
    '2xl': 'max-w-2xl',
    '3xl': 'max-w-3xl',
    '4xl': 'max-w-4xl',
    '5xl': 'max-w-5xl',
    '6xl': 'max-w-6xl',
    '7xl': 'max-w-7xl',
  };

  const contentClasses = isFullscreen
    ? "w-full h-full"
    : `relative w-full ${sizeClasses[size]} mx-auto rounded-3xl bg-white dark:bg-gray-900`;

  return (
    <div
      ref={layerRef}
      className="fixed inset-0 flex items-center justify-center overflow-y-auto modal z-99999"
      style={zIndex === undefined ? undefined : { zIndex }}
    >
      {!isFullscreen && (
        <div
          className="erp-modal-backdrop fixed inset-0 h-full w-full bg-black/10 backdrop-blur-sm"
          onClick={onClose}
        ></div>
      )}
      <div
        ref={modalRef}
        role={ariaLabel ? 'dialog' : undefined}
        aria-modal={ariaLabel ? true : undefined}
        aria-label={ariaLabel}
        tabIndex={ariaLabel ? -1 : undefined}
        className={`${contentClasses}  ${className}`}
        onClick={(e) => e.stopPropagation()}
      >
        {showCloseButton && (
          <button
            type="button"
            aria-label="Close dialog"
            onClick={onClose}
            className="absolute right-3 top-3 z-999 flex h-9.5 w-9.5 items-center justify-center rounded-full bg-gray-100 text-gray-400 transition-colors hover:bg-gray-200 hover:text-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white sm:right-6 sm:top-6 sm:h-11 sm:w-11"
          >
            <svg
              width="24"
              height="24"
              viewBox="0 0 24 24"
              fill="none"
              xmlns="http://www.w3.org/2000/svg"
            >
              <path
                fillRule="evenodd"
                clipRule="evenodd"
                d="M6.04289 16.5413C5.65237 16.9318 5.65237 17.565 6.04289 17.9555C6.43342 18.346 7.06658 18.346 7.45711 17.9555L11.9987 13.4139L16.5408 17.956C16.9313 18.3466 17.5645 18.3466 17.955 17.956C18.3455 17.5655 18.3455 16.9323 17.955 16.5418L13.4129 11.9997L17.955 7.4576C18.3455 7.06707 18.3455 6.43391 17.955 6.04338C17.5645 5.65286 16.9313 5.65286 16.5408 6.04338L11.9987 10.5855L7.45711 6.0439C7.06658 5.65338 6.43342 5.65338 6.04289 6.0439C5.65237 6.43442 5.65237 7.06759 6.04289 7.45811L10.5845 11.9997L6.04289 16.5413Z"
                fill="currentColor"
              />
            </svg>
          </button>
        )}
        <div>{children}</div>
      </div>
    </div>
  );
};
