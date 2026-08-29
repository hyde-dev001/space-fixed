import type { RegistrationDocumentMetadata } from '@/Pages/UserSide/Auth/registrationDocumentPayload';
import { useState } from 'react';

type RegistrationDocumentMetadataFieldsProps = {
  idPrefix: string;
  label: string;
  metadata: RegistrationDocumentMetadata;
  onChange: (updates: Partial<RegistrationDocumentMetadata>) => void;
};

export default function RegistrationDocumentMetadataFields({
  idPrefix,
  label,
  metadata,
  onChange,
}: RegistrationDocumentMetadataFieldsProps) {
  const [expirationModalOpen, setExpirationModalOpen] = useState(false);

  const closeExpirationModal = () => {
    if (!metadata.expiresOn) {
      onChange({ expirationMode: 'none', expiresOn: '' });
    }
    setExpirationModalOpen(false);
  };

  return (
    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
      <div>
        <label htmlFor={`${idPrefix}_issued_on`} className="mb-1 block text-xs font-semibold text-gray-700">
          {label} issued date
        </label>
        <input
          id={`${idPrefix}_issued_on`}
          aria-label={`${label} issued date`}
          type="date"
          value={metadata.issuedOn ?? ''}
          onChange={(event) => onChange({ issuedOn: event.target.value })}
          className="h-10 w-full rounded-md border border-gray-300 bg-white px-2 text-sm text-gray-700 outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10"
        />
      </div>

      <div>
        <label htmlFor={`${idPrefix}_expiration_mode`} className="mb-1 block text-xs font-semibold text-gray-700">
          {label} expiration
        </label>
        <select
          id={`${idPrefix}_expiration_mode`}
          value={metadata.expirationMode}
          onChange={(event) => {
            const expirationMode = event.target.value as RegistrationDocumentMetadata['expirationMode'];
            onChange({ expirationMode, expiresOn: expirationMode === 'none' ? '' : metadata.expiresOn });
            if (expirationMode === 'dated') setExpirationModalOpen(true);
          }}
          className="h-10 w-full rounded-md border border-gray-300 bg-white px-2 text-sm text-gray-700 outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10"
        >
          <option value="dated">Has an expiration date</option>
          <option value="none">No expiration</option>
        </select>
      </div>

      {expirationModalOpen && (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) closeExpirationModal(); }}>
          <div className="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-6 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby={`${idPrefix}_expiration_title`}>
            <h2 id={`${idPrefix}_expiration_title`} className="text-lg font-semibold text-gray-900">{label} expiration date</h2>
            <p className="mt-1 text-sm text-gray-600">Choose the date when this document expires.</p>
            <label htmlFor={`${idPrefix}_expiration_date`} className="mt-5 block text-sm font-semibold text-gray-800">Expiration date</label>
            <input
              id={`${idPrefix}_expiration_date`}
              aria-label={`${label} expiration date`}
              type="date"
              value={metadata.expiresOn ?? ''}
              onChange={(event) => onChange({ expiresOn: event.target.value })}
              className="mt-2 h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 outline-none focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10"
            />
            <div className="mt-6 flex justify-end gap-3">
              <button type="button" onClick={closeExpirationModal} className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-100">Cancel</button>
              <button type="button" onClick={() => setExpirationModalOpen(false)} disabled={!metadata.expiresOn} className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black disabled:cursor-not-allowed disabled:bg-gray-400">Save date</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
