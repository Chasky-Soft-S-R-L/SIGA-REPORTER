<?php
/**
 * dashboard.php  ·  Dashboard reactivo del CMN (cross-filter)
 * Clic en centro / meta / fase / genérica -> filtra TODO el panel al instante.
 * Consume dashboard-api.php.  Servir: php -S localhost:8000 -t E:\SIGA-REPORTER
 * Abrir: http://localhost:8000/dashboard.php?anio=2026
 */
$anio = (int)($_GET['anio'] ?? 2026);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard CMN · <?= $anio ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
tailwind.config = { theme: { extend: { colors: {
  primary:{light:'rgb(72,230,198)',DEFAULT:'rgb(26,187,156)',dark:'rgb(20,150,125)'},
  secondary:{DEFAULT:'#0d6efd',light:'#4d94ff',dark:'#0a58ca'}, warning:'#ffc107', info:'#0dcaf0'
} } } };
</script>
</head>
<body class="bg-gray-50 text-gray-800">
<div class="max-w-[1400px] mx-auto p-3 sm:p-5">

  <header class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
    <div>
      <span class="text-primary-dark font-bold text-sm">SIGA · REPORTES</span>
      <h1 class="text-lg sm:text-xl font-bold text-gray-800">Dashboard del Cuadro de Necesidades <span class="text-primary">· <?= $anio ?></span></h1>
      <p id="sub" class="text-xs text-gray-400">cargando…</p>
    </div>
    <div class="flex items-center gap-2">
      <label class="text-xs text-gray-500">Año</label>
      <input id="anio" type="number" value="<?= $anio ?>" class="w-24 px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary">
      <a href="index.php?anio=<?= $anio ?>" class="px-3 py-2 text-sm rounded-lg bg-white border border-gray-300 hover:bg-gray-50">Ver reporte</a>
    </div>
  </header>

  <!-- Filtros activos (breadcrumb) -->
  <div id="activeFilters" class="flex flex-wrap gap-2 mb-3"></div>

  <!-- KPIs -->
  <div id="kpis" class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-4"></div>

  <!-- Tabla resumen por centro (foco principal · filtra todo el panel) -->
  <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-4">
    <div class="px-4 py-3 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
      <h3 class="text-sm font-semibold text-gray-700">Centros de costo · ejecutado y pendiente <span class="font-normal text-gray-400">(clic para filtrar)</span></h3>
      <div class="relative w-full sm:w-72">
        <input id="tblSearch" type="text" placeholder="Buscar centro por código o nombre…" class="w-full pl-9 pr-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary">
        <svg class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" stroke-width="2"/><path d="M21 21l-4-4" stroke-width="2" stroke-linecap="round"/></svg>
      </div>
    </div>
    <div class="overflow-auto">
    <table class="min-w-full text-xs">
      <thead class="bg-gray-50 text-gray-500">
        <tr><th class="px-3 py-2 text-left">Centro</th><th class="px-3 py-2 text-right">Programado</th><th class="px-3 py-2 text-right">Modificado</th><th class="px-3 py-2 text-right">Ejecutado</th><th class="px-3 py-2 text-right">Pendiente</th><th class="px-3 py-2 text-right">% Avance</th></tr>
      </thead>
      <tbody id="tblCentros" class="divide-y divide-gray-100"></tbody>
    </table>
    </div>
    <div id="tblPager" class="flex items-center justify-between gap-2 px-4 py-3 border-t border-gray-100 text-xs text-gray-500"></div>
  </div>

  <!-- Gráficos de apoyo -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
    <div class="bg-white rounded-xl border border-gray-200 p-4">
      <h3 class="text-sm font-semibold text-gray-700 mb-2">Ejecución por fase del gasto</h3>
      <p class="text-xs text-gray-400 mb-2">Clic en una fase para filtrar</p>
      <div class="h-64"><canvas id="chFase"></canvas></div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4">
      <h3 class="text-sm font-semibold text-gray-700 mb-2">Monto por meta</h3>
      <p class="text-xs text-gray-400 mb-2">Clic en una meta para filtrar</p>
      <div class="h-64"><canvas id="chMeta"></canvas></div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4">
      <h3 class="text-sm font-semibold text-gray-700 mb-2">Monto por genérica de gasto</h3>
      <p class="text-xs text-gray-400 mb-2">Clic en una genérica para filtrar</p>
      <div class="h-64"><canvas id="chGen"></canvas></div>
    </div>
  </div>

  <p class="text-[11px] text-gray-400 mt-3">Los montos usan el CMN modificado (vigente). Ejecutado = valorización al precio real de compra devengado. Datos al momento de la consulta.</p>
</div>

<script>
const money = n => 'S/ ' + (+n||0).toLocaleString('es-PE',{minimumFractionDigits:2,maximumFractionDigits:2});
const short = n => { n=+n||0; if(Math.abs(n)>=1e6) return (n/1e6).toFixed(1)+'M'; if(Math.abs(n)>=1e3) return (n/1e3).toFixed(1)+'k'; return n.toFixed(0); };
const FASE_COLOR = {PENDIENTE:'#9ca3af', CERTIFICADO:'#ffc107', COMPROMETIDO:'#0d6efd', DEVENGADO:'rgb(26,187,156)'};
const FASES = ['PENDIENTE','CERTIFICADO','COMPROMETIDO','DEVENGADO'];

let ITEMS = [];
const filters = { cc:null, meta:null, fase:null, gen:null };
let charts = {};

function passExcept(d, except){
  return (except==='cc'   || !filters.cc   || d.cc===filters.cc)
      && (except==='meta' || !filters.meta || d.meta===filters.meta)
      && (except==='fase' || !filters.fase || d.fase===filters.fase)
      && (except==='gen'  || !filters.gen  || d.gen===filters.gen);
}
const applyAll = () => ITEMS.filter(d=>passExcept(d,null));

function groupSum(items, keyFn){
  const m=new Map();
  items.forEach(d=>{ const k=keyFn(d); const g=m.get(k)||{prog:0,mod:0,ejec:0,pend:0,n:0,label:k}; g.prog+=d.prog;g.mod+=d.mod;g.ejec+=d.ejec;g.pend+=d.pend;g.n++; m.set(k,g); });
  return [...m.values()];
}

function setFilter(dim, val){ filters[dim] = (filters[dim]===val ? null : val); render(); }

function render(){
  const all = applyAll();
  renderActive();
  renderKPIs(all);
  renderFase();
  renderCentro();
  renderMeta();
  renderGen();
  renderTable();
}

/* ---- Filtros activos ---- */
function renderActive(){
  const box=document.getElementById('activeFilters'); box.innerHTML='';
  const labels={cc:'Centro',meta:'Meta',fase:'Fase',gen:'Genérica'};
  let any=false;
  for(const k in filters){ if(filters[k]){ any=true;
    const b=document.createElement('button');
    b.className='px-3 py-1 rounded-full text-xs bg-primary/10 text-primary-dark flex items-center gap-1 hover:bg-primary/20';
    b.innerHTML=labels[k]+': <b>'+filters[k]+'</b> <span class="opacity-60">✕</span>';
    b.onclick=()=>{filters[k]=null;render();};
    box.appendChild(b);
  }}
  if(any){ const c=document.createElement('button'); c.className='px-3 py-1 rounded-full text-xs bg-gray-200 text-gray-600 hover:bg-gray-300'; c.textContent='Limpiar todo'; c.onclick=()=>{Object.keys(filters).forEach(k=>filters[k]=null);render();}; box.appendChild(c); }
  else box.innerHTML='<span class="text-xs text-gray-400">Sin filtros · haz clic en cualquier gráfico para filtrar</span>';
}

/* ---- KPIs ---- */
function renderKPIs(items){
  const t=items.reduce((a,d)=>{a.prog+=d.prog;a.mod+=d.mod;a.ejec+=d.ejec;a.pend+=d.pend;return a;},{prog:0,mod:0,ejec:0,pend:0});
  const avance = t.mod>0 ? (t.ejec/t.mod*100) : 0;
  const cards=[
    ['Programado', money(t.prog), 'text-gray-700'],
    ['Modificado', money(t.mod), 'text-gray-700'],
    ['Ejecutado', money(t.ejec), 'text-primary-dark'],
    ['Pendiente', money(t.pend), 'text-secondary-dark'],
    ['% Avance', avance.toFixed(1)+'%', 'text-gray-800'],
  ];
  document.getElementById('kpis').innerHTML = cards.map(c=>
    '<div class="bg-white rounded-xl border border-gray-200 p-3"><div class="text-[11px] text-gray-400 uppercase">'+c[0]+'</div><div class="text-base sm:text-lg font-bold tabular-nums '+c[2]+'">'+c[1]+'</div></div>'
  ).join('');
}

/* ---- Charts ---- */
function makeOrUpdate(id, cfg){
  if(charts[id]){ charts[id].data=cfg.data; charts[id].options=cfg.options; charts[id].update(); }
  else charts[id]=new Chart(document.getElementById(id), cfg);
}

function renderFase(){
  const items = ITEMS.filter(d=>passExcept(d,'fase'));
  const g=groupSum(items,d=>d.fase);
  const data=FASES.map(f=>{const x=g.find(v=>v.label===f);return x?x.mod:0;});
  makeOrUpdate('chFase',{ type:'doughnut',
    data:{ labels:FASES, datasets:[{ data, backgroundColor:FASES.map(f=>FASE_COLOR[f]),
      borderWidth: FASES.map(f=>filters.fase&&filters.fase!==f?0:2), borderColor:'#fff',
      offset: FASES.map(f=>filters.fase===f?12:0) }] },
    options:{ responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{position:'bottom',labels:{font:{size:11},boxWidth:12}},
        tooltip:{callbacks:{label:c=>c.label+': '+money(c.raw)}} },
      onClick:(e,el)=>{ if(el.length) setFilter('fase', FASES[el[0].index]); } } });
}

function renderCentro(){
  const items = ITEMS.filter(d=>passExcept(d,'cc'));
  let g=groupSum(items,d=>d.cc).sort((a,b)=>b.mod-a.mod).slice(0,15);
  const labels=g.map(x=>x.label);
  const names=g.map(x=>{const it=ITEMS.find(d=>d.cc===x.label);return it?it.ccn:x.label;});
  makeOrUpdate('chCentro',{ type:'bar',
    data:{ labels, datasets:[
      { label:'Ejecutado', data:g.map(x=>x.ejec), backgroundColor:'rgb(26,187,156)', stack:'s',
        borderWidth:g.map(x=>filters.cc&&filters.cc!==x.label?0:0) },
      { label:'Pendiente', data:g.map(x=>x.pend), backgroundColor:'#e5e7eb', stack:'s' },
    ]},
    options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false,
      scales:{ x:{stacked:true,ticks:{callback:v=>short(v)}}, y:{stacked:true,ticks:{font:{size:10}}} },
      plugins:{ legend:{position:'bottom',labels:{font:{size:11},boxWidth:12}},
        tooltip:{callbacks:{title:c=>names[c[0].dataIndex],label:c=>c.dataset.label+': '+money(c.raw)}} },
      onClick:(e,el)=>{ if(el.length) setFilter('cc', labels[el[0].index]); } } });
}

function renderMeta(){
  const items = ITEMS.filter(d=>passExcept(d,'meta'));
  let g=groupSum(items,d=>d.meta||'—').sort((a,b)=>b.mod-a.mod).slice(0,15);
  const labels=g.map(x=>'Meta '+x.label);
  makeOrUpdate('chMeta',{ type:'bar',
    data:{ labels, datasets:[{ data:g.map(x=>x.mod),
      backgroundColor:g.map(x=>filters.meta===x.label?'rgb(20,150,125)':'rgba(26,187,156,.6)') }]},
    options:{ responsive:true, maintainAspectRatio:false,
      scales:{ y:{ticks:{callback:v=>short(v)}}, x:{ticks:{font:{size:10}}} },
      plugins:{ legend:{display:false}, tooltip:{callbacks:{label:c=>money(c.raw)}} },
      onClick:(e,el)=>{ if(el.length) setFilter('meta', g[el[0].index].label); } } });
}

function renderGen(){
  const items = ITEMS.filter(d=>passExcept(d,'gen'));
  let g=groupSum(items,d=>d.gen||'—').sort((a,b)=>b.mod-a.mod);
  const labels=g.map(x=>'Gen. '+x.label);
  makeOrUpdate('chGen',{ type:'bar',
    data:{ labels, datasets:[{ data:g.map(x=>x.mod),
      backgroundColor:g.map(x=>filters.gen===x.label?'#0a58ca':'rgba(13,110,253,.55)') }]},
    options:{ responsive:true, maintainAspectRatio:false,
      scales:{ y:{ticks:{callback:v=>short(v)}}, x:{ticks:{font:{size:10}}} },
      plugins:{ legend:{display:false}, tooltip:{callbacks:{label:c=>money(c.raw)}} },
      onClick:(e,el)=>{ if(el.length) setFilter('gen', g[el[0].index].label); } } });
}

/* ---- Tabla por centro (con búsqueda + paginación) ---- */
let tblPage = 1;
const TBL_SIZE = 10;
function normT(s){ return (s||'').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,''); }

function renderTable(){
  const items=applyAll();
  let g=groupSum(items,d=>d.cc).sort((a,b)=>b.pend-a.pend);
  const nameOf=cc=>{const it=ITEMS.find(d=>d.cc===cc);return it?it.ccn:'';};
  g.forEach(x=>x._name=nameOf(x.label));

  // búsqueda
  const q=normT(document.getElementById('tblSearch').value);
  if(q) g=g.filter(x=>normT(x.label+' '+x._name).includes(q));

  // paginación
  const total=g.length, pages=Math.max(1,Math.ceil(total/TBL_SIZE));
  if(tblPage>pages) tblPage=pages;
  const start=(tblPage-1)*TBL_SIZE;
  const page=g.slice(start,start+TBL_SIZE);

  document.getElementById('tblCentros').innerHTML = page.length? page.map(x=>{
    const av=x.mod>0?(x.ejec/x.mod*100):0;
    const sel=filters.cc===x.label?'bg-primary/5':'';
    return '<tr class="'+sel+' hover:bg-gray-50 cursor-pointer" onclick="setFilter(\'cc\',\''+x.label+'\')">'
      +'<td class="px-3 py-2"><div class="font-medium text-gray-700">'+x.label+'</div><div class="text-[11px] text-gray-400">'+x._name+'</div></td>'
      +'<td class="px-3 py-2 text-right tabular-nums">'+money(x.prog)+'</td>'
      +'<td class="px-3 py-2 text-right tabular-nums">'+money(x.mod)+'</td>'
      +'<td class="px-3 py-2 text-right tabular-nums text-primary-dark">'+money(x.ejec)+'</td>'
      +'<td class="px-3 py-2 text-right tabular-nums text-secondary-dark">'+money(x.pend)+'</td>'
      +'<td class="px-3 py-2 text-right"><div class="flex items-center gap-2 justify-end"><div class="w-16 bg-gray-100 rounded-full h-1.5"><div class="bg-primary h-1.5 rounded-full" style="width:'+Math.min(av,100)+'%"></div></div><span class="tabular-nums text-gray-600">'+av.toFixed(0)+'%</span></div></td>'
      +'</tr>';
  }).join('') : '<tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Sin centros que coincidan</td></tr>';

  // pie de paginación
  const from=total?start+1:0, to=Math.min(start+TBL_SIZE,total);
  document.getElementById('tblPager').innerHTML =
    '<span>'+from+'–'+to+' de '+total+' centros</span>'
    +'<div class="flex items-center gap-1">'
    +'<button '+(tblPage<=1?'disabled':'')+' onclick="gotoPage('+(tblPage-1)+')" class="px-2 py-1 rounded border border-gray-200 '+(tblPage<=1?'opacity-40':'hover:bg-gray-50')+'">‹ Ant.</button>'
    +'<span class="px-2">'+tblPage+' / '+pages+'</span>'
    +'<button '+(tblPage>=pages?'disabled':'')+' onclick="gotoPage('+(tblPage+1)+')" class="px-2 py-1 rounded border border-gray-200 '+(tblPage>=pages?'opacity-40':'hover:bg-gray-50')+'">Sig. ›</button>'
    +'</div>';
}
function gotoPage(p){ tblPage=p; renderTable(); }

/* ---- Carga ---- */
async function load(anio){
  document.getElementById('sub').textContent='cargando…';
  try{
    const res=await fetch('dashboard-api.php?anio='+anio);
    const j=await res.json();
    if(!j.ok) throw new Error(j.error||'Error');
    ITEMS=j.items;
    document.getElementById('sub').textContent=j.total+' ítems · ejecución '+j.anioEjec+' · '+new Date(j.generado).toLocaleString('es-PE');
    Object.keys(filters).forEach(k=>filters[k]=null);
    render();
  }catch(e){ document.getElementById('sub').innerHTML='<span class="text-red-600">Error: '+e.message+'</span>'; }
}
document.getElementById('tblSearch').addEventListener('input',()=>{ tblPage=1; renderTable(); });
document.getElementById('anio').addEventListener('change',e=>{ const a=e.target.value; history.replaceState(null,'','?anio='+a); load(a); });
load(<?= $anio ?>);
</script>
</body>
</html>