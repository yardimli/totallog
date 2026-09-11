<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string', 'device_name' => 'required|string|max:100']);
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password) || $user->is_guest) {
            throw ValidationException::withMessages(['email' => ['The supplied credentials are incorrect.']]);
        }

        return response()->json(['token' => $user->createToken($data['device_name'], ['mobile'])->plainTextToken, 'user' => $user->only('id', 'name', 'email'), 'server_time' => now()->utc()->toISOString()]);
    }

    public function register(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users', 'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()]]);
        $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password'])]);
        event(new \Illuminate\Auth\Events\Registered($user));

        return response()->json(['message' => 'Account created. You can now sign in.'], 201);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        \Illuminate\Support\Facades\Password::sendResetLink($request->only('email'));

        return response()->json(['message' => 'If this account exists, a password reset link has been sent.']);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }
}
