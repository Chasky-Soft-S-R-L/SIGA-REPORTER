<?php
/**
 * partials/sidebar.php  ·  Barra lateral de navegación
 *
 * Variables esperadas:
 *   $PAGINA  clave de la página activa (ver partials/nav.php)
 *   $USR     usuario en sesión
 *   $ANIO    año en curso (para los enlaces)
 *
 * En pantallas menores a 1024px queda oculto y se abre con el botón ☰ de la
 * cabecera (clase .sbOn en el body).
 */
$PAGINA = $PAGINA ?? '';
$ANIO   = (int)($ANIO ?? ANIO_PROG);
$MENU   = require __DIR__ . '/nav.php';

$puedeVer = fn(array $it) => !isset($it['rol']) || ($USR['rol'] ?? '') === $it['rol'];
$href     = fn(array $it) => str_replace('{anio}', (string)$ANIO, $it['url']);

// Agrupar respetando el orden de aparición
$grupos = [];
foreach ($MENU as $it) { if ($puedeVer($it)) $grupos[$it['grupo'] ?? 'General'][] = $it; }
?>
<!-- Fondo oscuro en móvil -->
<div id="sbBack" class="fixed inset-0 z-40 bg-black/40 lg:hidden" onclick="document.body.classList.remove('sbOn')"></div>

<aside id="sidebar" class="w-60 shrink-0 bg-white border-r border-gray-200 flex flex-col">

  <!-- Marca -->
  <div class="px-4 py-4 border-b border-gray-100 flex items-center gap-2.5">
    <div class="w-9 h-9 rounded-xl grid place-items-center shrink-0"
         style="background:linear-gradient(135deg,var(--teal),var(--teal-dark))">
      <i class="fa-solid fa-file-invoice-dollar text-white text-sm"></i>
    </div>
    <div class="leading-tight min-w-0">
      <p class="text-primary-dark font-bold text-sm truncate"><?= htmlspecialchars(APP_NOMBRE) ?></p>
      <p class="text-[10px] text-gray-400 truncate">Ejercicio <?= $ANIO ?></p>
    </div>
    <button class="ml-auto lg:hidden text-gray-400 hover:text-gray-600 px-1"
            onclick="document.body.classList.remove('sbOn')" aria-label="Cerrar menú">✕</button>
  </div>

  <!-- Accesos rápidos -->
  <div class="px-3 pt-3">
    <button type="button" data-ap-open
            class="w-full flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-200 text-left
                   text-gray-500 hover:border-primary hover:text-primary-dark transition">
      <i class="fa-solid fa-magnifying-glass text-xs"></i>
      <span class="text-xs">Ir a…</span>
      <kbd class="ml-auto">Ctrl K</kbd>
    </button>
  </div>

  <!-- Menú -->
  <nav class="p-3 text-sm flex-1 overflow-y-auto space-y-4">
    <?php foreach ($grupos as $grupo => $items): ?>
      <div>
        <p class="px-2 mb-1 text-[10px] font-bold uppercase tracking-widest text-gray-400"><?= htmlspecialchars($grupo) ?></p>
        <?php foreach ($items as $it):
              $act = ($it['clave'] === $PAGINA);
              $sal = ($it['clave'] === 'salir'); ?>
          <a href="<?= htmlspecialchars($href($it)) ?>" title="<?= htmlspecialchars($it['desc'] ?? '') ?>"
             class="navlink flex items-center gap-2.5 px-3 py-2 rounded-lg <?=
               $act ? 'bg-primary/10 text-primary-dark font-semibold'
                    : ($sal ? 'text-gray-500 hover:bg-red-50 hover:text-red-600'
                            : 'text-gray-600 hover:bg-gray-50') ?>">
            <i class="fa-solid <?= htmlspecialchars($it['icono']) ?> w-4 text-center text-xs"></i>
            <span class="truncate"><?= htmlspecialchars($it['texto']) ?></span>
            <?php if ($act): ?><span class="ml-auto w-1.5 h-1.5 rounded-full bg-primary"></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </nav>

  <!-- Pie -->
  <div class="px-4 py-3 border-t border-gray-100">
    <p class="text-[10px] text-gray-400 leading-snug"><?= htmlspecialchars(APP_ENTIDAD) ?></p>
  </div>
</aside>