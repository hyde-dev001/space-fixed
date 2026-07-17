import Swal, { type SweetAlertOptions, type SweetAlertResult } from "sweetalert2";

const withDefaultConfirm = (options: SweetAlertOptions): SweetAlertOptions => ({
	confirmButtonColor: "#2563eb",
	...options,
});

export const workflowFeedback = {
	alert(options: SweetAlertOptions): Promise<SweetAlertResult> {
		return Swal.fire(withDefaultConfirm(options));
	},

	warning(title: string, text: string): Promise<SweetAlertResult> {
		return Swal.fire(withDefaultConfirm({ icon: "warning", title, text }));
	},

	error(text: string, title = "Error"): Promise<SweetAlertResult> {
		return Swal.fire(withDefaultConfirm({ icon: "error", title, text }));
	},

	success(options: Omit<SweetAlertOptions, "icon">): Promise<SweetAlertResult> {
		return Swal.fire(withDefaultConfirm({ icon: "success", ...options }));
	},

	toast(icon: "success" | "error" | "warning", title: string): Promise<SweetAlertResult> {
		return Swal.fire({ toast: true, position: "top-end", timer: 2200, timerProgressBar: true, showConfirmButton: false, icon, title });
	},

	confirm(options: Omit<SweetAlertOptions, "icon">): Promise<SweetAlertResult> {
		return Swal.fire(
			withDefaultConfirm({
				icon: "question",
				showCancelButton: true,
				cancelButtonText: "Cancel",
				cancelButtonColor: "#6b7280",
				...options,
			}),
		);
	},

	async errorWithRetry(text: string, title = "Request failed"): Promise<boolean> {
		const result = await Swal.fire(
			withDefaultConfirm({
				icon: "error",
				title,
				text,
				showCancelButton: true,
				confirmButtonText: "Retry",
				cancelButtonText: "Cancel",
				cancelButtonColor: "#6b7280",
			}),
		);

		return result.isConfirmed;
	},
};
