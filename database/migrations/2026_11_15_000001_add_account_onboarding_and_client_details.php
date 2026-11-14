<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            $t->boolean('must_change_password')->default(false);
        });
        Schema::table('clients', function (Blueprint $t): void {
            $t->string('business_type')->nullable();
            $t->string('industry')->nullable();
            $t->string('contact_name')->nullable();
            $t->string('contact_position')->nullable();
            $t->string('phone', 60)->nullable();
            $t->string('website', 500)->nullable();
            $t->text('address')->nullable();
            $t->string('status')->default('active');
            $t->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('must_change_password'));
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn(['business_type', 'industry', 'contact_name', 'contact_position', 'phone', 'website', 'address', 'status', 'notes']));
    }
};
