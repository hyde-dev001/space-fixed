const emptyFallback = (current: unknown, fallback: unknown) =>
    String(current || fallback || '');

export const mergeRepairProcessPrefill = (
    current: Record<string, any>,
    user?: Record<string, any> | null,
    address?: Record<string, any> | null,
) => {
    const province = address?.province || address?.region || '';

    return {
        ...current,
        customerName: emptyFallback(current.customerName, user?.name),
        email: emptyFallback(current.email, user?.email),
        phone: emptyFallback(current.phone, user?.phone || address?.phone),
        pickupAddressLine: emptyFallback(current.pickupAddressLine, address?.address_line),
        pickupBarangay: emptyFallback(current.pickupBarangay, address?.barangay),
        pickupCity: emptyFallback(current.pickupCity, address?.city),
        pickupRegion: emptyFallback(current.pickupRegion, province),
        pickupPostalCode: emptyFallback(current.pickupPostalCode, address?.postal_code),
        returnAddressLine: emptyFallback(current.returnAddressLine, address?.address_line),
        returnBarangay: emptyFallback(current.returnBarangay, address?.barangay),
        returnCity: emptyFallback(current.returnCity, address?.city),
        returnRegion: emptyFallback(current.returnRegion, province),
        returnPostalCode: emptyFallback(current.returnPostalCode, address?.postal_code),
    };
};
