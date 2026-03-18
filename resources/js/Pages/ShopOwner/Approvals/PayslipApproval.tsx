import AppLayoutShopOwner from "../../../layout/AppLayout_shopOwner";
import PayslipApproval from "../../ERP/Finance/payslipApproval";

export default function ShopOwnerPayslipApprovalPage() {
	return (
		<AppLayoutShopOwner>
			<PayslipApproval
				apiBase="/api/shop-owner/payslip-approvals"
				allowDisbursement={false}
				headTitle="Payslip Approval - Shop Owner"
			/>
		</AppLayoutShopOwner>
	);
}