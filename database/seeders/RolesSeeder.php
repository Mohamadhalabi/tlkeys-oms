<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Branch;
use Spatie\Permission\Models\Role;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        // Roles
        $admin  = Role::firstOrCreate(['name' => 'admin']);
        $seller = Role::firstOrCreate(['name' => 'seller']);

        // Branches
        Branch::firstOrCreate(['code' => 'SA'], ['name' => 'Saudi Arabia']);
        Branch::firstOrCreate(['code' => 'AE'], ['name' => 'United Arab Emirates']);

        // Make the first user (if any) an admin
        if ($user = User::first()) {
            if (! $user->hasRole('admin')) {
                $user->assignRole('admin');
            }
        }
    }
}
