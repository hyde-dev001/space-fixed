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

  it("uses the black primary action in the salary change modal", () => {
    const submitLabelIndex = salaryChanges.indexOf("Submit Salary Change");
    const submitClassStart = salaryChanges.lastIndexOf("className=", submitLabelIndex);
    const submitSection = salaryChanges.slice(submitClassStart, salaryChanges.indexOf("</button>", submitLabelIndex));

    expect(submitSection).toContain("bg-black");
    expect(submitSection).toContain("text-white");
    expect(submitSection).not.toContain("bg-blue-600");
  });
});
