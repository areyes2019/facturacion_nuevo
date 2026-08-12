<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use PDO;
use RuntimeException;
use ZipArchive;

#[Signature('catalogos-sat:actualizar')]
#[Description('Descarga y reconstruye la base sqlite de catálogos SAT (régimen fiscal, código postal, clave de producto/servicio, clave de unidad, uso de CFDI y forma de pago) usada por los módulos de clientes, artículos y facturación.')]
class ActualizarCatalogosSat extends Command
{
    private const RESOURCE_ZIP_URL = 'https://github.com/phpcfdi/resources-sat-catalogs/archive/master.zip';

    /**
     * Tablas que realmente usa este proyecto (ver 004-gestion-clientes.md,
     * 006-gestion-articulos.md y 007-facturacion.md); el recurso completo trae ~180 catálogos.
     */
    private const TABLAS = [
        'cfdi_40_regimenes_fiscales',
        'cfdi_40_codigos_postales',
        'cfdi_40_productos_servicios',
        'cfdi_40_claves_unidades',
        'cfdi_40_usos_cfdi',
        'cfdi_40_formas_pago',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $destino = config('services.sat_catalogos.database_path');

        $this->info('Descargando catálogos SAT desde '.self::RESOURCE_ZIP_URL.'...');
        $response = Http::timeout(120)->get(self::RESOURCE_ZIP_URL);

        if ($response->failed()) {
            $this->error('No se pudo descargar el recurso de catálogos SAT.');

            return self::FAILURE;
        }

        $zipPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('sat-catalogos-').'.zip';
        file_put_contents($zipPath, $response->body());

        try {
            $sql = $this->extraerSqlDeCatalogos($zipPath);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            unlink($zipPath);

            return self::FAILURE;
        }

        unlink($zipPath);

        $dbTemporal = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('sat-catalogos-').'.sqlite';

        $pdo = new PDO('sqlite:'.$dbTemporal, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec($sql);
        unset($pdo);

        rename($dbTemporal, $destino);

        $pdo = new PDO('sqlite:'.$destino);
        $this->info("Catálogos actualizados en {$destino}:");
        foreach (self::TABLAS as $tabla) {
            $total = (int) $pdo->query("select count(*) from {$tabla}")->fetchColumn();
            $this->line("  - {$tabla}: {$total} entradas");
        }

        return self::SUCCESS;
    }

    private function extraerSqlDeCatalogos(string $zipPath): string
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('No se pudo abrir el zip descargado de catálogos SAT.');
        }

        $sql = '';
        foreach (['schemas', 'data'] as $carpeta) {
            foreach (self::TABLAS as $tabla) {
                $contenido = $this->leerEntradaPorSufijo($zip, "database/{$carpeta}/{$tabla}.sql");
                $sql .= $contenido."\n";
            }
        }

        $zip->close();

        return $sql;
    }

    private function leerEntradaPorSufijo(ZipArchive $zip, string $sufijo): string
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombre = $zip->getNameIndex($i);
            if ($nombre !== null && str_ends_with($nombre, $sufijo)) {
                $contenido = $zip->getFromIndex($i);
                if ($contenido !== false) {
                    return $contenido;
                }
            }
        }

        throw new RuntimeException("No se encontró la entrada '{$sufijo}' en el zip de catálogos SAT.");
    }
}
