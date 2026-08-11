import { Head, usePage } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { erpUrl } from "../../../utils/erpCapabilities";

type AuditLog = {
  id: number;
  action?: string | null;
  description?: string | null;
  module?: string | null;
  severity?: string | null;
  created_at?: string | null;
  entity_type?: string | null;
  entity_id?: number | string | null;
  user?: { name?: string | null; email?: string | null } | null;
  employee?: { first_name?: string | null; last_name?: string | null } | null;
};

type AuditPage = {
  data: AuditLog[];
  current_page: number;
  last_page: number;
  total: number;
};

type AuditLogsProps = {
  title: string;
  description: string;
  capabilityKey: string;
};

const displayName = (log: AuditLog): string => {
  const employeeName = [log.employee?.first_name, log.employee?.last_name].filter(Boolean).join(" ");
  return employeeName || log.user?.name || log.user?.email || "System";
};

const formatDate = (value?: string | null): string => {
  if (!value) return "—";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
};

export default function AuditLogs({ title, description, capabilityKey }: AuditLogsProps) {
  const { erpCapabilities } = usePage().props as any;
  const apiUrl = erpUrl(erpCapabilities, capabilityKey);
  const [auditPage, setAuditPage] = useState<AuditPage | null>(null);
  const [search, setSearch] = useState("");
  const [module, setModule] = useState("");
  const [severity, setSeverity] = useState("");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const query = useMemo(() => {
    const params = new URLSearchParams({ page: String(page), per_page: "50" });
    if (search.trim()) params.set("search", search.trim());
    if (module) params.set("module", module);
    if (severity) params.set("severity", severity);
    return params.toString();
  }, [module, page, search, severity]);

  useEffect(() => {
    if (!apiUrl) {
      setLoading(false);
      setError("Audit log access is not available for this module.");
      return;
    }

    const controller = new AbortController();
    const loadLogs = async () => {
      setLoading(true);
      setError(null);
      try {
        const response = await fetch(`${apiUrl}?${query}`, {
          headers: { Accept: "application/json" },
          credentials: "include",
          signal: controller.signal,
        });
        if (!response.ok) throw new Error(`Unable to load audit logs (${response.status}).`);

        const payload = await response.json();
        setAuditPage(payload.data ?? null);
      } catch (caught) {
        if ((caught as Error).name === "AbortError") return;
        setAuditPage(null);
        setError(caught instanceof Error ? caught.message : "Unable to load audit logs.");
      } finally {
        if (!controller.signal.aborted) setLoading(false);
      }
    };

    void loadLogs();
    return () => controller.abort();
  }, [apiUrl, query]);

  const logs = auditPage?.data ?? [];

  return (
    <AppLayoutERP>
      <Head title={`${title} - Solespace ERP`} />
      <div className="space-y-6 p-6">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900 dark:text-white">{title}</h1>
          <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">{description}</p>
        </div>

        <div className="grid gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 md:grid-cols-3">
          <input
            value={search}
            onChange={(event) => { setSearch(event.target.value); setPage(1); }}
            placeholder="Search audit activity..."
            className="rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800"
          />
          <input
            value={module}
            onChange={(event) => { setModule(event.target.value); setPage(1); }}
            placeholder="Filter by module"
            className="rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800"
          />
          <select
            value={severity}
            onChange={(event) => { setSeverity(event.target.value); setPage(1); }}
            className="rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800"
          >
            <option value="">All severities</option>
            <option value="info">Info</option>
            <option value="warning">Warning</option>
            <option value="critical">Critical</option>
          </select>
        </div>

        {error && <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">{error}</div>}

        <div className="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
          <table className="min-w-full text-left text-sm">
            <thead className="border-b border-gray-200 bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:border-gray-800 dark:bg-gray-800/50">
              <tr>
                <th className="px-4 py-3">Date</th>
                <th className="px-4 py-3">Actor</th>
                <th className="px-4 py-3">Action</th>
                <th className="px-4 py-3">Module</th>
                <th className="px-4 py-3">Details</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-800">
              {loading && <tr><td colSpan={5} className="px-4 py-10 text-center text-gray-500">Loading audit logs...</td></tr>}
              {!loading && logs.map((log) => (
                <tr key={log.id} className="align-top">
                  <td className="whitespace-nowrap px-4 py-3 text-gray-600 dark:text-gray-400">{formatDate(log.created_at)}</td>
                  <td className="px-4 py-3 font-medium text-gray-900 dark:text-white">{displayName(log)}</td>
                  <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{log.action || "—"}</td>
                  <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{log.module || "—"}</td>
                  <td className="max-w-xl px-4 py-3 text-gray-600 dark:text-gray-400">{log.description || log.entity_type || "—"}</td>
                </tr>
              ))}
              {!loading && logs.length === 0 && <tr><td colSpan={5} className="px-4 py-10 text-center text-gray-500">No audit activity found.</td></tr>}
            </tbody>
          </table>
          {auditPage && auditPage.last_page > 1 && (
            <div className="flex items-center justify-between border-t border-gray-200 px-4 py-3 text-sm dark:border-gray-800">
              <span className="text-gray-500">{auditPage.total} total records</span>
              <div className="flex gap-2">
                <button type="button" disabled={page <= 1} onClick={() => setPage((current) => Math.max(1, current - 1))} className="rounded border px-3 py-1 disabled:opacity-50">Previous</button>
                <span className="px-2 py-1">Page {auditPage.current_page} of {auditPage.last_page}</span>
                <button type="button" disabled={page >= auditPage.last_page} onClick={() => setPage((current) => Math.min(auditPage.last_page, current + 1))} className="rounded border px-3 py-1 disabled:opacity-50">Next</button>
              </div>
            </div>
          )}
        </div>
      </div>
    </AppLayoutERP>
  );
}
