import type { ApexAxisChartSeries, ApexOptions } from 'apexcharts';
import Chart from 'react-apexcharts';
import DashboardState from './DashboardState';

type DashboardTrendChartProps = {
  title: string;
  categories: string[];
  series: ApexAxisChartSeries;
  options?: ApexOptions;
  type?: 'area' | 'bar' | 'line';
  height?: number;
  emptyMessage?: string;
  summary?: string;
  testId?: string;
};

const defaultColors = ['#111111', '#707072', '#a3a3a3'];

export default function DashboardTrendChart({
  title,
  categories,
  series,
  options = {},
  type = 'area',
  height = 300,
  emptyMessage = 'No trend data is available for this period.',
  summary,
  testId = 'dashboard-trend-chart',
}: DashboardTrendChartProps) {
  const hasData = categories.length > 0 && series.some((item) => Array.isArray(item.data) && item.data.length > 0);

  if (!hasData) {
    return <DashboardState status="empty" title={title} message={emptyMessage} />;
  }

  const chartOptions: ApexOptions = {
    chart: {
      type,
      toolbar: { show: false },
      zoom: { enabled: false },
      fontFamily: 'inherit',
      ...options.chart,
    },
    colors: options.colors ?? defaultColors,
    dataLabels: { enabled: false, ...options.dataLabels },
    stroke: { curve: 'smooth', width: 2, ...options.stroke },
    grid: { borderColor: '#e5e5e5', strokeDashArray: 4, ...options.grid },
    legend: { position: 'top', horizontalAlign: 'left', ...options.legend },
    xaxis: {
      categories,
      axisBorder: { show: false },
      axisTicks: { show: false },
      labels: { style: { colors: '#707072' }, ...options.xaxis?.labels },
      ...options.xaxis,
    },
    ...options,
  };

  return (
    <div data-testid={testId} role="img" aria-label={title + ' chart'}>
      <Chart options={chartOptions} series={series} type={type} height={height} />
      <p className="sr-only">{summary ?? title + ' showing ' + categories.length + ' data points.'}</p>
    </div>
  );
}
