import { useEffect, useRef, useState } from "react";
import { router } from "@inertiajs/react";
import { shouldStartCustomerPageTransition } from "../../utils/customerPageTransition";

const EXIT_DURATION_MS = 260;
const FALLBACK_DURATION_MS = 700;

export function CustomerPageTransition() {
	const [state, setState] = useState<"hidden" | "visible" | "leaving">("hidden");
	const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

	useEffect(() => {
		const clearTimer = () => {
			if (timerRef.current) {
				clearTimeout(timerRef.current);
				timerRef.current = null;
			}
		};

		const leave = () => {
			clearTimer();
			setState((currentState) => currentState === "hidden" ? currentState : "leaving");
			timerRef.current = setTimeout(() => {
				timerRef.current = null;
				setState("hidden");
			}, EXIT_DURATION_MS);
		};

		const unsubs = [
			router.on("start", (event) => {
				const destinationUrl = event.detail?.visit?.url;
				if (typeof destinationUrl !== "string" || !shouldStartCustomerPageTransition(window.location.href, destinationUrl)) {
					return;
				}

				clearTimer();
				setState("visible");
				timerRef.current = setTimeout(leave, FALLBACK_DURATION_MS);
			}),
			router.on("finish", leave),
			router.on("error", leave),
			router.on("cancel", leave),
		];

		return () => {
			clearTimer();
			unsubs.forEach((unsubscribe) => unsubscribe());
		};
	}, []);

	return (
		<div
			aria-hidden="true"
			className="customer-page-transition"
			data-state={state}
			data-testid="customer-page-transition"
		>
			<span className="customer-page-transition__wordmark">SOLESPACE</span>
		</div>
	);
}
