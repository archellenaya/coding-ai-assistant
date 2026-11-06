<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        abort_unless($request->hasSession(), 419, 'A stateful browser session is required.');
        $data = $request->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:255|unique:users', 'password' => ['required', 'confirmed', Password::min(12)]]);
        $user = DB::transaction(function () use ($data) {
            $user = User::create($data);
            $org = Organization::create(['name' => $data['name']."'s organization"]);
            DB::table('organization_members')->insert(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'owner']);

            return $user;
        });
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['data' => $user], 201);
    }

    public function login(Request $request)
    {
        abort_unless($request->hasSession(), 419, 'A stateful browser session is required.');
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::guard('web')->attempt($data)) {
            throw ValidationException::withMessages(['email' => ['Invalid credentials.']]);
        }
        $request->session()->regenerate();

        return response()->json(['data' => Auth::guard('web')->user()]);
    }

    public function logout(Request $request)
    {
        abort_unless($request->hasSession(), 419);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function user(Request $request)
    {
        return response()->json(['data' => $request->user()]);
    }
}
