<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE `gestiones`
                ADD COLUMN `dni_normalizado` varchar(20)
                    GENERATED ALWAYS AS (
                        CASE
                            WHEN TRIM(`dni`) REGEXP '^[0-9]+$' AND CHAR_LENGTH(TRIM(`dni`)) < 8
                                THEN LPAD(TRIM(`dni`), 8, '0')
                            ELSE TRIM(`dni`)
                        END
                    ) STORED,
                ADD INDEX `idx_gestiones_dni_normalizado` (`dni_normalizado`)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `Asignacion_Expertis_Historico`
                ADD COLUMN `documento_normalizado` varchar(20)
                    GENERATED ALWAYS AS (
                        CASE
                            WHEN TRIM(`Documento_RUC`) REGEXP '^[0-9]+$' AND CHAR_LENGTH(TRIM(`Documento_RUC`)) < 8
                                THEN LPAD(TRIM(`Documento_RUC`), 8, '0')
                            ELSE TRIM(`Documento_RUC`)
                        END
                    ) STORED,
                ADD INDEX `idx_aeh_periodo_documento_normalizado` (`FEC_PRO`, `documento_normalizado`)
        SQL);
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE `Asignacion_Expertis_Historico` DROP INDEX `idx_aeh_periodo_documento_normalizado`, DROP COLUMN `documento_normalizado`');
        DB::statement('ALTER TABLE `gestiones` DROP INDEX `idx_gestiones_dni_normalizado`, DROP COLUMN `dni_normalizado`');
    }
};
