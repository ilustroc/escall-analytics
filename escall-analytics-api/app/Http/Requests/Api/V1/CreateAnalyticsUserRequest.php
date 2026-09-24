<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AnalyticsUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateAnalyticsUserRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:analytics_users,email'],
            'role' => ['required', 'string', Rule::in(AnalyticsUser::manageableRoles())],
        ];
    }
}
