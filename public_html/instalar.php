<?php
/**
 * INSTALADOR · SIGA-REPORTER
 * Crea la base SQLite y deja listo el usuario administrador.
 *
 * Uso:  http://localhost:8000/instalar.php
 * ⚠ BORRE ESTE ARCHIVO cuando termine la instalación.
 *
 * Reglas de seguridad:
 *   · Si la base aún no tiene usuarios, cualquiera puede ejecutarlo (primer arranque).
 *   · Si ya hay usuarios, solo se permite desde el propio servidor (localhost)
 *     o con una sesión de administrador abierta.
 */
require __DIR__ . '/Auth.php';

const USUARIO_ADMIN = 'admin';
const CLAVE_ADMIN   = '$ecret123';   // comillas simples: la clave lleva '$'
const NOMBRE_ADMIN  = 'Administrador del Sistema';

$auth   = new Auth();
$lista  = $auth->usuarios();
$vacio  = count($lista) === 0;
$ip     = $_SERVER['REMOTE_ADDR'] ?? '';
$local  = in_array($ip, ['127.0.0.1', '::1', 'localhost'], true);
$puede  = $vacio || $local || $auth->esAdmin();

$hecho = false; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puede) {
    $existe = null;
    foreach ($lista as $u) { if ($u['usuario'] === USUARIO_ADMIN) { $existe = $u; break; } }

    if ($existe) {
        $auth->cambiarClave((int)$existe['id'], CLAVE_ADMIN);
        $auth->desbloquear((int)$existe['id']);
        $auth->setActivo((int)$existe['id'], true);
        $auth->actualizar((int)$existe['id'], NOMBRE_ADMIN, 'admin', null);
        $hecho = 'reset';
    } else {
        $auth->crearUsuario(USUARIO_ADMIN, NOMBRE_ADMIN, CLAVE_ADMIN, 'admin')
            ? $hecho = 'creado'
            : $error = 'No se pudo crear el usuario.';
    }
    $lista = $auth->usuarios();
}
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalación · SIGA Reportes</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body class="bg-slate-100 min-h-screen grid place-items-center p-4 text-gray-800">
<div class="w-full max-w-lg bg-white rounded-2xl shadow-xl border border-gray-200 overflow-hidden">

  <div class="px-6 py-5 text-white" style="background:linear-gradient(135deg,rgb(26,187,156),rgb(20,150,125))">
    <p class="text-[10px] font-bold tracking-[.2em] opacity-80 uppercase">SIGA · Reportes</p>
    <h1 class="text-lg font-bold">Instalación del sistema</h1>
  </div>

  <div class="p-6 space-y-4">

    <?php if (!$puede): ?>
      <div class="rounded-lg px-3 py-3 text-sm" style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c">
        <i class="fa-solid fa-shield-halved mr-1"></i>
        El sistema ya está instalado. Por seguridad, este instalador solo puede ejecutarse
        desde el servidor (localhost) o con una sesión de administrador abierta.
      </div>
      <a href="login.php" class="block text-center py-2.5 rounded-lg text-sm font-semibold text-white" style="background:rgb(26,187,156)">Ir al login</a>

    <?php elseif ($hecho): ?>
      <div class="rounded-lg px-3 py-3 text-sm" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#047857">
        <i class="fa-solid fa-circle-check mr-1"></i>
        <?= $hecho === 'creado' ? 'Usuario administrador creado correctamente.' : 'Contraseña del administrador restablecida.' ?>
      </div>

      <div class="rounded-lg border border-gray-200 divide-y divide-gray-100 text-sm">
        <div class="flex justify-between px-3 py-2"><span class="text-gray-500">Usuario</span><b class="font-mono"><?= htmlspecialchars(USUARIO_ADMIN) ?></b></div>
        <div class="flex justify-between px-3 py-2"><span class="text-gray-500">Contraseña</span><b class="font-mono"><?= htmlspecialchars(CLAVE_ADMIN) ?></b></div>
        <div class="flex justify-between px-3 py-2"><span class="text-gray-500">Rol</span><b>admin</b></div>
        <div class="flex justify-between px-3 py-2"><span class="text-gray-500">Base de datos</span><span class="font-mono text-xs">data/siga_reporter.sqlite</span></div>
      </div>

      <div class="rounded-lg px-3 py-3 text-[13px]" style="background:#fffbeb;border:1px solid #fde68a;color:#92400e">
        <b><i class="fa-solid fa-triangle-exclamation mr-1"></i> Antes de usar el sistema:</b>
        <ol class="list-decimal ml-5 mt-1.5 space-y-0.5">
          <li>Elimine el archivo <span class="font-mono">instalar.php</span> del servidor.</li>
          <li>Ingrese y cambie la contraseña desde <span class="font-mono">usuarios.php</span>.</li>
          <li>Bloquee el acceso web a la carpeta <span class="font-mono">data/</span>.</li>
        </ol>
      </div>

      <a href="login.php" class="block text-center py-2.5 rounded-lg text-sm font-semibold text-white" style="background:rgb(26,187,156)">
        <i class="fa-solid fa-right-to-bracket mr-1"></i> Ir al login
      </a>

    <?php else: ?>
      <?php if ($error): ?>
        <div class="rounded-lg px-3 py-2 text-sm" style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <p class="text-sm text-gray-600">
        Se creará la base SQLite y el usuario administrador con estas credenciales:
      </p>
      <div class="rounded-lg bg-gray-50 border border-gray-200 p-3 text-sm">
        <div class="flex justify-between py-0.5"><span class="text-gray-500">Usuario</span><b class="font-mono"><?= htmlspecialchars(USUARIO_ADMIN) ?></b></div>
        <div class="flex justify-between py-0.5"><span class="text-gray-500">Contraseña</span><b class="font-mono"><?= htmlspecialchars(CLAVE_ADMIN) ?></b></div>
      </div>

      <?php if (!$vacio): ?>
        <div class="rounded-lg px-3 py-2 text-[13px]" style="background:#fffbeb;border:1px solid #fde68a;color:#92400e">
          <i class="fa-solid fa-rotate mr-1"></i> Ya existen <?= count($lista) ?> usuario(s).
          Al continuar, la cuenta <span class="font-mono">admin</span> se restablecerá con esa contraseña
          (los demás usuarios no se tocan).
        </div>
      <?php endif; ?>

      <form method="post">
        <button class="w-full py-2.5 rounded-lg text-sm font-semibold text-white" style="background:rgb(26,187,156)">
          <i class="fa-solid fa-wand-magic-sparkles mr-1"></i>
          <?= $vacio ? 'Instalar sistema' : 'Restablecer administrador' ?>
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body></html>