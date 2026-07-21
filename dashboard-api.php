<?php
/**
 * dashboard-api.php  ·  API JSON para el dashboard reactivo
 * Devuelve el detalle liviano del CMN (todos los centros) para cross-filter en cliente.
 * Reutiliza CmnQuery: misma lógica de ejecutado, estados y diferencia que el reporte.
 *
 * ESTADOS (3, iguales a index.php): PROGRAMADO · MODIFICADO · EJECUTADO.
 * Llegan ya clasificados desde la capa Query en la columna ESTADO_FASE.
 *
 *   GET dashboard-api.php?anio=2026            -> JSON
 *   GET dashboard-api.php?anio=2026&fresh=1    -> ignora caché
 *
 * CONFIGURACIÓN: servidor, base, SEC_EJEC, CACHE_TTL y MAX_ROWS salen del .env
 * a través de config.php. No hay credenciales escritas en este archivo.
 *
 * SEGURIDAD: exige sesión activa. A diferencia de las vistas, aquí NO se redirige
 * al login (el fetch recibiría el HTML del formulario y reventaría al parsear):
 * se responde 401 con JSON y el dashboard muestra el aviso de sesión expirada.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/CmnQuery.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/* ---- SEGURIDAD: sin sesión no se entrega ni un dato ---- */
$auth = new Auth();
if (!$auth->logueado()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sesión no válida o expirada.', 'login' => 'login.php'],
                     JSON_UNESCAPED_UNICODE);
    exit;
}
$USR = $auth->usuario();

$anio  = (int)($_GET['anio'] ?? ANIO_PROG);
$fresh = !empty($_GET['fresh']);
$cache = sys_get_temp_dir() . "/cmn_dash_v2_" . SEC_EJEC . "_{$anio}.json";

// Servir de caché si está fresca
if (!$fresh && is_file($cache) && (time() - filemtime($cache)) < CACHE_TTL) {
    echo file_get_contents($cache);
    exit;
}

try {
    $q    = new CmnQuery(DB_SERVER, DB_NAME, DB_USER, DB_PASS);
    $ejec = $q->ejecYear($anio, SEC_EJEC);
    if ($ejec === null) throw new RuntimeException("No hay CMN programado para el año {$anio}.");

    /* rows() devuelve ['rows'=>…, 'total'=>…] y pagina por defecto a 50 filas:
       hay que pedir explícitamente la página completa. */
    $res  = $q->rows($anio, $ejec, SEC_EJEC, null,   // todos los centros
                     '', '', '', '', '',             // tipo, q, meta, act, fase
                     'act_item', 1, MAX_ROWS);
    $rows = $res['rows'];

    $FASES = ['PROGRAMADO','MODIFICADO','EJECUTADO'];
    $items = [];
    foreach ($rows as $r) {
        $prog = (float)($r['IMPORTE_PROG'] ?? 0);
        $mod  = (float)($r['IMPORTE_MOD']  ?? 0);
        $ejc  = (float)($r['IMPORTE_EJEC'] ?? 0);
        $dif  = (float)($r['DIFERENCIA']   ?? 0);   // saldo por ejecutar (puede ser negativo)
        $fase = (string)($r['ESTADO_FASE'] ?? '');
        if (!in_array($fase, $FASES, true)) $fase = 'PROGRAMADO';

        $items[] = [
            'cc'     => (string)($r['CCOSTO_COD'] ?? ''),
            'ccn'    => (string)($r['CCOSTO_NOMBRE'] ?? ''),
            'meta'   => (string)($r['META'] ?? ''),
            'act'    => (string)($r['ACTIV_OPERAT_COD'] ?? ''),
            'gen'    => (string)($r['GENERICA'] ?? ''),
            'clasif' => (string)($r['CLASIF_COD'] ?? ''),
            'item'   => (string)($r['NOMBRE_ITEM'] ?? ''),
            'tipo'   => (string)($r['TIPO_BIEN'] ?? ''),
            'fase'   => $fase,                                  // PROGRAMADO/MODIFICADO/EJECUTADO
            'cmn'    => (string)($r['ESTADO_CMN'] ?? ''),        // ANTIGUO/INCLUIDO/EXCLUIDO/MODIFICADO
            'orden'  => (string)($r['ESTADO_ORDEN'] ?? ''),      // trazabilidad de O/C u O/S
            'prog'   => $prog,
            'mod'    => $mod,
            'ejec'   => $ejc,
            'dif'    => $dif,
        ];
    }

    $out  = ['ok' => true, 'anio' => $anio, 'anioEjec' => $ejec,
             'generado' => date('c'), 'total' => count($items), 'items' => $items];
    $json = json_encode($out, JSON_UNESCAPED_UNICODE);

    /* La caché va al directorio temporal del sistema, fuera del docroot, así que
       no es descargable por URL aunque contenga los montos de la entidad. */
    @file_put_contents($cache, $json);
    @chmod($cache, 0600);
    echo $json;

} catch (Throwable $e) {
    http_response_code(500);
    error_log('[dashboard-api] ' . $e->getMessage());
    /* Al cliente solo un mensaje genérico: el detalle podría revelar la cadena
       de conexión o nombres de tablas del SIGA. En desarrollo (APP_DEBUG=true)
       sí se envía completo para poder depurar. */
    echo json_encode(['ok' => false,
        'error' => APP_DEBUG ? $e->getMessage()
                             : 'No se pudo obtener la información. Revise el log del servidor.'],
        JSON_UNESCAPED_UNICODE);
}