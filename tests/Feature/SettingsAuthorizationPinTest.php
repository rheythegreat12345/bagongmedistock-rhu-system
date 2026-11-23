<?php

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function administratorWithSettingsAccess(): User
{
    $role = Role::factory()->create(['slug' => 'administrator', 'is_active' => true]);

    return User::factory()->create(['role_id' => $role->id]);
}

function validSettingsPayload(array $overrides = []): array
{
    return array_merge([
        'system_name' => 'MediStock RHU',
        'facility_name' => 'Sudipen RHU',
        'contact_phone' => '',
        'contact_email' => '',
        'address' => '',
        'low_stock_threshold' => 10,
        'expiration_warning_days' => 90,
        'date_format' => 'M d, Y',
        'timezone' => 'Asia/Manila',
        'notification_low_stock' => '1',
        'notification_expiration' => '1',
        'default_theme' => 'system',
    ], $overrides);
}

test('an administrator can configure a shared medicine authorization PIN', function () {
    $administrator = administratorWithSettingsAccess();

    $this->actingAs($administrator)
        ->put(route('settings.update'), validSettingsPayload([
            'medicine_edit_pin' => '2468',
            'medicine_edit_pin_confirmation' => '2468',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $pinHash = Setting::query()->where('key', 'medicine_edit_pin')->value('value');
    expect(Hash::check('2468', $pinHash))->toBeTrue();

    $this->actingAs($administrator)
        ->get(route('settings.edit'))
        ->assertOk()
        ->assertSee('Medicine authorization')
        ->assertDontSee($pinHash);
});

test('updating other settings preserves an existing PIN without exposing it', function () {
    $administrator = administratorWithSettingsAccess();
    $pinHash = Hash::make('2468');
    Setting::factory()->create([
        'key' => 'medicine_edit_pin',
        'value' => $pinHash,
        'type' => 'secret',
        'group' => 'security',
    ]);

    $this->actingAs($administrator)
        ->put(route('settings.update'), validSettingsPayload([
            'system_name' => 'Updated MediStock',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Setting::query()->where('key', 'medicine_edit_pin')->value('value'))->toBe($pinHash)
        ->and(Setting::value('system_name'))->toBe('Updated MediStock');

    $this->actingAs($administrator)
        ->get(route('settings.edit'))
        ->assertOk()
        ->assertSee('Medicine authorization')
        ->assertDontSee($pinHash);

    $this->actingAs($administrator)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee($pinHash);
});

test('the shared medicine PIN must contain four digits and match its confirmation', function () {
    $administrator = administratorWithSettingsAccess();

    foreach ([
        ['medicine_edit_pin' => '246', 'medicine_edit_pin_confirmation' => '246'],
        ['medicine_edit_pin' => '2468', 'medicine_edit_pin_confirmation' => '0000'],
    ] as $pinInput) {
        $this->actingAs($administrator)
            ->from(route('settings.edit'))
            ->put(route('settings.update'), validSettingsPayload($pinInput))
            ->assertRedirect()
            ->assertSessionHasErrors('medicine_edit_pin')
            ->assertSessionMissing('_old_input.medicine_edit_pin')
            ->assertSessionMissing('_old_input.medicine_edit_pin_confirmation');
    }

    $this->assertDatabaseMissing('settings', ['key' => 'medicine_edit_pin']);
});

test('replacing a configured PIN requires the administrator current password', function () {
    $administrator = administratorWithSettingsAccess();
    $currentPassword = 'password';

    $pinHash = Hash::make('2468');
    Setting::factory()->create([
        'key' => 'medicine_edit_pin',
        'value' => $pinHash,
        'type' => 'secret',
        'group' => 'security',
    ]);
    Setting::factory()->create([
        'key' => 'system_name',
        'value' => 'Original system',
        'type' => 'string',
        'group' => 'general',
    ]);

    $this->actingAs($administrator)
        ->from(route('settings.edit'))
        ->put(route('settings.update'), validSettingsPayload([
            'system_name' => 'Should not be saved',
            'current_password' => 'WrongPassword',
            'medicine_edit_pin' => '9999',
            'medicine_edit_pin_confirmation' => '9999',
        ]))
        ->assertRedirect()
        ->assertSessionHasErrors('current_password')
        ->assertSessionMissing('_old_input.medicine_edit_pin')
        ->assertSessionMissing('_old_input.current_password');

    expect(Setting::query()->where('key', 'medicine_edit_pin')->value('value'))->toBe($pinHash)
        ->and(Setting::value('system_name'))->toBe('Original system');

    $this->actingAs($administrator)
        ->put(route('settings.update'), validSettingsPayload([
            'current_password' => $currentPassword,
            'medicine_edit_pin' => '9999',
            'medicine_edit_pin_confirmation' => '9999',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $newPinHash = Setting::query()->where('key', 'medicine_edit_pin')->value('value');
    expect(Hash::check('9999', $newPinHash))->toBeTrue()
        ->and(Hash::check('2468', $newPinHash))->toBeFalse();
    $this->assertDatabaseHas('audit_logs', ['action' => 'authorization_pin_updated']);
});

test('changing a configured PIN requires a current password value', function () {
    $administrator = administratorWithSettingsAccess();

    $pinHash = Hash::make('2468');
    Setting::factory()->create([
        'key' => 'medicine_edit_pin',
        'value' => $pinHash,
        'type' => 'secret',
        'group' => 'security',
    ]);

    $this->actingAs($administrator)
        ->from(route('settings.edit'))
        ->put(route('settings.update'), validSettingsPayload([
            'medicine_edit_pin' => '9999',
            'medicine_edit_pin_confirmation' => '9999',
        ]))
        ->assertRedirect(route('settings.edit'))
        ->assertSessionHasErrors('current_password')
        ->assertSessionMissing('_old_input.medicine_edit_pin');

    expect(Setting::query()->where('key', 'medicine_edit_pin')->value('value'))->toBe($pinHash)
        ->and(Setting::value('system_name'))->toBeNull();
});

test('a password without a new PIN cannot be treated as a successful PIN change', function () {
    $administrator = administratorWithSettingsAccess();
    $pinHash = Hash::make('2468');
    Setting::factory()->create([
        'key' => 'medicine_edit_pin',
        'value' => $pinHash,
        'type' => 'secret',
        'group' => 'security',
    ]);
    Setting::factory()->create([
        'key' => 'system_name',
        'value' => 'Original system',
        'type' => 'string',
        'group' => 'general',
    ]);

    $this->actingAs($administrator)
        ->from(route('settings.edit'))
        ->put(route('settings.update'), validSettingsPayload([
            'system_name' => 'Should not be saved',
            'current_password' => 'WrongPassword',
        ]))
        ->assertRedirect(route('settings.edit'))
        ->assertSessionHasErrors('current_password');

    expect(Setting::query()->where('key', 'medicine_edit_pin')->value('value'))->toBe($pinHash)
        ->and(Setting::value('system_name'))->toBe('Original system');
});

test('an incorrect current password does not replace a configured PIN', function () {
    $administrator = administratorWithSettingsAccess();

    $pinHash = Hash::make('2468');
    Setting::factory()->create([
        'key' => 'medicine_edit_pin',
        'value' => $pinHash,
        'type' => 'secret',
        'group' => 'security',
    ]);

    $this->actingAs($administrator)
        ->from(route('settings.edit'))
        ->put(route('settings.update'), validSettingsPayload([
            'current_password' => 'WrongPassword',
            'medicine_edit_pin' => '9999',
            'medicine_edit_pin_confirmation' => '9999',
        ]))
        ->assertRedirect(route('settings.edit'))
        ->assertSessionHasErrors('current_password');

    expect(Setting::query()->where('key', 'medicine_edit_pin')->value('value'))->toBe($pinHash)
        ->and(Setting::value('system_name'))->toBeNull();
});

test('ordinary settings changes do not require or modify a configured PIN', function () {
    $administrator = administratorWithSettingsAccess();
    $pinHash = Hash::make('2468');
    Setting::factory()->create([
        'key' => 'medicine_edit_pin',
        'value' => $pinHash,
        'type' => 'secret',
        'group' => 'security',
    ]);

    $this->actingAs($administrator)
        ->put(route('settings.update'), validSettingsPayload([
            'system_name' => 'Updated MediStock',
            'medicine_edit_pin' => '',
            'medicine_edit_pin_confirmation' => '',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Setting::query()->where('key', 'medicine_edit_pin')->value('value'))->toBe($pinHash)
        ->and(Setting::value('system_name'))->toBe('Updated MediStock');
});
