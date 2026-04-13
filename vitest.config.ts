import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";
import path from "node:path";

export default defineConfig({
	plugins: [react()],
	resolve: {
		alias: {
			"@": path.resolve(__dirname, "resources/js"),
		},
	},
	test: {
		environment: "jsdom",
		globals: true,
		setupFiles: ["resources/js/test/setup.ts"],
		include: ["resources/js/**/*.test.{ts,tsx}"],
	},
});
