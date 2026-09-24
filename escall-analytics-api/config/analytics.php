<?php

return [
    'timezone' => env('ANALYTICS_TIMEZONE', 'America/Lima'),
    'expertis_team' => env('ANALYTICS_EXPERTIS_TEAM', 'KATHERINE HUAMAN'),
    'assignment_table' => 'Asignacion_Expertis_Historico',
    'import_max_mb' => (int) env('ANALYTICS_IMPORT_MAX_MB', 150),
    'import_max_kb' => (int) env('ANALYTICS_IMPORT_MAX_MB', 150) * 1024,
    'import_max_rows' => (int) env('ANALYTICS_IMPORT_MAX_ROWS', 500000),
    'import_chunk_size' => (int) env('ANALYTICS_IMPORT_CHUNK_SIZE', 1200),
    // Payment rows contain fewer business columns than assignments. 2,000
    // remains below MariaDB prepared-parameter limits while reducing remote
    // round trips during validation.
    'payment_import_chunk_size' => (int) env('ANALYTICS_PAYMENT_IMPORT_CHUNK_SIZE', 2000),
    'import_max_uncompressed_mb' => (int) env('ANALYTICS_IMPORT_MAX_UNCOMPRESSED_MB', 1024),
    'import_storage_directory' => 'imports',
    'expertis_report' => [
        'to' => array_values(array_filter(array_map('trim', explode(',', (string) env('EXPERTIS_REPORT_TO', 'alejandro.romero@goexpertis.com,Gina.Georffino@goexpertis.com'))))),
        'cc' => array_values(array_filter(array_map('trim', explode(',', (string) env('EXPERTIS_REPORT_CC', 'katherine.supervision@escallperu.com,cespinozag@escallperu.com'))))),
        'timezone' => env('EXPERTIS_REPORT_TIMEZONE', 'America/Lima'),
    ],
];
