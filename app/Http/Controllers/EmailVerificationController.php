<?php

namespace App\Http\Controllers;

use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmailVerificationController extends Controller
{
    /**
     * Mark the explicitly signed account's email address as verified.
     * Handles both regular users and shop owners without requiring a session.
     */
    public function verify(Request $request, string $accountType, int $id, string $hash)
    {
        $principal = $this->principalForAccountType($accountType, $id);

        abort_unless($principal instanceof User || $principal instanceof ShopOwner, 403, 'Invalid verification link.');
        abort_unless(
            hash_equals(sha1((string) $principal->getEmailForVerification()), $hash),
            403,
            'Invalid verification link.',
        );

        if ($principal->hasVerifiedEmail()) {
            return $this->verifiedRedirect($request, $principal, true);
        }

        if ($principal->markEmailAsVerified()) {
            event(new Verified($principal));
        }

        if ($principal instanceof User
            && (int) $request->session()->get('pending_customer_verification_user_id') === (int) $principal->getKey()) {
            $request->session()->forget('pending_customer_verification_user_id');
        }

        return $this->verifiedRedirect($request, $principal);
    }

    private function principalForAccountType(string $accountType, int $id): User|ShopOwner|null
    {
        return match ($accountType) {
            'user' => User::query()->find($id),
            'shop_owner' => ShopOwner::query()->find($id),
            default => null,
        };
    }

    private function verifiedRedirect(Request $request, User|ShopOwner $principal, bool $alreadyVerified = false)
    {
        if ($principal instanceof ShopOwner) {
            return redirect()->route('shop-owner.pending-approval')->with(
                $alreadyVerified ? 'message' : 'success',
                $alreadyVerified
                    ? 'Email already verified! Your application is under review.'
                    : 'Email verified successfully! Your application is under review.',
            );
        }

        $this->forgetVerificationIntendedUrl($request);

        return redirect()->route('login')->with(
            'success',
            $alreadyVerified
                ? 'Your email is already verified.'
                : 'Email verified successfully. You may now sign in.',
        );
    }

    private function forgetVerificationIntendedUrl(Request $request): void
    {
        $intended = $request->session()->get('url.intended');
        $path = is_string($intended) ? parse_url($intended, PHP_URL_PATH) : null;

        if (is_string($path) && Str::startsWith($path, '/email/verify/')) {
            $request->session()->forget('url.intended');
        }
    }
}
