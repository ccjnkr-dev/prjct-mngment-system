<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('member');
            $t->boolean('active')->default(true);
        });
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable();
            $t->timestamps();
        });
        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained();
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('status')->default('active');
            $t->date('start_date')->nullable();
            $t->date('due_date')->nullable();
            $t->string('drive_folder_id')->nullable();
            $t->timestamps();
        });
        Schema::create('project_user', function (Blueprint $t) {
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['project_id', 'user_id']);
        });
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('title');
            $t->string('status')->default('todo');
            $t->string('priority')->default('medium');
            $t->date('start_date')->nullable();
            $t->date('due_date')->nullable();
            $t->boolean('milestone')->default(false);
            $t->timestamps();
        });
        Schema::create('checklist_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('task_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->boolean('done')->default(false);
            $t->timestamps();
        });
        Schema::create('notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->string('title');
            $t->text('body');
            $t->boolean('pinned')->default(false);
            $t->timestamps();
        });
        Schema::create('assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->string('name');
            $t->string('mime')->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->text('url')->nullable();
            $t->string('drive_id')->nullable();
            $t->string('path')->nullable();
            $t->string('status')->default('queued');
            $t->text('error')->nullable();
            $t->timestamps();
        });
        Schema::create('activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('description');
            $t->timestamps();
        });
        Schema::create('invitations', function (Blueprint $t) {
            $t->id();
            $t->string('email')->unique();
            $t->string('role')->default('member');
            $t->string('token', 64)->unique();
            $t->timestamp('expires_at');
            $t->timestamps();
        });
        Schema::create('drive_connections', function (Blueprint $t) {
            $t->id();
            $t->text('refresh_token');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['drive_connections', 'invitations', 'activities', 'assets', 'notes', 'checklist_items', 'tasks', 'project_user', 'projects', 'clients'] as $table) {
            Schema::dropIfExists($table);
        } Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'active']));
    }
};
