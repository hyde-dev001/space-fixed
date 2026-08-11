import { afterEach, describe, expect, it, vi } from "vitest";
import {
	APP_LOADER_ENABLED_CLASS,
	APP_LOADER_READY_CLASS,
	dismissAppLoader,
} from "../appLoader";

describe("app loader", () => {
	afterEach(() => {
		document.documentElement.classList.remove(APP_LOADER_ENABLED_CLASS);
		document.documentElement.classList.remove(APP_LOADER_READY_CLASS);
		document.documentElement.removeAttribute("data-solespace-loader-started-at");
		document.body.innerHTML = "";
		vi.useRealTimers();
	});

	it("keeps the server-rendered loader visible for three seconds on the first load", () => {
		vi.useFakeTimers();
		document.documentElement.classList.add(APP_LOADER_ENABLED_CLASS);
		document.documentElement.dataset.solespaceLoaderStartedAt = String(Date.now());
		document.body.innerHTML = '<div id="solespace-app-loader"></div>';

		dismissAppLoader();

		const loader = document.getElementById("solespace-app-loader");
		expect(loader).not.toHaveClass("is-leaving");

		vi.advanceTimersByTime(3000 - 360 - 1);
		expect(loader).not.toHaveClass("is-leaving");

		vi.advanceTimersByTime(1);
		expect(loader).toHaveClass("is-leaving");

		vi.advanceTimersByTime(359);
		expect(loader).toBeInTheDocument();

		vi.advanceTimersByTime(1);

		expect(document.getElementById("solespace-app-loader")).toBeNull();
		expect(document.documentElement).toHaveClass(APP_LOADER_READY_CLASS);
	});

	it("does not animate a hidden loader on refresh", () => {
		document.body.innerHTML = '<div id="solespace-app-loader"></div>';

		dismissAppLoader();

		const loader = document.getElementById("solespace-app-loader");
		expect(loader).not.toHaveClass("is-leaving");
		expect(loader).toBeInTheDocument();
		expect(document.documentElement).toHaveClass(APP_LOADER_READY_CLASS);
	});

	it("is safe when the loader is not present", () => {
		document.documentElement.classList.add(APP_LOADER_ENABLED_CLASS);
		expect(() => dismissAppLoader()).not.toThrow();
		expect(document.documentElement).toHaveClass(APP_LOADER_READY_CLASS);
	});
});
