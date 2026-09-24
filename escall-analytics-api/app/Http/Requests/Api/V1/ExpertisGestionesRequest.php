<?php

namespace App\Http\Requests\Api\V1;

final class ExpertisGestionesRequest extends AnalyticsFiltersRequest
{
    public function rules(): array
    {
        return array_merge($this->analyticsFilterRules(), [
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'in:25,50,100'],
            'search' => ['nullable', 'string', 'max:120'],
            'sortField' => ['nullable', 'string', 'in:fechaLlamada,hora,dni,nombreCliente,asesor,cosecha,nivel1,nivel2,fechaCompromiso,monto,medioGestion,telefono,equipo'],
            'sortDirection' => ['nullable', 'string', 'in:asc,desc'],
        ]);
    }
}
