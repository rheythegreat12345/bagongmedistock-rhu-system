<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;

test('the audit log displays exact field changes without technical or sensitive values', function () {
    $administratorRole = Role::factory()->create(['slug' => 'administrator', 'is_active' => true]);
    $administrator = User::factory()->create(['role_id' => $administratorRole->id]);
    AuditLog::factory()->create([
        'user_id' => $administrator->id,
        'old_values' => [
            'generic_name' => 'Amlodipine',
            'strength' => '5 mg',
            'updated_at' => '2026-09-29T10:00:00.000000Z',
            'password' => 'old-password',
        ],
        'new_values' => [
            'generic_name' => 'Amlodipine',
            'strength' => '15 mg',
            'updated_at' => '2026-09-29T10:10:00.000000Z',
            'password' => 'new-password',
        ],
    ]);

    $this->actingAs($administrator)
        ->get(route('audit.index'))
        ->assertOk()
        ->assertSee('View 1 change')
        ->assertSee('Strength')
        ->assertSee('5 mg')
        ->assertSee('15 mg')
        ->assertDontSee('updated at')
        ->assertDontSee('old-password')
        ->assertDontSee('new-password')
        ->assertDontSee('Before:');
});

test('the audit log displays Philippine login and logout times in changed values', function () {
    $administratorRole = Role::factory()->create(['slug' => 'administrator', 'is_active' => true]);
    $administrator = User::factory()->create(['role_id' => $administratorRole->id]);

    AuditLog::factory()->create([
        'user_id' => $administrator->id,
        'action' => 'login',
        'new_values' => ['login_time' => 'Oct 06, 2026 11:16:00 AM PHT'],
    ]);
    AuditLog::factory()->create([
        'user_id' => $administrator->id,
        'action' => 'logout',
        'new_values' => ['logout_time' => 'Oct 06, 2026 12:30:00 PM PHT'],
    ]);

    $this->actingAs($administrator)
        ->get(route('audit.index'))
        ->assertOk()
        ->assertSee('Login Time')
        ->assertSee('Oct 06, 2026 11:16:00 AM PHT')
        ->assertSee('Logout Time')
        ->assertSee('Oct 06, 2026 12:30:00 PM PHT');
});
