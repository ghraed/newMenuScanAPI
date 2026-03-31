<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'deviceId' => ['nullable', 'string', 'max:255'],
            'dishId' => ['nullable', 'integer', 'exists:dishes,id'],
            'targetType' => ['required', 'string', 'in:dish,juice'],
            'scaleMeters' => ['required', 'numeric'],
            'slotsTotal' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
