<?php
/**
 * VIEW + ENTRADA · SIGA-REPORTER · Tabla/Kanban con paginado y filtros de servidor
 * 3 capas: Query · Service · View.  Servir: php -S localhost:8000 -t E:\SIGA-REPORTER
 *
 * ESTADOS (3): Programado · Modificado · Ejecutado. El estado de cada ítem llega
 * ya clasificado desde la capa Query en la columna ESTADO_FASE.
 */
require __DIR__ . '/CmnQuery.php';
require __DIR__ . '/ExportService.php';

const DB_SERVER='localhost'; const DB_NAME='SIGA_104'; const DB_USER=''; const DB_PASS=''; const SEC_EJEC=104;

$resource  = $_GET['resource'] ?? 'cmn';
$anioProg  = (int)($_GET['anio'] ?? 2026);
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
$fSort   = (string)($_GET['sort'] ?? 'mod_desc');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = min(200, max(10, (int)($_GET['perPage'] ?? 50)));

if ($resource !== 'cmn') { http_response_code(404); exit('Recurso no encontrado'); }

try {
    $q        = new CmnQuery(DB_SERVER, DB_NAME, DB_USER, DB_PASS);
    $anioEjec = $q->ejecYear($anioProg, SEC_EJEC);
    if ($anioEjec === null) throw new RuntimeException("No hay CMN programado para el año {$anioProg}.");
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre style="color:#b91c1c">Error: '.htmlspecialchars($e->getMessage()).'</pre>'; exit;
}

/* ---- EXPORTACIÓN (respeta filtros; sin paginar) ---- */
if ($export === 'excel' || $export === 'pdf') {
    $all = $q->rows($anioProg,$anioEjec,SEC_EJEC,$ccosto,$fTipo,$fQ,$fMeta,$fAct,$fFase,$fSort,1,100000)['rows'];
    $nombre = 'CMN_'.$anioProg.($ccosto ? '_'.str_replace('.','',$ccosto) : '_TODOS');
    if ($export==='excel') ExportService::excel($all,$nombre); else ExportService::pdf($all,'Cuadro de Necesidades '.$anioProg);
    exit;
}

/* ---- ENDPOINT: historial ---- */
if ($action === 'historial') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        echo json_encode($q->historial($anioProg,$anioEjec,SEC_EJEC,(string)($_GET['cc']??$ccosto),
            (string)($_GET['t']??''),(string)($_GET['g']??''),(string)($_GET['c']??''),
            (string)($_GET['f']??''),(string)($_GET['it']??''),(int)($_GET['meta']??0),(string)($_GET['clasif']??'')), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
    exit;
}

/* ---- ENDPOINT: datos paginados + resumen ---- */
if ($action === 'data') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $res = $q->rows($anioProg,$anioEjec,SEC_EJEC,$ccosto,$fTipo,$fQ,$fMeta,$fAct,$fFase,$fSort,$page,$perPage);
        $sum = $q->summary($anioProg,$anioEjec,SEC_EJEC,$ccosto,$fTipo,$fQ,$fMeta,$fAct);
        echo json_encode(['rows'=>$res['rows'],'total'=>$res['total'],'page'=>$page,'perPage'=>$perPage,'summary'=>$sum], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
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
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>CMN <?= $anioProg ?> · Tabla / Kanban</title>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{colors:{
  primary:{light:'rgb(72,230,198)',DEFAULT:'rgb(26,187,156)',dark:'rgb(20,150,125)'},
  secondary:{DEFAULT:'#0d6efd',light:'#4d94ff',dark:'#0a58ca'},warning:'#ffc107',info:'#0dcaf0'}}}};</script>
</head>
<body class="bg-gray-50 text-gray-800">
<div class="flex min-h-screen">
  <aside class="w-52 shrink-0 bg-white border-r border-gray-200 hidden lg:block">
    <div class="px-4 py-4 border-b border-gray-100"><span class="text-primary-dark font-bold text-sm">SIGA · REPORTES</span></div>
    <nav class="p-2 text-sm"><a href="?resource=cmn&anio=<?= $anioProg ?>" class="block px-3 py-2 rounded-lg bg-primary/10 text-primary-dark font-medium">Cuadro de Necesidades</a></nav>
  </aside>
  <main class="flex-1 min-w-0 p-3 sm:p-4 flex flex-col">
    <div class="lg:hidden mb-3"><span class="text-primary-dark font-bold text-sm">SIGA · REPORTES</span></div>
    <header class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-3">
      <h1 class="text-base sm:text-lg font-bold leading-tight">Cuadro de Necesidades <span class="text-primary">· <?= $anioProg ?></span>
        <span class="block sm:inline text-xs text-gray-400 font-normal">(ejecución <?= $anioEjec ?>) · <span id="totLbl">…</span></span></h1>
      <div class="flex gap-2">
        <div class="inline-flex rounded-lg border border-gray-300 overflow-hidden text-sm" id="modeSwitch">
          <button data-mode="table" class="px-3 py-2 font-medium">Tabla</button>
          <button data-mode="kanban" class="px-3 py-2 font-medium border-l border-gray-300">Kanban</button>
        </div>
        <a id="expExcel" href="#" class="px-3 py-2 text-sm rounded-lg bg-primary text-white hover:bg-primary-dark">Excel</a>
        <a id="expPdf" href="#" target="_blank" class="px-3 py-2 text-sm rounded-lg bg-secondary text-white hover:bg-secondary-dark">PDF</a>
      </div>
    </header>

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
          <option value="mod_desc">Mayor importe</option><option value="mod_asc">Menor importe</option><option value="item_asc">Nombre A-Z</option></select>
      </div>
    </div>

    <div id="viewTable" class="bg-white rounded-xl border border-gray-200 overflow-auto max-h-[calc(100vh-320px)]" style="-webkit-overflow-scrolling:touch">
      <table class="min-w-full text-xs whitespace-nowrap">
        <thead class="sticky top-0 z-10"><tr id="thead" class="bg-primary text-white"></tr></thead>
        <tbody id="tbody" class="divide-y divide-gray-100"></tbody>
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

<style type="text/tailwindcss">@layer components{.input-bordered{@apply w-full px-3 py-2.5 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all;}}</style>

<script>
/* ===== Buscador centro de costo (recarga servidor) ===== */
(function(){const centros=<?= $jsonCent ?>;const box=document.getElementById('ccBox'),s=document.getElementById('ccSearch'),v=document.getElementById('ccValue'),l=document.getElementById('ccList'),cl=document.getElementById('ccClear'),fm=s.closest('form');
const nz=x=>(x||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');const ec=x=>(x||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function pt(t,q){if(!q)return ec(t);const nt=nz(t),nq=nz(q);let o='',i=0,x;while((x=nt.indexOf(nq,i))!==-1){o+=ec(t.slice(i,x))+'<mark class="bg-primary/20 text-primary-dark rounded px-0.5">'+ec(t.slice(x,x+q.length))+'</mark>';i=x+q.length;if(!nq.length)break;}return o+ec(t.slice(i));}
function rd(q){const nq=nz(q),m=centros.filter(c=>nz(c.cod+' '+c.nombre).includes(nq)).slice(0,60);l.innerHTML='';const a=document.createElement('li');a.className='px-3 py-2 cursor-pointer hover:bg-primary/5 text-gray-500 border-b';a.textContent='— Todos los centros —';a.onclick=()=>pk('','');l.appendChild(a);m.forEach(c=>{const li=document.createElement('li');li.className='px-3 py-2 cursor-pointer hover:bg-primary/5';li.innerHTML='<b class="text-gray-700">'+pt(c.cod,q)+'</b> <span class="text-gray-500">· '+pt(c.nombre,q)+'</span>';li.onclick=()=>pk(c.cod,c.cod+'  ·  '+c.nombre);l.appendChild(li);});l.classList.remove('hidden');}
function pk(cod,lab){v.value=cod;s.value=cod?lab:'';l.classList.add('hidden');cl.classList.toggle('hidden',!cod);fm.submit();}
s.addEventListener('input',()=>rd(s.value));s.addEventListener('focus',()=>rd(s.value));cl.addEventListener('click',()=>pk('',''));document.addEventListener('click',e=>{if(!box.contains(e.target))l.classList.add('hidden');});})();

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
  let mode='table', last={rows:[],total:0,summary:[]};

  function params(extra){return new URLSearchParams(Object.assign({resource:'cmn',anio:ANIO,ccosto:CC,tipo:st.tipo,q:st.q,meta:st.meta,act:st.act,fase:st.fase,sort:st.sort,page:st.page,perPage:st.perPage},extra||{}));}
  function updateExport(){$('expExcel').href='?'+params({export:'excel'}).toString();$('expPdf').href='?'+params({export:'pdf'}).toString();}

  /* Detalle de órdenes (trazabilidad SIGA): conserva las fases nativas de cada O/C u O/S. */
  function badge(estado){return (estado||'').split(',').map(s=>s.trim()).filter(Boolean).map(p=>{const e=p.toUpperCase();let c='bg-gray-100 text-gray-600';if(e.includes('DEVENGADO'))c='bg-primary/15 text-primary-dark';else if(e.includes('COMPROMETIDO'))c='bg-secondary/15 text-secondary-dark';else if(e.includes('CERTIFICADO'))c='bg-warning/20 text-yellow-700';else if(e.includes('PENDIENTE'))c='bg-gray-100 text-gray-500';return '<span class="inline-block px-1.5 py-0.5 rounded-full text-[10px] '+c+'">'+ec(p)+'</span>';}).join(' ');}

  function renderChips(sum){const map={};let tc=0,tm=0;sum.forEach(s=>{map[s.fase]={c:+s.c,m:+s.monto};tc+=+s.c;tm+=+s.monto;});chipsEl.innerHTML='';
    chipsEl.appendChild(chip('Todos',tc,tm,'bg-gray-800 text-white','ring-gray-800',st.fase==='',()=>{st.fase='';st.page=1;load();}));
    FASES.forEach(f=>{const g=map[f.key]||{c:0,m:0};chipsEl.appendChild(chip(f.label,g.c,g.m,f.chip,f.ring,st.fase===f.key,()=>{st.fase=(st.fase===f.key?'':f.key);st.page=1;load();},f.dot));});}
  function chip(label,count,monto,cls,ring,active,onclick,dot){const b=document.createElement('button');b.className='px-3 py-1.5 rounded-full text-xs font-medium flex items-center gap-2 transition-all '+cls+(active?(' ring-2 ring-offset-1 '+ring):' opacity-90 hover:opacity-100');b.innerHTML=(dot?'<span class="w-2 h-2 rounded-full '+dot+'"></span>':'')+'<span>'+label+'</span><span class="opacity-60">·</span><span>'+count+'</span><span class="opacity-60">S/ '+money(monto)+'</span>';b.onclick=onclick;return b;}

  function renderTable(rows){
    theadEl.innerHTML='<th class="px-2 py-2 w-6"></th>'+HKEYS.map(k=>'<th class="px-2 py-2 font-semibold '+(NUM.has(k)?'text-right':'text-left')+'">'+ec(HEADERS[k])+'</th>').join('');
    tbodyEl.innerHTML=rows.length?rows.map((d,idx)=>{const f=FMAP[faseKey(d)];const tint=f.tint||'bg-white';
      const at='data-idx="'+idx+'" data-cc="'+ec(d.CCOSTO_COD)+'" data-t="'+ec(d.TIPO_BIEN)+'" data-g="'+ec(d.GRUPO_BIEN)+'" data-c="'+ec(d.CLASE_BIEN)+'" data-f="'+ec(d.FAMILIA_BIEN)+'" data-it="'+ec(d.ITEM_BIEN)+'" data-meta="'+ec(d.META)+'" data-clasif="'+ec(d.CLASIF_COD)+'"';
      return '<tr class="'+tint+' hover:brightness-95 cursor-pointer trow" '+at+'><td class="px-2 py-1 text-gray-400" title="Ver trazabilidad">▸</td>'
        +HKEYS.map(k=>k==='ESTADO_ORDEN'?'<td class="px-2 py-1"><div class="flex flex-wrap gap-1">'+badge(d[k])+'</div></td>':NUM.has(k)?'<td class="px-2 py-1 text-right tabular-nums">'+money(d[k])+'</td>':'<td class="px-2 py-1">'+ec(d[k])+'</td>').join('')+'</tr>';
    }).join(''):'<tr><td colspan="'+(HKEYS.length+1)+'" class="px-3 py-6 text-center text-gray-400">Sin resultados</td></tr>';
    const tP=rows.reduce((s,d)=>s+ +d.IMPORTE_PROG,0),tM=rows.reduce((s,d)=>s+ +d.IMPORTE_MOD,0),tE=rows.reduce((s,d)=>s+ +d.IMPORTE_EJEC,0),tD=rows.reduce((s,d)=>s+ +d.DIFERENCIA,0);
    const iP=HKEYS.indexOf('IMPORTE_PROG')+1;let cells='<td colspan="'+iP+'" class="px-2 py-2 text-right">SUBTOTAL PÁGINA</td>';
    for(let i=iP-1;i<HKEYS.length;i++){const k=HKEYS[i];cells+=k==='IMPORTE_PROG'?'<td class="px-2 py-2 text-right tabular-nums">'+money(tP)+'</td>':k==='IMPORTE_MOD'?'<td class="px-2 py-2 text-right tabular-nums">'+money(tM)+'</td>':k==='IMPORTE_EJEC'?'<td class="px-2 py-2 text-right tabular-nums">'+money(tE)+'</td>':k==='DIFERENCIA'?'<td class="px-2 py-2 text-right tabular-nums text-primary-dark">'+money(tD)+'</td>':'<td></td>';}
    tfootEl.innerHTML='<tr class="bg-gray-100 font-bold text-gray-700">'+cells+'</tr>';
  }
  function renderKanban(rows){vKanban.innerHTML='';const vis=st.fase?FASES.filter(f=>f.key===st.fase):FASES;
    vis.forEach(f=>{const g=[];rows.forEach((d,i)=>{if(faseKey(d)===f.key)g.push({d,i});});const monto=g.reduce((s,x)=>s+ +x.d.IMPORTE_MOD,0);
      const col=document.createElement('div');col.className='flex-shrink-0 w-72 bg-gray-100/70 rounded-xl flex flex-col max-h-[calc(100vh-360px)]';
      col.innerHTML='<div class="p-3 border-b border-gray-200"><div class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full '+f.dot+'"></span><span class="font-semibold text-sm text-gray-700">'+f.label+'</span><span class="ml-auto text-xs bg-white px-2 py-0.5 rounded-full text-gray-500">'+g.length+'</span></div><div class="text-[11px] text-gray-500 mt-1">S/ '+money(monto)+' (página)</div></div>';
      const body=document.createElement('div');body.className='p-2 space-y-2 overflow-y-auto';
      body.innerHTML=g.length?g.map(x=>card(x.d,f,x.i)).join(''):'<p class="text-center text-xs text-gray-400 py-6">Sin ítems</p>';
      col.appendChild(body);vKanban.appendChild(col);});}
  function card(d,f,idx){return '<div class="bg-white rounded-lg border border-gray-200 border-l-4 '+f.col+' p-2.5 shadow-sm cursor-pointer hover:shadow kcard" data-idx="'+idx+'"><p class="text-[13px] font-medium leading-snug">'+ec(d.NOMBRE_ITEM)+'</p><p class="text-[11px] text-gray-400 mt-0.5">Meta '+ec(d.META)+' · '+ec(d.ACTIV_OPERAT_COD)+' · '+ec(d.UNIDAD_MEDIDA)+'</p>'+(d.ESTADO_ORDEN?'<div class="mt-1 flex flex-wrap gap-1">'+badge(d.ESTADO_ORDEN)+'</div>':'')+'<div class="grid grid-cols-4 gap-1 mt-2 text-center">'+mini('Prog',d.IMPORTE_PROG)+mini('Mod',d.IMPORTE_MOD)+mini('Ejec',d.IMPORTE_EJEC)+mini('Dif',d.DIFERENCIA,1)+'</div></div>';}
  function mini(l,v,hl){return '<div class="rounded '+(hl?'bg-primary/5':'bg-gray-50')+' py-1"><div class="text-[9px] text-gray-400 uppercase">'+l+'</div><div class="text-[11px] font-semibold tabular-nums '+(hl?'text-primary-dark':'')+'">'+money(v)+'</div></div>';}

  function paint(){if(mode==='table'){vTable.classList.remove('hidden');vKanban.classList.add('hidden');renderTable(last.rows);}else{vKanban.classList.remove('hidden');vTable.classList.add('hidden');renderKanban(last.rows);}}
  function renderPager(){const t=last.total,pp=st.perPage,from=t?((st.page-1)*pp+1):0,to=Math.min(t,st.page*pp),pages=Math.max(1,Math.ceil(t/pp));
    $('totLbl').textContent=t+' ítems';$('pageInfo').textContent=from+'–'+to+' de '+t;$('pageNum').textContent='Pág. '+st.page+' / '+pages;
    $('prev').disabled=st.page<=1;$('next').disabled=st.page>=pages;}

  async function load(){
    tbodyEl.innerHTML='<tr><td colspan="'+(HKEYS.length+1)+'" class="px-3 py-6 text-center text-gray-400">Cargando…</td></tr>';
    updateExport();
    try{const r=await fetch('?'+params({action:'data'}).toString());const j=await r.json();
      if(j.error){tbodyEl.innerHTML='<tr><td colspan="'+(HKEYS.length+1)+'" class="px-3 py-6 text-center text-red-600">'+ec(j.error)+'</td></tr>';return;}
      last=j;renderChips(j.summary);paint();renderPager();
    }catch(e){tbodyEl.innerHTML='<tr><td colspan="'+(HKEYS.length+1)+'" class="px-3 py-6 text-center text-red-600">Error de red</td></tr>';}
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
    $('hmSub').textContent=d.CCOSTO_COD+' · '+d.CCOSTO_NOMBRE+'   |   Meta '+d.META+' · '+d.CLASIF_COD+' · '+d.ACTIV_OPERAT_COD+' · '+(d.UNIDAD_MEDIDA||'');
    const ah=new Date();$('hmFecha').textContent='CONSULTA: '+ah.toLocaleDateString('es-PE')+' · '+ah.toLocaleTimeString('es-PE');
    $('hmResumen').innerHTML='<p class="text-xs text-gray-400 py-8 text-center">Cargando expediente…</p>';$('hmKardex').innerHTML='';
    setTab('resumen');hmOpen();
    let h=histCache[key];
    if(!h){const p=new URLSearchParams({resource:'cmn',anio:ANIO,action:'historial',cc:d.CCOSTO_COD,t:d.TIPO_BIEN,g:d.GRUPO_BIEN,c:d.CLASE_BIEN,f:d.FAMILIA_BIEN,it:d.ITEM_BIEN,meta:d.META,clasif:d.CLASIF_COD});
      try{h=await(await fetch('?'+p.toString())).json();histCache[key]=h;}
      catch(x){$('hmResumen').innerHTML='<p class="text-xs text-red-600">No se pudo cargar el expediente.</p>';return;}}
    if(h.error){$('hmResumen').innerHTML='<p class="text-xs text-red-600">'+ec(h.error)+'</p>';return;}
    $('hmResumen').innerHTML=renderResumen(h,d);
    $('hmKardex').innerHTML=renderKardex(h,d);
  }
  tbodyEl.addEventListener('click',e=>{const tr=e.target.closest('.trow');if(tr)openHist(tr);});
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

  /* — RESUMEN EJECUTIVO — */
  function renderResumen(h,d){
    const {cua,con,cer,ord,fas}=prep(h);
    const dev=fas.filter(r=>r.fase==='Devengado'),com=fas.filter(r=>r.fase==='Comprometido');
    const P=+d.IMPORTE_PROG,M=+d.IMPORTE_MOD,E=+d.IMPORTE_EJEC;
    const max=Math.max(P,M,E,1);
    const bar=(l,v,c)=>'<div class="flex items-center gap-2 py-1"><span class="w-36 text-[10px] uppercase tracking-wide text-gray-500 text-right shrink-0">'+l+'</span><div class="flex-1 h-4 bg-gray-100 rounded-sm overflow-hidden"><div class="h-full transition-all" style="width:'+Math.max(1,v/max*100)+'%;background:'+c+'"></div></div><span class="w-28 text-right text-[11px] font-bold tabular-nums shrink-0" style="color:'+c+'">S/ '+money(v)+'</span></div>';
    const pct=M>0?Math.min(999,E/M*100):0;
    const pcol=pct>=70?INK.verde:pct>=50?'#d97706':INK.rojo;
    let out=secT('Situación económica del ítem')
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
 
    /* narrativa de 5 pasos */
    const paso=(n,c,t,b,fin)=>'<div class="flex gap-3"><div class="flex flex-col items-center shrink-0"><div class="w-6 h-6 rounded-full flex items-center justify-center text-white text-[10px] font-bold" style="background:'+c+'">'+n+'</div>'+(fin?'':'<div class="w-px flex-1 my-1" style="background:#e5e7eb"></div>')+'</div><div class="pb-4 flex-1 min-w-0"><div class="text-[10px] font-bold uppercase tracking-wide mb-1" style="color:'+c+'">'+t+'</div><div class="space-y-0.5">'+b+'</div></div></div>';
    let p1=ori?fila('El centro programó en el Cuadro de Necesidades','<b>'+(+ori.cant)+' × S/ '+money(ori.precio)+' = S/ '+money(ori.monto)+'</b>'):'<span class="text-xs text-gray-400">Sin registro del cuadro original.</span>';
    mods.forEach(r=>{p1+=fila('Cuadro vigente'+(r.estado?' · '+ec(r.estado):'')+(r.fecha?' <span class="text-gray-400 italic">('+ec(r.fecha)+')</span>':''),(+r.cant)+' × S/ '+money(r.precio)+' = <b>S/ '+money(r.monto)+'</b>');});
    let p2=con.length?con.map(r=>{const cambio=(base!==null&&Math.abs(base-(+r.precio))>0.005)
        ?'<div class="mt-1 text-[11px] px-2 py-1 rounded" style="background:#fffbeb;border:1px solid #fde68a">Precio referencial <span class="line-through text-gray-400">S/ '+money(base)+'</span> → <b style="color:'+((+r.precio)>base?INK.rojo:INK.verde)+'">S/ '+money(r.precio)+'</b> · fijado por Logística en el estudio de mercado del consolidado.</div>':'';
      return fila('Consolidado N° <b>'+ec(r.nro)+'</b>'+(r.nro_cert?' · Cert. '+ec(r.nro_cert):'')+' <span class="text-gray-400 italic">('+ec(r.fecha_precio||r.fecha_consolid||'')+')</span>','<b>'+(+r.cant)+' × S/ '+money(r.precio)+' = S/ '+money(r.monto)+'</b>')+cambio;}).join('')
      :'<span class="text-xs text-gray-400">Aún no consolidado por Logística: rige el precio referencial.</span>';
    let p3=cer.length?cer.map(r=>{const anu=/Anulada/i.test(r.estado||'');
      return fila((anu?'<span class="line-through text-gray-400">':'')+'Cert. '+ec(r.nro)+' · '+ec(r.estado)+(anu?' <b style="color:'+INK.rojo+'" class="no-underline">ANULADO</b></span>':'')+' <span class="text-gray-400 italic">('+ec(r.fecha)+')</span>','<span class="'+(anu?'line-through text-gray-400':'font-bold')+'">S/ '+money(r.monto)+'</span>',anu?'rounded':'').replace('border-b py-1 rounded','border-b py-1 rounded px-1" style="background:#fdf2f2;border-color:#e5e7eb');}).join('')
      :'<span class="text-xs text-gray-400">Sin certificación presupuestal todavía.</span>';
    let p4=ord.length?ord.map(r=>fila('<b>'+ec(r.orden)+'</b> · '+ec(r.proveedor)+' <span class="text-gray-400 italic">('+ec(r.fecha)+')</span>',(+r.cant)+' × S/ '+money(r.precio)+' = <b>S/ '+money(r.monto)+'</b>')).join(''):'<span class="text-xs text-gray-400">Sin orden de compra/servicio emitida.</span>';
    const totDev=dev.reduce((s,r)=>s+ +r.monto,0);
    let p5=dev.length?dev.map(r=>fila(ec(r.doc)+' <span class="text-gray-400 italic">('+ec(r.fecha||'')+')</span>','S/ '+money(r.monto))).join('')+fila('<b>Total devengado</b>','<b style="color:'+INK.verde+'">S/ '+money(totDev)+'</b>')
      :com.length?'<span class="text-xs" style="color:'+INK.azul+'">Comprometido con orden emitida, aún sin devengar — el gasto está reservado pero todavía no se recibe/factura.</span>'
      :'<span class="text-xs text-gray-400">Sin ejecución registrada.</span>';
    out+=secT('¿Qué pasó con este ítem? · flujo del gasto')
      +paso(1,INK.gris,'Programación · Cuadro de Necesidades',p1)
      +paso(2,INK.violeta,'Consolidación PAAC · aquí se fija el precio real',p2)
      +paso(3,INK.ambar,'Certificación presupuestal',p3)
      +paso(4,INK.azul,'Orden de compra / servicio',p4)
      +paso(5,INK.verde,'Ejecución (devengado)',p5,true)
      +'<p class="text-[10px] text-gray-400 italic mt-1">El girado y pagado se registran en el SIAF.</p>';
    return out;
  }

  /* — KÁRDEX CRONOLÓGICO — */
  function tcolor(t){return t==='DEVENGADO'?INK.verde:t==='COMPROMISO'?INK.azul:t==='ORDEN'?'#0284c7':t==='CERTIFICACIÓN'?INK.ambar:t==='CONSOLIDADO'?INK.violeta:t==='MODIFICACIÓN'?'#d97706':'#374151';}
  function renderKardex(h,d){
    const {cua,con,cer,ord,fas}=prep(h);
    const ev=[];
    const ori=cua.find(r=>r.etapa==='Original');
    if(ori)ev.push({f:'',tipo:'PROGRAMACIÓN',doc:'CMN',det:'Cuadro original: '+(+ori.cant)+' × S/ '+money(ori.precio),m:+ori.monto});
    cua.filter(r=>r.etapa==='Modificado').forEach(r=>ev.push({f:r.fecha||'',tipo:'MODIFICACIÓN',doc:'CMN'+(r.estado?' ('+r.estado+')':''),det:'Cuadro vigente: '+(+r.cant)+' × S/ '+money(r.precio),m:+r.monto}));
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
  sortEl.addEventListener('change',()=>{st.sort=sortEl.value;st.page=1;load();});
  perPageEl.addEventListener('change',()=>{st.perPage=+perPageEl.value;st.page=1;load();});
  $('prev').addEventListener('click',()=>{if(st.page>1){st.page--;load();}});
  $('next').addEventListener('click',()=>{st.page++;load();});

  // set selects a estado inicial
  sortEl.value=st.sort; perPageEl.value=st.perPage;
  setMode('table'); load();
})();
</script>
</body></html>