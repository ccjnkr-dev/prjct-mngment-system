<?php

namespace App\Http\Controllers;

use App\Mail\TeamInvitation;
use App\Models\Invitation;
use App\Models\User;
use App\Services\AccountManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(Request $r): mixed
    {
        $this->admin($r);

        return view('users', ['users' => User::all(), 'invites' => Invitation::all()]);
    }

    public function store(Request $r, AccountManager $accounts): RedirectResponse
    {
        $this->admin($r);
        $r->merge(['email' => strtolower(trim((string) $r->input('email')))]);
        $data = $r->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:200|unique:users,email',
            'role' => 'required|in:admin,member',
            'password' => ['required', 'string', 'confirmed', Password::min(10)],
        ]);
        $accounts->create($data);

        return redirect('/users')->with('success', 'User created. Share the temporary password securely; they must change it when they sign in.');
    }

    public function invite(Request $r): mixed
    {
        $this->admin($r);
        $r->merge(['email' => strtolower(trim((string) $r->input('email')))]);
        $d = $r->validate(['email' => 'required|email|max:200|unique:users,email', 'role' => 'required|in:admin,member']);
        $token = Str::random(64);
        $i = Invitation::updateOrCreate(['email' => strtolower($d['email'])], ['role' => $d['role'], 'token' => hash('sha256', $token), 'expires_at' => now()->addDays(3)]);
        Mail::to($i->email)->send(new TeamInvitation(url('/invitations/'.$token)));

        return back()->with('success', 'Invitation sent; the link expires in 3 days.');
    }

    public function revoke(Request $r, Invitation $invitation): mixed
    {
        $this->admin($r);
        $invitation->delete();

        return back();
    }

    public function update(Request $r, User $user): mixed
    {
        $this->admin($r);
        $d = $r->validate(['role' => 'required|in:admin,member', 'active' => 'required|boolean']);
        DB::transaction(function () use ($user, $d) {
            $admins = User::where('role', 'admin')->where('active', true)->lockForUpdate()->get();
            abort_if($user->role === 'admin' && $user->active && ($d['role'] !== 'admin' || ! $d['active']) && $admins->count() <= 1, 422, 'Keep at least one active administrator.');
            $user->update($d);
        });

        return back()->with('success', 'User updated.');
    }

    private function admin(Request $r): void
    {
        abort_unless($r->user()->role === 'admin', 403);
    }
}
