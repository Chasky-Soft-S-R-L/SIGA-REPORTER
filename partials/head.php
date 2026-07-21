<?php
/**
 * partials/head.php  ·  <head> común + estilos compartidos
 *
 * Variables opcionales antes de incluirlo:
 *   $TITULO_PAG  título de la pestaña
 *   $EXTRA_HEAD  HTML adicional (por ejemplo el <script> de Chart.js)
 *
 * Requiere config.php ya cargado.
 */
$TITULO_PAG = $TITULO_PAG ?? APP_NOMBRE;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($TITULO_PAG) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<script>
tailwind.config={theme:{extend:{colors:{
  primary:{light:'rgb(72,230,198)',DEFAULT:'rgb(26,187,156)',dark:'rgb(20,150,125)'},
  secondary:{DEFAULT:'#0d6efd',light:'#4d94ff',dark:'#0a58ca'},
  warning:'#ffc107', info:'#0dcaf0'}}}};
</script>
<script>
/* Aplicar el estado guardado del sidebar ANTES del primer pintado:
   evita el parpadeo ancho→angosto al cargar. Debe ir en el <head>. */
try{ if(localStorage.getItem('siga.sb')==='min') document.documentElement.classList.add('sbMin'); }catch(e){}
</script>
<style>
  :root{ --teal:rgb(26,187,156); --teal-dark:rgb(20,150,125); }

  /* ── Sidebar ─────────────────────────────────────────────
     Dos estados en escritorio: expandido (15rem) y comprimido (4rem, solo
     iconos). La clase .sbMin va en <html> y se recuerda en localStorage.
     En móvil (<1024px) es un cajón deslizante y SIEMPRE sale expandido. */
  #sidebar{width:15rem;transition:width .18s cubic-bezier(.2,.8,.2,1)}

  @media (min-width:1024px){
    html.sbMin #sidebar{width:4rem}
    /* lo que desaparece al comprimir */
    html.sbMin #sidebar .sbTxt{display:none}
    html.sbMin #sidebar .sbGrpTitle{display:none}
    html.sbMin #sidebar .sbBrandTxt{display:none}
    html.sbMin #sidebar .sbFoot{display:none}
    html.sbMin #sidebar .sbDotActivo{display:none}
    html.sbMin #sidebar .sbKbd{display:none}
    /* centrar iconos */
    html.sbMin #sidebar .navlink{justify-content:center;padding-left:.5rem;padding-right:.5rem}
    html.sbMin #sidebar .sbBrand{justify-content:center;padding-left:0;padding-right:0}
    html.sbMin #sidebar .sbQuick{justify-content:center;padding-left:.5rem;padding-right:.5rem}
    html.sbMin #sidebar nav{padding-left:.5rem;padding-right:.5rem}
    /* separador entre grupos cuando no hay títulos */
    html.sbMin #sidebar .sbGrp + .sbGrp{border-top:1px solid #f1f5f9;padding-top:.75rem}
    /* flecha del botón plegar */
    html.sbMin #sidebar #sbToggle i{transform:rotate(180deg)}
    /* tooltip a la derecha del icono (solo comprimido) */
    html.sbMin #sidebar .navlink{position:relative}
    html.sbMin #sidebar .navlink::after{
      content:attr(data-tip); position:absolute; left:calc(100% + 10px); top:50%;
      transform:translateY(-50%) translateX(-4px); white-space:nowrap;
      background:#1f2937; color:#fff; font-size:11px; font-weight:600;
      padding:5px 9px; border-radius:7px; pointer-events:none;
      opacity:0; transition:opacity .12s ease, transform .12s ease; z-index:60;
      box-shadow:0 6px 20px -6px rgba(15,23,42,.45)}
    html.sbMin #sidebar .navlink:hover::after{opacity:1;transform:translateY(-50%) translateX(0)}
  }

  /* Botón plegar: solo escritorio */
  #sbToggle{display:none}
  @media (min-width:1024px){ #sbToggle{display:grid} }

  /* Móvil: cajón deslizante (idéntico a antes) */
  #sbBack{display:none}
  body.sbOn #sbBack{display:block}
  @media (max-width:1023px){
    #sidebar{position:fixed;inset:0 auto 0 0;z-index:50;transform:translateX(-100%);
             transition:transform .22s cubic-bezier(.2,.8,.2,1);width:15rem!important}
    body.sbOn #sidebar{transform:none}
  }
  .navlink{transition:background .12s ease,color .12s ease}

  /* ── Paleta de accesos rápidos ── */
  #apOv{position:fixed;inset:0;z-index:60;display:none;background:rgba(15,23,42,.45);
        backdrop-filter:blur(2px);-webkit-backdrop-filter:blur(2px)}
  #apOv.on{display:block;animation:apIn .14s ease}
  @keyframes apIn{from{opacity:0}to{opacity:1}}
  #apBox{animation:apUp .18s cubic-bezier(.2,.8,.2,1)}
  @keyframes apUp{from{transform:translateY(-8px);opacity:.6}to{transform:none;opacity:1}}
  .apItem[aria-selected="true"]{background:rgba(26,187,156,.10)}
  .apItem[aria-selected="true"] .apIco{background:var(--teal);color:#fff}
  kbd{font-family:inherit;font-size:10px;line-height:1;padding:3px 5px;border-radius:4px;
      background:#f1f5f9;border:1px solid #e2e8f0;color:#64748b}

  @media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
</style>
<style type="text/tailwindcss">@layer components{
  .input-bordered{@apply w-full px-3 py-2.5 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all;}
}</style>
<?= $EXTRA_HEAD ?? '' ?>
</head>