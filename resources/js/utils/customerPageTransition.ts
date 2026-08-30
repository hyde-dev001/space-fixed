const CUSTOMER_STATIC_PATHS = new Set([
	"/",
	"/products",
	"/my-orders",
	"/my-repairs",
	"/repair-services",
	"/repair-process",
	"/services",
	"/services/product-image-spin-tutorial",
	"/download",
	"/checkout",
	"/payment",
	"/order-success",
	"/payment-failed",
	"/customer-profile",
	"/messages",
	"/message",
	"/customer/conversations",
	"/notifications",
	"/notifications/settings",
	"/login",
	"/register",
	"/forgot-password",
	"/otp",
	"/new-password",
	"/email/verify",
	"/shop-owner-register",
	"/shop-owner/two-factor",
]);

function pathnameOf(url: string): string {
	return new URL(url, "http://localhost").pathname.replace(/\/$/, "") || "/";
}

export function isCustomerTransitionPath(pathname: string): boolean {
	const normalizedPath = pathname.replace(/\/$/, "") || "/";

	return CUSTOMER_STATIC_PATHS.has(normalizedPath)
		|| /^\/products\/[^/]+$/.test(normalizedPath)
		|| /^\/repair-shop\/[^/]+$/.test(normalizedPath)
		|| /^\/shop-profile\/[^/]+(?:\/virtual-showroom)?$/.test(normalizedPath)
		|| /^\/message\/[^/]+$/.test(normalizedPath)
		|| /^\/tracking\/shipments\/[^/]+(?:\/proofs\/[^/]+|\/attempts\/[^/]+\/proof)?$/.test(normalizedPath);
}

export function shouldStartCustomerPageTransition(currentUrl: string, destinationUrl: string): boolean {
	const currentPath = pathnameOf(currentUrl);
	const destinationPath = pathnameOf(destinationUrl);

	return currentPath !== destinationPath
		&& isCustomerTransitionPath(currentPath)
		&& isCustomerTransitionPath(destinationPath);
}
