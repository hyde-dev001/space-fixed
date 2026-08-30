const CUSTOMER_STATIC_PATHS = new Set([
	"/",
	"/products",
	"/my-orders",
	"/my-repairs",
	"/repair-services",
	"/services",
]);

function pathnameOf(url: string): string {
	return new URL(url, "http://localhost").pathname.replace(/\/$/, "") || "/";
}

export function isCustomerTransitionPath(pathname: string): boolean {
	const normalizedPath = pathname.replace(/\/$/, "") || "/";

	return CUSTOMER_STATIC_PATHS.has(normalizedPath) || /^\/products\/[^/]+$/.test(normalizedPath);
}

export function shouldStartCustomerPageTransition(currentUrl: string, destinationUrl: string): boolean {
	const currentPath = pathnameOf(currentUrl);
	const destinationPath = pathnameOf(destinationUrl);

	return currentPath !== destinationPath
		&& isCustomerTransitionPath(currentPath)
		&& isCustomerTransitionPath(destinationPath);
}
