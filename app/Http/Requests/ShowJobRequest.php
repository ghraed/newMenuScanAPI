<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShowJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'jobId' => $this->route('jobId'),
        ]);
    }

    public function rules(): array
    {
        return [
            'jobId' => ['required', 'uuid', 'exists:jobs,id'],
        ];
    }
}
