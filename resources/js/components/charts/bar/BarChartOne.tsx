import Chart from "react-apexcharts";
import { ApexOptions } from "apexcharts";

interface BarChartOneProps {
  categories?: string[];
  seriesData?: number[];
  series?: Array<{ name: string; data: number[] }>;
  seriesName?: string;
  color?: string;
  colors?: string[];
  height?: number;
  minWidthClass?: string;
  yAxisFormatter?: (val: number) => string;
  tooltipFormatter?: (val: number) => string;
}

export default function BarChartOne({
  categories,
  seriesData,
  series,
  seriesName = "Sales",
  color = "#465fff",
  colors,
  height = 180,
  minWidthClass = "min-w-[1000px]",
  yAxisFormatter,
  tooltipFormatter,
}: BarChartOneProps = {}) {
  const defaultCategories = [
    "Jan",
    "Feb",
    "Mar",
    "Apr",
    "May",
    "Jun",
    "Jul",
    "Aug",
    "Sep",
    "Oct",
    "Nov",
    "Dec",
  ];

  const chartCategories = categories && categories.length > 0 ? categories : defaultCategories;
  const chartSeriesData = seriesData && seriesData.length > 0
    ? seriesData
    : [168, 385, 201, 298, 187, 195, 291, 110, 215, 390, 280, 112];

  const chartSeries = series && series.length > 0
    ? series
    : [
        {
          name: seriesName,
          data: chartSeriesData,
        },
      ];

  const options: ApexOptions = {
    colors: colors && colors.length > 0 ? colors : [color],
    chart: {
      fontFamily: "Outfit, sans-serif",
      type: "bar",
      height,
      toolbar: {
        show: false,
      },
    },
    plotOptions: {
      bar: {
        horizontal: false,
        columnWidth: "39%",
        borderRadius: 5,
        borderRadiusApplication: "end",
      },
    },
    dataLabels: {
      enabled: false,
    },
    stroke: {
      show: true,
      width: 4,
      colors: ["transparent"],
    },
    xaxis: {
      categories: chartCategories,
      axisBorder: {
        show: false,
      },
      axisTicks: {
        show: false,
      },
    },
    legend: {
      show: true,
      position: "top",
      horizontalAlign: "left",
      fontFamily: "Outfit",
    },
    yaxis: {
      title: {
        text: undefined,
      },
      labels: {
        formatter: (val: number) => (yAxisFormatter ? yAxisFormatter(val) : `${val}`),
      },
    },
    grid: {
      yaxis: {
        lines: {
          show: true,
        },
      },
    },
    fill: {
      opacity: 1,
    },

    tooltip: {
      x: {
        show: false,
      },
      y: {
        formatter: (val: number) => (tooltipFormatter ? tooltipFormatter(val) : `${val}`),
      },
    },
  };

  return (
    <div className="max-w-full overflow-x-auto custom-scrollbar">
      <div id="chartOne" className={minWidthClass}>
        <Chart options={options} series={chartSeries} type="bar" height={height} />
      </div>
    </div>
  );
}
