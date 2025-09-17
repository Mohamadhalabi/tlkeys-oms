<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Branch;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure roles + branches exist
        $this->call([
            RolesSeeder::class,
        ]);

        // Admin user
        $admin = User::firstOrCreate(
            ['email' => 'admin@tlkeys.local'],
            ['name' => 'Admin', 'password' => bcrypt('password')]
        );
        if (! $admin->hasRole('admin')) {
            $admin->assignRole('admin');
        }

        // Seller user (SA)
        $saId = Branch::firstWhere('code', 'SA')?->id;
        $seller = User::firstOrCreate(
            ['email' => 'seller-sa@tlkeys.local'],
            ['name' => 'Seller SA', 'password' => bcrypt('password'), 'branch_id' => $saId]
        );
        $seller->branch_id = $saId;
        $seller->save();
        if (! $seller->hasRole('seller')) {
            $seller->assignRole('seller');
        }
    }
}
