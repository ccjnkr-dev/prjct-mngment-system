<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $r): mixed
    {
        $r->merge(['email' => strtolower(trim((string) $r->input('email')))]);
        $data = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt([...$data, 'active' => true], $r->boolean('remember'))) {
            return back()->withErrors(['email' => 'These credentials do not match an active account.']);
        }
        $r->session()->regenerate();

        return $r->user()->must_change_password ? redirect()->route('password.change') : redirect()->intended('/');
    }

    public function logout(Request $r): mixed
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    }

    public function invite(string $token): mixed
    {
        $invite = Invitation::where('token', hash('sha256', $token))->where('expires_at', '>', now())->firstOrFail();

        return view('auth.accept', compact('invite', 'token'));
    }

    public function accept(Request $r, string $token): mixed
    {
        $data = $r->validate(['name' => 'required|string|max:100', 'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(10)]]);
        DB::transaction(function () use ($data, $token) {
            $invite = Invitation::where('token', hash('sha256', $token))->where('expires_at', '>', now())->lockForUpdate()->firstOrFail();
            abort_if(User::where('email', $invite->email)->exists(), 422, 'Account already exists.');
            User::create([...$data, 'email' => $invite->email, 'role' => $invite->role, 'active' => true, 'email_verified_at' => now()]);
            $invite->delete();
        });

        return redirect('/login')->with('success', 'Account created. Sign in to continue.');
    }

    public function forgot(Request $r): mixed
    {
        $r->validate(['email' => 'required|email']);
        Password::sendResetLink($r->only('email'));

        return back()->with('success', 'If the account exists, a reset link has been sent.');
    }

    public function reset(Request $r): mixed
    {
        $r->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(10)]]);
        $status = Password::reset($r->only('email', 'password', 'password_confirmation', 'token'), function (User $u, string $p) {
            $u->forceFill(['password' => Hash::make($p), 'remember_token' => Str::random(60), 'must_change_password' => false])->save();
        });

        return $status === Password::PASSWORD_RESET ? redirect('/login')->with('success', __($status)) : back()->withErrors(['email' => __($status)]);
    }
}
