<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartBackgroundRemovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $selection = $this->input('objectSelection');

        if ($selection === null && $this->has('object_selection')) {
            $selection = $this->input('object_selection');
        }

        $this->merge([
            'scanId' => $this->route('scanId'),
            'objectSelection' => $selection,
        ]);
    }

    public function rules(): array
    {
        return [
            'scanId' => ['required', 'uuid', 'exists:scans,id'],
            'objectSelection' => ['nullable', 'array'],
            'objectSelection.method' => ['required_with:objectSelection', 'string', 'in:tap,box'],
            'objectSelection.selectedAt' => ['nullable', 'numeric'],
            'objectSelection.point' => ['nullable', 'array'],
            'objectSelection.point.x' => ['required_with:objectSelection.point', 'numeric', 'min:0', 'max:1'],
            'objectSelection.point.y' => ['required_with:objectSelection.point', 'numeric', 'min:0', 'max:1'],
            'objectSelection.bbox' => ['required_with:objectSelection', 'array'],
            'objectSelection.bbox.x' => ['required_with:objectSelection.bbox', 'numeric', 'min:0', 'max:1'],
            'objectSelection.bbox.y' => ['required_with:objectSelection.bbox', 'numeric', 'min:0', 'max:1'],
            'objectSelection.bbox.width' => ['required_with:objectSelection.bbox', 'numeric', 'gt:0', 'max:1'],
            'objectSelection.bbox.height' => ['required_with:objectSelection.bbox', 'numeric', 'gt:0', 'max:1'],
        ];
    }
}
