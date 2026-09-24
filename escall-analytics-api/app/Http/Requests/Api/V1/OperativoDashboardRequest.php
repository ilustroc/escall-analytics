<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class OperativoDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'asesor' => ['nullable', 'array'],
            'asesor.*' => ['string', 'max:160'],
            'equipo' => ['nullable', 'array'],
            'equipo.*' => ['string', 'max:160'],
            'cosecha' => ['nullable', 'array'],
            'cosecha.*' => ['string', 'max:160'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $date = $this->validated('fecha') ?? now((string) config('analytics.timezone'))->toDateString();

        return [
            'fechaDesde' => $date,
            'fechaHasta' => $date,
            'asesor' => $this->validated('asesor', []),
            'cosecha' => $this->validated('cosecha', []),
            'nivel1' => [],
            'nivel2' => [],
            'equipo' => $this->validated('equipo', []),
        ];
    }
}
