<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DriveController;
use App\Http\Controllers\PasswordChangeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Middleware\ActiveUser;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::get('/invitations/{token}', [AuthController::class, 'invite']);
    Route::post('/invitations/{token}', [AuthController::class, 'accept'])->middleware('throttle:6,1');
    Route::view('/forgot-password', 'auth.forgot')->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:3,1')->name('password.email');
    Route::get('/reset-password/{token}', fn (string $token) => view('auth.reset', compact('token')))->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'reset'])->name('password.update');
});
Route::middleware(['auth', ActiveUser::class])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/change-password', [PasswordChangeController::class, 'show'])->name('password.change');
    Route::post('/change-password', [PasswordChangeController::class, 'update'])->middleware('throttle:6,1')->name('password.change.update');
    Route::get('/', [WorkspaceController::class, 'index'])->name('dashboard');
    Route::get('/clients', [ClientController::class, 'index']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::get('/clients/{client}/edit', [ClientController::class, 'edit']);
    Route::patch('/clients/{client}', [ClientController::class, 'update']);
    Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');
    Route::get('/projects', [WorkspaceController::class, 'projects'])->name('projects.index');
    Route::post('/projects', [WorkspaceController::class, 'create']);
    Route::get('/projects/{project}', [WorkspaceController::class, 'show']);
    Route::patch('/projects/{project}', [WorkspaceController::class, 'update']);
    Route::delete('/projects/{project}', [WorkspaceController::class, 'destroy'])->name('projects.destroy');
    Route::post('/projects/{project}/tasks/{task?}', [WorkspaceController::class, 'task']);
    Route::post('/projects/{project}/tasks/{task}/checklist', [WorkspaceController::class, 'checklist']);
    Route::post('/projects/{project}/checklist/{item}', [WorkspaceController::class, 'toggle']);
    Route::post('/projects/{project}/notes/{note?}', [WorkspaceController::class, 'note']);
    Route::post('/projects/{project}/assets', [WorkspaceController::class, 'asset']);
    Route::post('/projects/{project}/links', [WorkspaceController::class, 'link']);
    Route::post('/projects/{project}/assets/{asset}/retry', [WorkspaceController::class, 'retry']);
    Route::get('/projects/{project}/timeline', [WorkspaceController::class, 'timeline']);
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::post('/invitations', [UserController::class, 'invite']);
    Route::delete('/invitations/{invitation}', [UserController::class, 'revoke']);
    Route::patch('/users/{user}', [UserController::class, 'update']);
    Route::get('/settings/drive', [DriveController::class, 'index']);
    Route::get('/settings/drive/connect', [DriveController::class, 'connect']);
    Route::get('/settings/drive/callback', [DriveController::class, 'callback']);
    Route::post('/settings/drive/disconnect', [DriveController::class, 'disconnect']);
});
