<?php
/**
 * dashboard-api.php  ·  API JSON para el dashboard reactivo
 * Devuelve el detalle liviano del CMN (todos los centros) para cross-filter en cliente.
 * Reutiliza CmnQuery (misma lógica de ejecutado/fases del reporte).
 *
 *   GET dashboard-api.php?anio=2026            -> JSON
 *   GET dashboard-api.php?anio=2026&fresh=1    -> ignora caché
 */

require __DIR__ . '/CmnQuery.php';

/* ----------------- CONFIG ----------------- */
const DB_SERVER = 'localhost';
const DB_NAME   = 'SIGA_104';
const DB_USER   = '';
const DB_PASS   = '';
const SEC_EJEC  = 104;
const CACHE_TTL = 300; // segundos
/* ------------------------------------------ */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

$anio  = (int)($_GET['anio'] ?? 2026);
$fresh = !empty($_GET['fresh']);
$cache = sys_get_temp_dir() . "/cmn_dash_{$anio}.json";

// Servir de caché si está fresca
if (!$fresh && is_file($cache) && (time() - filemtime($cache)) < CACHE_TTL) {
    echo file_get_contents($cache);
    exit;
}

$faseDe = function (string $estado): string {
    $e = strtoupper($estado);
    if (str_contains($e, 'DEVENGADO'))    return 'DEVENGADO';
    if (str_contains($e, 'COMPROMETIDO')) return 'COMPROMETIDO';
    if (str_contains($e, 'CERTIFICADO'))  return 'CERTIFICADO';
    return 'PENDIENTE';
};

try {
    $q    = new CmnQuery(DB_SERVER, DB_NAME, DB_USER, DB_PASS);
    $ejec = $q->ejecYear($anio, SEC_EJEC);
    if ($ejec === null) throw new RuntimeException("No hay CMN programado para el año {$anio}.");

    $rows  = $q->rows($anio, $ejec, SEC_EJEC, null); // todos los centros
    $items = [];
    foreach ($rows as $r) {
        $mod  = (float)($r['IMPORTE_MOD'] ?? 0);
        $ejecM= (float)($r['IMPORTE_EJEC'] ?? 0);
        $items[] = [
            'cc'     => (string)($r['CCOSTO_COD'] ?? ''),
            'ccn'    => (string)($r['CCOSTO_NOMBRE'] ?? ''),
            'meta'   => (string)($r['META'] ?? ''),
            'act'    => (string)($r['ACTIV_OPERAT_COD'] ?? ''),
            'gen'    => (string)($r['GENERICA'] ?? ''),
            'clasif' => (string)($r['CLASIF_COD'] ?? ''),
            'item'   => (string)($r['NOMBRE_ITEM'] ?? ''),
            'estado' => (string)($r['ESTADO_ORDEN'] ?? ''),
            'fase'   => $faseDe((string)($r['ESTADO_ORDEN'] ?? '')),
            'prog'   => (float)($r['IMPORTE_PROG'] ?? 0),
            'mod'    => $mod,
            'ejec'   => $ejecM,
            'pend'   => max($mod - $ejecM, 0),
        ];
    }

    $out  = ['ok' => true, 'anio' => $anio, 'anioEjec' => $ejec,
             'generado' => date('c'), 'total' => count($items), 'items' => $items];
    $json = json_encode($out, JSON_UNESCAPED_UNICODE);
    @file_put_contents($cache, $json);
    echo $json;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}