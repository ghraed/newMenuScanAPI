<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShowScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'scanId' => $this->route('scanId'),
        ]);
    }

    public function rules(): array
    {
        return [
            'scanId' => ['required', 'uuid', 'exists:scans,id'],
        ];
    }
}
