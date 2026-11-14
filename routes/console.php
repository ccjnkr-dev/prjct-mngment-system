<?php

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('team:admin {email} {--name=Administrator}', function () {
    $email = strtolower($this->argument('email'));
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $this->error('Invalid email.');
    }
    if (User::where('email', $email)->exists()) {
        return $this->error('Account already exists.');
    }
    $password = $this->secret('Password (minimum 10 characters)');
    if (strlen($password) < 10) {
        return $this->error('Password too short.');
    }User::create(['name' => $this->option('name'), 'email' => $email, 'password' => $password, 'role' => 'admin', 'active' => true]);
    $this->info('Administrator created.');
})->purpose('Create the first team administrator');
Schedule::call(fn () => Invitation::where('expires_at', '<', now())->delete())->daily();
