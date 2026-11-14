<?php

namespace App\Services;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AccountManager
{
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'role' => $data['role'], 'password' => $data['password'], 'active' => true, 'must_change_password' => true]);
            Invitation::where('email', $user->email)->delete();

            return $user;
        });
    }

    public function changePassword(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password): void {
            $user->forceFill(['password' => Hash::make($password), 'must_change_password' => false, 'remember_token' => Str::random(60)])->save();
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }
        });
    }
}
