export const loadModalDraft = <T>(key: string): T | null => {
	if (typeof window === "undefined") return null;

	try {
		const raw = window.localStorage.getItem(key);
		if (!raw) return null;
		return JSON.parse(raw) as T;
	} catch {
		return null;
	}
};

export const saveModalDraft = <T>(key: string, data: T): void => {
	if (typeof window === "undefined") return;

	try {
		window.localStorage.setItem(key, JSON.stringify(data));
	} catch {
		// ignore localStorage failures
	}
};

export const clearModalDraft = (key: string): void => {
	if (typeof window === "undefined") return;

	try {
		window.localStorage.removeItem(key);
	} catch {
		// ignore localStorage failures
	}
};