<?php

namespace App\Http\Requests\Api\V1;

final class ReportGestionesRequest extends AnalyticsFiltersRequest
{
    public function rules(): array
    {
        return array_merge($this->analyticsFilterRules(), [
            'cartera' => ['nullable', 'array'],
            'cartera.*' => ['string', 'max:160'],
            'dni' => ['nullable', 'string', 'max:20'],
            'telefono' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'in:25,50,100'],
            'sortField' => ['nullable', 'string', 'in:idGestion,dni,asesor,fechaLlamada,nivel1,nivel2,monto,puntaje'],
            'sortDirection' => ['nullable', 'string', 'in:asc,desc'],
        ]);
    }

    /** @return array<string, mixed> */
    public function reportFilters(): array
    {
        return [...$this->filters(),
            'cartera' => $this->validated('cartera', []),
            'dni' => $this->validated('dni', ''),
            'telefono' => $this->validated('telefono', ''),
            'search' => $this->validated('search', ''),
        ];
    }
}
