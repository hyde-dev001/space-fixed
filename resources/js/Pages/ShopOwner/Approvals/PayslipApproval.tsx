import { usePage } from "@inertiajs/react";
import AppLayoutShopOwner from "../../../layout/AppLayout_shopOwner";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import PayslipApproval from "../../ERP/Finance/payslipApproval";

export default function ShopOwnerPayslipApprovalPage() {
	const erpMode = (usePage().props as any)?.erpMode === true;
	const Layout = erpMode ? AppLayoutERP : AppLayoutShopOwner;

	return (
		<Layout>
			<PayslipApproval
				apiBase="/api/shop-owner/payslip-approvals"
				allowDisbursement={false}
				allowFinalApproveAll={true}
				headTitle="Payslip Approval - Shop Owner"
			/>
		</Layout>
	);
}
