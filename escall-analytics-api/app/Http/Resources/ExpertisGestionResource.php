<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin object */
final class ExpertisGestionResource extends JsonResource
{
    /** @return array<string, int|float|string|null> */
    public function toArray(Request $request): array
    {
        return [
            'idGestion' => (int) $this->id_gestion,
            'canalGestion' => (string) $this->canal_gestion,
            'canalAsignacion' => (string) $this->canal_asignacion,
            'dni' => (string) $this->dni,
            'nombreCliente' => $this->nombre_cliente === null ? null : (string) $this->nombre_cliente,
            'cartera' => $this->cartera === null ? null : (string) $this->cartera,
            'asesor' => (string) $this->asesor,
            'equipo' => (string) $this->equipo,
            'telefono' => $this->telefono === null ? null : (string) $this->telefono,
            'fechaLlamada' => (string) $this->fecha_llamada,
            'cosecha' => $this->cosecha === null ? null : (string) $this->cosecha,
            'hora' => $this->hora === null ? null : (string) $this->hora,
            'nivel1' => (string) $this->nivel1,
            'nivel2' => (string) $this->nivel2,
            'fechaCompromiso' => $this->fecha_compromiso === null ? null : (string) $this->fecha_compromiso,
            'monto' => (float) $this->monto,
            'observacion' => $this->observacion === null ? null : (string) $this->observacion,
            'medioGestion' => (string) $this->medio_gestion,
        ];
    }
}
