<?php

namespace App\Http\Controllers;

use App\Services\AccountManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class PasswordChangeController extends Controller
{
    public function show(): View
    {
        return view('auth.change-password');
    }

    public function update(Request $request, AccountManager $accounts): RedirectResponse
    {
        $data = $request->validate(['current_password' => 'required|string|current_password', 'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::min(10)]]);
        $accounts->changePassword($request->user(), $data['password']);
        $request->session()->regenerate(true);

        return redirect('/')->with('success', 'Password updated. Welcome to your workspace.');
    }
}
