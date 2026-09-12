import { afterEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, render, screen } from "@testing-library/react";
import { CustomerPageTransition } from "../CustomerPageTransition";

const { listeners } = vi.hoisted(() => ({
	listeners: new Map<string, Set<(event?: unknown) => void>>(),
}));

vi.mock("@inertiajs/react", () => ({
	router: {
		on: vi.fn((eventName: string, callback: (event?: unknown) => void) => {
			const callbacks = listeners.get(eventName) ?? new Set();
			callbacks.add(callback);
			listeners.set(eventName, callbacks);
			return () => callbacks.delete(callback);
		}),
	},
}));

function emit(eventName: string, event?: unknown): void {
	listeners.get(eventName)?.forEach((callback) => callback(event));
}

describe("CustomerPageTransition", () => {
	afterEach(() => {
		cleanup();
		listeners.clear();
		vi.useRealTimers();
		window.history.replaceState({}, "", "/products");
	});

	it("opens for an eligible Inertia visit and closes on finish", () => {
		vi.useFakeTimers();
		render(<CustomerPageTransition />);

		act(() => emit("start", { detail: { visit: { url: "/services" } } }));
		expect(screen.getByTestId("customer-page-transition")).toHaveAttribute("data-state", "visible");

		act(() => emit("finish"));
		expect(screen.getByTestId("customer-page-transition")).toHaveAttribute("data-state", "leaving");

		act(() => vi.advanceTimersByTime(260));
		expect(screen.getByTestId("customer-page-transition")).toHaveAttribute("data-state", "hidden");
	});

	it("does not open for an ERP destination", () => {
		render(<CustomerPageTransition />);

		act(() => emit("start", { detail: { visit: { url: "/erp/hr" } } }));

		expect(screen.getByTestId("customer-page-transition")).toHaveAttribute("data-state", "hidden");
	});

	it("accepts the URL object emitted by Inertia", () => {
		render(<CustomerPageTransition />);

		act(() => emit("start", { detail: { visit: { url: new URL("/services", window.location.origin) } } }));

		expect(screen.getByTestId("customer-page-transition")).toHaveAttribute("data-state", "visible");
	});

	it.each(["error", "cancel"])("closes after %s", (eventName) => {
		render(<CustomerPageTransition />);
		act(() => emit("start", { detail: { visit: { url: "/services" } } }));
		act(() => emit(eventName));

		expect(screen.getByTestId("customer-page-transition")).toHaveAttribute("data-state", "leaving");
	});

	it("removes all router listeners on unmount", () => {
		const { unmount } = render(<CustomerPageTransition />);
		expect(listeners.size).toBe(4);

		unmount();

		expect(listeners.size).toBe(4);
		expect([...listeners.values()].every((callbacks) => callbacks.size === 0)).toBe(true);
	});
});
