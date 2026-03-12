import { Head } from "@inertiajs/react";
import AppLayout from "../../layout/AppLayout";
import PageBreadcrumb from "../../components/common/PageBreadCrumb";
import ComponentCard from "../../components/common/ComponentCard";
import LineChartOne from "../../components/charts/line/LineChartOne";

interface Props {
  // Add props from Laravel controller later
}

export default function LineChart() {
  return (
    <AppLayout>
      <Head title="Line Chart" />
      <PageBreadcrumb pageTitle="Line Chart" />
      <div className="space-y-6">
        <ComponentCard title="Line Chart 1">
          <LineChartOne />
        </ComponentCard>
      </div>
    </AppLayout>
  );
}
