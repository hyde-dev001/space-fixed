import { Head, Link, useForm } from "@inertiajs/react";
import { useRef, useState } from "react";

interface Props {
    companyAccount: string;
}

export default function EmployeeMfaChallenge({ companyAccount }: Props) {
    const [recoveryMode, setRecoveryMode] = useState(false);
    const inputRefs = useRef<Array<HTMLInputElement | null>>([]);
    const { data, setData, post, processing, errors } = useForm({ code: "" });

    const setDigit = (index: number, value: string) => {
        const digit = value.replace(/[^0-9]/g, "").slice(-1);
        const nextCode = data.code.split("");

        nextCode[index] = digit;
        setData("code", nextCode.join("").slice(0, 6));

        if (digit && index < 5) {
            inputRefs.current[index + 1]?.focus();
        }
    };

    const submit = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        post(route("erp.mfa.challenge.verify"));
    };

    return (
        <>
            <Head title="Two-Factor Authentication - SoleSpace" />
            <main className="flex min-h-screen items-center justify-center bg-gray-50 px-4 py-10">
                <section className="w-full max-w-md rounded-lg border border-gray-200 bg-white p-8 shadow-sm">
                    <div className="mb-8 text-center">
                        <p className="text-xl font-bold text-gray-900">SoleSpace</p>
                        <h1 className="mt-8 text-xl font-semibold text-gray-900">
                            Two-Factor Authentication
                        </h1>
                        <p className="mt-2 text-sm text-gray-600">
                            Enter the 6-digit code from your authenticator app.
                        </p>
                        <p className="mt-1 text-xs text-gray-500">{companyAccount}</p>
                    </div>

                    <form onSubmit={submit} className="space-y-6">
                        {recoveryMode ? (
                            <input
                                type="text"
                                value={data.code}
                                onChange={(event) => setData("code", event.target.value)}
                                className="w-full rounded-lg border border-gray-300 px-4 py-3 text-center tracking-widest"
                                placeholder="Recovery code"
                                autoComplete="one-time-code"
                                autoFocus
                            />
                        ) : (
                            <div className="flex justify-center gap-2">
                                {Array.from({ length: 6 }, (_, index) => (
                                    <input
                                        key={index}
                                        ref={(element) => {
                                            inputRefs.current[index] = element;
                                        }}
                                        inputMode="numeric"
                                        autoComplete={index === 0 ? "one-time-code" : "off"}
                                        value={data.code[index] ?? ""}
                                        onChange={(event) => setDigit(index, event.target.value)}
                                        onKeyDown={(event) => {
                                            if (event.key === "Backspace" && !data.code[index] && index > 0) {
                                                inputRefs.current[index - 1]?.focus();
                                            }
                                        }}
                                        className="h-12 w-11 rounded-lg border border-gray-300 text-center text-lg font-semibold text-gray-900"
                                        maxLength={1}
                                        aria-label={"Digit " + (index + 1)}
                                        required
                                    />
                                ))}
                            </div>
                        )}

                        {errors.code && (
                            <p className="text-center text-sm text-red-600">{errors.code}</p>
                        )}

                        <button
                            type="submit"
                            disabled={processing || data.code.length < (recoveryMode ? 1 : 6)}
                            className="w-full rounded-lg bg-black px-4 py-3 text-sm font-medium text-white disabled:opacity-50"
                        >
                            {processing ? "Verifying..." : "Verify"}
                        </button>

                        <label className="flex items-center justify-center gap-2 text-sm text-gray-600">
                            <input
                                type="checkbox"
                                checked={recoveryMode}
                                onChange={(event) => {
                                    setRecoveryMode(event.target.checked);
                                    setData("code", "");
                                }}
                            />
                            Use a recovery code instead
                        </label>
                    </form>

                    <Link
                        href={route("login")}
                        className="mt-6 block text-center text-sm font-medium text-blue-600 hover:underline"
                    >
                        Back to login
                    </Link>
                </section>
            </main>
        </>
    );
}