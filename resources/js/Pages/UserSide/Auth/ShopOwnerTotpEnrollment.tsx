import axios from 'axios';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { route } from 'ziggy-js';

interface Props {
    qr_code: string;
    manual_key: string;
    expires_at: number;
}

function errorMessage(error: unknown): string {
    if (axios.isAxiosError(error) && typeof error.response?.data?.message === 'string') {
        return error.response.data.message;
    }

    return 'The authenticator code is invalid. Please try again.';
}

export default function ShopOwnerTotpEnrollment({ qr_code, manual_key }: Props) {
    const [code, setCode] = useState('');
    const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
    const [error, setError] = useState<string>();
    const [processing, setProcessing] = useState(false);

    const submit = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (processing) return;
        if (!/^\d{6}$/.test(code)) {
            setError('Enter the complete six-digit verification code.');
            return;
        }

        setError(undefined);
        setProcessing(true);
        axios.post(route('shop-owner.two-factor.enroll.verify'), { code }, {
            headers: { Accept: 'application/json' },
            withCredentials: true,
        }).then((response) => {
            const codes = response.data?.recovery_codes;
            if (!Array.isArray(codes)) throw new Error('Incomplete enrollment response.');
            setRecoveryCodes(codes);
        }).catch((requestError: unknown) => setError(errorMessage(requestError)))
            .finally(() => setProcessing(false));
    };

    if (recoveryCodes) {
        return (
            <>
                <Head title='Save Recovery Codes - SoleSpace' />
                <main className='userside-auth-page flex min-h-screen items-center justify-center bg-gray-50 px-4 py-10'>
                    <section className='userside-auth-card w-full max-w-md rounded-lg border border-gray-200 bg-white p-8 shadow-sm'>
                        <h1 className='text-xl font-semibold text-gray-900'>Save your recovery codes</h1>
                        <p className='mt-2 text-sm text-gray-600'>Store these codes somewhere secure. Each can be used once if you lose access to your authenticator app.</p>
                        <div className='mt-6 grid grid-cols-2 gap-2 rounded-lg bg-gray-50 p-4 font-mono text-sm text-gray-900'>
                            {recoveryCodes.map((recoveryCode) => <code key={recoveryCode}>{recoveryCode}</code>)}
                        </div>
                        <Link href={route('shop-owner.dashboard')} className='userside-auth-primary mt-6 block w-full rounded-lg bg-black px-4 py-3 text-center text-sm font-medium text-white'>Continue to SoleSpace</Link>
                    </section>
                </main>
            </>
        );
    }

    return (
        <>
            <Head title='Set Up Two-Factor Authentication - SoleSpace' />
            <main className='userside-auth-page flex min-h-screen items-center justify-center bg-gray-50 px-4 py-10'>
                <section className='userside-auth-card w-full max-w-lg rounded-lg border border-gray-200 bg-white p-8 shadow-sm'>
                    <div className='text-center'>
                        <p className='text-xl font-bold text-gray-900'>SoleSpace</p>
                        <h1 className='mt-8 text-xl font-semibold text-gray-900'>Set up your authenticator</h1>
                        <p className='mt-2 text-sm text-gray-600'>Scan the QR code with your authenticator app, or enter the manual key. Then confirm a current six-digit code.</p>
                    </div>
                    <div className='mt-8 grid gap-6 sm:grid-cols-[auto_1fr] sm:items-start'>
                        <div className='mx-auto rounded-lg border border-gray-200 bg-white p-3'><img src={qr_code} alt='Authenticator app QR code' className='h-44 w-44' /></div>
                        <div className='text-sm text-gray-600'>
                            <p className='font-semibold text-gray-900'>Can&apos;t scan?</p>
                            <p className='mt-1'>Enter this key manually:</p>
                            <code className='mt-3 block break-all rounded-lg bg-gray-100 px-3 py-2 font-mono text-xs text-gray-900'>{manual_key}</code>
                        </div>
                    </div>
                    <form onSubmit={submit} className='mt-8 space-y-5' noValidate>
                        {error && <p className='text-sm text-red-600' role='alert'>{error}</p>}
                        <label htmlFor='shop-owner-enrollment-code' className='block text-sm font-semibold text-gray-800'>Six-digit verification code</label>
                        <input id='shop-owner-enrollment-code' type='text' inputMode='numeric' autoComplete='one-time-code' maxLength={6} value={code} onChange={(event) => setCode(event.target.value.replace(/\D/g, '').slice(0, 6))} className='w-full rounded-lg border border-gray-300 px-4 py-3 text-center font-mono text-xl tracking-[0.35em]' required />
                        <button type='submit' disabled={processing} className='userside-auth-primary w-full rounded-lg bg-black px-4 py-3 text-sm font-medium text-white disabled:opacity-50'>{processing ? 'Verifying...' : 'Verify authenticator'}</button>
                    </form>
                    <Link href={route('login')} className='mt-6 block text-center text-sm font-medium text-blue-600 hover:underline'>Back to login</Link>
                </section>
            </main>
        </>
    );
}
