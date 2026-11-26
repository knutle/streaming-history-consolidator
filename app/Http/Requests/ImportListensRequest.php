<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImportListensRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'source' => ['required_without:files', 'in:spotify,apple,apple_zip'],
            'file' => ['required_without:files', 'file', 'max:1048576'],
            'files' => ['required_without:file', 'array', 'min:1', 'max:20'],
            'files.*' => ['required', 'file', 'max:1048576'],
        ];
    }
}
