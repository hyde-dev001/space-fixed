import { describe, expect, it } from "vitest";
import {
	isCustomerTransitionPath,
	shouldStartCustomerPageTransition,
} from "../customerPageTransition";

describe("customer page transition route policy", () => {
	it.each([
		"/",
		"/products",
		"/products/air-jordan-1",
		"/my-orders",
		"/my-repairs",
		"/repair-services",
		"/services",
	])("allows %s", (path) => {
		expect(isCustomerTransitionPath(path)).toBe(true);
	});

	it.each([
		"/products/a/b",
		"/checkout",
		"/repair-shop/1",
		"/erp/hr",
		"/admin",
	])("rejects %s", (path) => {
		expect(isCustomerTransitionPath(path)).toBe(false);
	});

	it("ignores query and hash differences", () => {
		expect(shouldStartCustomerPageTransition("/products?sort=new", "/services#care")).toBe(true);
	});

	it("rejects same-path navigation", () => {
		expect(shouldStartCustomerPageTransition("/products?sort=new", "/products?sort=sale")).toBe(false);
	});
});
