<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class ValidateAssignmentImportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'period' => ['required', 'string', 'regex:/^\d{6}$/'],
            'file' => ['required', 'file', 'max:'.config('analytics.import_max_kb')],
        ];
    }
}
