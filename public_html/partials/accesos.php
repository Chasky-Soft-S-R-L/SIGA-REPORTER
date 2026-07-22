<?php
/**
 * partials/accesos.php  ·  Paleta de accesos rápidos (Ctrl+K)
 *
 * Se abre con Ctrl/Cmd + K, con la tecla "/" o con cualquier botón que tenga
 * el atributo data-ap-open. Busca en tres fuentes:
 *
 *   1. Las pantallas de partials/nav.php
 *   2. Los años cercanos (salta al mismo reporte con otro ejercicio)
 *   3. Acciones de la pantalla actual, que cada vista registra con:
 *          SIGA.accion('Exportar a Excel', 'fa-file-excel', () => …);
 *      (si la vista no registra nada, la sección simplemente no aparece)
 *
 * Incluir al final del <body>, después del contenido.
 */
$ANIO = (int)($ANIO ?? ANIO_PROG);
$MENU = require __DIR__ . '/nav.php';

$items = [];
foreach ($MENU as $it) {
    if (isset($it['rol']) && ($USR['rol'] ?? '') !== $it['rol']) continue;
    $items[] = ['t'=>$it['texto'], 'd'=>$it['desc'] ?? '', 'i'=>$it['icono'],
                'u'=>str_replace('{anio}', (string)$ANIO, $it['url']), 'g'=>'Ir a'];
}
// Saltar de ejercicio sin perder la pantalla actual
$actual = basename($_SERVER['PHP_SELF']);
for ($a = $ANIO - 2; $a <= $ANIO + 1; $a++) {
    if ($a === $ANIO) continue;
    $items[] = ['t'=>"Ejercicio {$a}", 'd'=>'Ver esta misma pantalla en '.$a, 'i'=>'fa-calendar-days',
                'u'=>$actual.'?anio='.$a, 'g'=>'Cambiar de año'];
}
?>
<div id="apOv" role="dialog" aria-modal="true" aria-label="Accesos rápidos">
  <div id="apBox" class="max-w-lg mx-4 sm:mx-auto mt-[12vh] bg-white rounded-2xl shadow-2xl overflow-hidden">

    <div class="flex items-center gap-3 px-4 py-3 border-b border-gray-100">
      <i class="fa-solid fa-magnifying-glass text-gray-400 text-sm"></i>
      <input id="apQ" type="text" autocomplete="off" placeholder="Buscar pantalla o acción…"
             class="flex-1 text-sm outline-none placeholder:text-gray-400">
      <kbd>Esc</kbd>
    </div>

    <ul id="apList" class="max-h-[52vh] overflow-y-auto py-1"></ul>

    <div class="px-4 py-2 border-t border-gray-100 bg-gray-50 flex items-center gap-3 text-[10px] text-gray-500">
      <span><kbd>↑</kbd> <kbd>↓</kbd> moverse</span>
      <span><kbd>↵</kbd> abrir</span>
      <span class="ml-auto"><?= htmlspecialchars(APP_NOMBRE) ?></span>
    </div>
  </div>
</div>

<script>
window.SIGA = window.SIGA || {};
(function(){
  const BASE = <?= json_encode($items, JSON_UNESCAPED_UNICODE) ?>;
  const acciones = [];   // registradas por la vista con SIGA.accion(...)

  /* API para las vistas: SIGA.accion(texto, icono, fn, descripcion) */
  SIGA.accion = (t, i, fn, d) => acciones.push({t, i:i||'fa-bolt', d:d||'', fn, g:'En esta pantalla'});

  const ov=document.getElementById('apOv'), box=document.getElementById('apBox'),
        q=document.getElementById('apQ'), lista=document.getElementById('apList');
  let filtrados=[], sel=0;

  const ec=s=>(s||'').toString().replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const nz=s=>(s||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');

  function todos(){ return acciones.concat(BASE); }

  function pintar(){
    const f=nz(q.value);
    filtrados = todos().filter(x=>!f || nz(x.t+' '+x.d).includes(f));
    sel = 0;
    if(!filtrados.length){
      lista.innerHTML='<li class="px-4 py-8 text-center text-xs text-gray-400">Nada coincide con «'+ec(q.value)+'»</li>';
      return;
    }
    let html='', grupoPrev='';
    filtrados.forEach((x,i)=>{
      if(x.g!==grupoPrev){ grupoPrev=x.g;
        html+='<li class="px-4 pt-3 pb-1 text-[10px] font-bold uppercase tracking-widest text-gray-400">'+ec(x.g)+'</li>'; }
      html+='<li class="apItem flex items-center gap-3 px-3 mx-1 py-2 rounded-lg cursor-pointer hover:bg-gray-50" '
           +'role="option" data-i="'+i+'" aria-selected="'+(i===0)+'">'
           +'<span class="apIco w-7 h-7 rounded-lg grid place-items-center bg-gray-100 text-gray-500 text-xs shrink-0">'
             +'<i class="fa-solid '+ec(x.i)+'"></i></span>'
           +'<span class="min-w-0"><span class="block text-[13px] text-gray-800 leading-tight">'+ec(x.t)+'</span>'
           +(x.d?'<span class="block text-[11px] text-gray-400 truncate">'+ec(x.d)+'</span>':'')+'</span></li>';
    });
    lista.innerHTML=html;
  }

  function marcar(){
    [...lista.querySelectorAll('.apItem')].forEach(el=>{
      const on = +el.dataset.i === sel;
      el.setAttribute('aria-selected', on);
      if(on) el.scrollIntoView({block:'nearest'});
    });
  }

  function abrir(){ ov.classList.add('on'); q.value=''; pintar(); q.focus(); document.body.style.overflow='hidden'; }
  function cerrar(){ ov.classList.remove('on'); document.body.style.overflow=''; }
  function lanzar(x){ if(!x) return; cerrar(); if(x.fn) x.fn(); else location.href=x.u; }

  document.querySelectorAll('[data-ap-open]').forEach(b=>b.addEventListener('click',abrir));
  ov.addEventListener('click', e=>{ if(!box.contains(e.target)) cerrar(); });
  lista.addEventListener('click', e=>{ const li=e.target.closest('.apItem'); if(li) lanzar(filtrados[+li.dataset.i]); });
  lista.addEventListener('mousemove', e=>{ const li=e.target.closest('.apItem'); if(li && +li.dataset.i!==sel){ sel=+li.dataset.i; marcar(); } });
  q.addEventListener('input', pintar);

  document.addEventListener('keydown', e=>{
    const abierta = ov.classList.contains('on');
    const escribiendo = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName);

    if((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='k'){ e.preventDefault(); abierta?cerrar():abrir(); return; }
    if(e.key==='/' && !abierta && !escribiendo){ e.preventDefault(); abrir(); return; }
    if(!abierta) return;

    if(e.key==='Escape'){ e.preventDefault(); e.stopPropagation(); cerrar(); }
    else if(e.key==='ArrowDown'){ e.preventDefault(); sel=Math.min(sel+1, filtrados.length-1); marcar(); }
    else if(e.key==='ArrowUp'){   e.preventDefault(); sel=Math.max(sel-1, 0); marcar(); }
    else if(e.key==='Enter'){     e.preventDefault(); lanzar(filtrados[sel]); }
  }, true);   // captura: así el Esc cierra la paleta antes que otros modales de la pantalla
})();
</script>