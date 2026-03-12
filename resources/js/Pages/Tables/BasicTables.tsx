import { Head } from "@inertiajs/react";
import AppLayout from "../../layout/AppLayout";
import PageBreadcrumb from "../../components/common/PageBreadCrumb";
import ComponentCard from "../../components/common/ComponentCard";
import BasicTableOne from "../../components/tables/BasicTables/BasicTableOne";

interface Props {
  // Add props from Laravel controller later
}

export default function BasicTables() {
  return (
    <AppLayout>
      <Head title="Basic Tables" />
      <PageBreadcrumb pageTitle="Basic Tables" />
      <div className="space-y-6">
        <ComponentCard title="Basic Table 1">
          <BasicTableOne />
        </ComponentCard>
      </div>
    </AppLayout>
  );
}
