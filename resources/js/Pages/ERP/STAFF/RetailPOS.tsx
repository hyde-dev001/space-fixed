import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { retailPosApi } from "../../../services/retailPosApi";

type RetailPosHistoryRow = {
	id: number;
	order_number: string;
	customer_name?: string | null;
	payment_method?: string | null;
	total_amount?: number | string;
	paid_at?: string | null;
};

const RetailPOSPage = () => {
	const [historyRows, setHistoryRows] = useState<RetailPosHistoryRow[]>([]);
	const [loadingHistory, setLoadingHistory] = useState(true);

	useEffect(() => {
		let active = true;

		const loadHistory = async () => {
			try {
				const response = await retailPosApi.history(20);
				if (!active) {
					return;
				}

				const rows = Array.isArray(response?.data?.data) ? response.data.data : [];
				setHistoryRows(rows);
			} catch {
				if (!active) {
					return;
				}

				setHistoryRows([]);
			} finally {
				if (active) {
					setLoadingHistory(false);
				}
			}
		};

		void loadHistory();

		return () => {
			active = false;
		};
	}, []);

	return (
		<AppLayoutERP>
			<Head title="Retail Point of Sale" />
			<div className="space-y-4 p-4 md:p-6">
				<h1 className="text-2xl font-bold text-slate-900">Point of Sale</h1>
				<p className="text-sm text-slate-500">Process walk-in retail shoe sales.</p>
				<section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
					<h2 className="text-lg font-semibold text-slate-900">Retail Catalog</h2>
					<p className="mt-1 text-xs text-slate-500">Retail POS flow for staff users only.</p>
				</section>
				<section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
					<h2 className="text-lg font-semibold text-slate-900">Recent Transactions</h2>
					{loadingHistory ? (
						<p className="mt-2 text-sm text-slate-500">Loading transactions...</p>
					) : historyRows.length === 0 ? (
						<p className="mt-2 text-sm text-slate-500">No transactions yet.</p>
					) : (
						<ul className="mt-3 space-y-2">
							{historyRows.map((row) => (
								<li key={row.id} className="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2">
									<div className="text-sm font-semibold text-slate-900">{row.order_number}</div>
									<div className="text-xs text-slate-500">
										{row.customer_name || "Walk-in"} · {(row.payment_method || "cash").toUpperCase()}
									</div>
								</li>
							))}
						</ul>
					)}
				</section>
			</div>
		</AppLayoutERP>
	);
};

export default RetailPOSPage;
