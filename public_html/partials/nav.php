<?php
/**
 * partials/nav.php  ·  Definición ÚNICA del menú
 * Todo lo que aparece en el sidebar y en el buscador de accesos rápidos sale
 * de aquí. Para añadir una pantalla, agrega una entrada: no hay que tocar
 * sidebar.php ni accesos.php.
 *
 *   clave   identificador para marcar la página activa ($PAGINA)
 *   texto   etiqueta visible
 *   icono   clase de Font Awesome
 *   url     destino; {anio} se reemplaza por el año en curso
 *   desc    ayuda corta (se ve en el buscador rápido)
 *   rol     opcional. Si está, solo ese rol ve el enlace.
 *   grupo   encabezado de sección en el sidebar
 */

return [
    ['clave'=>'cmn',       'grupo'=>'Reportes',
     'texto'=>'Cuadro de Necesidades', 'icono'=>'fa-table-list',
     'url'=>'index.php?resource=cmn&anio={anio}',
     'desc'=>'Tabla y kanban del CMN con trazabilidad por ítem'],

    ['clave'=>'dashboard', 'grupo'=>'Reportes',
     'texto'=>'Dashboard',  'icono'=>'fa-chart-pie',
     'url'=>'dashboard.php?anio={anio}',
     'desc'=>'Panel reactivo: avance por centro, meta y genérica'],

    ['clave'=>'usuarios',  'grupo'=>'Administración',
     'texto'=>'Usuarios',   'icono'=>'fa-users-gear',
     'url'=>'usuarios.php',
     'desc'=>'Cuentas de acceso y cambio de contraseña'],

    ['clave'=>'salir',     'grupo'=>'Administración',
     'texto'=>'Cerrar sesión', 'icono'=>'fa-right-from-bracket',
     'url'=>'logout.php',
     'desc'=>'Salir del sistema'],
];