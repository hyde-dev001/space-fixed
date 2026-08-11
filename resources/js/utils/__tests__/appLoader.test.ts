import { afterEach, describe, expect, it, vi } from "vitest";
import { APP_LOADER_ENABLED_CLASS, dismissAppLoader } from "../appLoader";

describe("app loader", () => {
	afterEach(() => {
		document.documentElement.classList.remove(APP_LOADER_ENABLED_CLASS);
		document.body.innerHTML = "";
		vi.useRealTimers();
	});

	it("fades out and removes the server-rendered loader on the first load", () => {
		vi.useFakeTimers();
		document.documentElement.classList.add(APP_LOADER_ENABLED_CLASS);
		document.body.innerHTML = '<div id="solespace-app-loader"></div>';

		dismissAppLoader();

		const loader = document.getElementById("solespace-app-loader");
		expect(loader).toHaveClass("is-leaving");

		vi.advanceTimersByTime(360);

		expect(document.getElementById("solespace-app-loader")).toBeNull();
	});

	it("does not animate a hidden loader on refresh", () => {
		document.body.innerHTML = '<div id="solespace-app-loader"></div>';

		dismissAppLoader();

		const loader = document.getElementById("solespace-app-loader");
		expect(loader).not.toHaveClass("is-leaving");
		expect(loader).toBeInTheDocument();
	});

	it("is safe when the loader is not present", () => {
		document.documentElement.classList.add(APP_LOADER_ENABLED_CLASS);
		expect(() => dismissAppLoader()).not.toThrow();
	});
});
