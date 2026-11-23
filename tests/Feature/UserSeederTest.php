<?php

use App\Models\User;
use Database\Seeders\UserSeeder;

test('the user seeder restores the six built-in accounts without duplicating them', function () {
    $this->seed(UserSeeder::class);
    $this->seed(UserSeeder::class);

    expect(User::query()->count())->toBe(6);

    $this->assertDatabaseHas('users', ['email' => 'admin@rhu.test', 'status' => 'active']);
    $this->assertDatabaseHas('users', ['email' => 'pharmacist@rhu.test', 'status' => 'active']);
    $this->assertDatabaseHas('users', ['email' => 'inventory@rhu.test', 'status' => 'active']);
    $this->assertDatabaseHas('users', ['email' => 'staff@rhu.test', 'status' => 'active']);
    $this->assertDatabaseHas('users', ['email' => 'viewer@rhu.test', 'status' => 'active']);
    $this->assertDatabaseHas('users', ['email' => 'demo@rhu.test', 'status' => 'active']);
});
