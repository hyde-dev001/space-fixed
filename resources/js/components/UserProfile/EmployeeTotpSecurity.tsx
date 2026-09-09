import { useState } from "react";
import axios from "axios";

interface SecurityActivity {
    action: string;
    label: string;
    description: string;
    created_at?: string | null;
}

interface ActiveSession {
    device: string;
    last_active_at: string;
    current: boolean;
}

interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface PaginatedResponse<T> {
    data: T[];
    meta: PaginationMeta;
}

type HistoryView = "activity" | "sessions" | null;

interface Props {
    enabled: boolean;
    activity?: SecurityActivity[];
    active_sessions?: ActiveSession[];
}

interface SetupResponse {
    qr_code: string;
    manual_key: string;
    expires_at: string;
}

type Modal = "setup" | "recovery" | "disable" | null;
type SetupStep = "password" | "verify";

interface ApiError {
    message?: string;
    errors?: Record<string, string[]>;
}

function errorMessage(error: unknown, fallback: string): string {
    if (!axios.isAxiosError(error)) {
        return fallback;
    }

    const payload = error.response?.data as ApiError | undefined;
    const firstValidationError = payload?.errors
        ? Object.values(payload.errors).flat()[0]
        : undefined;

    return firstValidationError ?? payload?.message ?? fallback;
}

function formatActivityDate(value?: string | null): string {
    if (!value) {
        return "";
    }

    return new Date(value).toLocaleString();
}

type HistoryRowsProps =
    | { activity: true; history: PaginatedResponse<SecurityActivity> | null }
    | { activity: false; history: PaginatedResponse<ActiveSession> | null };

function HistoryRows({ history, activity }: HistoryRowsProps) {
    if (!history) return <p className="px-6 py-8 text-sm text-gray-500">Loading...</p>;
    if (history.data.length === 0) return <p className="px-6 py-8 text-sm text-gray-500">No records found.</p>;

    if (activity) {
        return <div className="divide-y divide-gray-200 dark:divide-gray-700">{history.data.map((entry) => <div key={entry.action + "-" + (entry.created_at ?? "recent")} className="px-6 py-4"><p className="font-medium text-gray-900 dark:text-white">{entry.label}</p><p className="text-sm text-gray-600 dark:text-gray-400">{entry.description}</p>{entry.created_at && <p className="mt-1 text-xs text-gray-500">{formatActivityDate(entry.created_at)}</p>}</div>)}</div>;
    }

    return <div className="divide-y divide-gray-200 dark:divide-gray-700">{history.data.map((session, index) => <div key={session.device + "-" + session.last_active_at + "-" + index} className="flex items-center justify-between gap-4 px-6 py-4"><div><p className="font-medium text-gray-900 dark:text-white">{session.device}</p><p className="text-sm text-gray-500 dark:text-gray-400">Last active {formatActivityDate(session.last_active_at)}</p></div>{session.current && <span className="rounded border border-green-200 px-2 py-1 text-xs font-medium text-green-700">This device</span>}</div>)}</div>;
}

export default function EmployeeTotpSecurity({ enabled, activity = [], active_sessions = [] }: Props) {
    const [totpEnabled, setTotpEnabled] = useState(enabled);
    const [modal, setModal] = useState<Modal>(null);
    const [setupStep, setSetupStep] = useState<SetupStep>("password");
    const [setup, setSetup] = useState<SetupResponse | null>(null);
    const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
    const [currentPassword, setCurrentPassword] = useState("");
    const [code, setCode] = useState("");
    const [error, setError] = useState("");
    const [processing, setProcessing] = useState(false);
    const [activeSessions, setActiveSessions] = useState(active_sessions);
    const [sessionError, setSessionError] = useState("");

    const [historyView, setHistoryView] = useState<HistoryView>(null);
    const [activityHistory, setActivityHistory] = useState<PaginatedResponse<SecurityActivity> | null>(null);
    const [sessionHistory, setSessionHistory] = useState<PaginatedResponse<ActiveSession> | null>(null);
    const [historyLoading, setHistoryLoading] = useState(false);
    const [historyError, setHistoryError] = useState("");

    const resetModal = () => {
        setModal(null);
        setSetupStep("password");
        setSetup(null);
        setRecoveryCodes([]);
        setCurrentPassword("");
        setCode("");
        setError("");
        setProcessing(false);
    };

    const startSetup = async () => {
        setProcessing(true);
        setError("");

        try {
            const response = await axios.post<SetupResponse>(
                route("erp.security.totp.setup"),
                { current_password: currentPassword },
            );

            setSetup(response.data);
            setSetupStep("verify");
            setCurrentPassword("");
        } catch (requestError) {
            setError(errorMessage(requestError, "Unable to start two-factor setup."));
        } finally {
            setProcessing(false);
        }
    };

    const verifySetup = async () => {
        setProcessing(true);
        setError("");

        try {
            const response = await axios.post<{ recovery_codes: string[] }>(
                route("erp.security.totp.verify"),
                { code },
            );

            setTotpEnabled(true);
            setRecoveryCodes(response.data.recovery_codes);
            setModal("recovery");
            setCode("");
            setSetup(null);
            setSetupStep("password");
        } catch (requestError) {
            setError(errorMessage(requestError, "The verification code is invalid or expired."));
        } finally {
            setProcessing(false);
        }
    };

    const regenerateRecoveryCodes = async () => {
        setProcessing(true);
        setError("");

        try {
            const response = await axios.post<{ recovery_codes: string[] }>(
                route("erp.security.totp.recovery.regenerate"),
                {
                    current_password: currentPassword,
                    code,
                },
            );

            setRecoveryCodes(response.data.recovery_codes);
            setCurrentPassword("");
            setCode("");
        } catch (requestError) {
            setError(errorMessage(requestError, "The password or verification code is invalid."));
        } finally {
            setProcessing(false);
        }
    };

    const disableTotp = async () => {
        setProcessing(true);
        setError("");

        try {
            await axios.post(route("erp.security.totp.disable"), {
                current_password: currentPassword,
                code,
            });

            setTotpEnabled(false);
            resetModal();
        } catch (requestError) {
            setError(errorMessage(requestError, "The password or verification code is invalid."));
        } finally {
            setProcessing(false);
        }
    };

    const logoutOtherSessions = async () => {
        setProcessing(true);
        setSessionError("");

        try {
            await axios.post(route("erp.security.sessions.logout-others"));
            setActiveSessions((sessions) => sessions.filter((session) => session.current));
        } catch (requestError) {
            setSessionError(errorMessage(requestError, "Unable to log out other sessions."));
        } finally {
            setProcessing(false);
        }
    };

    const loadHistory = async (view: Exclude<HistoryView, null>, page = 1) => {
        setHistoryView(view);
        setHistoryLoading(true);
        setHistoryError("");
        try {
            if (view === "activity") {
                const response = await axios.get<PaginatedResponse<SecurityActivity>>(route("erp.security.activity"), { params: { page, per_page: 10 } });
                setActivityHistory(response.data);
            } else {
                const response = await axios.get<PaginatedResponse<ActiveSession>>(route("erp.security.sessions.index"), { params: { page, per_page: 10 } });
                setSessionHistory(response.data);
            }
        } catch (requestError) {
            setHistoryError(errorMessage(requestError, "Unable to load security history."));
        } finally {
            setHistoryLoading(false);
        }
    };

    const renderHistoryModal = () => {
        if (!historyView) return null;
        const isActivity = historyView === "activity";
        const history = isActivity ? activityHistory : sessionHistory;
        const title = isActivity ? "Security Activity" : "Active Sessions";

        return <div className="fixed inset-0 z-[1000] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-label={title}>
            <button type="button" aria-label="Close" className="absolute inset-0" onClick={() => setHistoryView(null)} />
            <div className="relative max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-900">
                <div className="flex items-center justify-between border-b border-gray-200 px-6 py-5 dark:border-gray-700"><h3 className="text-lg font-bold text-gray-900 dark:text-white">{title}</h3><button type="button" onClick={() => setHistoryView(null)} className="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-300">Close</button></div>
                {historyLoading ? <p className="px-6 py-8 text-sm text-gray-500">Loading...</p> : historyError ? <p className="px-6 py-8 text-sm text-red-600">{historyError}</p> : isActivity ? <HistoryRows history={activityHistory} activity={true} /> : <HistoryRows history={sessionHistory} activity={false} />}
                {history && history.meta.last_page > 1 && <div className="flex items-center justify-between border-t border-gray-200 px-6 py-4 text-sm dark:border-gray-700"><button type="button" disabled={history.meta.current_page === 1} onClick={() => void loadHistory(isActivity ? "activity" : "sessions", history.meta.current_page - 1)} className="rounded border border-gray-300 px-3 py-2 disabled:opacity-50 dark:border-gray-600">Previous</button><span>Page {history.meta.current_page} of {history.meta.last_page}</span><button type="button" disabled={history.meta.current_page === history.meta.last_page} onClick={() => void loadHistory(isActivity ? "activity" : "sessions", history.meta.current_page + 1)} className="rounded border border-gray-300 px-3 py-2 disabled:opacity-50 dark:border-gray-600">Next</button></div>}
            </div>
        </div>;
    };

    const copyRecoveryCodes = async () => {
        try {
            await navigator.clipboard.writeText(recoveryCodes.join("\n"));
        } catch {
            setError("Unable to copy the recovery codes.");
        }
    };

    const downloadRecoveryCodes = () => {
        const blob = new Blob([recoveryCodes.join("\n")], { type: "text/plain" });
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a");

        link.href = url;
        link.download = "solespace-recovery-codes.txt";
        link.click();
        URL.revokeObjectURL(url);
    };

    const renderModal = () => {
        if (!modal) {
            return null;
        }

        const isSetup = modal === "setup";
        const isRecovery = modal === "recovery";

        return (
            <div
                className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                role="presentation"
            >
                <div
                    className="w-full max-w-lg rounded-lg border border-gray-200 bg-white p-6 shadow-xl dark:border-gray-700 dark:bg-gray-900"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="employee-totp-dialog-title"
                >
                    <div className="mb-6 flex items-start justify-between gap-4">
                        <div>
                            <h4
                                id="employee-totp-dialog-title"
                                className="text-lg font-semibold text-gray-900 dark:text-white"
                            >
                                {isSetup
                                    ? "Set Up Two-Factor Authentication"
                                    : isRecovery
                                      ? "Your Recovery Codes"
                                      : "Disable Two-Factor Authentication"}
                            </h4>
                            {isSetup && (
                                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    Use an authenticator app such as Google Authenticator, Microsoft Authenticator, or Authy.
                                </p>
                            )}
                        </div>
                        <button
                            type="button"
                            onClick={resetModal}
                            className="text-2xl leading-none text-gray-500 hover:text-gray-900 dark:hover:text-white"
                            aria-label="Close"
                        >
                            ×
                        </button>
                    </div>

                    {isSetup && setupStep === "password" && (
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                void startSetup();
                            }}
                            className="space-y-4"
                        >
                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Confirm current password
                                <input
                                    type="password"
                                    value={currentPassword}
                                    onChange={(event) => setCurrentPassword(event.target.value)}
                                    className="mt-2 w-full rounded-lg border border-gray-300 px-4 py-3 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                    autoFocus
                                    required
                                />
                            </label>
                            {error && <p className="text-sm text-red-600">{error}</p>}
                            <div className="flex justify-end gap-3">
                                <button
                                    type="button"
                                    onClick={resetModal}
                                    className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 dark:border-gray-600 dark:text-gray-200"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="rounded-lg bg-black px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                                >
                                    {processing ? "Loading..." : "Continue"}
                                </button>
                            </div>
                        </form>
                    )}

                    {isSetup && setupStep === "verify" && setup && (
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                void verifySetup();
                            }}
                            className="space-y-5"
                        >
                            <div className="text-center">
                                <p className="mb-3 text-sm font-medium text-gray-700 dark:text-gray-300">
                                    1. Scan the QR code
                                </p>
                                <img
                                    src={setup.qr_code}
                                    alt="Two-factor setup QR code"
                                    className="mx-auto h-48 w-48 border border-gray-200 p-2"
                                />
                                <p className="mt-3 text-xs text-gray-500 dark:text-gray-400">
                                    Or enter this setup key manually:
                                </p>
                                <code className="mt-2 inline-block rounded bg-gray-100 px-3 py-2 text-sm text-gray-800 dark:bg-gray-800 dark:text-gray-200">
                                    {setup.manual_key}
                                </code>
                            </div>
                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                2. Enter the 6-digit code
                                <input
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    value={code}
                                    onChange={(event) => setCode(event.target.value.replace(/[^0-9]/g, "").slice(0, 6))}
                                    className="mt-2 w-full rounded-lg border border-gray-300 px-4 py-3 tracking-[0.5em] dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                    required
                                    minLength={6}
                                    maxLength={6}
                                    autoFocus
                                />
                            </label>
                            {error && <p className="text-sm text-red-600">{error}</p>}
                            <div className="flex justify-end gap-3">
                                <button
                                    type="button"
                                    onClick={resetModal}
                                    className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 dark:border-gray-600 dark:text-gray-200"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={processing || code.length !== 6}
                                    className="rounded-lg bg-black px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                                >
                                    {processing ? "Verifying..." : "Verify and Enable"}
                                </button>
                            </div>
                        </form>
                    )}

                    {isRecovery && recoveryCodes.length > 0 && (
                        <div className="space-y-5">
                            <div className="border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                                Each code can be used only once. Store these codes somewhere safe.
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                                {recoveryCodes.map((recoveryCode) => (
                                    <code
                                        key={recoveryCode}
                                        className="rounded border border-gray-200 bg-gray-50 px-3 py-2 text-center text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200"
                                    >
                                        {recoveryCode}
                                    </code>
                                ))}
                            </div>
                            {error && <p className="text-sm text-red-600">{error}</p>}
                            <div className="flex flex-wrap justify-end gap-3">
                                <button
                                    type="button"
                                    onClick={() => void copyRecoveryCodes()}
                                    className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 dark:border-gray-600 dark:text-gray-200"
                                >
                                    Copy Codes
                                </button>
                                <button
                                    type="button"
                                    onClick={downloadRecoveryCodes}
                                    className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 dark:border-gray-600 dark:text-gray-200"
                                >
                                    Download Codes
                                </button>
                                <button
                                    type="button"
                                    onClick={resetModal}
                                    className="rounded-lg bg-black px-4 py-2 text-sm font-medium text-white"
                                >
                                    Done
                                </button>
                            </div>
                        </div>
                    )}

                    {isRecovery && recoveryCodes.length === 0 && (
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                void regenerateRecoveryCodes();
                            }}
                            className="space-y-4"
                        >
                            <p className="text-sm text-gray-600 dark:text-gray-400">
                                Enter your current password and a valid authenticator code. Previous recovery codes will be invalidated.
                            </p>
                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Current Password
                                <input
                                    type="password"
                                    value={currentPassword}
                                    onChange={(event) => setCurrentPassword(event.target.value)}
                                    className="mt-2 w-full rounded-lg border border-gray-300 px-4 py-3 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                    required
                                />
                            </label>
                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Authenticator Code
                                <input
                                    inputMode="numeric"
                                    value={code}
                                    onChange={(event) => setCode(event.target.value.replace(/[^0-9]/g, "").slice(0, 6))}
                                    className="mt-2 w-full rounded-lg border border-gray-300 px-4 py-3 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                    required
                                    minLength={6}
                                    maxLength={6}
                                />
                            </label>
                            {error && <p className="text-sm text-red-600">{error}</p>}
                            <div className="flex justify-end gap-3">
                                <button
                                    type="button"
                                    onClick={resetModal}
                                    className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 dark:border-gray-600 dark:text-gray-200"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="rounded-lg bg-black px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                                >
                                    {processing ? "Generating..." : "Regenerate Codes"}
                                </button>
                            </div>
                        </form>
                    )}

                    {modal === "disable" && (
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                void disableTotp();
                            }}
                            className="space-y-4"
                        >
                            <p className="text-sm text-gray-600 dark:text-gray-400">
                                This will remove your authenticator and invalidate your recovery codes.
                            </p>
                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Current Password
                                <input
                                    type="password"
                                    value={currentPassword}
                                    onChange={(event) => setCurrentPassword(event.target.value)}
                                    className="mt-2 w-full rounded-lg border border-gray-300 px-4 py-3 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                    required
                                />
                            </label>
                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Current Authenticator Code
                                <input
                                    inputMode="numeric"
                                    value={code}
                                    onChange={(event) => setCode(event.target.value.replace(/[^0-9]/g, "").slice(0, 6))}
                                    className="mt-2 w-full rounded-lg border border-gray-300 px-4 py-3 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                    required
                                    minLength={6}
                                    maxLength={6}
                                />
                            </label>
                            {error && <p className="text-sm text-red-600">{error}</p>}
                            <div className="flex justify-end gap-3">
                                <button
                                    type="button"
                                    onClick={resetModal}
                                    className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 dark:border-gray-600 dark:text-gray-200"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                                >
                                    {processing ? "Disabling..." : "Disable 2FA"}
                                </button>
                            </div>
                        </form>
                    )}
                </div>
            </div>
        );
    };

    return (
        <>
            <div className="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div className="border-b border-gray-200 px-8 py-6 dark:border-gray-700">
                    <h3 className="text-xl font-bold text-gray-900 dark:text-white">Security</h3>
                    <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Manage additional security settings for your account.
                    </p>
                </div>
                <div className="px-8 py-6">
                    <div className="flex flex-col justify-between gap-5 md:flex-row md:items-center">
                        <div>
                            <h4 className="font-semibold text-gray-900 dark:text-white">
                                Two-Factor Authentication
                            </h4>
                            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Add an extra layer of security using an authenticator app.
                            </p>
                            <p className="mt-2 text-sm text-gray-700 dark:text-gray-300">
                                Status:{" "}
                                <span className={totpEnabled ? "font-semibold text-green-700" : "font-medium text-gray-600"}>
                                    {totpEnabled ? "Enabled" : "Not enabled"}
                                </span>
                                {!totpEnabled && <span className="ml-2 text-gray-500">(Optional)</span>}
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-3">
                            {!totpEnabled ? (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setModal("setup");
                                        setError("");
                                    }}
                                    className="rounded-lg bg-black px-4 py-3 text-sm font-medium text-white"
                                >
                                    Enable Two-Factor Authentication
                                </button>
                            ) : (
                                <>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setModal("recovery");
                                            setError("");
                                        }}
                                        className="rounded-lg border border-gray-300 px-4 py-3 text-sm font-medium text-gray-700 dark:border-gray-600 dark:text-gray-200"
                                    >
                                        Regenerate Recovery Codes
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setModal("disable");
                                            setError("");
                                        }}
                                        className="rounded-lg border border-red-300 px-4 py-3 text-sm font-medium text-red-700"
                                    >
                                        Disable 2FA
                                    </button>
                                </>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <div className="mt-8 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div className="flex flex-col justify-between gap-4 border-b border-gray-200 px-8 py-6 md:flex-row md:items-center dark:border-gray-700">
                    <div>
                        <h3 className="text-xl font-bold text-gray-900 dark:text-white">Active Sessions</h3>
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            These are the devices currently signed in to your account.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-3">
                        <button type="button" onClick={() => void loadHistory("sessions")} className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 dark:border-gray-600 dark:text-gray-200">View all</button>
                        <button
                            type="button"
                            onClick={() => void logoutOtherSessions()}
                            disabled={processing || !activeSessions.some((session) => !session.current)}
                            className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 disabled:opacity-50 dark:border-gray-600 dark:text-gray-200"
                        >
                            {processing ? "Logging out..." : "Log Out Other Sessions"}
                        </button>
                    </div>
                </div>
                <div className="divide-y divide-gray-200 dark:divide-gray-700">
                    {activeSessions.length === 0 ? (
                        <p className="px-8 py-5 text-sm text-gray-500 dark:text-gray-400">No active sessions found.</p>
                    ) : (
                        activeSessions.map((session, index) => (
                            <div key={session.device + "-" + session.last_active_at + "-" + index} className="flex items-center justify-between gap-4 px-8 py-4">
                                <div>
                                    <p className="font-medium text-gray-900 dark:text-white">{session.device}</p>
                                    <p className="text-sm text-gray-500 dark:text-gray-400">
                                        Last active {formatActivityDate(session.last_active_at)}
                                    </p>
                                </div>
                                {session.current && (
                                    <span className="rounded border border-green-200 px-2 py-1 text-xs font-medium text-green-700">
                                        This device
                                    </span>
                                )}
                            </div>
                        ))
                    )}
                </div>
                {sessionError && <p className="px-8 py-4 text-sm text-red-600">{sessionError}</p>}
            </div>

            <div className="mt-8 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div className="flex items-center justify-between border-b border-gray-200 px-8 py-6 dark:border-gray-700">
                    <h3 className="text-xl font-bold text-gray-900 dark:text-white">Recent Security Activity</h3>
                    <button type="button" onClick={() => void loadHistory("activity")} className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 dark:border-gray-600 dark:text-gray-200">View all</button>
                </div>
                <div className="divide-y divide-gray-200 dark:divide-gray-700">
                    {activity.length === 0 ? (
                        <p className="px-8 py-5 text-sm text-gray-500 dark:text-gray-400">No security activity yet.</p>
                    ) : activity.map((entry) => (
                        <div key={entry.action + "-" + (entry.created_at ?? "recent")} className="px-8 py-4">
                            <p className="font-medium text-gray-900 dark:text-white">{entry.label}</p>
                            <p className="text-sm text-gray-600 dark:text-gray-400">{entry.description}</p>
                            {entry.created_at && <p className="mt-1 text-xs text-gray-500">{formatActivityDate(entry.created_at)}</p>}
                        </div>
                    ))}
                </div>
            </div>

            {renderModal()}
            {renderHistoryModal()}
        </>
    );
}
