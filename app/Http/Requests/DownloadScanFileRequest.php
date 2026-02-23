<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DownloadScanFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'scanId' => $this->route('scanId'),
            'type' => $this->route('type'),
        ]);
    }

    public function rules(): array
    {
        return [
            'scanId' => ['required', 'uuid', 'exists:scans,id'],
            'type' => ['required', 'string', 'in:glb,usdz'],
        ];
    }
}
