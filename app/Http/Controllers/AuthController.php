<?php

namespace App\Http\Controllers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\Employee;
use App\Models\User;
use App\Services\HR\EmployeeOperationalPolicy;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function __construct(private readonly EmployeeOperationalPolicy $employeePolicy)
    {
    }

    public function register(Request $request) {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|unique:users,email',
            'password' => 'required|confirmed'
        ]);

        if($validator->fails()) {
            return response()->json([
                'message' => [
                    'icon' => 'error',
                    'title' => 'Registration Error',
                    'html' => implode('<br>', $validator->errors()->all())
                ], Response::HTTP_UNPROCESSABLE_ENTITY
            ]);
        } else {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($request->password)
            ]);

            if($user) {
                $verificationEmailFailed = false;
                try {
                    event(new Registered($user));
                } catch (\Throwable $exception) {
                    $verificationEmailFailed = true;
                    Log::warning('API registration verification email delivery failed', [
                        'user_id' => $user->getKey(),
                        'exception' => $exception::class,
                    ]);
                }

                return response()->json([
                    'message' => [
                        'icon' => 'success',
                        'title' => 'Success',
                        'text' => 'User created successfully. Please verify your email before signing in.'
                    ],
                    'email_delivery_status' => $verificationEmailFailed ? 'failed' : 'sent',
                ], Response::HTTP_OK);
            }
        }
    }
    public function login(Request $request) {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required'
        ]);

        if($validator->fails()) {
            return response()->json([
                'message' => [
                    'icon' => 'error',
                    'title' => 'Login Error',
                    'html' => implode('<br>', $validator->errors()->all())
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        
        $credentials = $request->only('email', 'password');
        $credentials['status'] = 'active';

        if (!Auth::guard('user')->attempt($credentials)) {
            return response()->json([
                'message' => [
                    'icon' => 'error',
                    'title' => 'Error',
                    'text' => 'Invalid Credentials'
                ]
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        
        $user = Auth::guard('user')->user();

        if ($user && $user->shop_owner_id) {
            $employee = Employee::query()
                ->where('shop_owner_id', $user->shop_owner_id)
                ->whereRaw('LOWER(email) = ?', [strtolower(trim((string) $user->email))])
                ->first();
            if ($employee && ! $this->employeePolicy->canAuthenticate($employee)) {
                Auth::guard('user')->logout();

                return response()->json([
                    'message' => [
                        'icon' => 'error',
                        'title' => 'Account Suspended',
                        'text' => 'Your account has been suspended. Please contact support.'
                    ]
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        if ($user && $user->isCustomerAccount() && ! $user->hasVerifiedEmail()) {
            Auth::guard('user')->logout();

            return response()->json([
                'success' => false,
                'code' => 'EMAIL_VERIFICATION_REQUIRED',
                'message' => [
                    'icon' => 'error',
                    'title' => 'Email Verification Required',
                    'text' => 'Please verify your email address before signing in.',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        /** @var User $user */
        $token = $user->createToken('auth_token', [ $request->ip() ])->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'message' => [
                'icon' => 'success',
                'title' => 'Success',
                'text' => 'User created successfully'
            ],
        ], Response::HTTP_OK);
    }
    public function logout(Request $request) {
        $request->user()->tokens()->delete();
        
        return response()->json([
            'message' => [
                'icon' => 'success',
                'title' => 'Success',
                'text' => 'User logged out successfully'
            ],
        ], Response::HTTP_OK);
    }
}
