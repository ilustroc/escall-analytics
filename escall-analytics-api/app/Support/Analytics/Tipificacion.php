<?php

namespace App\Support\Analytics;

final class Tipificacion
{
    /** @var array<string, array{peso: int, gestion: string}> */
    private const CATALOG = [
        'PPC' => ['peso' => 1, 'gestion' => 'CEF'],
        'PPM' => ['peso' => 2, 'gestion' => 'CEF'],
        'PAR' => ['peso' => 3, 'gestion' => 'CEF'],
        'VLL' => ['peso' => 4, 'gestion' => 'CEF'],
        'RPP' => ['peso' => 5, 'gestion' => 'CEF'],
        'CAN' => ['peso' => 6, 'gestion' => 'CEF'],
        'TAT' => ['peso' => 7, 'gestion' => 'CEF'],
        'REN' => ['peso' => 8, 'gestion' => 'CEF'],
        'REC' => ['peso' => 9, 'gestion' => 'CEF'],
        'FAL' => ['peso' => 10, 'gestion' => 'CNE'],
        'MCT' => ['peso' => 11, 'gestion' => 'CNE'],
        'DES' => ['peso' => 12, 'gestion' => 'NOC'],
        'GRB' => ['peso' => 13, 'gestion' => 'NOC'],
        'HOM' => ['peso' => 14, 'gestion' => 'NOC'],
        'ILC' => ['peso' => 15, 'gestion' => 'NOC'],
        'NOC' => ['peso' => 16, 'gestion' => 'NOC'],
        'NTT' => ['peso' => 17, 'gestion' => 'NOC'],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::CATALOG);
    }

    public static function weightExpression(string $column): string
    {
        return self::caseExpression($column, 'peso', '999');
    }

    public static function gestionExpression(string $column): string
    {
        return self::caseExpression($column, 'gestion', "'NOC'");
    }

    public static function nivel1(string $gestion): string
    {
        return match ($gestion) {
            'CEF' => 'CONTACTO EFECTIVO',
            'CNE' => 'CONTACTO NO EFECTIVO',
            default => 'NO CONTACTO',
        };
    }

    /** @return array<string, int> */
    public static function weights(): array
    {
        return array_map(static fn (array $item): int => $item['peso'], self::CATALOG);
    }

    private static function caseExpression(string $column, string $key, string $fallback): string
    {
        $clauses = [];

        foreach (self::CATALOG as $code => $item) {
            $value = $key === 'gestion' ? "'{$item[$key]}'" : (string) $item[$key];
            $clauses[] = "WHEN '{$code}' THEN {$value}";
        }

        return "CASE {$column} ".implode(' ', $clauses)." ELSE {$fallback} END";
    }
}
