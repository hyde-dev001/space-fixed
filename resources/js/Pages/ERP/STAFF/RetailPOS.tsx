import { Head } from "@inertiajs/react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";

const RetailPOSPage = () => {
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
			</div>
		</AppLayoutERP>
	);
};

export default RetailPOSPage;
