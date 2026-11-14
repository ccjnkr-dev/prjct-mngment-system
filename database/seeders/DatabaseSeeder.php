<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Accounts are created through team:admin and invitations, never with seeded passwords.
    }
}
