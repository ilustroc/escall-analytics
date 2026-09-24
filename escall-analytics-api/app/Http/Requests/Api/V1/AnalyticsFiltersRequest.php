<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class AnalyticsFiltersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    protected function analyticsFilterRules(): array
    {
        return [
            'fechaDesde' => ['nullable', 'date_format:Y-m-d'],
            'fechaHasta' => ['nullable', 'date_format:Y-m-d'],
            'asesor' => ['nullable', 'array'],
            'asesor.*' => ['string', 'max:160'],
            'cosecha' => ['nullable', 'array'],
            'cosecha.*' => ['string', 'max:160'],
            'nivel1' => ['nullable', 'array'],
            'nivel1.*' => ['string', 'max:80'],
            'nivel2' => ['nullable', 'array'],
            'nivel2.*' => ['string', 'max:20'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $range = $this->defaultDateRange();
            $from = $this->input('fechaDesde') ?: $range['fechaDesde'];
            $to = $this->input('fechaHasta') ?: $range['fechaHasta'];

            if (is_string($from) && is_string($to) && $from > $to) {
                $validator->errors()->add('fechaHasta', 'La fechaHasta debe ser posterior o igual a fechaDesde.');
            }
        }];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $range = $this->defaultDateRange();

        return [
            'fechaDesde' => $this->validated('fechaDesde') ?? $range['fechaDesde'],
            'fechaHasta' => $this->validated('fechaHasta') ?? $range['fechaHasta'],
            'asesor' => $this->validated('asesor', []),
            'cosecha' => $this->validated('cosecha', []),
            'nivel1' => $this->validated('nivel1', []),
            'nivel2' => $this->validated('nivel2', []),
        ];
    }

    /** @return array{fechaDesde: string, fechaHasta: string} */
    private function defaultDateRange(): array
    {
        $today = now((string) config('analytics.timezone'));

        return [
            'fechaDesde' => $today->copy()->startOfMonth()->toDateString(),
            'fechaHasta' => $today->toDateString(),
        ];
    }
}
