<?php

namespace App\Http\Requests\Api\V1;

final class ReportPaymentsRequest extends AnalyticsFiltersRequest
{
    public function rules(): array
    {
        return array_merge($this->analyticsFilterRules(), [
            'codigo' => ['nullable', 'string', 'max:100'],
            'dni' => ['nullable', 'string', 'max:20'],
            'ejecutivo' => ['nullable', 'string', 'max:150'],
            'tipoAcuerdo' => ['nullable', 'array'],
            'tipoAcuerdo.*' => ['string', 'in:PAR,PPC,PPM'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'in:25,50,100'],
            'sortField' => ['nullable', 'string', 'in:fechaPago,codigo,dni,monto,ejecutivo,tipoAcuerdo'],
            'sortDirection' => ['nullable', 'string', 'in:asc,desc'],
        ]);
    }

    /** @return array<string, mixed> */
    public function reportFilters(): array
    {
        $range = $this->filters();

        return [
            'fechaDesde' => $range['fechaDesde'],
            'fechaHasta' => $range['fechaHasta'],
            'codigo' => $this->validated('codigo', ''),
            'dni' => $this->validated('dni', ''),
            'ejecutivo' => $this->validated('ejecutivo', ''),
            'tipoAcuerdo' => $this->validated('tipoAcuerdo', []),
            'search' => $this->validated('search', ''),
        ];
    }
}
