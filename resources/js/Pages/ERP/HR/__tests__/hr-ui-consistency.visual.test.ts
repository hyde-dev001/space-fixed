import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

const pageSource = (fileName: string) =>
  readFileSync(join(process.cwd(), "resources/js/Pages/ERP/HR", fileName), "utf8");

const employeeDirectory = pageSource("EmployeeDirectory.tsx");
const attendanceRecords = pageSource("AttendanceRecords.tsx");
const overtimeApprovals = pageSource("OvertimeApprovals.tsx");
const generateSlip = pageSource("generateSlip.tsx");
const salaryChanges = pageSource("SalaryChanges.tsx");

const sectionBetween = (source: string, startMarker: string, endMarker: string) => {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start + startMarker.length);

  expect(start).toBeGreaterThanOrEqual(0);
  expect(end).toBeGreaterThan(start);

  return source.slice(start, end);
};

describe("HR UI consistency presentation", () => {
  it("uses neutral primary and informational treatments in lifecycle modals", () => {
    const invitationAction = employeeDirectory.slice(
      employeeDirectory.lastIndexOf("className=", employeeDirectory.indexOf("Email to Personal Address")),
      employeeDirectory.indexOf("</button>", employeeDirectory.indexOf("Email to Personal Address")),
    );

    expect(invitationAction).toContain("bg-gray-950");
    expect(invitationAction).toContain("text-white");
    expect(invitationAction).not.toContain("bg-blue-600");
    expect(employeeDirectory).toContain("mb-6 bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700 rounded-lg p-4");
    expect(employeeDirectory).toContain("rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/50");
    expect(employeeDirectory).not.toContain("mb-6 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4");
    expect(employeeDirectory).not.toContain("mb-6 rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-900/60 dark:bg-red-900/20");
    expect(employeeDirectory).not.toContain("rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900/60 dark:bg-blue-900/20");
  });

  it("uses compact black actions for attendance and overtime", () => {
    const saveCorrectionIndex = attendanceRecords.indexOf("Save Correction");
    const saveCorrectionAction = attendanceRecords.slice(
      attendanceRecords.lastIndexOf("className=", saveCorrectionIndex),
      attendanceRecords.indexOf("</button>", saveCorrectionIndex),
    );
    const overtimeHeader = sectionBetween(overtimeApprovals, "{/* Header */}", "{/* Metrics */}");

    expect(saveCorrectionAction).toContain("bg-gray-950");
    expect(saveCorrectionAction).toContain("text-white");
    expect(saveCorrectionAction).not.toContain("bg-blue-600");
    expect(overtimeHeader).toContain("inline-flex items-center gap-2");
    expect(overtimeHeader).toContain("px-4 py-2");
    expect(overtimeHeader).toContain("text-sm");
    expect(overtimeHeader).toContain("bg-gray-950");
    expect(overtimeHeader).not.toContain("px-6 py-3");
    expect(overtimeHeader).not.toContain("shadow-md");
  });

  it("reuses the shared statistic card for payroll release authorization", () => {
    expect(generateSlip).toContain('import { DashboardMetricCard } from "../../../components/dashboard";');
    expect(generateSlip.match(/<DashboardMetricCard/g) ?? []).toHaveLength(2);
    expect(generateSlip).not.toContain("rounded-lg border border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50 px-4 py-3");
  });

  it("right-aligns page actions without changing their guarded handlers", () => {
    const overtimeHeader = sectionBetween(overtimeApprovals, "{/* Header */}", "{/* Metrics */}");
    const salaryHeader = sectionBetween(salaryChanges, "{/* Header */}", "{/* Metrics */}");

    expect(employeeDirectory).toContain('className="flex justify-end mb-8"');
    expect(employeeDirectory).toContain("handleAddEmployee");
    expect(overtimeHeader).toContain('className="flex justify-end"');
    expect(overtimeHeader).toContain("setIsAssignModalOpen(true)");
    expect(salaryHeader).toContain('className="flex justify-end"');
    expect(salaryHeader).toContain("openNewChangeModal");
  });

  it("keeps attendance values canonical while presenting neutral table controls", () => {
    const header = sectionBetween(attendanceRecords, "{/* Header */}", "{/* Metric Cards */}");
    const table = sectionBetween(attendanceRecords, "{/* Attendance Table */}", "{/* Pagination */}");
    const hoursCell = sectionBetween(attendanceRecords, "{record.totalHours}h", "</td>");

    expect(header).not.toContain("Download");
    expect(table).toContain('<div className="flex justify-end border-b border-gray-200 dark:border-gray-800 px-6 py-4">');
    expect(table).toContain("handleDownloadCSV");
    expect(attendanceRecords).toContain('case "half_day":');
    expect(attendanceRecords).toContain('return "Half Day";');
    expect(attendanceRecords).toContain('{getStatusLabel(record.status)}');
    expect(attendanceRecords).toContain('case "late":\n        return "bg-red-100');
    expect(hoursCell).not.toMatch(/bg-[a-z0-9/-]+/);
  });

  it("uses compact neutral payroll controls and standalone status cards", () => {
    expect(generateSlip).toContain('className="min-w-56 h-10 rounded-lg');
    expect(generateSlip).toContain('className="h-10 px-4 rounded-lg bg-black text-white');
    expect(generateSlip).toContain('className="space-y-3"');
    expect(generateSlip).not.toContain("bg-blue-50 border-blue-200 shadow-sm dark:bg-blue-900/20 dark:border-blue-800");
    expect(generateSlip).not.toContain("hover:border-blue-300 hover:shadow-sm hover:-translate-y-px");
    expect(generateSlip).not.toContain("bg-blue-50/70 dark:bg-blue-900/10");
    expect(generateSlip).not.toContain("hover:bg-blue-50 dark:hover:bg-blue-900/20");
  });

  it("uses neutral summary cards and a black batch generation action", () => {
    const batchPreview = sectionBetween(generateSlip, "Batch Payroll Preview", "{/* Generation Progress Overlay */}");
    const confirmIndex = batchPreview.indexOf("Confirm & Generate All");
    const confirmHandlerIndex = batchPreview.indexOf("onClick={handleConfirmBatchGeneration}");
    const confirmAction = batchPreview.slice(
      batchPreview.indexOf("className=", confirmHandlerIndex),
      batchPreview.indexOf("</button>", confirmIndex),
    );

    expect(batchPreview).toContain("rounded-2xl border border-gray-200 bg-white p-5 shadow-sm");
    expect(batchPreview).not.toContain("bg-green-50");
    expect(batchPreview).not.toContain("bg-red-50");
    expect(batchPreview).not.toContain("bg-purple-50");
    expect(confirmAction).toContain("bg-gray-950");
    expect(confirmAction).not.toContain("bg-green-600");
    expect(confirmAction).not.toContain("shadow-green-500/30");
  });

  it("keeps the batch preview scrollable without showing a native scrollbar", () => {
    const titleIndex = generateSlip.indexOf("Batch Payroll Preview");
    const batchPreview = generateSlip.slice(
      generateSlip.lastIndexOf("showBatchPreviewModal", titleIndex),
      generateSlip.indexOf("{/* Generation Progress Overlay */}", titleIndex),
    );

    expect(batchPreview).toContain("overflow-y-auto no-scrollbar");
  });

  it("uses the black primary action in the salary change modal", () => {
    const submitLabelIndex = salaryChanges.indexOf("Submit Salary Change");
    const submitClassStart = salaryChanges.lastIndexOf("className=", submitLabelIndex);
    const submitSection = salaryChanges.slice(submitClassStart, salaryChanges.indexOf("</button>", submitLabelIndex));

    expect(submitSection).toContain("bg-black");
    expect(submitSection).toContain("text-white");
    expect(submitSection).not.toContain("bg-blue-600");
  });

  it("uses a neutral informational treatment for the current daily rate", () => {
    expect(salaryChanges).toContain("rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/60 px-4 py-3");
    expect(salaryChanges).toContain("text-sm text-gray-800 dark:text-gray-200");
    expect(salaryChanges).not.toContain("rounded-lg border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20 px-4 py-3");
  });
});
