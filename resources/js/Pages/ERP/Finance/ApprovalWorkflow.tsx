import React from "react";
import { Head } from "@inertiajs/react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";

const ApprovalWorkflowRemoved: React.FC = () => {
	return (
		<AppLayoutERP>
			<Head title="Approval Workflow" />
			<div className="p-8">
				<h1 className="text-2xl font-semibold">Approval Workflow removed</h1>
				<p className="mt-2 text-gray-600">This page has been removed from the codebase. If you need it restored, add the page back under resources/js/Pages/ERP/Finance.</p>
			</div>
		</AppLayoutERP>
	);
};

export default ApprovalWorkflowRemoved;
