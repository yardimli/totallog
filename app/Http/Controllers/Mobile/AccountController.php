<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AccountController extends Controller
{
    public function update(Request $request)
    {
        $user = $request->user();
        $data = $request->validate(['name' => 'required|string|max:255', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)]]);
        if ($data['email'] !== $user->email) {
            $data['email_verified_at'] = null;
        }
        $user->forceFill($data)->save();

        return response()->json(['message' => 'Profile saved.']);
    }

    public function password(Request $request)
    {
        $data = $request->validate(['current_password' => 'required|string', 'password' => ['required', 'confirmed', Password::defaults()]]);
        abort_unless(Hash::check($data['current_password'], $request->user()->password), 422, 'Current password is incorrect.');
        $request->user()->update(['password' => Hash::make($data['password'])]);

        return response()->json(['message' => 'Password updated.']);
    }

    public function destroy(Request $request)
    {
        $data = $request->validate(['password' => 'required|string']);
        abort_unless(Hash::check($data['password'], $request->user()->password), 422, 'Password is incorrect.');
        $request->user()->tokens()->delete();
        $request->user()->delete();

        return response()->json(['message' => 'Account deleted.']);
    }

    public function users(Request $request)
    {
        abort_unless($request->user()->is_admin, 403);

        return response()->json(['users' => User::withCount(['dailyLogs', 'taskDefinitions'])->orderByDesc('id')->get()->map(fn ($u) => $u->only('id', 'name', 'email', 'is_guest', 'created_at', 'daily_logs_count', 'task_definitions_count'))]);
    }

    public function resetDemo(Request $request)
    {
        abort_unless($request->user()->is_admin, 403);
        app(\App\Services\GuestDemoService::class)->reset();

        return response()->json(['message' => 'Demo data reset.']);
    }
}
