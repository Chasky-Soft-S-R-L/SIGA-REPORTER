<?php
/**
 * index.php  ·  RAÍZ del proyecto SIGA-REPORTER
 *
 * RED DE SEGURIDAD, no el mecanismo principal.
 *
 * Lo normal es que el .htaccess de esta misma carpeta redirija todo a
 * public_html/ de forma transparente, sin que la URL cambie. Este archivo
 * solo entra en acción si el hosting tiene mod_rewrite desactivado o
 * AllowOverride en None: en ese caso el .htaccess se ignora y, sin esto,
 * el visitante vería el listado de carpetas del proyecto.
 *
 * Aquí la URL SÍ cambia (queda /public_html/…), que es más feo pero
 * funciona. Si ves esa ruta en el navegador, es señal de que el rewrite
 * no está activo: pídele al administrador que habilite AllowOverride All.
 */

$destino = 'public_html/';

// Conservar la query string (?anio=2026, ?next=…)
$qs = $_SERVER['QUERY_STRING'] ?? '';
if ($qs !== '') $destino .= '?' . $qs;

header('Location: ' . $destino, true, 302);
exit;