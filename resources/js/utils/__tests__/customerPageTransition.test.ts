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
		"/repair-process",
		"/repair-shop/42",
		"/shop-profile/42",
		"/shop-profile/42/virtual-showroom",
		"/services/product-image-spin-tutorial",
		"/download",
		"/checkout",
		"/payment",
		"/order-success",
		"/payment-failed",
		"/customer-profile",
		"/messages",
		"/message",
		"/message/42",
		"/customer/conversations",
		"/tracking/shipments/42",
		"/tracking/shipments/42/proofs/7",
		"/tracking/shipments/42/attempts/3/proof",
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
	])("allows %s", (path) => {
		expect(isCustomerTransitionPath(path)).toBe(true);
	});

	it.each([
		"/products/a/b",
		"/checkout/extra",
		"/repair-shop/1/extra",
		"/erp/hr",
		"/admin",
		"/api/search/suggestions",
		"/api/customer/badge-counts",
		"/apk/download",
		"/articles",
		"/email/verify/1/hash",
		"/repair-shop/1/extra",
		"/shop-profile/1/virtual-showroom/extra",
		"/tracking/shipments/1/extra",
		"/shop-owner/dashboard",
	])("rejects %s", (path) => {
		expect(isCustomerTransitionPath(path)).toBe(false);
	});

	it("ignores query and hash differences", () => {
		expect(shouldStartCustomerPageTransition("/products?sort=new", "/services#care")).toBe(true);
	});

	it("rejects same-path navigation", () => {
		expect(shouldStartCustomerPageTransition("/products?sort=new", "/products?sort=sale")).toBe(false);
	});

	it.each(["men", "women", "kids", "sports"])("animates the %s category switch", (category) => {
		expect(shouldStartCustomerPageTransition("/products", `/products?category=${category}`)).toBe(true);
	});

	it("animates between product categories", () => {
		expect(shouldStartCustomerPageTransition("/products?category=men", "/products?category=women")).toBe(true);
	});
});
