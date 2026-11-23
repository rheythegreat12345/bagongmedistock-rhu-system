<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function edit(): View
    {
        $settings = Setting::query()->pluck('value', 'key');
        $medicineEditPinConfigured = filled($settings->pull('medicine_edit_pin'));

        return view('settings.edit', [
            'settings' => $settings,
            'medicineEditPinConfigured' => $medicineEditPinConfigured,
        ]);
    }

    public function update(Request $request, AuditService $auditService): RedirectResponse
    {
        $pinAlreadyConfigured = filled(Setting::query()->where('key', 'medicine_edit_pin')->value('value'));
        $changingPin = $request->filled('medicine_edit_pin');
        $validator = Validator::make($request->all(), [
            'system_name' => ['required', 'string', 'max:255'],
            'facility_name' => ['required', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'low_stock_threshold' => ['required', 'integer', 'min:0', 'max:10000'],
            'expiration_warning_days' => ['required', 'integer', 'min:1', 'max:365'],
            'date_format' => ['required', Rule::in(['M d, Y', 'd/m/Y', 'm/d/Y', 'Y-m-d'])],
            'timezone' => ['required', 'timezone'],
            'notification_low_stock' => ['required', 'boolean'],
            'notification_expiration' => ['required', 'boolean'],
            'default_theme' => ['required', 'in:light,dark,system'],
            'current_password' => [
                Rule::requiredIf(fn (): bool => $pinAlreadyConfigured && $changingPin),
                Rule::prohibitedIf(fn (): bool => ! $changingPin),
                'nullable',
                'string',
            ],
            'medicine_edit_pin' => [
                Rule::requiredIf(fn (): bool => ! $pinAlreadyConfigured),
                'nullable',
                'digits:4',
                'confirmed',
            ],
        ], [
            'current_password.prohibited' => 'Enter a new PIN before providing the administrator password.',
        ]);

        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput($request->except(['medicine_edit_pin', 'medicine_edit_pin_confirmation', 'current_password']));
        }

        $data = $validator->validated();
        $medicineEditPin = $data['medicine_edit_pin'] ?? null;
        $currentPassword = $data['current_password'] ?? null;
        unset($data['medicine_edit_pin'], $data['current_password']);

        if (filled($medicineEditPin) && $pinAlreadyConfigured) {
            $user = $request->user();

            if (! $user || ! Auth::validate(['email' => $user->email, 'password' => (string) $currentPassword])) {
                return back()
                    ->withInput($request->except(['medicine_edit_pin', 'medicine_edit_pin_confirmation', 'current_password']))
                    ->withErrors(['current_password' => 'The current administrator password is incorrect.']);
            }
        }

        $booleanKeys = ['notification_low_stock', 'notification_expiration'];
        $integerKeys = ['low_stock_threshold', 'expiration_warning_days'];

        foreach ($data as $key => $value) {
            Setting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'value' => in_array($key, $booleanKeys, true) ? ($request->boolean($key) ? '1' : '0') : (string) $value,
                    'type' => match (true) {
                        in_array($key, $booleanKeys, true) => 'boolean',
                        in_array($key, $integerKeys, true) => 'integer',
                        default => 'string',
                    },
                    'group' => in_array($key, ['system_name', 'facility_name', 'contact_phone', 'contact_email', 'address'], true) ? 'general' : 'inventory',
                    'is_public' => in_array($key, ['system_name', 'facility_name'], true),
                ],
            );
        }

        if (filled($medicineEditPin)) {
            Setting::query()->updateOrCreate(
                ['key' => 'medicine_edit_pin'],
                [
                    'value' => Hash::make($medicineEditPin),
                    'type' => 'secret',
                    'group' => 'security',
                    'is_public' => false,
                ],
            );
            $auditService->record('authorization_pin_updated', 'Updated the shared medicine authorization PIN.');
        }

        $auditService->record('settings_updated', 'Updated system settings.', null, null, $data);

        return back()->with('success', 'Settings saved successfully.');
    }
}
