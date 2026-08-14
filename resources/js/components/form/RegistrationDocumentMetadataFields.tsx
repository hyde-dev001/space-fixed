import type { RegistrationDocumentMetadata } from '@/Pages/UserSide/Auth/registrationDocumentPayload';

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
          className="h-10 w-full rounded-md border border-gray-300 px-2 text-sm text-gray-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
        />
      </div>

      <div>
        <label htmlFor={`${idPrefix}_expiration_mode`} className="mb-1 block text-xs font-semibold text-gray-700">
          {label} expiration
        </label>
        <select
          id={`${idPrefix}_expiration_mode`}
          value={metadata.expirationMode}
          onChange={(event) => onChange({
            expirationMode: event.target.value as RegistrationDocumentMetadata['expirationMode'],
            expiresOn: event.target.value === 'none' ? '' : metadata.expiresOn,
          })}
          className="h-10 w-full rounded-md border border-gray-300 bg-white px-2 text-sm text-gray-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
        >
          <option value="dated">Has an expiration date</option>
          <option value="none">No expiration</option>
        </select>
        {metadata.expirationMode === 'dated' && (
          <input
            aria-label={`${label} expiration date`}
            type="date"
            value={metadata.expiresOn ?? ''}
            onChange={(event) => onChange({ expiresOn: event.target.value })}
            className="mt-2 h-10 w-full rounded-md border border-gray-300 px-2 text-sm text-gray-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
          />
        )}
      </div>
    </div>
  );
}
