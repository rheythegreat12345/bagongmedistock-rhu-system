<?php

namespace App\Http\Requests;

use App\Models\Setting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMedicineRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $medicine = $this->route('medicine');
        $existingMaximum = $medicine?->maximum_stock_level ?? 100;

        $this->merge([
            'dosage_form' => $this->input('dosage_form', $medicine?->dosage_form ?? 'Unspecified'),
            'maximum_stock_level' => $existingMaximum,
        ]);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('medicines.edit') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $medicine = $this->route('medicine');

        return [
            'medicine_category_id' => ['required', 'exists:medicine_categories,id'],
            'medicine_code' => ['required', 'string', 'max:30', Rule::unique('medicines', 'medicine_code')->ignore($medicine)],
            'barcode' => ['nullable', 'string', 'max:80', Rule::unique('medicines', 'barcode')->ignore($medicine)],
            'generic_name' => ['required', 'string', 'max:255'],
            'brand_name' => ['nullable', 'string', 'max:255'],
            'dosage' => ['nullable', 'string', 'max:255'],
            'strength' => ['nullable', 'string', 'max:255'],
            'dosage_form' => ['required', 'string', 'max:80'],
            'unit' => ['required', 'string', 'max:40'],
            'box_size' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'minimum_stock_level' => ['required', 'integer', 'min:0'],
            'maximum_stock_level' => ['required', 'integer', 'gte:minimum_stock_level'],
            'reorder_level' => ['required', 'integer', 'min:0', 'lte:maximum_stock_level'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'reference_price' => ['nullable', 'numeric', 'min:0'],
            'storage_condition' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'in:active,inactive'],
            'authorization_pin' => ['required', 'digits:4'],
        ];
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        $this->replace($this->except(['authorization_pin']));
        $this->query->remove('authorization_pin');
        $this->json()->remove('authorization_pin');

        $request = request();
        $request->replace($request->except(['authorization_pin']));
        $request->query->remove('authorization_pin');
        $request->json()->remove('authorization_pin');

        parent::failedValidation($validator);
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('authorization_pin')) {
                return;
            }

            $pinHash = Setting::value('medicine_edit_pin');

            if (! filled($pinHash) || ! Hash::check((string) $this->input('authorization_pin'), (string) $pinHash)) {
                $validator->errors()->add('authorization_pin', 'The authorization PIN is incorrect or has not been configured by an administrator.');
            }
        }];
    }
}
