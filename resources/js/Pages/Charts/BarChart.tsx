import { Head } from "@inertiajs/react";
import AppLayout from "../../layout/AppLayout";
import PageBreadcrumb from "../../components/common/PageBreadCrumb";
import ComponentCard from "../../components/common/ComponentCard";
import BarChartOne from "../../components/charts/bar/BarChartOne";

interface Props {
  // Add props from Laravel controller later
}

export default function BarChart() {
  return (
    <AppLayout>
      <Head title="Bar Chart" />
      <PageBreadcrumb pageTitle="Bar Chart" />
      <div className="space-y-6">
        <ComponentCard title="Bar Chart 1">
          <BarChartOne />
        </ComponentCard>
      </div>
    </AppLayout>
  );
}
