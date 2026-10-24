import { useEffect, useRef, useState } from 'react';

export default function DeliveryPhotoUpload({
  inputId,
  inputLabel,
  label,
  file,
  onChange,
  required = false,
  accept = 'image/*',
  capture,
  disabled = false,
  hint,
}: {
  inputId: string;
  inputLabel: string;
  label: string;
  file: File | null;
  onChange: (file: File | null) => void;
  required?: boolean;
  accept?: string;
  capture?: 'environment';
  disabled?: boolean;
  hint?: string;
}) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);

  useEffect(() => {
    if (!file || typeof URL === 'undefined' || typeof URL.createObjectURL !== 'function') {
      setPreviewUrl(null);
      return;
    }

    const nextPreviewUrl = URL.createObjectURL(file);
    setPreviewUrl(nextPreviewUrl);

    return () => {
      if (typeof URL.revokeObjectURL === 'function') URL.revokeObjectURL(nextPreviewUrl);
    };
  }, [file]);

  const openFilePicker = () => inputRef.current?.click();
  const removeFile = () => {
    if (inputRef.current) inputRef.current.value = '';
    onChange(null);
  };

  return (
    <div className="space-y-2">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-sm font-semibold text-slate-950 dark:text-white">
            {label}{' '}
            <span className="font-medium text-slate-500 dark:text-slate-400">
              · {required ? 'Required' : 'Optional'}
            </span>
          </p>
          <p className="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400">
            {hint ?? (required ? 'Add a clear photo to continue.' : 'Add a photo if it helps explain the issue.')}
          </p>
        </div>
        {file && (
          <span className="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold text-slate-700 dark:bg-slate-800 dark:text-slate-200">
            Selected
          </span>
        )}
      </div>

      <input
        ref={inputRef}
        id={inputId}
        type="file"
        disabled={disabled}
        required={required}
        accept={accept}
        capture={capture}
        aria-label={inputLabel}
        onChange={(event) => onChange(event.target.files?.[0] ?? null)}
        className="sr-only"
      />

      {file ? (
        <div className="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-900">
          <div className="h-16 w-16 shrink-0 overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-950">
            {previewUrl ? (
              <img
                src={previewUrl}
                alt={`${label} preview`} onError={() => setPreviewUrl(null)}
                className="h-full w-full object-cover"
              />
            ) : (
              <div className="flex h-full items-center justify-center px-2 text-center text-[10px] font-bold uppercase tracking-wide text-slate-500">
                Image ready
              </div>
            )}
          </div>
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-semibold text-slate-950 dark:text-white">{file.name}</p>
            <button
              type="button" disabled={disabled}
              onClick={openFilePicker}
              className="mt-1 inline-flex min-h-11 items-center rounded-lg px-1 text-xs font-bold text-slate-700 underline decoration-slate-400 underline-offset-2 transition-colors hover:text-slate-950 focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 dark:text-slate-200 dark:hover:text-white dark:focus:ring-gray-300"
            >
              Replace photo
            </button>
          </div>
          <button
            type="button" disabled={disabled}
            aria-label={`Remove ${inputLabel}`}
            title="Remove photo"
            onClick={removeFile}
            className="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white text-slate-700 transition-colors hover:bg-slate-100 hover:text-slate-950 focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white dark:focus:ring-gray-300"
          >
            <svg aria-hidden="true" className="h-5 w-5" viewBox="0 0 24 24" fill="none">
              <path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7l1-3h4l1 3" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
          </button>
        </div>
      ) : (
        <div className="flex flex-col items-start gap-3 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-4 sm:flex-row sm:items-center dark:border-slate-700 dark:bg-slate-900">
          <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200">
            <svg aria-hidden="true" className="h-5 w-5" viewBox="0 0 24 24" fill="none">
              <path d="M4 16.5V19a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-2.5M12 4v11m0-11L8.5 7.5M12 4l3.5 3.5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
          </div>
          <div className="min-w-0 flex-1">
            <p className="text-sm font-semibold text-slate-950 dark:text-white">No photo selected</p>
            <p className="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400">
              Upload a clear photo from your device or camera.
            </p>
          </div>
          <button
            type="button" disabled={disabled}
            onClick={openFilePicker}
            className="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-slate-950 px-4 text-sm font-bold text-white transition-colors hover:bg-black focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 sm:w-auto dark:bg-slate-950 dark:text-white dark:hover:bg-black dark:focus:ring-gray-300"
          >
            Upload photo
          </button>
        </div>
      )}
    </div>
  );
}
