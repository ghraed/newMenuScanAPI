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
            'targetType' => ['required', 'string', 'in:dish'],
            'scaleMeters' => ['required', 'numeric'],
            'slotsTotal' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
