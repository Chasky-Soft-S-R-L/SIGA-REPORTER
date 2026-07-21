<?php
/**
 * partials/header.php  ·  Barra superior
 *
 * Variables esperadas:
 *   $USR        usuario en sesión
 *   $TITULO     título de la pantalla
 *   $SUBTITULO  línea secundaria (admite HTML: se usa para contadores en vivo)
 *   $ACCIONES   HTML de los botones propios de la pantalla (Excel, PDF, Tabla/Kanban…)
 */
$TITULO    = $TITULO    ?? '';
$SUBTITULO = $SUBTITULO ?? '';
$ACCIONES  = $ACCIONES  ?? '';
$ini       = strtoupper(mb_substr($USR['nombre'] ?? '?', 0, 1));
?>
<header class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-3">

  <div class="flex items-start gap-2 min-w-0">
    <!-- Abrir menú (solo móvil) -->
    <button class="lg:hidden mt-0.5 px-2.5 py-1.5 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50"
            onclick="document.body.classList.add('sbOn')" aria-label="Abrir menú">
      <i class="fa-solid fa-bars text-sm"></i>
    </button>
    <h1 class="text-base sm:text-lg font-bold leading-tight min-w-0">
      <?= $TITULO ?>
      <?php if ($SUBTITULO): ?>
        <span class="block sm:inline text-xs text-gray-400 font-normal"><?= $SUBTITULO ?></span>
      <?php endif; ?>
    </h1>
  </div>

  <div class="flex flex-wrap items-center gap-2">
    <?= $ACCIONES ?>

    <!-- Buscador rápido -->
    <button type="button" data-ap-open title="Accesos rápidos (Ctrl K)"
            class="px-2.5 py-2 text-sm rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 transition">
      <i class="fa-solid fa-magnifying-glass"></i>
    </button>

    <!-- Usuario -->
    <div class="inline-flex items-center gap-2 pl-2 ml-1 border-l border-gray-300">
      <span class="hidden sm:flex items-center gap-2 px-2.5 py-1.5 rounded-lg bg-white border border-gray-200"
            title="<?= htmlspecialchars($USR['usuario'] ?? '') ?> · <?= htmlspecialchars($USR['rol'] ?? '') ?>">
        <span class="w-6 h-6 rounded-full grid place-items-center text-white text-[10px] font-bold"
              style="background:linear-gradient(135deg,var(--teal),var(--teal-dark))"><?= htmlspecialchars($ini) ?></span>
        <span class="text-xs font-medium text-gray-700 leading-none"><?= htmlspecialchars($USR['nombre'] ?? '') ?></span>
      </span>
      <a href="usuarios.php" title="Usuarios y contraseña"
         class="px-3 py-2 text-sm rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 transition">
        <i class="fa-solid fa-users-gear"></i>
      </a>
      <a href="logout.php" title="Cerrar sesión"
         class="px-3 py-2 text-sm rounded-lg border border-gray-300 text-gray-600 hover:bg-red-50 hover:text-red-600 hover:border-red-200 transition">
        <i class="fa-solid fa-right-from-bracket"></i>
      </a>
    </div>
  </div>
</header>