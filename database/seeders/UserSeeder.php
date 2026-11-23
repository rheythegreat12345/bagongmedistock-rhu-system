<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        $users = [
            ['System Administrator', 'admin@rhu.test', 'administrator', 'Municipal Health Administrator'],
            ['Dr. Elena Ramos', 'pharmacist@rhu.test', 'pharmacist', 'RHU Pharmacist'],
            ['Marco Villanueva', 'inventory@rhu.test', 'rhu-staff', 'Inventory Custodian'],
            ['Nurse Ana Flores', 'staff@rhu.test', 'rhu-staff', 'Public Health Nurse'],
            ['RHU Observer', 'viewer@rhu.test', 'rhu-staff', 'Municipal Viewer'],
        ];

        foreach ($users as [$name, $email, $role, $jobTitle]) {
            User::query()->updateOrCreate(['email' => $email], [
                'role_id' => Role::query()->where('slug', $role)->value('id'),
                'name' => $name,
                'job_title' => $jobTitle,
                'phone' => '09'.fake()->numerify('#########'),
                'password' => 'password',
                'status' => 'active',
                'email_verified_at' => now(),
                'preferences' => ['theme' => 'system'],
            ]);
        }

        $this->call(DemoAccountSeeder::class);
    }
}
