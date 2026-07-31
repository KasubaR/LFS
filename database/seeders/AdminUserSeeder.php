<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use App\Models\AdminUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        if (AdminUser::query()->exists()) {
            return;
        }

        $email = (string) config('admin.email');
        $hash = (string) config('admin.password_hash');
        $resolvedEmail = $email !== '' ? $email : 'support@lfszambia.run';

        // Prefer the existing env bcrypt hash so production access is preserved.
        // Fall back to a local default only when the hash is the invalid placeholder.
        if (str_contains($hash, 'invalid.placeholder')) {
            AdminUser::query()->create([
                'name' => 'Super Admin',
                'email' => $resolvedEmail,
                'password' => 'changeme',
                'role' => AdminRole::SuperAdmin,
                'is_active' => true,
            ]);

            return;
        }

        $now = now();
        DB::table('admin_users')->insert([
            'name' => 'Super Admin',
            'email' => $resolvedEmail,
            'password' => $hash,
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
