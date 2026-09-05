import { X } from "lucide-react";
import { useEffect, useRef, useState } from "react";

import type {
  ArticleLanguage,
  ArticleScreenshot as ArticleScreenshotData,
} from "../../data/staffArticles";

type ArticleLightboxProps = {
  open: boolean;
  screenshot: ArticleScreenshotData | null;
  language: ArticleLanguage;
  onClose: () => void;
};

const FOCUSABLE_SELECTOR = [
  "button:not([disabled])",
  "[href]",
  "input:not([disabled])",
  "select:not([disabled])",
  "textarea:not([disabled])",
  "[tabindex]:not([tabindex=\"-1\"])",
].join(",");

const copy = {
  en: { preview: "Screenshot preview", close: "Close screenshot preview", unavailable: "Screenshot unavailable" },
  tl: { preview: "Preview ng screenshot", close: "Isara ang preview ng screenshot", unavailable: "Hindi available ang screenshot" },
} as const;

export default function ArticleLightbox({
  open,
  screenshot,
  language,
  onClose,
}: ArticleLightboxProps) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const previouslyFocused = useRef<HTMLElement | null>(null);
  const onCloseRef = useRef(onClose);
  const [hasError, setHasError] = useState(false);

  onCloseRef.current = onClose;

  useEffect(() => {
    if (!open || !screenshot) {
      previouslyFocused.current?.focus();
      return;
    }

    previouslyFocused.current = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    dialogRef.current?.focus();
    setHasError(false);

    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        event.preventDefault();
        onCloseRef.current();
        return;
      }

      if (event.key !== "Tab" || !dialogRef.current) return;

      const focusable = Array.from(
        dialogRef.current.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR),
      );
      if (focusable.length === 0) {
        event.preventDefault();
        dialogRef.current.focus();
        return;
      }

      const first = focusable[0];
      const last = focusable[focusable.length - 1];

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };

    document.addEventListener("keydown", handleKeyDown);

    return () => {
      document.removeEventListener("keydown", handleKeyDown);
      document.body.style.overflow = previousOverflow;
    };
  }, [open, screenshot]);

  if (!open || !screenshot) return null;

  const alt = screenshot.alt[language];
  const languageCopy = copy[language];

  return (
    <dialog
      ref={dialogRef}
      open
      tabIndex={-1}
      aria-label={languageCopy.preview}
      className="fixed inset-0 z-50 m-0 flex h-full max-h-none w-full max-w-none items-center justify-center border-0 bg-black/80 p-4 backdrop:bg-black/80 dark:bg-black/90"
    >
      <div className="relative flex max-h-full w-full max-w-5xl flex-col gap-3 rounded-2xl border border-white/20 bg-white p-3 text-gray-950 dark:bg-gray-950 dark:text-white sm:p-5">
        <div className="flex items-center justify-between gap-4">
          <h2 className="sr-only">{languageCopy.preview}</h2>
          <p className="min-w-0 truncate text-xs text-gray-600 dark:text-gray-300">{alt}</p>
          <button
            type="button"
            className="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-full border border-gray-300 text-gray-800 transition-colors hover:border-gray-950 hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 focus-visible:ring-offset-2 dark:border-gray-700 dark:text-gray-100 dark:hover:border-white dark:hover:bg-white/10 dark:focus-visible:ring-white"
            aria-label={languageCopy.close}
            onClick={onClose}
          >
            <X aria-hidden="true" className="h-5 w-5" />
          </button>
        </div>

        <div
          className="flex max-h-[calc(100vh-9rem)] min-h-48 items-center justify-center overflow-auto rounded-xl border border-gray-200 bg-gray-100 p-2 dark:border-gray-800 dark:bg-gray-900"
          style={{ aspectRatio: screenshot.aspectRatio }}
        >
          {hasError ? (
            <div className="max-w-md space-y-2 text-center text-sm text-gray-600 dark:text-gray-300">
              <p className="font-semibold">{languageCopy.unavailable}</p>
              <code className="block break-all text-xs text-gray-500 dark:text-gray-400">{screenshot.path}</code>
            </div>
          ) : (
            <img
              src={screenshot.path}
              alt={alt}
              className="max-h-full max-w-full object-contain"
              onError={() => setHasError(true)}
            />
          )}
        </div>
      </div>
    </dialog>
  );
}
