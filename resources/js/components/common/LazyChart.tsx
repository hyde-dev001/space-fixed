import React, { Suspense, lazy } from "react";

interface LazyChartProps {
  component: React.ComponentType<any>;
  props: any;
  fallback?: React.ReactNode;
}

const LazyChartSkeleton = () => (
  <div className="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-6 h-96 animate-pulse">
    <div className="h-4 bg-gray-200 dark:bg-gray-700 rounded mb-4 w-32"></div>
    <div className="h-64 bg-gray-100 dark:bg-gray-800 rounded"></div>
  </div>
);

const LazyChart: React.FC<LazyChartProps> = ({ component: Component, props, fallback }) => {
  return (
    <Suspense fallback={fallback || <LazyChartSkeleton />}>
      <Component {...props} />
    </Suspense>
  );
};

export default LazyChart;
