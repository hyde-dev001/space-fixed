import axios from "axios";

export const retailPosApi = {
	listProducts(search = "") {
		const query = search.trim();
		return axios.get("/api/retail-pos/products", {
			params: query.length > 0 ? { q: query } : {},
			withCredentials: true,
		});
	},
	checkout(payload: Record<string, unknown>) {
		return axios.post("/api/retail-pos/checkout", payload, { withCredentials: true });
	},
	history(limit = 200) {
		return axios.get("/api/retail-pos/history", {
			params: { limit },
			withCredentials: true,
		});
	},
	receipt(orderId: number) {
		return axios.get(`/api/retail-pos/orders/${orderId}/receipt`, {
			withCredentials: true,
		});
	},
};
