export const APP_LOADER_ENABLED_CLASS = "solespace-first-load";

const APP_LOADER_ID = "solespace-app-loader";
const LEAVING_CLASS = "is-leaving";
const REMOVE_DELAY_MS = 360;

export function dismissAppLoader(): void {
	const loader = document.getElementById(APP_LOADER_ID);

	if (
		!loader
		|| !document.documentElement.classList.contains(APP_LOADER_ENABLED_CLASS)
		|| loader.classList.contains(LEAVING_CLASS)
	) {
		return;
	}

	loader.classList.add(LEAVING_CLASS);
	window.setTimeout(() => loader.remove(), REMOVE_DELAY_MS);
}
