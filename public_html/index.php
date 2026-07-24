<?php
/**
 * VIEW + ENTRADA · SIGA-REPORTER · Tabla/Kanban con paginado y filtros de servidor
 * 3 capas: Query · Service · View.  Servir: php -S localhost:8000 -t E:\SIGA-REPORTER
 *
 * ESTADOS (3): Programado · Modificado · Ejecutado. El estado de cada ítem llega
 * ya clasificado desde la capa Query en la columna ESTADO_FASE.
 *
 * CARGA BAJO DEMANDA: al abrir la pantalla NO se consulta el SIGA (traer todos
 * los centros es la operación más costosa). Se muestra un estado inicial y los
 * datos llegan al elegir un centro o al pulsar "Cargar todos los centros".
 *
 * CONFIGURACIÓN: servidor, base, SEC_EJEC y año por defecto salen del .env a
 * través de config.php. No hay credenciales escritas en este archivo.
 * Usa los partials compartidos: head · sidebar · header · accesos (Ctrl+K).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/CmnQuery.php';
require_once __DIR__ . '/ExportService.php';

/* ---- SEGURIDAD: sin sesión no se entra (protege vista, endpoints y export) ---- */
$auth = new Auth();
$auth->exigirLogin();
$USR = $auth->usuario();

$resource  = $_GET['resource'] ?? 'cmn';
$anioProg  = (int)($_GET['anio'] ?? ANIO_PROG);
$ccostoRaw = $_GET['ccosto'] ?? '';
$ccosto    = $ccostoRaw !== '' ? $ccostoRaw : null;
$export    = $_GET['export'] ?? null;
$action    = $_GET['action'] ?? '';

// Filtros de servidor
$fTipo   = in_array($_GET['tipo'] ?? '', ['B','S'], true) ? $_GET['tipo'] : '';
$fQ      = trim((string)($_GET['q'] ?? ''));
$fMeta   = (string)($_GET['meta'] ?? '');
$fAct    = (string)($_GET['act'] ?? '');
$fFase   = (string)($_GET['fase'] ?? '');
$fSort   = (string)($_GET['sort'] ?? 'act_item');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(200, max(10, (int)($_GET['perPage'] ?? 50)));

if ($resource !== 'cmn') { http_response_code(404); exit('Recurso no encontrado'); }

/* Mensaje de error para el navegador: en producción nunca el detalle de la
   excepción (revelaría la cadena de conexión o las tablas del SIGA). */
$errPublico = fn(Throwable $e) => APP_DEBUG
    ? $e->getMessage()
    : 'No se pudo consultar el SIGA. Revise el log del servidor.';

try {
    $q        = new CmnQuery(DB_SERVER, DB_NAME, DB_USER, DB_PASS);
    $anioEjec = $q->ejecYear($anioProg, SEC_EJEC);
    if ($anioEjec === null) throw new RuntimeException("No hay CMN programado para el año {$anioProg}.");
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[index] ' . $e->getMessage());
    echo '<pre style="color:#b91c1c">Error: '.htmlspecialchars($errPublico($e)).'</pre>'; exit;
}

/* ---- EXPORTACIÓN (respeta filtros; sin paginar) ---- */
if ($export === 'excel' || $export === 'pdf') {
    $all = $q->rows($anioProg,$anioEjec,SEC_EJEC,$ccosto,$fTipo,$fQ,$fMeta,$fAct,$fFase,$fSort,1,MAX_ROWS)['rows'];
    $nombre = 'CMN_'.$anioProg.($ccosto ? '_'.str_replace('.','',$ccosto) : '_TODOS');
    // Contexto para que el archivo salga igual que la pantalla (cabecera + bloques).
    $ccNom = 'TODOS LOS CENTROS';
    if ($ccosto) { foreach ($q->centros($anioProg,$anioEjec,SEC_EJEC) as $c) { if ($c['cod']===$ccosto) { $ccNom = $c['cod'].'  ·  '.$c['nombre']; break; } } }
    $meta = ['titulo'=>'CUADRO DE NECESIDADES '.$anioProg.'  (ejecución '.$anioEjec.')',
             'centro'=>$ccNom, 'anio'=>$anioProg, 'agrupar'=>($fSort==='act_item'),
             'entidad'=>APP_ENTIDAD];

    /* Campos visibles elegidos en pantalla (selector de campos). Si ExportService
       aún no los soporta, esta sección es inocua: solo recorta las claves del array. */
    $colsSel = array_values(array_filter(array_map('trim', explode(',', (string)($_GET['cols'] ?? '')))));
    if ($colsSel) {
        $colsSel = array_values(array_intersect($colsSel, array_keys(ExportService::HEADERS)));
        if ($colsSel) {
            $keep = array_flip($colsSel);
            $all  = array_map(fn($r) => array_intersect_key($r, $keep), $all);
            $meta['cols'] = $colsSel;
        }
    }

    if ($export==='excel') ExportService::excel($all,$nombre,$meta);
    else ExportService::pdf($all,'CUADRO DE NECESIDADES '.$anioProg.'  (ejecución '.$anioEjec.')',$meta);
    exit;
}

/* ---- ENDPOINT: historial ---- */
if ($action === 'historial') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        echo json_encode($q->historial($anioProg,$anioEjec,SEC_EJEC,(string)($_GET['cc']??$ccosto),
            (string)($_GET['t']??''),(string)($_GET['g']??''),(string)($_GET['c']??''),
            (string)($_GET['f']??''),(string)($_GET['it']??''),(int)($_GET['meta']??0),(string)($_GET['clasif']??'')), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[index/historial] ' . $e->getMessage());
        echo json_encode(['error'=>$errPublico($e)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ---- ENDPOINT: datos paginados + resumen ---- */
if ($action === 'data') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        $res = $q->rows($anioProg,$anioEjec,SEC_EJEC,$ccosto,$fTipo,$fQ,$fMeta,$fAct,$fFase,$fSort,$page,$perPage);
        $sum = $q->summary($anioProg,$anioEjec,SEC_EJEC,$ccosto,$fTipo,$fQ,$fMeta,$fAct);
        echo json_encode(['rows'=>$res['rows'],'total'=>$res['total'],'page'=>$page,'perPage'=>$perPage,'summary'=>$sum], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[index/data] ' . $e->getMessage());
        echo json_encode(['error'=>$errPublico($e)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ---- RENDER SHELL ---- */
$centros   = $q->centros($anioProg,$anioEjec,SEC_EJEC);
$opts      = $q->opciones($anioProg,$anioEjec,SEC_EJEC,$ccosto);
$H         = ExportService::HEADERS;
$NUM       = array_keys(array_flip(ExportService::NUM));
$jsonH     = json_encode($H, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$jsonNum   = json_encode($NUM, JSON_HEX_TAG);
$jsonCent  = json_encode($centros, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$ccostoNombre='';
foreach ($centros as $c){ if($c['cod']===$ccosto){$ccostoNombre=$c['cod'].'  ·  '.$c['nombre'];break;} }

/* ---- Variables de los partials ---- */
$ANIO   = $anioProg;      // año para sidebar y accesos
$PAGINA = 'cmn';          // clave en partials/nav.php

$TITULO_PAG = "CMN {$anioProg} · Tabla / Kanban";

$TITULO    = 'Cuadro de Necesidades <span class="text-primary">· '.$anioProg.'</span>';
$SUBTITULO = '(ejecución '.$anioEjec.') · <span id="totLbl">…</span>';
$ACCIONES  = '
        <div class="inline-flex rounded-lg border border-gray-300 overflow-hidden text-sm" id="modeSwitch">
          <button data-mode="table" class="px-3 py-2 font-medium">Tabla</button>
          <button data-mode="kanban" class="px-3 py-2 font-medium border-l border-gray-300">Kanban</button>
        </div>
        <a id="expExcel" href="#" class="px-3 py-2 text-sm rounded-lg bg-primary text-white hover:bg-primary-dark">Excel</a>
        <a id="expPdf" href="#" target="_blank" class="px-3 py-2 text-sm rounded-lg bg-secondary text-white hover:bg-secondary-dark">PDF</a>';

include __DIR__ . '/partials/head.php';
?>
<body class="bg-gray-50 text-gray-800">
<div class="flex min-h-screen">
  <?php include __DIR__ . '/partials/sidebar.php'; ?>

  <main class="flex-1 min-w-0 p-3 sm:p-4 flex flex-col">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <!-- Filtros de servidor: año + centro -->
    <form method="get" class="flex flex-col sm:flex-row sm:flex-wrap sm:items-end gap-3 mb-3 bg-white p-3 rounded-xl border border-gray-200">
      <input type="hidden" name="resource" value="cmn">
      <div class="w-full sm:w-auto"><label class="block text-xs text-gray-500 mb-1">Año programado</label>
        <input type="number" name="anio" value="<?= $anioProg ?>" class="input-bordered w-full sm:w-28"></div>
      <div class="w-full sm:flex-1 sm:min-w-[260px] relative" id="ccBox">
        <label class="block text-xs text-gray-500 mb-1">Centro de costo</label>
        <input type="hidden" name="ccosto" id="ccValue" value="<?= htmlspecialchars($ccosto ?? '') ?>">
        <div class="relative">
          <input type="text" id="ccSearch" autocomplete="off" spellcheck="false" placeholder="Escribe código o nombre…" value="<?= htmlspecialchars($ccostoNombre) ?>" class="input-bordered pr-8">
          <button type="button" id="ccClear" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 <?= $ccosto?'':'hidden' ?>">✕</button>
        </div>
        <ul id="ccList" class="hidden absolute z-30 mt-1 w-full max-h-64 overflow-auto bg-white border border-gray-200 rounded-lg shadow-lg text-sm"></ul>
      </div>
      <button class="w-full sm:w-auto px-5 py-3 text-sm rounded-lg bg-primary text-white hover:bg-primary-dark">Consultar</button>
      <button type="button" id="btnAll" class="w-full sm:w-auto px-4 py-3 text-sm rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 whitespace-nowrap">
        <i class="fa-solid fa-layer-group mr-1"></i> Cargar todos los centros
      </button>
    </form>

    <!-- Barra de herramientas (cliente → servidor) -->
    <div class="bg-white rounded-xl border border-gray-200 p-3 mb-3 space-y-3">
      <div id="chips" class="flex flex-wrap gap-2"></div>
      <div class="flex flex-col sm:flex-row gap-2">
        <div class="relative flex-1 min-w-[180px]">
          <input id="q" type="text" value="<?= htmlspecialchars($fQ) ?>" placeholder="Buscar ítem, clasificador u orden…" class="input-bordered pl-9">
          <svg class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" stroke-width="2"/><path d="M21 21l-4-4" stroke-width="2" stroke-linecap="round"/></svg>
        </div>
        <select id="fTipo" class="input-bordered sm:w-40"><option value="">Bien y Servicio</option>
          <option value="B" <?= $fTipo==='B'?'selected':'' ?>>Solo Bienes</option>
          <option value="S" <?= $fTipo==='S'?'selected':'' ?>>Solo Servicios</option></select>
        <select id="fMeta" class="input-bordered sm:w-36"><option value="">Todas las metas</option>
          <?php foreach ($opts['metas'] as $m): ?><option value="<?= htmlspecialchars($m) ?>" <?= $fMeta===$m?'selected':'' ?>>Meta <?= htmlspecialchars($m) ?></option><?php endforeach; ?></select>
        <select id="fAct" class="input-bordered sm:w-40"><option value="">Toda actividad</option>
          <?php foreach ($opts['actividades'] as $a): ?><option value="<?= htmlspecialchars($a) ?>" <?= $fAct===$a?'selected':'' ?>><?= htmlspecialchars($a) ?></option><?php endforeach; ?></select>
        <select id="sort" class="input-bordered sm:w-40">
          <option value="mod_desc">Mayor importe</option><option value="mod_asc">Menor importe</option><option value="item_asc">Nombre A-Z</option><option value="act_item">Actividad + código ítem</option></select>
        <div class="inline-flex items-center gap-1">
          <label class="inline-flex items-center gap-2 px-3 py-2 text-sm rounded-lg border border-gray-300 cursor-pointer select-none whitespace-nowrap">
            <input type="checkbox" id="agrupar" class="accent-primary" checked> Agrupar por actividad
          </label>
          <button id="gExpand" type="button" title="Expandir todo" class="px-2 py-2 text-sm rounded-lg border border-gray-300 hover:bg-gray-50">⊞</button>
          <button id="gCollapse" type="button" title="Contraer todo" class="px-2 py-2 text-sm rounded-lg border border-gray-300 hover:bg-gray-50">⊟</button>

          <!-- ── Selector de campos ── -->
          <div class="relative" id="colBox">
            <button id="btnCols" type="button" title="Elegir qué columnas mostrar"
                    class="inline-flex items-center gap-2 px-3 py-2 text-sm rounded-lg border border-gray-300 hover:bg-gray-50 text-gray-700 whitespace-nowrap">
              <i class="fa-solid fa-table-columns"></i>
              <span class="hidden sm:inline">Campos</span>
              <span id="colCount" class="text-[10px] font-bold bg-primary/15 text-primary-dark rounded-full px-1.5 py-0.5"></span>
            </button>
            <div id="colPanel" class="hidden absolute right-0 z-40 mt-2 w-[320px] bg-white border border-gray-200 rounded-xl shadow-2xl overflow-hidden"></div>
          </div>

          <button id="btnFs" type="button" title="Pantalla completa (F11 · Esc para salir)"
                  class="px-2.5 py-2 text-sm rounded-lg border border-gray-300 hover:bg-gray-50 text-gray-600">
            <i class="fa-solid fa-expand"></i>
          </button>
        </div>
      </div>
    </div>

    <div id="viewTable" class="bg-white rounded-xl border border-gray-200 overflow-auto max-h-[calc(100vh-320px)]" style="-webkit-overflow-scrolling:touch">
      <table class="min-w-full text-xs whitespace-nowrap">
        <thead class="sticky top-0 z-10"><tr id="thead" class="bg-primary text-white"></tr></thead>
        <tbody id="tbody"></tbody>
        <tfoot id="tfoot" class="sticky bottom-0"></tfoot>
      </table>
    </div>
    <div id="viewKanban" class="hidden flex-1 flex gap-3 overflow-x-auto pb-2" style="-webkit-overflow-scrolling:touch"></div>

    <!-- Paginación -->
    <div class="flex items-center justify-between gap-3 mt-3 text-sm">
      <span id="pageInfo" class="text-gray-500"></span>
      <div class="flex items-center gap-2">
        <select id="perPage" class="input-bordered w-auto py-1.5">
          <option value="25">25</option><option value="50" selected>50</option><option value="100">100</option><option value="200">200</option>
        </select>
        <button id="prev" class="px-3 py-1.5 rounded-lg border border-gray-300 hover:bg-gray-100 disabled:opacity-40">‹</button>
        <span id="pageNum" class="px-2 text-gray-600"></span>
        <button id="next" class="px-3 py-1.5 rounded-lg border border-gray-300 hover:bg-gray-100 disabled:opacity-40">›</button>
      </div>
    </div>
  </main>
</div>

<div id="fsBar">
  <i class="fa-solid fa-table-list"></i>
  <span class="text-[13px] font-bold">Cuadro de Necesidades <?= $anioProg ?></span>
  <span id="fsCentro" class="text-[11px] opacity-90 truncate"><?= htmlspecialchars($ccostoNombre ?: 'Todos los centros') ?></span>
  <span id="fsTot" class="text-[11px] opacity-90 ml-auto whitespace-nowrap"></span>
  <button id="fsExit" class="px-2.5 py-1 rounded text-[11px] font-semibold" style="background:rgba(255,255,255,.2)">
    <i class="fa-solid fa-compress mr-1"></i> Salir
  </button>
</div>

<div id="loadBar"><span></span></div>
<div id="loadOv"><div class="loadCard">
  <div class="loadRing"><i class="fa-solid fa-circle-notch"></i><i class="fa-solid fa-file-invoice-dollar"></i></div>
  <div>
    <p class="text-[13px] font-bold text-gray-800 leading-tight">Consultando el SIGA<span class="loadDots"></span></p>
    <p class="text-[11px] text-gray-500 mt-0.5">Cuadro de necesidades y ejecución del gasto</p>
  </div>
</div></div>

<!-- Modal de trazabilidad · Expediente formal -->
<div id="histModal" class="hidden fixed inset-0 z-50">
  <div id="histBack" class="absolute inset-0 bg-black/50"></div>
  <div id="histPanel" class="relative max-w-4xl mx-3 sm:mx-auto mt-5 mb-5 bg-white rounded-lg shadow-2xl overflow-hidden max-h-[calc(100vh-40px)] flex flex-col">
    <div class="px-6 pt-4 pb-3 border-b-2" style="border-color:#333">
      <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
          <p class="text-[9px] font-bold tracking-[.2em] text-gray-500 uppercase">SIGA · Reportes — Expediente de Trazabilidad de Ejecución</p>
          <p id="hmItem" class="font-bold text-[15px] text-gray-900 leading-snug mt-0.5"></p>
          <p id="hmSub" class="text-[11px] text-gray-500 italic mt-0.5"></p>
        </div>
        <div class="text-right shrink-0">
          <p id="hmFecha" class="text-[10px] italic text-gray-500"></p>
          <div class="flex gap-2 mt-1.5 justify-end no-print">
            <button id="histPrint" class="px-2.5 py-1 text-[11px] rounded border border-gray-300 hover:bg-gray-50 font-medium">🖨 Imprimir</button>
            <button id="histClose" class="px-2.5 py-1 text-[11px] rounded border border-gray-300 hover:bg-gray-50 font-medium">Cerrar ✕</button>
          </div>
        </div>
      </div>
    </div>
    <div class="px-6 pt-2 flex gap-1 border-b border-gray-200 no-print">
      <button data-tab="resumen" class="htab px-3 py-1.5 text-[11px] font-bold uppercase tracking-wide border-b-2 border-transparent">Resumen ejecutivo</button>
      <button data-tab="kardex" class="htab px-3 py-1.5 text-[11px] font-bold uppercase tracking-wide border-b-2 border-transparent">Kárdex cronológico</button>
    </div>
    <div class="overflow-y-auto">
      <div id="hmResumen" class="px-6 py-4"></div>
      <div id="hmKardex" class="px-6 py-4 hidden"></div>
    </div>
  </div>
</div>
<style>
@media print{
  body *{visibility:hidden}
  #histModal,#histModal *{visibility:visible}
  #histModal{position:absolute;inset:0}
  #histBack{display:none}
  #histPanel{max-height:none!important;box-shadow:none!important;max-width:100%!important;margin:0!important;border-radius:0!important}
  #histPanel .overflow-y-auto{overflow:visible!important}
  .no-print{display:none!important}
  #hmResumen,#hmKardex{display:block!important}
}
</style>

<style>
  /* ── Loader elegante ───────────────────────────── */
  #loadBar{position:fixed;top:0;left:0;right:0;height:3px;z-index:60;background:transparent;overflow:hidden;opacity:0;transition:opacity .2s}
  #loadBar.on{opacity:1}
  #loadBar span{position:absolute;inset:0;width:40%;border-radius:99px;
    background:linear-gradient(90deg,transparent,rgb(26,187,156),rgb(72,230,198),transparent);
    animation:slide 1.1s cubic-bezier(.4,0,.2,1) infinite}
  @keyframes slide{0%{left:-40%}100%{left:100%}}
  #loadOv{position:fixed;inset:0;z-index:55;display:none;place-items:center;
    background:rgba(248,250,252,.55);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px)}
  #loadOv.on{display:grid;animation:fadeIn .18s ease}
  @keyframes fadeIn{from{opacity:0}to{opacity:1}}
  .loadCard{display:flex;align-items:center;gap:14px;padding:16px 22px;border-radius:14px;background:#fff;
    box-shadow:0 12px 40px -12px rgba(15,23,42,.28),0 0 0 1px rgba(15,23,42,.05);animation:rise .25s cubic-bezier(.2,.8,.2,1)}
  @keyframes rise{from{transform:translateY(8px) scale(.98);opacity:0}to{transform:none;opacity:1}}
  .loadRing{position:relative;width:38px;height:38px;display:grid;place-items:center}
  .loadRing i.fa-circle-notch{font-size:34px;color:rgb(26,187,156);animation:spin .9s linear infinite}
  .loadRing i.fa-file-invoice-dollar{position:absolute;font-size:13px;color:rgb(20,150,125);animation:beat 1.4s ease-in-out infinite}
  @keyframes spin{to{transform:rotate(360deg)}}
  @keyframes beat{0%,100%{transform:scale(1);opacity:.85}50%{transform:scale(1.12);opacity:1}}
  .loadDots::after{content:'';animation:dots 1.4s steps(4,end) infinite}
  @keyframes dots{0%{content:''}25%{content:'.'}50%{content:'..'}75%{content:'...'}}
  /* esqueleto de tabla */
  .skl{background:linear-gradient(90deg,#eef2f7 25%,#f8fafc 37%,#eef2f7 63%);background-size:400% 100%;
    animation:shim 1.3s ease infinite;border-radius:4px;height:9px}
  @keyframes shim{0%{background-position:100% 0}100%{background-position:0 0}}
  /* ── Pantalla completa de la tabla ─────────── */
  #viewTable.fs{position:fixed;inset:0;z-index:45;margin:0;border-radius:0;border:0;
    max-height:none!important;height:100vh;background:#fff;padding-top:44px;
    animation:fsIn .18s cubic-bezier(.2,.8,.2,1)}
  @keyframes fsIn{from{opacity:.4;transform:scale(.995)}to{opacity:1;transform:none}}
  #fsBar{display:none;position:fixed;top:0;left:0;right:0;height:44px;z-index:46;
    align-items:center;gap:10px;padding:0 14px;color:#fff;
    background:linear-gradient(135deg,rgb(20,150,125),rgb(26,187,156));
    box-shadow:0 2px 12px -4px rgba(15,23,42,.4)}
  body.fsOn #fsBar{display:flex}
  body.fsOn{overflow:hidden}
  tr.itemrow{transition:filter .12s ease}
  /* Cabeceras a doble línea: en vez de una fila larguísima, el texto se envuelve. */
  #thead th{white-space:normal;line-height:1.15;vertical-align:bottom;min-width:64px;max-width:150px}
  #thead th.w-6{min-width:0}
  tr.itemrow:hover{filter:brightness(.965)}
  tr.ghead:hover{filter:brightness(1.06)}
  tr.gsub{letter-spacing:.02em}
  #tbody tr.itemrow td:first-child{position:relative}
  /* ── Estado inicial (sin consulta) ─────────── */
  .phFade{animation:phIn .35s cubic-bezier(.2,.8,.2,1)}
  @keyframes phIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
  .phIcon{animation:phFloat 3.2s ease-in-out infinite}
  @keyframes phFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-5px)}}
</style>

<?php /* Paleta Ctrl+K ANTES del script principal: así SIGA.accion ya existe
         cuando el IIFE de datos registra las acciones de esta pantalla. */
include __DIR__ . '/partials/accesos.php'; ?>

<script>
/* ===== Buscador centro de costo (recarga servidor) ===== */
(function(){const centros=<?= $jsonCent ?>;const box=document.getElementById('ccBox'),s=document.getElementById('ccSearch'),v=document.getElementById('ccValue'),l=document.getElementById('ccList'),cl=document.getElementById('ccClear'),fm=s.closest('form');
const nz=x=>(x||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');const ec=x=>(x||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function pt(t,q){if(!q)return ec(t);const nt=nz(t),nq=nz(q);let o='',i=0,x;while((x=nt.indexOf(nq,i))!==-1){o+=ec(t.slice(i,x))+'<mark class="bg-primary/20 text-primary-dark rounded px-0.5">'+ec(t.slice(x,x+q.length))+'</mark>';i=x+q.length;if(!nq.length)break;}return o+ec(t.slice(i));}
function rd(q){const nq=nz(q),m=centros.filter(c=>nz(c.cod+' '+c.nombre).includes(nq)).slice(0,60);l.innerHTML='';const a=document.createElement('li');a.className='px-3 py-2 cursor-pointer hover:bg-primary/5 text-gray-500 border-b';a.textContent='— Todos los centros —';a.onclick=()=>pk('','');l.appendChild(a);m.forEach(c=>{const li=document.createElement('li');li.className='px-3 py-2 cursor-pointer hover:bg-primary/5';li.innerHTML='<b class="text-gray-700">'+pt(c.cod,q)+'</b> <span class="text-gray-500">· '+pt(c.nombre,q)+'</span>';li.onclick=()=>pk(c.cod,c.cod+'  ·  '+c.nombre);l.appendChild(li);});l.classList.remove('hidden');}
function pk(cod,lab){v.value=cod;s.value=cod?lab:'';l.classList.add('hidden');cl.classList.toggle('hidden',!cod);fm.submit();}
s.addEventListener('input',()=>rd(s.value));s.addEventListener('focus',()=>rd(s.value));cl.addEventListener('click',()=>pk('',''));document.addEventListener('click',e=>{if(!box.contains(e.target))l.classList.add('hidden');});
/* acceso rápido: enfocar el buscador de centro */
if(window.SIGA&&SIGA.accion)SIGA.accion('Buscar centro de costo','fa-building',()=>{s.focus();s.select();},'Enfoca el buscador de centro de costo');})();

/* ===== Datos paginados + Tabla/Kanban ===== */
(function(){
  const HEADERS=<?= $jsonH ?>, NUM=new Set(<?= $jsonNum ?>), HKEYS=Object.keys(HEADERS);
  const ANIO='<?= $anioProg ?>', CC='<?= htmlspecialchars($ccosto ?? '') ?>';
  /* Los 3 estados del gasto planificado.  El estado de cada fila llega en d.ESTADO_FASE. */
  const FASES=[
    {key:'PROGRAMADO',label:'Programado',dot:'bg-gray-400',col:'border-gray-300',chip:'bg-gray-100 text-gray-600',ring:'ring-gray-400',tint:''},
    {key:'MODIFICADO',label:'Modificado',dot:'bg-warning',col:'border-warning',chip:'bg-warning/20 text-yellow-700',ring:'ring-warning',tint:'bg-warning/10'},
    {key:'EJECUTADO', label:'Ejecutado', dot:'bg-primary',col:'border-primary',chip:'bg-primary/15 text-primary-dark',ring:'ring-primary',tint:'bg-primary/5'}];
  const FMAP=Object.fromEntries(FASES.map(f=>[f.key,f]));
  const money=n=>(+n||0).toLocaleString('es-PE',{minimumFractionDigits:2,maximumFractionDigits:2});
  const ec=s=>(s||'').toString().replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  /* Estado consolidado del ítem: viene calculado desde la capa Query (ESTADO_FASE). */
  const faseKey=d=>FMAP[d.ESTADO_FASE]?d.ESTADO_FASE:'PROGRAMADO';

  const $=id=>document.getElementById(id);
  const chipsEl=$('chips'),qEl=$('q'),fTipoEl=$('fTipo'),fMetaEl=$('fMeta'),fActEl=$('fAct'),sortEl=$('sort'),perPageEl=$('perPage');
  const vTable=$('viewTable'),vKanban=$('viewKanban'),theadEl=$('thead'),tbodyEl=$('tbody'),tfootEl=$('tfoot');
  const st={tipo:'<?= $fTipo ?>',q:<?= json_encode($fQ) ?>,meta:'<?= htmlspecialchars($fMeta) ?>',act:'<?= htmlspecialchars($fAct) ?>',fase:'<?= htmlspecialchars($fFase) ?>',sort:'<?= htmlspecialchars($fSort) ?>',page:<?= $page ?>,perPage:<?= $perPage ?>};
  /* consultado = ya se trajo data del servidor al menos una vez. Distingue
     "todavía no consulté" (placeholder) de "consulté y no hubo resultados". */
  let mode='table', agrupar=true, prevSort='mod_desc', consultado=false, last={rows:[],total:0,summary:[]};

  /* ═══════════════ SELECTOR DE CAMPOS ═══════════════
     Columnas virtuales (__) + columnas reales de ExportService::HEADERS.
     El orden de la tabla siempre respeta el orden original de HEADERS. */
  const VCOL={__FASE:'Indicador de fase',__CMN:'Estado CMN'};
  const ALLC=[...Object.keys(VCOL),...HKEYS];
  const LBL=k=>VCOL[k]||HEADERS[k]||k;
  const LS_COLS='siga.cols.v1';

  function GRUPO(k){
    if(k.startsWith('__')||/^ESTADO/.test(k))                      return 'Estado y seguimiento';
    if(/^IMPORTE|^PRECIO|^CANT|DIFERENCIA|^SALDO|^DEVENGADO/.test(k)) return 'Montos y cantidades';
    if(/^CCOSTO|^META|^ACTIV|^FUENTE|^RUBRO|^FF/.test(k))          return 'Organización';
    if(/BIEN|ITEM|CLASIF|UNIDAD|TIPO|GRUPO|CLASE|FAMILIA|^COD_PROD/.test(k)) return 'Identificación del ítem';
    if(/ORDEN|PROVE|CERT|SIAF|DOC|FECHA/.test(k))                  return 'Ejecución';
    return 'Otros campos';
  }
  const PRE={
    trabajo   :()=>ALLC.filter(k=>['__FASE','__CMN','META','ACTIV_OPERAT_COD','COD_PRODUCTO','NOMBRE_ITEM','UNIDAD_MEDIDA','IMPORTE_COMP','DEVENGADO','SALDO_DEVENGAR'].includes(k)
                                || /^IMPORTE_(PROG|MOD|EJEC)$|^DIFERENCIA$/.test(k)),
    financiero:()=>ALLC.filter(k=>['__FASE','NOMBRE_ITEM','CLASIF_COD','FF','FF_NOMBRE','IMPORTE_COMP','DEVENGADO','SALDO_DEVENGAR'].includes(k)
                                || /^IMPORTE|^PRECIO|^CANT|DIFERENCIA/.test(k)),
    completo  :()=>ALLC.slice()
  };

  let VIS=new Set();
  (function(){
    let s=null; try{s=JSON.parse(localStorage.getItem(LS_COLS)||'null');}catch(e){}
    const base=(Array.isArray(s)&&s.length)?s.filter(k=>ALLC.includes(k)):PRE.trabajo();
    VIS=new Set(base.length>=2?base:ALLC);
  })();
  const COLS=()=>ALLC.filter(k=>VIS.has(k));
  function saveCols(){try{localStorage.setItem(LS_COLS,JSON.stringify([...VIS]));}catch(e){}}

  const colPanel=$('colPanel');
  function colBadge(){$('colCount').textContent=COLS().length+'/'+ALLC.length;}

  function renderColPanel(filtro){
    const f=(filtro||'').trim().toLowerCase(), g={};
    ALLC.forEach(k=>{
      if(f && !LBL(k).toLowerCase().includes(f) && !k.toLowerCase().includes(f))return;
      (g[GRUPO(k)]=g[GRUPO(k)]||[]).push(k);
    });
    const lista=Object.keys(g).map(gr=>
        '<div class="px-3 pt-3">'
       +'<div class="flex items-center justify-between mb-0.5">'
         +'<p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">'+ec(gr)+'</p>'
         +'<button type="button" data-gall="'+ec(gr)+'" class="text-[10px] font-semibold text-primary-dark hover:underline">alternar</button>'
       +'</div>'
       +g[gr].map(k=>
          '<label class="flex items-center gap-2.5 py-1.5 px-1 rounded-md hover:bg-gray-50 cursor-pointer">'
         +'<input type="checkbox" class="colChk accent-primary" value="'+ec(k)+'"'+(VIS.has(k)?' checked':'')+'>'
         +'<span class="text-[12px] text-gray-700 leading-tight">'+ec(LBL(k))+'</span></label>').join('')
       +'</div>').join('')
      || '<p class="p-6 text-center text-xs text-gray-400">Ningún campo coincide</p>';

    colPanel.innerHTML=
       '<div class="px-3 py-2.5 border-b border-gray-100">'
      +'<div class="flex items-center justify-between mb-2">'
        +'<p class="text-[11px] font-bold uppercase tracking-wide text-gray-600">Campos visibles</p>'
        +'<button type="button" id="colClose" class="text-gray-400 hover:text-gray-600 text-xs">✕</button></div>'
      +'<input id="colQ" type="text" placeholder="Buscar campo…" value="'+ec(f)+'" '
        +'class="w-full px-2.5 py-1.5 text-[12px] border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary">'
      +'<div class="flex gap-1 mt-2">'
        +'<button type="button" data-pre="trabajo"    class="flex-1 px-2 py-1 text-[10px] font-semibold rounded border border-gray-200 hover:bg-gray-50">Trabajo</button>'
        +'<button type="button" data-pre="financiero" class="flex-1 px-2 py-1 text-[10px] font-semibold rounded border border-gray-200 hover:bg-gray-50">Financiero</button>'
        +'<button type="button" data-pre="completo"   class="flex-1 px-2 py-1 text-[10px] font-semibold rounded border border-gray-200 hover:bg-gray-50">Completo</button>'
      +'</div></div>'
      +'<div class="max-h-[45vh] overflow-y-auto pb-2">'+lista+'</div>'
      +'<div class="px-3 py-2 border-t border-gray-100 flex items-center justify-between bg-gray-50">'
        +'<span class="text-[10px] text-gray-500">'+COLS().length+' de '+ALLC.length+' campos</span>'
        +'<button type="button" data-pre="trabajo" class="text-[11px] font-semibold text-gray-500 hover:text-gray-700">Restablecer</button>'
      +'</div>';
    const qi=$('colQ'); if(f){qi.focus();qi.setSelectionRange(f.length,f.length);}
  }

  function aplicarCols(){saveCols();colBadge();updateExport();paint();}

  $('btnCols').addEventListener('click',e=>{
    e.stopPropagation();
    if(!colPanel.classList.contains('hidden')){colPanel.classList.add('hidden');return;}
    renderColPanel('');colPanel.classList.remove('hidden');
  });
  colPanel.addEventListener('click',e=>{
    e.stopPropagation();
    const chk=e.target.closest('.colChk');
    if(chk){ chk.checked?VIS.add(chk.value):VIS.delete(chk.value);
      if(!COLS().length){VIS.add(chk.value);chk.checked=true;return;}
      aplicarCols(); renderColPanel($('colQ').value); return; }
    const pre=e.target.closest('[data-pre]');
    if(pre){ VIS=new Set(PRE[pre.dataset.pre]()); aplicarCols(); renderColPanel($('colQ').value); return; }
    const gall=e.target.closest('[data-gall]');
    if(gall){ const gr=gall.dataset.gall, ks=ALLC.filter(k=>GRUPO(k)===gr);
      const todos=ks.every(k=>VIS.has(k));
      ks.forEach(k=>todos?VIS.delete(k):VIS.add(k));
      if(!COLS().length)VIS=new Set(PRE.trabajo());
      aplicarCols(); renderColPanel($('colQ').value); return; }
    if(e.target.id==='colClose')colPanel.classList.add('hidden');
  });
  colPanel.addEventListener('input',e=>{ if(e.target.id==='colQ')renderColPanel(e.target.value); });
  document.addEventListener('click',()=>colPanel.classList.add('hidden'));
  document.addEventListener('keydown',e=>{ if(e.key==='Escape')colPanel.classList.add('hidden'); });
  colBadge();
  /* ═══════════════ fin selector de campos ═══════════════ */

  function params(extra){return new URLSearchParams(Object.assign({resource:'cmn',anio:ANIO,ccosto:CC,tipo:st.tipo,q:st.q,meta:st.meta,act:st.act,fase:st.fase,sort:st.sort,page:st.page,perPage:st.perPage},extra||{}));}
  function updateExport(){
    const cx={cols:COLS().filter(k=>!k.startsWith('__')).join(',')};
    $('expExcel').href='?'+params(Object.assign({export:'excel'},cx)).toString();
    $('expPdf').href  ='?'+params(Object.assign({export:'pdf'  },cx)).toString();
  }

  /* Detalle de órdenes (trazabilidad SIGA): conserva las fases nativas de cada O/C u O/S. */
  function badge(estado){return (estado||'').split(',').map(s=>s.trim()).filter(Boolean).map(p=>{const e=p.toUpperCase();let c='bg-gray-100 text-gray-600';if(e.includes('DEVENGADO'))c='bg-primary/15 text-primary-dark';else if(e.includes('COMPROMETIDO'))c='bg-secondary/15 text-secondary-dark';else if(e.includes('CERTIFICADO'))c='bg-warning/20 text-yellow-700';else if(e.includes('PENDIENTE'))c='bg-gray-100 text-gray-500';return '<span class="inline-block px-1.5 py-0.5 rounded-full text-[10px] '+c+'">'+ec(p)+'</span>';}).join(' ');}
  /* Estado de línea del CMN: ANTIGUO (base) · INCLUIDO · EXCLUIDO · MODIFICADO. */
  const CMN_EST={
    'ANTIGUO'   :['Antiguo'   ,'bg-gray-100 text-gray-600 border-gray-300' ,'Ya venía en el cuadro base aprobado'],
    'INCLUIDO'  :['Incluido'  ,'bg-blue-100 text-blue-800 border-blue-300' ,'Añadido después por modificación (I)'],
    'EXCLUIDO'  :['Excluido'  ,'bg-red-100 text-red-800 border-red-300'    ,'Alguna línea del ítem fue retirada (E)'],
    'MODIFICADO':['Modificado','bg-amber-100 text-amber-800 border-amber-300','El importe vigente cambió respecto del original']};
  function cmnBadge(d){const s=(d.ESTADO_CMN||'');if(!s)return'';
    let out=s.split(',').map(x=>x.trim()).filter(Boolean).map(x=>{const m=CMN_EST[x]||[x,'bg-gray-100 text-gray-600 border-gray-300',''];
      return '<span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-bold border '+m[1]+'" title="'+m[2]+'">'+m[0]+'</span>';}).join(' ');
    if(+d.NRO_LINEAS>1)out+=' <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-bold border bg-violet-100 text-violet-700 border-violet-300" title="Agrupa '+d.NRO_LINEAS+' líneas del cuadro">×'+d.NRO_LINEAS+'</span>';
    return out;}
  /* Color estable por actividad operativa (para el agrupado). */
  const ACT_PAL=[['#059669','#ecfdf5'],['#0284c7','#eff6ff'],['#6d28d9','#f5f3ff'],['#b45309','#fffbeb'],['#dc2626','#fef2f2'],['#0f766e','#f0fdfa'],['#a21caf','#fdf4ff'],['#4d7c0f','#f7fee7']];
  function actColor(cod){let h=0;const s=(cod||'').toString();for(let i=0;i<s.length;i++)h=(h*31+s.charCodeAt(i))>>>0;return ACT_PAL[h%ACT_PAL.length];}

  /* Los chips muestran los MISMOS totales que las columnas de la tabla:
       Programado = Σ IMPORTE_PROG · Modificado = Σ IMPORTE_MOD · Ejecutado = Σ IMPORTE_EJEC
     (totales del centro, no la porción de cada fase). El número junto al chip
     es la cantidad de ítems en esa fase, que es lo que filtra al hacer clic. */
  function renderChips(sum){
    const map={};let tc=0,tProg=0,tMod=0,tEjec=0;
    sum.forEach(s=>{map[s.fase]={c:+s.c};tc+=+s.c;tProg+=+s.prog;tMod+=+s.monto;tEjec+=+s.ejec;});
    const TOT={PROGRAMADO:tProg,MODIFICADO:tMod,EJECUTADO:tEjec};
    chipsEl.innerHTML='';
    chipsEl.appendChild(chip('Todos',tc,tMod,'bg-gray-800 text-white','ring-gray-800',st.fase==='',
      ()=>{st.fase='';st.page=1;load();},null,
      'Importe vigente total S/ '+money(tMod)+'  ·  Ejecutado S/ '+money(tEjec)));
    FASES.forEach(f=>{const g=map[f.key]||{c:0};
      const tip=(f.key==='PROGRAMADO'?'Total programado en el cuadro aprobado'
               :f.key==='MODIFICADO'?'Total vigente del cuadro modificado'
               :'Total efectivamente ejecutado')+'  ·  '+g.c+' ítems en esta fase (clic para filtrar)';
      chipsEl.appendChild(chip(f.label,g.c,TOT[f.key],f.chip,f.ring,st.fase===f.key,
        ()=>{st.fase=(st.fase===f.key?'':f.key);st.page=1;load();},f.dot,tip));});}

  function chip(label,count,monto,cls,ring,active,onclick,dot,tip){const b=document.createElement('button');if(tip)b.title=tip;b.className='px-3 py-1.5 rounded-full text-xs font-medium flex items-center gap-2 transition-all '+cls+(active?(' ring-2 ring-offset-1 '+ring):' opacity-90 hover:opacity-100');b.innerHTML=(dot?'<span class="w-2 h-2 rounded-full '+dot+'"></span>':'')+'<span>'+label+'</span><span class="opacity-60">·</span><span>'+count+'</span><span class="opacity-60">S/ '+money(monto)+'</span>';b.onclick=onclick;return b;}

  const colapsados=new Set();
  /* Fila de totales alineada bajo las columnas de importe visibles (estilo SIGA). */
  function rowTotales(label,sP,sM,sE,sD,opt){
    const o=opt||{}, cols=COLS();
    const MAP={IMPORTE_PROG:sP,IMPORTE_MOD:sM,IMPORTE_EJEC:sE,DIFERENCIA:sD};
    let first=cols.findIndex(k=>k in MAP); if(first<0)first=cols.length;
    let c='<td colspan="'+Math.max(1,first)+'" class="px-3 py-1.5 text-right '+(o.lblCls||'')+'" style="'+(o.lblStyle||'')+'">'+label+'</td>';
    for(let i=first;i<cols.length;i++){const k=cols[i];const val=(k in MAP)?MAP[k]:null;
      c+= val===null ? '<td class="py-1.5"></td>'
        : '<td class="px-2 py-1.5 text-right tabular-nums '+(k==='DIFERENCIA'&&val<-0.005?'text-red-600':'')+'">'+money(val)+'</td>';}
    return '<tr class="'+(o.trCls||'')+'" style="'+(o.trStyle||'')+'">'+c+'</tr>';
  }

  /* ── ESTADO INICIAL · sin consulta ─────────────────────────────────────
     Traer todos los centros es la operación más costosa del sistema, así que
     al abrir la pantalla no se consulta nada: se muestra este estado y los
     datos llegan cuando el usuario elige un centro (recarga con ?ccosto=) o
     pulsa "Cargar todos los centros". */
  function placeholder(){
    const nc=Math.max(1,COLS().length);
    chipsEl.innerHTML='<span class="inline-flex items-center gap-2 text-xs text-gray-400">'
      +'<i class="fa-regular fa-circle-pause"></i> Sin consulta activa'
      +'<span class="text-gray-300">·</span> elige un centro de costo o carga todos</span>';
    theadEl.innerHTML=''; tfootEl.innerHTML='';
    tbodyEl.innerHTML='<tr><td colspan="'+nc+'" class="p-0"><div class="phFade flex flex-col items-center text-center px-6 py-20">'
      +'<div class="phIcon w-16 h-16 rounded-2xl grid place-items-center mb-4" '
        +'style="background:linear-gradient(135deg,rgba(26,187,156,.16),rgba(13,110,253,.12))">'
        +'<i class="fa-solid fa-magnifying-glass-chart text-2xl" style="color:rgb(20,150,125)"></i></div>'
      +'<p class="text-[15px] font-bold text-gray-800">Elige un centro de costo para empezar</p>'
      +'<p class="text-[12px] text-gray-500 mt-1 max-w-md leading-relaxed">'
        +'Busca por código o nombre en el campo de arriba. También puedes traer el cuadro '
        +'completo de la entidad, aunque esa consulta demora bastante más.</p>'
      +'<div class="flex flex-wrap gap-2 justify-center mt-5">'
        +'<button type="button" id="phBuscar" class="px-4 py-2 text-sm font-semibold rounded-lg text-white shadow-sm hover:brightness-105" '
          +'style="background:linear-gradient(135deg,rgb(26,187,156),rgb(20,150,125))">'
          +'<i class="fa-solid fa-building mr-1"></i> Buscar centro de costo</button>'
        +'<button type="button" id="phAll" class="px-4 py-2 text-sm font-semibold rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50">'
          +'<i class="fa-solid fa-layer-group mr-1"></i> Cargar todos los centros</button>'
      +'</div>'
      +'<p class="text-[10px] text-gray-400 mt-5">Ejercicio <?= $anioProg ?> · ejecución <?= $anioEjec ?></p>'
      +'</div></td></tr>';
    $('totLbl').textContent='sin consultar';
    $('pageInfo').textContent=''; $('pageNum').textContent='';
    $('prev').disabled=true; $('next').disabled=true;
    const b=$('phBuscar'); if(b) b.onclick=()=>{const i=document.getElementById('ccSearch'); if(i){i.focus();i.select();}};
    const a=$('phAll');    if(a) a.onclick=()=>load();
  }

  function renderTable(rows){
    const cols=COLS(), nCols=Math.max(1,cols.length);
    theadEl.innerHTML=cols.map(k=>
        k==='__FASE' ? '<th class="px-2 py-2 w-6"></th>'
      : k==='__CMN'  ? '<th class="px-2 py-2 font-semibold text-left">ESTADO CMN</th>'
      : '<th class="px-2 py-2 font-semibold '+(NUM.has(k)?'text-right':'text-left')+'">'+ec(HEADERS[k])+'</th>').join('');
    if(!rows.length){tbodyEl.innerHTML='<tr><td colspan="'+nCols+'" class="px-3 py-6 text-center text-gray-400">Sin resultados</td></tr>';tfootEl.innerHTML='';return;}

    const codBien=d=>[d.GRUPO_BIEN,d.CLASE_BIEN,d.FAMILIA_BIEN,d.ITEM_BIEN].map(x=>(x||'').toString()).join('');
    /* Fila de ítem. Dentro de un bloque toma el color del grupo: fondo tintado
       alternado, riel izquierdo y hairline del mismo tono → jerarquía visual. */
    const tr1=(d,idx,c,par)=>{
      const dentro=!!c;
      const bg   = dentro ? (par? c[0]+'12' : c[0]+'08') : 'transparent';
      const style= dentro
        ? 'background:'+bg+';box-shadow:inset 5px 0 0 '+c[0]+'80;border-bottom:1px solid '+c[0]+'1f;'
        : '';
      const f=FMAP[faseKey(d)];
      const cells=cols.map((k,ci)=>{
        if(k==='__FASE') return '<td class="py-1 text-center '+(dentro&&ci===0?'pl-4 pr-1':'px-2')+'" title="'+f.label+'">'
                               +'<span class="inline-block w-1.5 h-1.5 rounded-full '+f.dot+' align-middle"></span></td>';
        if(k==='__CMN')  return '<td class="px-2 py-1"><div class="flex flex-wrap gap-1">'+cmnBadge(d)+'</div></td>';
        if(k==='ESTADO_ORDEN') return '<td class="px-2 py-1"><div class="flex flex-wrap gap-1">'+badge(d[k])+'</div></td>';
        if(k==='DIFERENCIA') return '<td class="px-2 py-1 text-right tabular-nums font-semibold '+((+d[k])<-0.005?'text-red-600':'text-gray-700')+'">'+money(d[k])+'</td>';
        return NUM.has(k)
          ? '<td class="px-2 py-1 text-right tabular-nums">'+money(d[k])+'</td>'
          : '<td class="px-2 py-1">'+ec(d[k])+'</td>';
      }).join('');
      return '<tr class="itemrow cursor-pointer trow'+(dentro?'':' bg-white hover:bg-gray-50')+'" style="'+style+'" data-idx="'+idx+'">'+cells+'</tr>';
    };

    let html='';
    if(agrupar){
      const g={};rows.forEach((d,i)=>{const k=d.ACTIV_OPERAT_COD||'—';(g[k]=g[k]||[]).push({d,i});});
      const keys=Object.keys(g).sort();
      keys.forEach((k,gi)=>{
        const items=g[k].sort((a,b)=>codBien(a.d)<codBien(b.d)?-1:codBien(a.d)>codBien(b.d)?1:0);
        const c=actColor(k), cerrado=colapsados.has(k);
        const sP=items.reduce((s,x)=>s+ +x.d.IMPORTE_PROG,0),sM=items.reduce((s,x)=>s+ +x.d.IMPORTE_MOD,0),
              sE=items.reduce((s,x)=>s+ +x.d.IMPORTE_EJEC,0),sD=items.reduce((s,x)=>s+ +x.d.DIFERENCIA,0);
        const pct=sM>0?Math.min(100,sE/sM*100):0;

        /* separador entre bloques */
        if(gi)html+='<tr><td colspan="'+nCols+'" class="p-0"><div style="height:10px;background:#f8fafc"></div></td></tr>';

        /* CABECERA DEL BLOQUE: color sólido, texto blanco (nivel 1) */
        html+='<tr class="ghead cursor-pointer select-none" data-act="'+ec(k)+'"><td colspan="'+nCols+'" class="p-0">'
          +'<div class="flex items-center gap-2.5 px-2.5 py-2 text-white" style="background:'+c[0]+'">'
            +'<span class="text-[11px] w-3 shrink-0 opacity-90">'+(cerrado?'▶':'▼')+'</span>'
            +'<span class="px-2 py-0.5 rounded-sm text-[10px] font-black tracking-widest shrink-0" style="background:rgba(255,255,255,.22)">'+ec(k)+'</span>'
            +'<span class="text-[12px] font-bold truncate uppercase tracking-wide">'+ec(items[0].d.ACTIV_OPERAT_NOMBRE||'Sin actividad')+'</span>'
            +'<span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold shrink-0" style="background:rgba(255,255,255,.9);color:'+c[0]+'">'+items.length+' ítems</span>'
            +'<div class="ml-auto flex items-center gap-2 shrink-0">'
              +'<div class="w-24 h-1.5 rounded-full overflow-hidden" style="background:rgba(255,255,255,.3)"><div class="h-full rounded-full bg-white" style="width:'+pct+'%"></div></div>'
              +'<span class="text-[11px] font-black tabular-nums w-12 text-right">'+pct.toFixed(1)+'%</span>'
            +'</div>'
          +'</div></td></tr>';

        if(!cerrado){
          items.forEach((x,ri)=>{html+=tr1(x.d,x.i,c,ri%2===1);});
          /* PIE DEL BLOQUE: subtotal alineado, cierra el bloque (nivel 2) */
          html+=rowTotales('Sub Total  ·  '+ec(k),sP,sM,sE,sD,{
            trCls:'gsub font-bold text-[11px]',
            trStyle:'background:'+c[0]+'26;box-shadow:inset 5px 0 0 '+c[0]+';border-top:2px solid '+c[0]+';border-bottom:2px solid '+c[0]+'59;color:'+c[0],
            lblCls:'uppercase'});
        }
      });
    } else rows.forEach((d,i)=>{html+=tr1(d,i,null,false);});
    tbodyEl.innerHTML=html;

    const tP=rows.reduce((s,d)=>s+ +d.IMPORTE_PROG,0),tM=rows.reduce((s,d)=>s+ +d.IMPORTE_MOD,0),
          tE=rows.reduce((s,d)=>s+ +d.IMPORTE_EJEC,0),tD=rows.reduce((s,d)=>s+ +d.DIFERENCIA,0);
    tfootEl.innerHTML=rowTotales('TOTAL PÁGINA',tP,tM,tE,tD,{
      trCls:'bg-gray-800 text-white font-bold',lblCls:'tracking-widest text-[11px]'});
  }

  function renderKanban(rows){vKanban.innerHTML='';const vis=st.fase?FASES.filter(f=>f.key===st.fase):FASES;
    vis.forEach(f=>{const g=[];rows.forEach((d,i)=>{if(faseKey(d)===f.key)g.push({d,i});});const monto=g.reduce((s,x)=>s+ +x.d.IMPORTE_MOD,0);
      const col=document.createElement('div');col.className='flex-shrink-0 w-72 bg-gray-100/70 rounded-xl flex flex-col max-h-[calc(100vh-360px)]';
      col.innerHTML='<div class="p-3 border-b border-gray-200"><div class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full '+f.dot+'"></span><span class="font-semibold text-sm text-gray-700">'+f.label+'</span><span class="ml-auto text-xs bg-white px-2 py-0.5 rounded-full text-gray-500">'+g.length+'</span></div><div class="text-[11px] text-gray-500 mt-1">S/ '+money(monto)+' (página)</div></div>';
      const body=document.createElement('div');body.className='p-2 space-y-2 overflow-y-auto';
      body.innerHTML=g.length?g.map(x=>card(x.d,f,x.i)).join(''):'<p class="text-center text-xs text-gray-400 py-6">Sin ítems</p>';
      col.appendChild(body);vKanban.appendChild(col);});}
  function card(d,f,idx){return '<div class="bg-white rounded-lg border border-gray-200 border-l-4 '+f.col+' p-2.5 shadow-sm cursor-pointer hover:shadow kcard" data-idx="'+idx+'"><p class="text-[13px] font-medium leading-snug">'+ec(d.NOMBRE_ITEM)+'</p><p class="text-[11px] text-gray-400 mt-0.5">Meta '+ec(d.META)+' · '+ec(d.ACTIV_OPERAT_COD)+' · '+ec(d.UNIDAD_MEDIDA)+'</p><div class="mt-1 flex flex-wrap gap-1">'+cmnBadge(d)+(d.ESTADO_ORDEN?badge(d.ESTADO_ORDEN):'')+'</div><div class="grid grid-cols-4 gap-1 mt-2 text-center">'+mini('Prog',d.IMPORTE_PROG)+mini('Mod',d.IMPORTE_MOD)+mini('Ejec',d.IMPORTE_EJEC)+mini('Dif',d.DIFERENCIA,1)+'</div></div>';}
  function mini(l,v,hl){return '<div class="rounded '+(hl?'bg-primary/5':'bg-gray-50')+' py-1"><div class="text-[9px] text-gray-400 uppercase">'+l+'</div><div class="text-[11px] font-semibold tabular-nums '+(hl?'text-primary-dark':'')+'">'+money(v)+'</div></div>';}

  /* Si aún no se ha consultado nada, paint() no debe pisar el estado inicial
     (se invoca también al cambiar de vista o de columnas). */
  function paint(){
    if(!consultado){placeholder();return;}
    if(mode==='table'){vTable.classList.remove('hidden');vKanban.classList.add('hidden');renderTable(last.rows);}
    else{vKanban.classList.remove('hidden');vTable.classList.add('hidden');renderKanban(last.rows);}}
  function renderPager(){const t=last.total,pp=st.perPage,from=t?((st.page-1)*pp+1):0,to=Math.min(t,st.page*pp),pages=Math.max(1,Math.ceil(t/pp));
    $('totLbl').textContent=t+' ítems';$('pageInfo').textContent=from+'–'+to+' de '+t;$('pageNum').textContent='Pág. '+st.page+' / '+pages;
    $('prev').disabled=st.page<=1;$('next').disabled=st.page>=pages;
    const ft=$('fsTot'); if(ft) ft.textContent=$('pageInfo').textContent;}

  /* ── Loader ─────────────────────────────────── */
  let loadT=null;
  function showLoad(){$('loadBar').classList.add('on');
    clearTimeout(loadT);loadT=setTimeout(()=>$('loadOv').classList.add('on'),260);}
  function hideLoad(){clearTimeout(loadT);$('loadBar').classList.remove('on');$('loadOv').classList.remove('on');}
  function skeleton(){const nc=Math.max(1,COLS().length);let h='';
    for(let r=0;r<8;r++){h+='<tr>';for(let i=0;i<nc;i++){const w=i<2?'60%':(i%3===0?'80%':'55%');
      h+='<td class="px-2 py-2"><div class="skl" style="width:'+w+';animation-delay:'+(r*.06)+'s"></div></td>';}h+='</tr>';}
    tbodyEl.innerHTML=h;tfootEl.innerHTML='';}

  async function load(){
    showLoad();skeleton();
    updateExport();
    try{
      const r=await fetch('?'+params({action:'data'}).toString(),{credentials:'same-origin'});
      /* Si la sesión venció, Auth redirige al login y llega HTML: se detecta por
         el content-type para no reventar en el JSON.parse. */
      const ct=(r.headers.get('content-type')||'');
      if(r.redirected || !ct.includes('json')){ location.href='login.php?next=index.php'; return; }
      const j=await r.json();
      if(j.error){tbodyEl.innerHTML='<tr><td colspan="'+Math.max(1,COLS().length)+'" class="px-3 py-6 text-center"><i class="fa-solid fa-triangle-exclamation text-red-500 mr-1"></i><span class="text-red-600">'+ec(j.error)+'</span></td></tr>';tfootEl.innerHTML='';return;}
      last=j;consultado=true;renderChips(j.summary);paint();renderPager();
    }catch(e){tbodyEl.innerHTML='<tr><td colspan="'+Math.max(1,COLS().length)+'" class="px-3 py-6 text-center"><i class="fa-solid fa-plug-circle-xmark text-red-500 mr-1"></i><span class="text-red-600">Error de red</span></td></tr>';tfootEl.innerHTML='';}
    finally{hideLoad();}
  }

  /* ===== MODAL DE TRAZABILIDAD · Expediente formal ===== */
  const INK={gris:'#6b7280',violeta:'#6d28d9',ambar:'#b45309',azul:'#0284c7',verde:'#059669',rojo:'#dc2626',tinta:'#111827'};
  const histCache={};
  const hmEl=$('histModal');
  function hmOpen(){hmEl.classList.remove('hidden');document.body.style.overflow='hidden';}
  function hmClose(){hmEl.classList.add('hidden');document.body.style.overflow='';}
  $('histClose').addEventListener('click',hmClose);$('histBack').addEventListener('click',hmClose);
  $('histPrint').addEventListener('click',()=>window.print());
  document.addEventListener('keydown',e=>{if(e.key==='Escape')hmClose();});
  function setTab(t){document.querySelectorAll('.htab').forEach(b=>{const on=b.dataset.tab===t;b.style.color=on?INK.verde:'#6b7280';b.style.borderColor=on?INK.verde:'transparent';});
    $('hmResumen').classList.toggle('hidden',t!=='resumen');$('hmKardex').classList.toggle('hidden',t!=='kardex');}
  document.querySelectorAll('.htab').forEach(b=>b.addEventListener('click',()=>setTab(b.dataset.tab)));

  async function openHist(el){
    const idx=+el.dataset.idx, d=last.rows[idx]; if(!d)return;
    const key=[d.CCOSTO_COD,d.TIPO_BIEN,d.GRUPO_BIEN,d.CLASE_BIEN,d.FAMILIA_BIEN,d.ITEM_BIEN,d.META,d.CLASIF_COD].join('|');
    $('hmItem').textContent=d.NOMBRE_ITEM||('Ítem '+d.ITEM_BIEN);
    $('hmSub').textContent=d.CCOSTO_COD+' · '+d.CCOSTO_NOMBRE+'   |   Meta '+d.META+' · '+d.CLASIF_COD+' · '+d.ACTIV_OPERAT_COD+' · '+(d.UNIDAD_MEDIDA||'')+(d.ESTADO_CMN?'   |   CMN: '+d.ESTADO_CMN+(+d.NRO_LINEAS>1?' ('+d.NRO_LINEAS+' líneas)':''):'');
    const ah=new Date();$('hmFecha').textContent='CONSULTA: '+ah.toLocaleDateString('es-PE')+' · '+ah.toLocaleTimeString('es-PE');
    $('hmResumen').innerHTML='<p class="text-xs text-gray-400 py-8 text-center">Cargando expediente…</p>';$('hmKardex').innerHTML='';
    setTab('resumen');hmOpen();
    let h=histCache[key];
    if(!h){const p=new URLSearchParams({resource:'cmn',anio:ANIO,action:'historial',cc:d.CCOSTO_COD,t:d.TIPO_BIEN,g:d.GRUPO_BIEN,c:d.CLASE_BIEN,f:d.FAMILIA_BIEN,it:d.ITEM_BIEN,meta:d.META,clasif:d.CLASIF_COD});
      try{h=await(await fetch('?'+p.toString(),{credentials:'same-origin'})).json();histCache[key]=h;}
      catch(x){$('hmResumen').innerHTML='<p class="text-xs text-red-600">No se pudo cargar el expediente.</p>';return;}}
    if(h.error){$('hmResumen').innerHTML='<p class="text-xs text-red-600">'+ec(h.error)+'</p>';return;}
    $('hmResumen').innerHTML=renderResumen(h,d);
    $('hmKardex').innerHTML=renderKardex(h,d);
  }
  tbodyEl.addEventListener('click',e=>{
    const gh=e.target.closest('.ghead');
    if(gh){const k=gh.dataset.act;if(colapsados.has(k))colapsados.delete(k);else colapsados.add(k);paint();return;}
    const tr=e.target.closest('.trow');if(tr)openHist(tr);});
  vKanban.addEventListener('click',e=>{const kc=e.target.closest('.kcard');if(kc)openHist(kc);});

  /* — utilitarios del expediente — */
  const fkey=f=>{if(!f)return'';const p=(f||'').split('/');return p.length===3?p[2]+p[1]+p[0]:'';};
  function dedup(arr,fn){const s=new Set();return (arr||[]).filter(r=>{const k=fn(r);if(s.has(k))return false;s.add(k);return true;});}
  function prep(h){return{
    cua:dedup(h.cuadro,r=>r.etapa+'|'+r.monto+'|'+(r.fecha||'')),
    con:dedup(h.consolidado,r=>r.nro+'|'+r.monto),
    cer:dedup(h.certificacion,r=>r.nro+'|'+r.monto+'|'+r.estado),
    ord:dedup(h.ordenes,r=>r.orden+'|'+r.monto),
    fas:dedup(h.fases,r=>r.fase+'|'+r.doc+'|'+r.monto)};}
  function secT(t){return '<div class="text-[10px] font-bold uppercase tracking-[.15em] text-gray-700 border-b pb-1 mb-2 mt-5 first:mt-0" style="border-color:#333">'+t+'</div>';}
  function fila(izq,der,cls){return '<div class="flex justify-between gap-3 border-b py-1 '+(cls||'')+'" style="border-color:#e5e7eb"><span class="min-w-0 text-xs text-gray-700">'+izq+'</span><span class="tabular-nums whitespace-nowrap text-xs">'+der+'</span></div>';}

  /* — REBAJA / AMPLIACIÓN del cuadro: original vs vigente — */
  function ajusteCuadro(ori,mods){
    if(!ori||!mods.length)return null;
    const m=mods[mods.length-1];
    const oC=+ori.cant,oP=+ori.precio,oM=+ori.monto, mC=+m.cant,mP=+m.precio,mM=+m.monto;
    if(Math.abs(mM-oM)<=0.005)return null;
    return {baja:mM<oM, dM:mM-oM, pct:oM>0?(mM-oM)/oM*100:0,
            dC:mC-oC, dP:mP-oP, oC,oP,oM,mC,mP,mM, fecha:m.fecha||''};
  }
  function ajusteBox(ori,mods){
    const a=ajusteCuadro(ori,mods); if(!a)return'';
    const col=a.baja?INK.rojo:INK.verde, bg=a.baja?'#fdf2f2':'#f0fdf4', bd=a.baja?'#fecaca':'#bbf7d0';
    const causa=[];
    if(Math.abs(a.dC)>0.005)causa.push('cantidad '+a.oC+' → <b>'+a.mC+'</b> ('+(a.dC>0?'+':'')+(+a.dC.toFixed(2))+' und)');
    if(Math.abs(a.dP)>0.005)causa.push('precio S/ '+money(a.oP)+' → <b>S/ '+money(a.mP)+'</b> ('+(a.dP>0?'+':'')+money(a.dP)+')');
    return '<div class="mt-1 text-[11px] px-2 py-1.5 rounded" style="background:'+bg+';border:1px solid '+bd+'">'
      +'<b style="color:'+col+'">'+(a.baja?'▼ REBAJA DEL CUADRO':'▲ AMPLIACIÓN DEL CUADRO')+': '
      +(a.dM>0?'+':'')+'S/ '+money(a.dM)+' ('+(a.pct>0?'+':'')+a.pct.toFixed(1)+'%)</b>'
      +(a.fecha?' <span class="text-gray-400 italic">'+ec(a.fecha)+'</span>':'')
      +'<div class="text-gray-600 mt-0.5">Motivo: '+(causa.length?causa.join(' · '):'ajuste de importe')+'.</div>'
      +'<div class="text-gray-500 mt-0.5">S/ '+money(a.oM)+' <span style="color:'+col+'">→</span> S/ '+money(a.mM)+' es el importe vigente que rige para la ejecución.</div>'
      +'</div>';
  }

  /* Banner prominente de REBAJA/AMPLIACIÓN, al tope del resumen (más visible que
     la caja intercalada en el paso 1). Solo aparece si el cuadro cambió de importe. */
  function bannerAjuste(ori,mods){
    const a=ajusteCuadro(ori,mods); if(!a)return'';
    const col=a.baja?INK.rojo:INK.verde, bg=a.baja?'#fef2f2':'#f0fdf4', bd=a.baja?'#fecaca':'#bbf7d0';
    return '<div class="flex items-center gap-3 mb-3 px-3 py-2 rounded-lg" style="background:'+bg+';border:1.5px solid '+bd+'">'
      +'<div class="w-9 h-9 rounded-full grid place-items-center text-white text-lg shrink-0" style="background:'+col+'">'+(a.baja?'▼':'▲')+'</div>'
      +'<div class="min-w-0"><p class="text-[13px] font-bold" style="color:'+col+'">'+(a.baja?'Cuadro REBAJADO':'Cuadro AMPLIADO')
        +' <span class="tabular-nums">'+(a.dM>0?'+':'')+'S/ '+money(a.dM)+'</span> <span class="text-[11px] font-normal">('+(a.pct>0?'+':'')+a.pct.toFixed(1)+'%)</span></p>'
      +'<p class="text-[11px] text-gray-600">De S/ '+money(a.oM)+' a <b>S/ '+money(a.mM)+'</b> vigente'+(a.fecha?' · '+ec(a.fecha):'')+'</p></div></div>';
  }

  /* — RESUMEN EJECUTIVO — */
  function renderResumen(h,d){
    const {cua,con,cer,ord,fas}=prep(h);
    const dev=fas.filter(r=>r.fase==='Devengado'),com=fas.filter(r=>r.fase==='Comprometido');
    const oriTop=cua.find(r=>r.etapa==='Original'),modsTop=cua.filter(r=>r.etapa==='Modificado');
    const P=+d.IMPORTE_PROG,M=+d.IMPORTE_MOD,E=+d.IMPORTE_EJEC;
    const max=Math.max(P,M,E,1);
    const bar=(l,v,c)=>'<div class="flex items-center gap-2 py-1"><span class="w-36 text-[10px] uppercase tracking-wide text-gray-500 text-right shrink-0">'+l+'</span><div class="flex-1 h-4 bg-gray-100 rounded-sm overflow-hidden"><div class="h-full transition-all" style="width:'+Math.max(1,v/max*100)+'%;background:'+c+'"></div></div><span class="w-28 text-right text-[11px] font-bold tabular-nums shrink-0" style="color:'+c+'">S/ '+money(v)+'</span></div>';
    const pct=M>0?Math.min(999,E/M*100):0;
    const pcol=pct>=70?INK.verde:pct>=50?'#d97706':INK.rojo;
    let out=bannerAjuste(oriTop,modsTop)
      +secT('Situación económica del ítem')
      +bar('Programado (CMN)',P,INK.gris)+bar('Modificado vigente',M,'#d97706')+bar('Ejecutado',E,INK.verde)
      +'<div class="flex items-center gap-3 mt-2"><div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden"><div class="h-full rounded-full" style="width:'+Math.min(100,pct)+'%;background:'+pcol+'"></div></div><span class="text-sm font-bold tabular-nums" style="color:'+pcol+'">'+pct.toFixed(1)+'%</span><span class="text-[10px] uppercase tracking-wide text-gray-400">de avance</span></div>'
      +'<p class="text-[11px] text-gray-500 mt-1">Saldo por ejecutar: <b class="tabular-nums" style="color:'+((M-E)<-0.005?INK.rojo:INK.tinta)+'">S/ '+money(M-E)+'</b></p>';

    /* análisis de precios */
    const ori=cua.find(r=>r.etapa==='Original'),mods=cua.filter(r=>r.etapa==='Modificado');
    const pRef=ori?+ori.precio:null, pCmn=mods.length?+mods[mods.length-1].precio:null;
    const pCon=con.length?+con[con.length-1].precio:null;
    const oc=ord.reduce((s,r)=>({c:s.c+ +r.cant,m:s.m+ +r.monto}),{c:0,m:0});
    const pOrd=oc.c>0?oc.m/oc.c:null;
    const pEje=+d.PRECIO_UNIT_EJEC>0?+d.PRECIO_UNIT_EJEC:null;
    const base=pRef??pCmn;
    const pr=(et,v,fuente)=>{if(v===null)return'';let vv='—',c=INK.tinta;
      if(base&&Math.abs(v-base)>0.005){const dp=(v-base)/base*100;c=dp>0?INK.rojo:INK.verde;vv=(dp>0?'+':'')+dp.toFixed(1)+'%';}
      else if(base)vv='0%';
      return '<tr><td class="py-1 pr-2 text-xs text-gray-700 border-b" style="border-color:#e5e7eb">'+et+' <span class="text-gray-400 text-[10px] italic">'+fuente+'</span></td><td class="py-1 text-right tabular-nums text-xs font-bold border-b" style="border-color:#e5e7eb">S/ '+money(v)+'</td><td class="py-1 pl-3 text-right tabular-nums text-[11px] font-bold border-b" style="border-color:#e5e7eb;color:'+c+'">'+vv+'</td></tr>';};
    out+=secT('Análisis de precio unitario · variación vs. referencial')
      +'<table class="w-full"><thead><tr>'
      +'<th class="text-left text-[9px] uppercase tracking-wide text-gray-500 pb-1" style="border-bottom:1.5px solid #333">Etapa</th>'
      +'<th class="text-right text-[9px] uppercase tracking-wide text-gray-500 pb-1" style="border-bottom:1.5px solid #333">Precio</th>'
      +'<th class="text-right text-[9px] uppercase tracking-wide text-gray-500 pb-1 pl-3" style="border-bottom:1.5px solid #333">Var.</th></tr></thead><tbody>'
      +pr('Referencial','· cuadro original',pRef)+pr('Cuadro vigente','· CMN modificado',pCmn)
      +pr('Consolidado PAAC','· estudio de mercado',pCon)+pr('Orden emitida','· promedio ponderado',pOrd)
      +pr('Devengado','· ejecución real',pEje)+'</tbody></table>';

    /* narrativa de 5 pasos */
    const paso=(n,c,t,b,fin)=>'<div class="flex gap-3"><div class="flex flex-col items-center shrink-0"><div class="w-6 h-6 rounded-full flex items-center justify-center text-white text-[10px] font-bold" style="background:'+c+'">'+n+'</div>'+(fin?'':'<div class="w-px flex-1 my-1" style="background:#e5e7eb"></div>')+'</div><div class="pb-4 flex-1 min-w-0"><div class="text-[10px] font-bold uppercase tracking-wide mb-1" style="color:'+c+'">'+t+'</div><div class="space-y-0.5">'+b+'</div></div></div>';
    let p1=ori?fila('El centro programó en el Cuadro de Necesidades','<b>'+(+ori.cant)+' × S/ '+money(ori.precio)+' = S/ '+money(ori.monto)+'</b>'):'<span class="text-xs text-gray-400">Sin registro del cuadro original.</span>';
    mods.forEach(r=>{p1+=fila('Cuadro vigente'+(r.estado?' · '+ec(r.estado):'')+(r.fecha?' <span class="text-gray-400 italic">('+ec(r.fecha)+')</span>':''),(+r.cant)+' × S/ '+money(r.precio)+' = <b>S/ '+money(r.monto)+'</b>');});
    p1+=ajusteBox(ori,mods);
    let p2=con.length?con.map(r=>{const cambio=(base!==null&&Math.abs(base-(+r.precio))>0.005)
        ?'<div class="mt-1 text-[11px] px-2 py-1 rounded" style="background:#fffbeb;border:1px solid #fde68a">Precio referencial <span class="line-through text-gray-400">S/ '+money(base)+'</span> → <b style="color:'+((+r.precio)>base?INK.rojo:INK.verde)+'">S/ '+money(r.precio)+'</b> · fijado por Logística en el estudio de mercado del consolidado.</div>':'';
      return fila('Consolidado N° <b>'+ec(r.nro)+'</b>'+(r.nro_cert?' · Cert. '+ec(r.nro_cert):'')+' <span class="text-gray-400 italic">('+ec(r.fecha_precio||r.fecha_consolid||'')+')</span>','<b>'+(+r.cant)+' × S/ '+money(r.precio)+' = S/ '+money(r.monto)+'</b>')+cambio;}).join('')
      :'<span class="text-xs text-gray-400">Aún no consolidado por Logística: rige el precio referencial.</span>';
    /* Certificaciones: se muestra el RESUMEN (vigente + total), y el detalle línea
       a línea queda plegado en un <details> para no saturar el expediente. */
    let p3;
    if(cer.length){
      const vig=cer.filter(r=>!/Anulada/i.test(r.estado||'')), anul=cer.filter(r=>/Anulada/i.test(r.estado||''));
      const tVig=vig.reduce((s,r)=>s+ +r.monto,0);
      let resumen=fila('<b>Certificado vigente</b> · '+vig.length+' cert.'+(anul.length?' <span class="text-gray-400">('+anul.length+' anulada'+(anul.length>1?'s':'')+')</span>':''),
                       '<b style="color:'+INK.ambar+'">S/ '+money(tVig)+'</b>');
      const detalle=cer.map(r=>{const anu=/Anulada/i.test(r.estado||'');
        return fila((anu?'<span class="line-through text-gray-400">':'')+'Cert. '+ec(r.nro)+' · '+ec(r.estado)+(anu?' <b style="color:'+INK.rojo+'" class="no-underline">ANULADO</b></span>':'')+' <span class="text-gray-400 italic">('+ec(r.fecha)+')</span>',
                    '<span class="'+(anu?'line-through text-gray-400':'font-bold')+'">S/ '+money(r.monto)+'</span>');}).join('');
      p3=resumen+'<details class="mt-1"><summary class="text-[11px] text-gray-500 cursor-pointer hover:text-gray-700 select-none">Ver detalle de las '+cer.length+' certificaciones</summary><div class="mt-1">'+detalle+'</div></details>';
    } else p3='<span class="text-xs text-gray-400">Sin certificación presupuestal todavía.</span>';
    /* Tabla estilo SIGA "Ejecución por Área Usuaria": cada orden con su proveedor,
       compromiso (monto de la orden), devengado (lo ya ejecutado de esa orden) y
       saldo por devengar. Reproduce el Excel que arma el área usuaria. */
    let p4;
    if(ord.length){
      /* Devengado por número de orden: la fase Devengado ahora trae nro_orden. */
      const devPorOrden={};
      dev.forEach(r=>{const k=String(r.nro_orden||'').trim();if(k)devPorOrden[k]=(devPorOrden[k]||0)+ +r.monto;});
      const numOrden=r=>{const m=(r.orden||'').match(/(\d+)/);return m?m[1]:'';};
      const esServicio=r=>/^O\/?S|\bOS\b|SERVICIO/i.test(r.orden||'');
      let tC=0,tD=0;
      const filas=ord.map(r=>{
        const comp=+r.monto, dv=devPorOrden[numOrden(r)]||0, saldo=comp-dv;
        tC+=comp; tD+=dv;
        return '<tr>'
          +'<td class="py-1 px-1.5 border-b text-xs font-bold" style="border-color:#e5e7eb">'+ec(r.orden)+'</td>'
          +'<td class="py-1 px-1.5 border-b text-[11px] text-gray-600" style="border-color:#e5e7eb">'+ec(r.proveedor||'—')+'</td>'
          +'<td class="py-1 px-1.5 border-b text-right tabular-nums text-xs" style="border-color:#e5e7eb">'+money(comp)+'</td>'
          +'<td class="py-1 px-1.5 border-b text-right tabular-nums text-xs" style="border-color:#e5e7eb;color:'+INK.verde+'">'+money(dv)+'</td>'
          +'<td class="py-1 px-1.5 border-b text-right tabular-nums text-xs" style="border-color:#e5e7eb;color:'+(saldo>0.005?INK.azul:'#9ca3af')+'">'+money(saldo)+'</td>'
          +'</tr>';
      }).join('');
      const th=t=>'<th class="py-1 px-1.5 text-[9px] uppercase tracking-wide text-gray-500 text-left" style="border-bottom:1.5px solid #333">'+t+'</th>';
      const thR=t=>'<th class="py-1 px-1.5 text-[9px] uppercase tracking-wide text-gray-500 text-right" style="border-bottom:1.5px solid #333">'+t+'</th>';
      p4='<table class="w-full"><thead><tr>'+th('N° Orden')+th('Proveedor')+thR('Compromiso')+thR('Devengado')+thR('Saldo × Dev')+'</tr></thead>'
        +'<tbody>'+filas+'</tbody>'
        +'<tfoot><tr class="font-bold">'
          +'<td colspan="2" class="py-1.5 px-1.5 text-right text-xs" style="border-top:1.5px solid #333">TOTAL ('+ord.length+' órdenes)</td>'
          +'<td class="py-1.5 px-1.5 text-right tabular-nums text-xs" style="border-top:1.5px solid #333">'+money(tC)+'</td>'
          +'<td class="py-1.5 px-1.5 text-right tabular-nums text-xs" style="border-top:1.5px solid #333;color:'+INK.verde+'">'+money(tD)+'</td>'
          +'<td class="py-1.5 px-1.5 text-right tabular-nums text-xs" style="border-top:1.5px solid #333;color:'+INK.azul+'">'+money(tC-tD)+'</td>'
        +'</tr></tfoot></table>';
    } else p4='<span class="text-xs text-gray-400">Sin orden de compra/servicio emitida.</span>';
    out+=secT('¿Qué pasó con este ítem? · flujo del gasto')
      +paso(1,INK.gris,'Programación · Cuadro de Necesidades',p1)
      +paso(2,INK.violeta,'Consolidación PAAC · aquí se fija el precio real',p2)
      +paso(3,INK.ambar,'Certificación presupuestal',p3)
      +paso(4,INK.azul,'Orden de compra / servicio · con ejecución por orden',p4,true)
      +'<p class="text-[10px] text-gray-400 italic mt-1">El compromiso, devengado y saldo se muestran por orden en la tabla. El girado y pagado se registran en el SIAF.</p>';
    return out;
  }

  /* — KÁRDEX CRONOLÓGICO — */
  function tcolor(t){return t==='DEVENGADO'?INK.verde:t==='COMPROMISO'?INK.azul:t==='ORDEN'?'#0284c7':t==='CERTIFICACIÓN'?INK.ambar:t==='CONSOLIDADO'?INK.violeta:t==='MODIFICACIÓN'?'#d97706':t==='REBAJA'?INK.rojo:t==='AMPLIACIÓN'?INK.verde:'#374151';}
  function renderKardex(h,d){
    const {cua,con,cer,ord,fas}=prep(h);
    const ev=[];
    const ori=cua.find(r=>r.etapa==='Original');
    if(ori)ev.push({f:'',tipo:'PROGRAMACIÓN',doc:'CMN',det:'Cuadro original: '+(+ori.cant)+' × S/ '+money(ori.precio),m:+ori.monto});
    const modsK=cua.filter(r=>r.etapa==='Modificado');
    modsK.forEach(r=>ev.push({f:r.fecha||'',tipo:'MODIFICACIÓN',doc:'CMN'+(r.estado?' ('+r.estado+')':''),det:'Cuadro vigente: '+(+r.cant)+' × S/ '+money(r.precio),m:+r.monto}));
    const aj=ajusteCuadro(ori,modsK);
    if(aj)ev.push({f:aj.fecha,tipo:aj.baja?'REBAJA':'AMPLIACIÓN',doc:'CMN',
      det:(aj.baja?'Rebaja':'Ampliación')+' del cuadro: S/ '+money(aj.oM)+' → S/ '+money(aj.mM)
          +(Math.abs(aj.dP)>0.005?' · precio '+money(aj.oP)+' → '+money(aj.mP):'')
          +(Math.abs(aj.dC)>0.005?' · cantidad '+aj.oC+' → '+aj.mC:''),
      m:aj.dM, rebaja:aj.baja});
    con.forEach(r=>ev.push({f:r.fecha_precio||r.fecha_consolid||'',tipo:'CONSOLIDADO',doc:'N° '+r.nro,det:'Precio real fijado: '+(+r.cant)+' × S/ '+money(r.precio)+(r.nro_cert?' · respalda Cert. '+r.nro_cert:''),m:+r.monto}));
    cer.forEach(r=>ev.push({f:r.fecha||'',tipo:'CERTIFICACIÓN',doc:'Cert. '+r.nro,det:'Certificación presupuestal · '+r.estado,m:+r.monto,anulado:/Anulada/i.test(r.estado||'')}));
    ord.forEach(r=>ev.push({f:r.fecha||'',tipo:'ORDEN',doc:r.orden,det:'Proveedor: '+r.proveedor+' · '+(+r.cant)+' × S/ '+money(r.precio),m:+r.monto}));
    fas.forEach(r=>ev.push({f:r.fecha||'',tipo:r.fase==='Devengado'?'DEVENGADO':'COMPROMISO',doc:r.doc,det:r.fase==='Devengado'?'Gasto ejecutado (recibido/facturado)':'Compromiso SIAF de la orden',m:+r.monto}));
    ev.sort((a,b)=>fkey(a.f)<fkey(b.f)?-1:fkey(a.f)>fkey(b.f)?1:0);
    const th=(t,al)=>'<th class="text-'+(al||'left')+' text-[9px] uppercase tracking-wide text-gray-600 py-1.5 px-1" style="border-top:1.5px solid #333;border-bottom:1.5px solid #333;background:#f9fafb">'+t+'</th>';
    let rows=ev.map((e,i)=>{const anu=!!e.anulado,fill=anu?'background:#fdf2f2;':'';const dec=anu?'line-through':'none';const col=anu?'#9ca3af':'';
      return '<tr style="'+fill+'">'
        +'<td class="py-1.5 px-1 text-center text-[10px] text-gray-400 border-b" style="border-color:#e5e7eb">'+(i+1)+'</td>'
        +'<td class="py-1.5 px-1 text-xs border-b tabular-nums" style="border-color:#e5e7eb;text-decoration:'+dec+';color:'+col+'">'+(e.f||'—')+'</td>'
        +'<td class="py-1.5 px-1 text-center border-b" style="border-color:#e5e7eb"><span class="text-[9px] font-bold" style="color:'+tcolor(e.tipo)+'">'+e.tipo+'</span>'+(anu?'<div class="text-[8px] font-bold" style="color:'+INK.rojo+'">ANULADO</div>':'')+'</td>'
        +'<td class="py-1.5 px-1 text-xs font-bold border-b" style="border-color:#e5e7eb;text-decoration:'+dec+';color:'+col+'">'+ec(e.doc)+'</td>'
        +'<td class="py-1.5 px-1 text-[11px] text-gray-600 border-b" style="border-color:#e5e7eb;text-decoration:'+dec+'">'+ec(e.det)+'</td>'
        +'<td class="py-1.5 px-1 text-right text-xs font-bold tabular-nums border-b" style="border-color:#e5e7eb;text-decoration:'+dec+';color:'+(anu?'#9ca3af':INK.tinta)+'">'+money(e.m)+'</td></tr>';}).join('');
    let out=secT('Kárdex cronológico unificado del ítem')
      +'<table class="w-full"><thead><tr>'+th('Nº','center')+th('Fecha')+th('Tipo','center')+th('Documento')+th('Detalle')+th('Importe S/','right')+'</tr></thead><tbody>'+rows+'</tbody></table>';
    if(ev.some(e=>e.anulado))out+='<p class="text-[10px] italic mt-2" style="color:'+INK.rojo+'">Las filas marcadas como ANULADO corresponden a documentos anulados y no se computan en la ejecución.</p>';
    /* ejecución mensual */
    const dev=fas.filter(r=>r.fase==='Devengado'&&r.fecha);
    if(dev.length){const MES=['ENE','FEB','MAR','ABR','MAY','JUN','JUL','AGO','SET','OCT','NOV','DIC'];const g={};
      dev.forEach(r=>{const p=r.fecha.split('/');if(p.length===3){const k=p[2]+p[1];g[k]=(g[k]||0)+ +r.monto;}});
      const ks=Object.keys(g).sort();const mx=Math.max(...ks.map(k=>g[k]),1);
      out+=secT('Ejecución devengada por mes')
        +'<div class="flex items-end gap-2 h-24 pt-2">'+ks.map(k=>{const v=g[k];const hpx=Math.max(6,v/mx*70);
          return '<div class="flex flex-col items-center gap-1 flex-1 max-w-[70px]"><span class="text-[9px] tabular-nums text-gray-500">'+money(v)+'</span><div class="w-full rounded-t" style="height:'+hpx+'px;background:'+INK.verde+'"></div><span class="text-[9px] font-bold text-gray-500">'+MES[+k.slice(4)-1]+' '+k.slice(2,4)+'</span></div>';}).join('')+'</div>';}
    return out;
  }

  /* modo */
  const sw=$('modeSwitch');function setMode(m){mode=m;sw.querySelectorAll('button').forEach(b=>{const on=b.dataset.mode===m;b.className=b.className.replace(/(bg-primary text-white|text-gray-600)/g,'').trim()+' '+(on?'bg-primary text-white':'text-gray-600');});paint();}
  sw.querySelectorAll('button').forEach(b=>b.addEventListener('click',()=>setMode(b.dataset.mode)));

  /* controles */
  let deb;qEl.addEventListener('input',()=>{clearTimeout(deb);deb=setTimeout(()=>{st.q=qEl.value;st.page=1;load();},350);});
  fTipoEl.addEventListener('change',()=>{st.tipo=fTipoEl.value;st.page=1;load();});
  fMetaEl.addEventListener('change',()=>{st.meta=fMetaEl.value;st.page=1;load();});
  fActEl.addEventListener('change',()=>{st.act=fActEl.value;st.page=1;load();});
  sortEl.addEventListener('change',()=>{st.sort=sortEl.value;if(st.sort==='act_item'){agrupar=true;$('agrupar').checked=true;}st.page=1;load();});
  $('gExpand').addEventListener('click',()=>{colapsados.clear();if(!agrupar){agrupar=true;$('agrupar').checked=true;}paint();});
  $('gCollapse').addEventListener('click',()=>{if(!agrupar){agrupar=true;$('agrupar').checked=true;}
    last.rows.forEach(d=>colapsados.add(d.ACTIV_OPERAT_COD||'—'));paint();});
  $('agrupar').addEventListener('change',e=>{agrupar=e.target.checked;if(agrupar){prevSort=st.sort;st.sort='act_item';}else if(st.sort==='act_item'){st.sort=prevSort||'mod_desc';}sortEl.value=st.sort;st.page=1;load();});
  perPageEl.addEventListener('change',()=>{st.perPage=+perPageEl.value;st.page=1;load();});
  /* Cargar todos los centros (botón del formulario y del estado inicial). */
  const btnAll=$('btnAll'); if(btnAll) btnAll.addEventListener('click',()=>load());
  /* pantalla completa de la tabla */
  function toggleFs(on){
    const t=$('viewTable'), b=$('btnFs').querySelector('i');
    const fs = on===undefined ? !t.classList.contains('fs') : on;
    t.classList.toggle('fs',fs);
    document.body.classList.toggle('fsOn',fs);
    b.className = fs ? 'fa-solid fa-compress' : 'fa-solid fa-expand';
    if(fs && mode!=='table') setMode('table');
    $('fsTot').textContent = $('pageInfo').textContent;
  }
  $('btnFs').addEventListener('click',()=>toggleFs());
  $('fsExit').addEventListener('click',()=>toggleFs(false));
  document.addEventListener('keydown',e=>{
    if(e.key==='Escape' && document.body.classList.contains('fsOn') && $('histModal').classList.contains('hidden')) toggleFs(false);});

  $('prev').addEventListener('click',()=>{if(st.page>1){st.page--;load();}});
  $('next').addEventListener('click',()=>{st.page++;load();});

  /* ── Acciones de ESTA pantalla en la paleta Ctrl+K ──
     accesos.php se incluye antes de este script, así que SIGA.accion existe. */
  if(window.SIGA&&SIGA.accion){
    SIGA.accion('Cargar todos los centros','fa-layer-group',()=>load(),'Consulta el CMN completo de la entidad');
    SIGA.accion('Exportar a Excel','fa-file-excel',()=>{updateExport();$('expExcel').click();},'Descarga el CMN con los filtros y campos actuales');
    SIGA.accion('Exportar a PDF','fa-file-pdf',()=>{updateExport();window.open($('expPdf').href,'_blank');},'Abre el PDF con los filtros y campos actuales');
    SIGA.accion('Pantalla completa','fa-expand',()=>toggleFs(true),'Tabla a pantalla completa (Esc para salir)');
    SIGA.accion('Vista tabla','fa-table-list',()=>setMode('table'),'Cambia a la vista de tabla');
    SIGA.accion('Vista kanban','fa-table-columns',()=>setMode('kanban'),'Cambia a la vista kanban por estado');
    SIGA.accion('Elegir campos visibles','fa-list-check',()=>$('btnCols').click(),'Abre el selector de columnas');
    SIGA.accion('Buscar ítem','fa-magnifying-glass',()=>{qEl.focus();qEl.select();},'Enfoca el buscador de ítems');
    SIGA.accion('Expandir todas las actividades','fa-up-right-and-down-left-from-center',()=>$('gExpand').click(),'Abre todos los bloques del agrupado');
    SIGA.accion('Contraer todas las actividades','fa-down-left-and-up-right-to-center',()=>$('gCollapse').click(),'Cierra todos los bloques del agrupado');
  }

  // set selects a estado inicial
  sortEl.value=st.sort; perPageEl.value=st.perPage;
  agrupar=(st.sort==='act_item');$('agrupar').checked=agrupar;
  setMode('table');
  /* Con centro elegido se consulta directo; sin centro, estado inicial. */
  if(CC!=='') load(); else placeholder();
})();
</script>
</body></html>