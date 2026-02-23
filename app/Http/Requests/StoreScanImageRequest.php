<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreScanImageRequest extends FormRequest
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
            'slot' => ['required', 'integer', 'min:0'],
            'heading' => ['required', 'numeric'],
            'image' => ['required', 'file', 'mimes:jpg,jpeg'],
        ];
    }
}
