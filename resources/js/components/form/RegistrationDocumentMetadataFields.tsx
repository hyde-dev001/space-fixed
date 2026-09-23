import MonochromeSelect from "@/components/form/Select";
import type { RegistrationDocumentMetadata } from '@/Pages/UserSide/Auth/registrationDocumentPayload';

type RegistrationDocumentMetadataFieldsProps = {
  idPrefix: string;
  label: string;
  metadata: RegistrationDocumentMetadata;
  expirationRequired?: boolean;
  onChange: (updates: Partial<RegistrationDocumentMetadata>) => void;
};

export default function RegistrationDocumentMetadataFields({
  idPrefix,
  label,
  metadata,
  expirationRequired = false,
  onChange,
}: RegistrationDocumentMetadataFieldsProps) {
  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
      <div>
        <label htmlFor={`${idPrefix}_issued_on`} className="mb-2 block text-xs font-semibold text-gray-700">
          {label} issued date
        </label>
        <input
          id={`${idPrefix}_issued_on`}
          aria-label={`${label} issued date`}
          type="date"
          value={metadata.issuedOn ?? ''}
          onChange={(event) => onChange({ issuedOn: event.target.value })}
          className="h-11 w-full min-w-0 cursor-pointer rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 shadow-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10"
        />
      </div>

      {expirationRequired ? (
        <div>
          <label htmlFor={`${idPrefix}_expiration_date`} className="mb-2 block text-xs font-semibold text-gray-700">
            {label} expiration date
          </label>
          <input
            id={`${idPrefix}_expiration_date`}
            aria-label={`${label} expiration date`}
            type="date"
            value={metadata.expiresOn ?? ''}
            onChange={(event) => onChange({ expiresOn: event.target.value })}
            className="h-11 w-full min-w-0 cursor-pointer rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 shadow-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10"
          />
        </div>
      ) : (
        <div>
          <label htmlFor={`${idPrefix}_expiration_mode`} className="mb-2 block text-xs font-semibold text-gray-700">
            {label} expiration
          </label>
          <MonochromeSelect
            id={`${idPrefix}_expiration_mode`}
            value={metadata.expirationMode}
            onChange={(event) => {
              const expirationMode = event.target.value as RegistrationDocumentMetadata['expirationMode'];
              onChange({ expirationMode, expiresOn: expirationMode === 'none' ? '' : metadata.expiresOn });
            }}
            className="h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 shadow-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10"
          >
            <option value="dated">Has an expiration date</option>
            <option value="none">No expiration</option>
          </MonochromeSelect>
          {metadata.expirationMode === 'dated' && (
            <div className="mt-3">
              <label htmlFor={`${idPrefix}_expiration_date`} className="mb-2 block text-xs font-semibold text-gray-700">
                {label} expiration date
              </label>
              <input
                id={`${idPrefix}_expiration_date`}
                aria-label={`${label} expiration date`}
                type="date"
                value={metadata.expiresOn ?? ''}
                onChange={(event) => onChange({ expiresOn: event.target.value })}
                className="h-11 w-full min-w-0 cursor-pointer rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700 shadow-sm outline-none transition focus:border-gray-900 focus:ring-2 focus:ring-gray-900/10"
              />
            </div>
          )}
        </div>
      )}
    </div>
  );
}
