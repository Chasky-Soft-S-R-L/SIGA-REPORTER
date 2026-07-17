<?php
/**
 * VIEW + ENTRADA · SIGA-REPORTER · Tabla/Kanban con paginado y filtros de servidor
 * 3 capas: Query · Service · View.  Servir: php -S localhost:8000 -t E:\SIGA-REPORTER
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
  const FASES=[
    {key:'PENDIENTE',label:'Pendiente',dot:'bg-gray-400',col:'border-gray-300',chip:'bg-gray-100 text-gray-600',ring:'ring-gray-400',tint:''},
    {key:'CERTIFICADO',label:'Certificado',dot:'bg-warning',col:'border-warning',chip:'bg-warning/20 text-yellow-700',ring:'ring-warning',tint:'bg-warning/10'},
    {key:'COMPROMETIDO',label:'Comprometido',dot:'bg-secondary',col:'border-secondary',chip:'bg-secondary/15 text-secondary-dark',ring:'ring-secondary',tint:'bg-secondary/5'},
    {key:'DEVENGADO',label:'Devengado',dot:'bg-primary',col:'border-primary',chip:'bg-primary/15 text-primary-dark',ring:'ring-primary',tint:'bg-primary/5'}];
  const FMAP=Object.fromEntries(FASES.map(f=>[f.key,f]));
  const money=n=>(+n||0).toLocaleString('es-PE',{minimumFractionDigits:2,maximumFractionDigits:2});
  const ec=s=>(s||'').toString().replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const faseDe=e=>{e=(e||'').toUpperCase();return e.includes('DEVENGADO')?'DEVENGADO':e.includes('COMPROMETIDO')?'COMPROMETIDO':e.includes('CERTIFICADO')?'CERTIFICADO':'PENDIENTE';};

  const $=id=>document.getElementById(id);
  const chipsEl=$('chips'),qEl=$('q'),fTipoEl=$('fTipo'),fMetaEl=$('fMeta'),fActEl=$('fAct'),sortEl=$('sort'),perPageEl=$('perPage');
  const vTable=$('viewTable'),vKanban=$('viewKanban'),theadEl=$('thead'),tbodyEl=$('tbody'),tfootEl=$('tfoot');
  const st={tipo:'<?= $fTipo ?>',q:<?= json_encode($fQ) ?>,meta:'<?= htmlspecialchars($fMeta) ?>',act:'<?= htmlspecialchars($fAct) ?>',fase:'<?= htmlspecialchars($fFase) ?>',sort:'<?= htmlspecialchars($fSort) ?>',page:<?= $page ?>,perPage:<?= $perPage ?>};
  let mode='table', last={rows:[],total:0,summary:[]};

  function params(extra){return new URLSearchParams(Object.assign({resource:'cmn',anio:ANIO,ccosto:CC,tipo:st.tipo,q:st.q,meta:st.meta,act:st.act,fase:st.fase,sort:st.sort,page:st.page,perPage:st.perPage},extra||{}));}
  function updateExport(){$('expExcel').href='?'+params({export:'excel'}).toString();$('expPdf').href='?'+params({export:'pdf'}).toString();}

  function badge(estado){return (estado||'').split(',').map(s=>s.trim()).filter(Boolean).map(p=>{const e=p.toUpperCase();let c='bg-gray-100 text-gray-600';if(e.includes('DEVENGADO'))c='bg-primary/15 text-primary-dark';else if(e.includes('COMPROMETIDO'))c='bg-secondary/15 text-secondary-dark';else if(e.includes('CERTIFICADO'))c='bg-warning/20 text-yellow-700';else if(e.includes('PENDIENTE'))c='bg-gray-100 text-gray-500';return '<span class="inline-block px-1.5 py-0.5 rounded-full text-[10px] '+c+'">'+ec(p)+'</span>';}).join(' ');}

  function renderChips(sum){const map={};let tc=0,tm=0;sum.forEach(s=>{map[s.fase]={c:+s.c,m:+s.monto};tc+=+s.c;tm+=+s.monto;});chipsEl.innerHTML='';
    chipsEl.appendChild(chip('Todos',tc,tm,'bg-gray-800 text-white','ring-gray-800',st.fase==='',()=>{st.fase='';st.page=1;load();}));
    FASES.forEach(f=>{const g=map[f.key]||{c:0,m:0};chipsEl.appendChild(chip(f.label,g.c,g.m,f.chip,f.ring,st.fase===f.key,()=>{st.fase=(st.fase===f.key?'':f.key);st.page=1;load();},f.dot));});}
  function chip(label,count,monto,cls,ring,active,onclick,dot){const b=document.createElement('button');b.className='px-3 py-1.5 rounded-full text-xs font-medium flex items-center gap-2 transition-all '+cls+(active?(' ring-2 ring-offset-1 '+ring):' opacity-90 hover:opacity-100');b.innerHTML=(dot?'<span class="w-2 h-2 rounded-full '+dot+'"></span>':'')+'<span>'+label+'</span><span class="opacity-60">·</span><span>'+count+'</span><span class="opacity-60">S/ '+money(monto)+'</span>';b.onclick=onclick;return b;}

  function renderTable(rows){
    theadEl.innerHTML='<th class="px-2 py-2 w-6"></th>'+HKEYS.map(k=>'<th class="px-2 py-2 font-semibold '+(NUM.has(k)?'text-right':'text-left')+'">'+ec(HEADERS[k])+'</th>').join('');
    tbodyEl.innerHTML=rows.length?rows.map((d,idx)=>{const f=FMAP[faseDe(d.ESTADO_ORDEN)];const tint=f.tint||'bg-white';
      const at='data-cc="'+ec(d.CCOSTO_COD)+'" data-t="'+ec(d.TIPO_BIEN)+'" data-g="'+ec(d.GRUPO_BIEN)+'" data-c="'+ec(d.CLASE_BIEN)+'" data-f="'+ec(d.FAMILIA_BIEN)+'" data-it="'+ec(d.ITEM_BIEN)+'" data-meta="'+ec(d.META)+'" data-clasif="'+ec(d.CLASIF_COD)+'"';
      return '<tr class="'+tint+' hover:brightness-95 cursor-pointer trow" '+at+'><td class="px-2 py-1 text-gray-400"><span class="chev inline-block transition-transform">▸</span></td>'
        +HKEYS.map(k=>k==='ESTADO_ORDEN'?'<td class="px-2 py-1"><div class="flex flex-wrap gap-1">'+badge(d[k])+'</div></td>':NUM.has(k)?'<td class="px-2 py-1 text-right tabular-nums">'+money(d[k])+'</td>':'<td class="px-2 py-1">'+ec(d[k])+'</td>').join('')+'</tr>'
        +'<tr class="detail hidden"><td colspan="'+(HKEYS.length+1)+'" class="p-0"><div class="hbox px-4 py-3 bg-gray-50 border-l-4 '+f.col+'"></div></td></tr>';
    }).join(''):'<tr><td colspan="'+(HKEYS.length+1)+'" class="px-3 py-6 text-center text-gray-400">Sin resultados</td></tr>';
    const tP=rows.reduce((s,d)=>s+ +d.IMPORTE_PROG,0),tM=rows.reduce((s,d)=>s+ +d.IMPORTE_MOD,0),tE=rows.reduce((s,d)=>s+ +d.IMPORTE_EJEC,0),tD=rows.reduce((s,d)=>s+ +d.DIFERENCIA,0);
    const iP=HKEYS.indexOf('IMPORTE_PROG')+1;let cells='<td colspan="'+iP+'" class="px-2 py-2 text-right">SUBTOTAL PÁGINA</td>';
    for(let i=iP-1;i<HKEYS.length;i++){const k=HKEYS[i];cells+=k==='IMPORTE_PROG'?'<td class="px-2 py-2 text-right tabular-nums">'+money(tP)+'</td>':k==='IMPORTE_MOD'?'<td class="px-2 py-2 text-right tabular-nums">'+money(tM)+'</td>':k==='IMPORTE_EJEC'?'<td class="px-2 py-2 text-right tabular-nums">'+money(tE)+'</td>':k==='DIFERENCIA'?'<td class="px-2 py-2 text-right tabular-nums text-primary-dark">'+money(tD)+'</td>':'<td></td>';}
    tfootEl.innerHTML='<tr class="bg-gray-100 font-bold text-gray-700">'+cells+'</tr>';
  }
  function renderKanban(rows){vKanban.innerHTML='';const vis=st.fase?FASES.filter(f=>f.key===st.fase):FASES;
    vis.forEach(f=>{const g=rows.filter(d=>faseDe(d.ESTADO_ORDEN)===f.key);const monto=g.reduce((s,d)=>s+ +d.IMPORTE_MOD,0);
      const col=document.createElement('div');col.className='flex-shrink-0 w-72 bg-gray-100/70 rounded-xl flex flex-col max-h-[calc(100vh-360px)]';
      col.innerHTML='<div class="p-3 border-b border-gray-200"><div class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-full '+f.dot+'"></span><span class="font-semibold text-sm text-gray-700">'+f.label+'</span><span class="ml-auto text-xs bg-white px-2 py-0.5 rounded-full text-gray-500">'+g.length+'</span></div><div class="text-[11px] text-gray-500 mt-1">S/ '+money(monto)+' (página)</div></div>';
      const body=document.createElement('div');body.className='p-2 space-y-2 overflow-y-auto';
      body.innerHTML=g.length?g.map(d=>card(d,f)).join(''):'<p class="text-center text-xs text-gray-400 py-6">Sin ítems</p>';
      col.appendChild(body);vKanban.appendChild(col);});}
  function card(d,f){return '<div class="bg-white rounded-lg border border-gray-200 border-l-4 '+f.col+' p-2.5 shadow-sm"><p class="text-[13px] font-medium leading-snug">'+ec(d.NOMBRE_ITEM)+'</p><p class="text-[11px] text-gray-400 mt-0.5">Meta '+ec(d.META)+' · '+ec(d.ACTIV_OPERAT_COD)+' · '+ec(d.UNIDAD_MEDIDA)+'</p>'+(d.ESTADO_ORDEN?'<div class="mt-1 flex flex-wrap gap-1">'+badge(d.ESTADO_ORDEN)+'</div>':'')+'<div class="grid grid-cols-4 gap-1 mt-2 text-center">'+mini('Prog',d.IMPORTE_PROG)+mini('Mod',d.IMPORTE_MOD)+mini('Ejec',d.IMPORTE_EJEC)+mini('Dif',d.DIFERENCIA,1)+'</div></div>';}
  function mini(l,v,hl){return '<div class="rounded '+(hl?'bg-primary/5':'bg-gray-50')+' py-1"><div class="text-[9px] text-gray-400 uppercase">'+l+'</div><div class="text-[11px] font-semibold tabular-nums '+(hl?'text-primary-dark':'')+'">'+money(v)+'</div></div>';}

  function paint(){if(mode==='table'){vTable.classList.remove('hidden');vKanban.classList.add('hidden');renderTable(last.rows);}else{vKanban.classList.remove('hidden');vTable.classList.add('hidden');renderKanban(last.rows);}}
  function renderPager(){const t=last.total,pp=st.perPage,from=t?((st.page-1)*pp+1):0,to=Math.min(t,st.page*pp),pages=Math.max(1,Math.ceil(t/pp));
    $('totLbl').textContent=t+' ítems';$('pageInfo').textContent=from+'–'+to+' de '+t;$('pageNum').textContent='Pág. '+st.page+' / '+pages;
    $('prev').disabled=st.page<=1;$('next').disabled=st.page>=pages;}

  async function load(){
    tbodyEl.innerHTML='<tr><td colspan="'+(HKEYS.length+1)+'" class="px-3 py-6 text-center text-gray-400">Cargando…</td></tr>';
    updateExport();
    try{const r=await fetch('?'+params({action:'data'}).toString());const j=await r.json();
      if(j.error){tbodyEl.innerHTML='<tr><td class="px-3 py-6 text-center text-red-600">'+ec(j.error)+'</td></tr>';return;}
      last=j;renderChips(j.summary);paint();renderPager();
    }catch(e){tbodyEl.innerHTML='<tr><td class="px-3 py-6 text-center text-red-600">Error de red</td></tr>';}
  }

  /* historial expandible */
  tbodyEl.addEventListener('click',async e=>{const tr=e.target.closest('.trow');if(!tr)return;const det=tr.nextElementSibling,box=det.querySelector('.hbox'),chev=tr.querySelector('.chev');
    if(!det.classList.contains('hidden')){det.classList.add('hidden');chev.style.transform='';return;}
    det.classList.remove('hidden');chev.style.transform='rotate(90deg)';if(box.dataset.loaded)return;box.innerHTML='<p class="text-xs text-gray-400">Cargando trazabilidad…</p>';
    const p=new URLSearchParams({resource:'cmn',anio:ANIO,action:'historial',cc:tr.dataset.cc,t:tr.dataset.t,g:tr.dataset.g,c:tr.dataset.c,f:tr.dataset.f,it:tr.dataset.it,meta:tr.dataset.meta,clasif:tr.dataset.clasif});
    try{const h=await(await fetch('?'+p.toString())).json();box.innerHTML=renderHist(h);box.dataset.loaded='1';}catch(x){box.innerHTML='<p class="text-xs text-red-600">No se pudo cargar.</p>';}});
  function sec(t,c,x){return '<div class="mb-3"><div class="text-[11px] font-bold uppercase tracking-wide '+c+' mb-1">'+t+'</div>'+x+'</div>';}
  function renderHist(h){if(h.error)return '<p class="text-xs text-red-600">'+ec(h.error)+'</p>';
    const c1=(h.cuadro||[]).map(r=>'<div class="flex justify-between gap-3 border-b border-gray-100 py-0.5"><span>'+ec(r.etapa)+(r.estado?' <span class="text-gray-400">('+ec(r.estado)+')</span>':'')+(r.fecha?' <span class="text-gray-300">'+ec(r.fecha)+'</span>':'')+'</span><span class="tabular-nums">'+(+r.cant)+' × '+money(r.precio)+' = <b>'+money(r.monto)+'</b></span></div>').join('')||'<span class="text-gray-400">Sin datos</span>';
    const c2=(h.certificacion||[]).map(r=>'<div class="flex justify-between gap-3 border-b border-gray-100 py-0.5"><span>Cert. '+ec(r.nro)+' <span class="text-gray-400">'+ec(r.estado)+'</span> <span class="text-gray-300">'+ec(r.fecha)+'</span></span><span class="tabular-nums">'+money(r.monto)+'</span></div>').join('')||'<span class="text-gray-400">Sin certificación</span>';
    const c3=(h.ordenes||[]).map(r=>'<div class="border-b border-gray-100 py-0.5"><div class="flex justify-between gap-3"><b>'+ec(r.orden)+'</b><span class="tabular-nums">'+(+r.cant)+' × '+money(r.precio)+' = <b>'+money(r.monto)+'</b></span></div><div class="text-gray-400 text-[11px]">'+ec(r.proveedor)+' · '+ec(r.fecha)+'</div></div>').join('')||'<span class="text-gray-400">Sin órdenes</span>';
    const c4=(h.fases||[]).map(r=>'<div class="flex justify-between gap-3 border-b border-gray-100 py-0.5"><span>'+ec(r.fase)+' <span class="text-gray-400">'+ec(r.doc)+'</span> <span class="text-gray-300">'+ec(r.fecha||'')+'</span></span><span class="tabular-nums">'+money(r.monto)+'</span></div>').join('')||'<span class="text-gray-400">Sin ejecución</span>';
    return '<div class="grid md:grid-cols-2 gap-x-8 gap-y-1 text-xs"><div>'+sec('1 · Cuadro (original → modificado)','text-gray-600',c1)+sec('2 · Certificación','text-yellow-700',c2)+'</div><div>'+sec('3 · Órdenes / subdivisión','text-secondary-dark',c3)+sec('4 · Fases del gasto','text-primary-dark',c4)+'<p class="text-[10px] text-gray-400">Girado/pagado se registran en el SIAF.</p></div></div>';}

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