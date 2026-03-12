<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
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
                return response()->json([
                    'message' => [
                        'icon' => 'success',
                        'title' => 'Success',
                        'text' => 'User created successfully'
                    ], Response::HTTP_OK
                ]);
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
        
        if (!Auth::guard('user')->attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => [
                    'icon' => 'error',
                    'title' => 'Error',
                    'text' => 'Invalid Credentials'
                ]
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        
        $user = Auth::guard('user')->user();
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
