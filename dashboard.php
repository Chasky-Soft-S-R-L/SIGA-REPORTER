<?php
/**
 * dashboard.php  ·  Dashboard reactivo del CMN (cross-filter)
 * Clic en centro / meta / estado / genérica -> filtra TODO el panel al instante.
 * Consume dashboard-api.php.  Servir: php -S localhost:8000 -t E:\SIGA-REPORTER
 * Abrir: http://localhost:8000/dashboard.php?anio=2026
 *
 * ESTADOS (3, iguales al reporte): Programado · Modificado · Ejecutado.
 * DIFERENCIA = saldo por ejecutar (negativo = sobre-ejecución).
 *
 * Usa los partials compartidos: head · sidebar · header · accesos (Ctrl+K).
 * LOADER: escarapela del Perú girando (CSS puro), con barra superior y
 * retardo de 250 ms para no parpadear cuando la caché responde al instante.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Auth.php';

/* ---- SEGURIDAD: sin sesión no se entra (igual que index.php) ---- */
$auth = new Auth();
$auth->exigirLogin();
$USR = $auth->usuario();

$ANIO   = (int)($_GET['anio'] ?? ANIO_PROG);
$anio   = $ANIO;               // alias que usa el JS de esta vista
$PAGINA = 'dashboard';         // clave en partials/nav.php

/* ---- Variables de los partials ---- */
$TITULO_PAG = "Dashboard CMN · {$anio}";
$EXTRA_HEAD = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';

$TITULO    = 'Dashboard del CMN <span class="text-primary">· '.$anio.'</span>';
$SUBTITULO = '<span id="sub">cargando…</span>';
$ACCIONES  = '
      <label class="text-xs text-gray-500">Año</label>
      <input id="anio" type="number" value="'.$anio.'" class="input-bordered w-24 py-2">
      <a href="index.php?anio='.$anio.'" class="px-3 py-2 text-sm rounded-lg bg-white border border-gray-300 hover:bg-gray-50">Ver reporte</a>';

include __DIR__ . '/partials/head.php';
?>
<body class="bg-gray-50 text-gray-800">
<div class="flex min-h-screen">
  <?php include __DIR__ . '/partials/sidebar.php'; ?>

  <main class="flex-1 min-w-0 p-3 sm:p-5">
    <?php include __DIR__ . '/partials/header.php'; ?>

    <!-- Filtros activos (breadcrumb) -->
    <div id="activeFilters" class="flex flex-wrap gap-2 mb-3"></div>

    <!-- Chips de estado: mismos 3 estados y mismos totales que el reporte -->
    <div id="chips" class="flex flex-wrap gap-2 mb-3"></div>

    <!-- KPIs -->
    <div id="kpis" class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-4"></div>

    <!-- Tabla resumen por centro (foco principal · filtra todo el panel) -->
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-4">
      <div class="px-4 py-3 border-b border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
        <h3 class="text-sm font-semibold text-gray-700">Centros de costo · programado, modificado y ejecutado <span class="font-normal text-gray-400">(clic para filtrar)</span></h3>
        <div class="relative w-full sm:w-72">
          <input id="tblSearch" type="text" placeholder="Buscar centro por código o nombre…" class="w-full pl-9 pr-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary">
          <svg class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" stroke-width="2"/><path d="M21 21l-4-4" stroke-width="2" stroke-linecap="round"/></svg>
        </div>
      </div>
      <div class="overflow-auto">
      <table class="min-w-full text-xs">
        <thead class="bg-gray-50 text-gray-500">
          <tr>
            <th class="px-3 py-2 text-left">Centro</th>
            <th class="px-3 py-2 text-right">Programado</th>
            <th class="px-3 py-2 text-right">Modificado</th>
            <th class="px-3 py-2 text-right">Ejecutado</th>
            <th class="px-3 py-2 text-right">Diferencia</th>
            <th class="px-3 py-2 text-right">% Avance</th>
          </tr>
        </thead>
        <tbody id="tblCentros" class="divide-y divide-gray-100"></tbody>
      </table>
      </div>
      <div id="tblPager" class="flex items-center justify-between gap-2 px-4 py-3 border-t border-gray-100 text-xs text-gray-500"></div>
    </div>

    <!-- Gráficos de apoyo -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
      <div class="bg-white rounded-xl border border-gray-200 p-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-2">Ítems por estado</h3>
        <p class="text-xs text-gray-400 mb-2">Clic en un estado para filtrar</p>
        <div class="h-64"><canvas id="chFase"></canvas></div>
      </div>
      <div class="bg-white rounded-xl border border-gray-200 p-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-2">Modificado vs. ejecutado por meta</h3>
        <p class="text-xs text-gray-400 mb-2">Clic en una meta para filtrar</p>
        <div class="h-64"><canvas id="chMeta"></canvas></div>
      </div>
      <div class="bg-white rounded-xl border border-gray-200 p-4">
        <h3 class="text-sm font-semibold text-gray-700 mb-2">Modificado vs. ejecutado por genérica</h3>
        <p class="text-xs text-gray-400 mb-2">Clic en una genérica para filtrar</p>
        <div class="h-64"><canvas id="chGen"></canvas></div>
      </div>
    </div>

    <p class="text-[11px] text-gray-400 mt-3">
      Programado = cuadro de necesidades aprobado · Modificado = importe vigente del CMN ·
      Ejecutado = devengado real atribuido al centro · Diferencia = saldo por ejecutar
      (negativo significa que se ejecutó más cantidad de la cuadrada). Datos al momento de la consulta.
    </p>
  </main>
</div>

<!-- ══════════ LOADER · Escarapela del Perú ══════════ -->
<div id="loadBar"><span></span></div>
<div id="loadOv">
  <div class="loadCard">
    <div class="escWrap">
      <div class="escarapela"></div>
      <div class="escBrillo"></div>
      <div class="escCintas"><i></i><i></i></div>
    </div>
    <div>
      <p class="text-[13px] font-bold text-gray-800 leading-tight">Consultando el SIGA<span class="loadDots"></span></p>
      <p class="text-[11px] text-gray-500 mt-0.5">Ejecución del gasto por centro de costo</p>
    </div>
  </div>
</div>
<style>
  :root{ --rojoPE:#D91023; --rojoPE-osc:#B00D1D; }

  /* barra superior de progreso (roja, en juego con la escarapela) */
  #loadBar{position:fixed;top:0;left:0;right:0;height:3px;z-index:60;overflow:hidden;opacity:0;transition:opacity .2s}
  #loadBar.on{opacity:1}
  #loadBar span{position:absolute;inset:0;width:40%;border-radius:99px;
    background:linear-gradient(90deg,transparent,var(--rojoPE),#ff5a68,transparent);
    animation:ldSlide 1.1s cubic-bezier(.4,0,.2,1) infinite}
  @keyframes ldSlide{0%{left:-40%}100%{left:100%}}

  /* velo + tarjeta */
  #loadOv{position:fixed;inset:0;z-index:55;display:none;place-items:center;
    background:rgba(248,250,252,.55);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px)}
  #loadOv.on{display:grid;animation:ldFade .18s ease}
  @keyframes ldFade{from{opacity:0}to{opacity:1}}
  .loadCard{display:flex;align-items:center;gap:16px;padding:18px 24px;border-radius:14px;background:#fff;
    box-shadow:0 12px 40px -12px rgba(15,23,42,.28),0 0 0 1px rgba(15,23,42,.05);
    animation:ldRise .25s cubic-bezier(.2,.8,.2,1)}
  @keyframes ldRise{from{transform:translateY(8px) scale(.98);opacity:0}to{transform:none;opacity:1}}

  /* ── Escarapela: rojo · blanco · rojo, girando con brillo ── */
  .escWrap{position:relative;width:52px;height:58px;flex-shrink:0}
  .escarapela{
    position:absolute;top:0;left:2px;width:48px;height:48px;border-radius:50%;
    background:
      radial-gradient(circle at 50% 50%,
        var(--rojoPE)      0 27%,          /* botón central rojo */
        #fff               27% 30%,
        #fdfdfd            30% 55%,        /* anillo blanco */
        #f1f2f4            55% 58%,
        var(--rojoPE)      58% 78%,        /* anillo rojo exterior */
        var(--rojoPE-osc)  78% 100%);
    /* pliegues radiales de la cinta, muy sutiles */
    -webkit-mask:none;
    box-shadow:0 2px 6px rgba(176,13,29,.35), inset 0 1px 2px rgba(255,255,255,.5);
    animation:escSpin 3.2s linear infinite;
  }
  .escarapela::after{  /* pliegues: rayitos casi transparentes que giran con ella */
    content:'';position:absolute;inset:0;border-radius:50%;
    background:repeating-conic-gradient(from 0deg,
      rgba(0,0,0,.05) 0deg 2deg, transparent 2deg 15deg);
  }
  @keyframes escSpin{to{transform:rotate(360deg)}}

  /* brillo que barre por encima (gira en sentido contrario: da vida sin marear) */
  .escBrillo{
    position:absolute;top:0;left:2px;width:48px;height:48px;border-radius:50%;pointer-events:none;
    background:conic-gradient(from 0deg,
      transparent 0deg, rgba(255,255,255,.5) 28deg, transparent 70deg 360deg);
    animation:escGlint 2.1s linear infinite reverse;
    mix-blend-mode:soft-light;
  }
  @keyframes escGlint{to{transform:rotate(360deg)}}

  /* cintas colgantes (quietas: solo gira el rosetón) */
  .escCintas{position:absolute;bottom:0;left:50%;transform:translateX(-50%);display:flex;gap:3px}
  .escCintas i{
    width:7px;height:13px;background:linear-gradient(180deg,var(--rojoPE),var(--rojoPE-osc));
    clip-path:polygon(0 0,100% 0,100% 100%,50% 72%,0 100%);
    box-shadow:0 1px 2px rgba(176,13,29,.3);
  }
  .escCintas i:first-child{transform:rotate(-14deg) translateY(-1px)}
  .escCintas i:last-child {transform:rotate( 14deg) translateY(-1px)}

  .loadDots::after{content:'';animation:ldDots 1.4s steps(4,end) infinite}
  @keyframes ldDots{0%{content:''}25%{content:'.'}50%{content:'..'}75%{content:'...'}}
</style>

<!-- Aviso de sesión expirada -->
<div id="sesionOv" class="hidden fixed inset-0 z-50 grid place-items-center" style="background:rgba(15,23,42,.45)">
  <div class="bg-white rounded-2xl shadow-2xl p-6 max-w-sm mx-4 text-center">
    <div class="w-12 h-12 mx-auto rounded-full grid place-items-center mb-3" style="background:#fef3c7">
      <i class="fa-solid fa-clock-rotate-left text-xl" style="color:#b45309"></i>
    </div>
    <p class="font-bold text-gray-800">La sesión expiró</p>
    <p class="text-xs text-gray-500 mt-1 mb-4">Vuelve a iniciar sesión para seguir consultando el dashboard.</p>
    <a href="login.php?next=dashboard.php" class="inline-block w-full py-2.5 rounded-lg text-sm font-semibold text-white"
       style="background:linear-gradient(135deg,var(--teal),var(--teal-dark))">Iniciar sesión</a>
  </div>
</div>

<script>
const money = n => 'S/ ' + (+n||0).toLocaleString('es-PE',{minimumFractionDigits:2,maximumFractionDigits:2});
const short = n => { n=+n||0; if(Math.abs(n)>=1e6) return (n/1e6).toFixed(1)+'M'; if(Math.abs(n)>=1e3) return (n/1e3).toFixed(1)+'k'; return n.toFixed(0); };

/* ── Loader (escarapela): barra al instante, tarjeta tras 250 ms.
   Así, cuando la caché del API responde de inmediato, solo se ve el
   destello de la barra y no un modal que aparece y desaparece. ── */
let ldT=null;
function showLoad(){
  document.getElementById('loadBar').classList.add('on');
  clearTimeout(ldT);
  ldT=setTimeout(()=>document.getElementById('loadOv').classList.add('on'),250);
}
function hideLoad(){
  clearTimeout(ldT);
  document.getElementById('loadBar').classList.remove('on');
  document.getElementById('loadOv').classList.remove('on');
}

/* Los 3 estados del reporte, con el mismo color en toda la app. */
const FASES = [
  {key:'PROGRAMADO', label:'Programado', color:'#9ca3af',
   tip:'Sigue tal como se aprobó en el cuadro de necesidades'},
  {key:'MODIFICADO', label:'Modificado', color:'#ffc107',
   tip:'El importe vigente cambió respecto del original'},
  {key:'EJECUTADO',  label:'Ejecutado',  color:'rgb(26,187,156)',
   tip:'Tiene devengado real registrado'}
];
const FKEYS = FASES.map(f=>f.key);
const FCOL  = Object.fromEntries(FASES.map(f=>[f.key,f.color]));

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
  items.forEach(d=>{ const k=keyFn(d);
    const g=m.get(k)||{prog:0,mod:0,ejec:0,dif:0,n:0,label:k};
    g.prog+=d.prog; g.mod+=d.mod; g.ejec+=d.ejec; g.dif+=d.dif; g.n++; m.set(k,g); });
  return [...m.values()];
}
function totales(items){
  return items.reduce((a,d)=>{a.prog+=d.prog;a.mod+=d.mod;a.ejec+=d.ejec;a.dif+=d.dif;a.n++;return a;},
                      {prog:0,mod:0,ejec:0,dif:0,n:0});
}

function setFilter(dim, val){ filters[dim] = (filters[dim]===val ? null : val); render(); }

function render(){
  const all = applyAll();
  renderActive();
  renderChips();
  renderKPIs(all);
  renderFase();
  renderMeta();
  renderGen();
  renderTable();
}

/* ---- Filtros activos ---- */
function renderActive(){
  const box=document.getElementById('activeFilters'); box.innerHTML='';
  const labels={cc:'Centro',meta:'Meta',fase:'Estado',gen:'Genérica'};
  let any=false;
  for(const k in filters){ if(filters[k]){ any=true;
    const b=document.createElement('button');
    b.className='px-3 py-1 rounded-full text-xs bg-primary/10 text-primary-dark flex items-center gap-1 hover:bg-primary/20';
    b.innerHTML=labels[k]+': <b>'+filters[k]+'</b> <span class="opacity-60">✕</span>';
    b.onclick=()=>{filters[k]=null;render();};
    box.appendChild(b);
  }}
  if(any){ const c=document.createElement('button'); c.className='px-3 py-1 rounded-full text-xs bg-gray-200 text-gray-600 hover:bg-gray-300'; c.textContent='Limpiar todo'; c.onclick=()=>{Object.keys(filters).forEach(k=>filters[k]=null);render();}; box.appendChild(c); }
  else box.innerHTML='<span class="text-xs text-gray-400">Sin filtros · haz clic en cualquier gráfico, chip o centro para filtrar</span>';
}

/* ---- Chips de estado (mismos totales que el reporte) ----
   El importe del chip es el TOTAL de esa columna (Σ programado, Σ modificado,
   Σ ejecutado) del universo filtrado; el número es la cantidad de ítems que
   están en ese estado, que es lo que filtra al hacer clic. */
function renderChips(){
  const box=document.getElementById('chips'); box.innerHTML='';
  const base = ITEMS.filter(d=>passExcept(d,'fase'));
  const t = totales(base);
  const cuenta = Object.fromEntries(FKEYS.map(k=>[k, base.filter(d=>d.fase===k).length]));
  const TOT = {PROGRAMADO:t.prog, MODIFICADO:t.mod, EJECUTADO:t.ejec};

  box.appendChild(chip('Todos', t.n, t.mod, '#1f2937', '#fff', !filters.fase,
    ()=>{filters.fase=null;render();}, null,
    'Importe vigente total '+money(t.mod)+'  ·  Ejecutado '+money(t.ejec)));
  FASES.forEach(f=>{
    box.appendChild(chip(f.label, cuenta[f.key]||0, TOT[f.key], f.color+'26', '#374151',
      filters.fase===f.key, ()=>setFilter('fase',f.key), f.color,
      f.tip+'  ·  '+(cuenta[f.key]||0)+' ítems en este estado (clic para filtrar)'));
  });
}
function chip(label,count,monto,bg,fg,active,onclick,dot,tip){
  const b=document.createElement('button'); if(tip)b.title=tip;
  b.className='px-3 py-1.5 rounded-full text-xs font-medium flex items-center gap-2 transition-all'
             +(active?' ring-2 ring-offset-1':' opacity-90 hover:opacity-100');
  b.style.background=bg; b.style.color=fg; if(active&&dot)b.style.setProperty('--tw-ring-color',dot);
  b.innerHTML=(dot?'<span class="w-2 h-2 rounded-full" style="background:'+dot+'"></span>':'')
    +'<span>'+label+'</span><span class="opacity-50">·</span><span>'+count+'</span>'
    +'<span class="opacity-60">'+money(monto)+'</span>';
  b.onclick=onclick; return b;
}

/* ---- KPIs ---- */
function renderKPIs(items){
  const t=totales(items);
  const avance = t.mod>0 ? (t.ejec/t.mod*100) : 0;
  const difCls = t.dif < -0.005 ? 'text-red-600' : 'text-secondary-dark';
  const cards=[
    ['Programado', money(t.prog), 'text-gray-700',  t.n+' ítems'],
    ['Modificado', money(t.mod),  'text-gray-700',  'importe vigente'],
    ['Ejecutado',  money(t.ejec), 'text-primary-dark', 'devengado real'],
    ['Diferencia', money(t.dif),  difCls,           'saldo por ejecutar'],
    ['% Avance',   avance.toFixed(1)+'%', 'text-gray-800', 'ejecutado / modificado'],
  ];
  document.getElementById('kpis').innerHTML = cards.map(c=>
    '<div class="bg-white rounded-xl border border-gray-200 p-3">'
    +'<div class="text-[11px] text-gray-400 uppercase">'+c[0]+'</div>'
    +'<div class="text-base sm:text-lg font-bold tabular-nums '+c[2]+'">'+c[1]+'</div>'
    +'<div class="text-[10px] text-gray-400">'+c[3]+'</div></div>'
  ).join('');
}

/* ---- Charts ---- */
function makeOrUpdate(id, cfg){
  const el=document.getElementById(id); if(!el) return;
  if(charts[id]){ charts[id].data=cfg.data; charts[id].options=cfg.options; charts[id].update(); }
  else charts[id]=new Chart(el, cfg);
}

/* Estados: cantidad de ítems (el monto va en el tooltip y en los chips). */
function renderFase(){
  const items = ITEMS.filter(d=>passExcept(d,'fase'));
  const cuenta = FKEYS.map(k=>items.filter(d=>d.fase===k).length);
  const montos = FKEYS.map(k=>items.filter(d=>d.fase===k).reduce((s,d)=>s+d.mod,0));
  makeOrUpdate('chFase',{ type:'doughnut',
    data:{ labels:FASES.map(f=>f.label), datasets:[{ data:cuenta,
      backgroundColor:FASES.map(f=>f.color), borderWidth:2, borderColor:'#fff',
      offset: FKEYS.map(k=>filters.fase===k?12:0) }] },
    options:{ responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{position:'bottom',labels:{font:{size:11},boxWidth:12}},
        tooltip:{callbacks:{label:c=>c.label+': '+c.raw+' ítems · '+money(montos[c.dataIndex])}} },
      onClick:(e,el)=>{ if(el.length) setFilter('fase', FKEYS[el[0].index]); } } });
}

function barMonto(id, items, keyFn, prefijo, dim, colorBase){
  let g=groupSum(items,keyFn).sort((a,b)=>b.mod-a.mod).slice(0,15);
  const labels=g.map(x=>prefijo+x.label);
  makeOrUpdate(id,{ type:'bar',
    data:{ labels, datasets:[
      { label:'Modificado', data:g.map(x=>x.mod),
        backgroundColor:g.map(x=>filters[dim]===x.label?colorBase:colorBase+'8c') },
      { label:'Ejecutado',  data:g.map(x=>x.ejec), backgroundColor:'rgb(26,187,156)' }
    ]},
    options:{ responsive:true, maintainAspectRatio:false,
      scales:{ y:{ticks:{callback:v=>short(v)}}, x:{ticks:{font:{size:10}}} },
      plugins:{ legend:{position:'bottom',labels:{font:{size:11},boxWidth:12}},
        tooltip:{callbacks:{
          label:c=>c.dataset.label+': '+money(c.raw),
          afterBody:c=>{const x=g[c[0].dataIndex];return 'Diferencia: '+money(x.dif)+'  ·  '+x.n+' ítems';} }} },
      onClick:(e,el)=>{ if(el.length) setFilter(dim, g[el[0].index].label); } } });
}

function renderMeta(){ barMonto('chMeta', ITEMS.filter(d=>passExcept(d,'meta')), d=>d.meta||'—', 'Meta ', 'meta', '#6d28d9'); }
function renderGen(){  barMonto('chGen',  ITEMS.filter(d=>passExcept(d,'gen')),  d=>d.gen||'—',  'Gen. ',  'gen',  '#0d6efd'); }

/* ---- Tabla por centro (con búsqueda + paginación) ---- */
let tblPage = 1;
const TBL_SIZE = 10;
function normT(s){ return (s||'').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,''); }

function renderTable(){
  const items=applyAll();
  let g=groupSum(items,d=>d.cc).sort((a,b)=>b.dif-a.dif);
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
    const difCls=x.dif<-0.005?'text-red-600 font-semibold':'text-secondary-dark';
    return '<tr class="'+sel+' hover:bg-gray-50 cursor-pointer" onclick="setFilter(\'cc\',\''+x.label+'\')">'
      +'<td class="px-3 py-2"><div class="font-medium text-gray-700">'+x.label+'</div><div class="text-[11px] text-gray-400">'+x._name+'</div></td>'
      +'<td class="px-3 py-2 text-right tabular-nums">'+money(x.prog)+'</td>'
      +'<td class="px-3 py-2 text-right tabular-nums">'+money(x.mod)+'</td>'
      +'<td class="px-3 py-2 text-right tabular-nums text-primary-dark">'+money(x.ejec)+'</td>'
      +'<td class="px-3 py-2 text-right tabular-nums '+difCls+'">'+money(x.dif)+'</td>'
      +'<td class="px-3 py-2 text-right"><div class="flex items-center gap-2 justify-end"><div class="w-16 bg-gray-100 rounded-full h-1.5"><div class="bg-primary h-1.5 rounded-full" style="width:'+Math.min(Math.max(av,0),100)+'%"></div></div><span class="tabular-nums text-gray-600">'+av.toFixed(0)+'%</span></div></td>'
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
  showLoad();
  try{
    const res=await fetch('dashboard-api.php?anio='+anio, {credentials:'same-origin'});

    /* La sesión pudo expirar con la pestaña abierta. El API responde 401 en JSON
       en vez de redirigir, para que el fetch no reciba el HTML del login. */
    if(res.status===401){
      document.getElementById('sesionOv').classList.remove('hidden');
      document.getElementById('sub').innerHTML='<span class="text-amber-600">Sesión expirada</span>';
      return;
    }

    const j=await res.json();
    if(!j.ok) throw new Error(j.error||'Error');
    ITEMS=j.items;
    document.getElementById('sub').textContent=j.total+' ítems · ejecución '+j.anioEjec+' · '+new Date(j.generado).toLocaleString('es-PE');
    Object.keys(filters).forEach(k=>filters[k]=null);
    tblPage=1;
    render();
  }catch(e){ document.getElementById('sub').innerHTML='<span class="text-red-600">Error: '+e.message+'</span>'; }
  finally{ hideLoad(); }
}
document.getElementById('tblSearch').addEventListener('input',()=>{ tblPage=1; renderTable(); });
document.getElementById('anio').addEventListener('change',e=>{ const a=e.target.value; history.replaceState(null,'','?anio='+a); load(a); });
load(<?= $anio ?>);
</script>

<?php include __DIR__ . '/partials/accesos.php'; ?>

<script>
/* Acciones de ESTA pantalla en la paleta Ctrl+K (registrar tras definir todo) */
SIGA.accion('Actualizar datos', 'fa-rotate',
  () => load(document.getElementById('anio').value),
  'Vuelve a consultar el SIGA (usa caché de 5 min)');
SIGA.accion('Limpiar filtros', 'fa-filter-circle-xmark',
  () => { Object.keys(filters).forEach(k=>filters[k]=null); render(); },
  'Quita centro, meta, estado y genérica');
SIGA.accion('Buscar centro de costo', 'fa-building',
  () => { document.getElementById('tblSearch').focus(); },
  'Salta al buscador de la tabla de centros');
SIGA.accion('Ver el reporte completo', 'fa-table-list',
  () => { location.href='index.php?anio='+document.getElementById('anio').value; },
  'Abre la tabla del CMN con este mismo ejercicio');
</script>
</body>
</html>