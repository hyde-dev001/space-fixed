export const APP_LOADER_ENABLED_CLASS = "solespace-first-load";
export const APP_LOADER_READY_CLASS = "solespace-app-ready";
export const APP_LOADER_DURATION_MS = 3000;
export const APP_LOADER_FADE_MS = 360;

const APP_LOADER_ID = "solespace-app-loader";
const LEAVING_CLASS = "is-leaving";
const STARTED_AT_ATTRIBUTE = "solespaceLoaderStartedAt";
const DISMISS_SCHEDULED_ATTRIBUTE = "dismissScheduled";

const markAppLoaderReady = (): void => {
	document.documentElement.classList.add(APP_LOADER_READY_CLASS);
};

export function dismissAppLoader(): void {
	const loader = document.getElementById(APP_LOADER_ID);

	if (!loader || !document.documentElement.classList.contains(APP_LOADER_ENABLED_CLASS)) {
		markAppLoaderReady();
		return;
	}

	if (
		loader.classList.contains(LEAVING_CLASS)
		|| loader.dataset[DISMISS_SCHEDULED_ATTRIBUTE] === "true"
	) {
		return;
	}

	loader.dataset[DISMISS_SCHEDULED_ATTRIBUTE] = "true";
	const startedAt = Number(document.documentElement.dataset[STARTED_AT_ATTRIBUTE]);
	const elapsed = Number.isFinite(startedAt) ? Math.max(0, Date.now() - startedAt) : 0;
	const leaveDelay = Math.max(0, APP_LOADER_DURATION_MS - elapsed - APP_LOADER_FADE_MS);

	window.setTimeout(() => {
		const currentLoader = document.getElementById(APP_LOADER_ID);
		if (!currentLoader) {
			markAppLoaderReady();
			return;
		}

		if (currentLoader.classList.contains(LEAVING_CLASS)) return;

		currentLoader.classList.add(LEAVING_CLASS);
		window.setTimeout(() => {
			currentLoader.remove();
			markAppLoaderReady();
		}, APP_LOADER_FADE_MS);
	}, leaveDelay);
}
