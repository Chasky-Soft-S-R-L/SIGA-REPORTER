<?php
/**
 * VISTA · Administración de usuarios · SIGA-REPORTER
 * Los administradores gestionan cuentas; cualquier usuario puede cambiar su clave.
 */
require __DIR__ . '/Auth.php';

$auth = new Auth();
$auth->exigirLogin();
$USR   = $auth->usuario();
$admin = $auth->esAdmin();

$msg = ''; $tipo = 'ok';
$flash = function (string $m, string $t = 'ok') use (&$msg, &$tipo) { $msg = $m; $tipo = $t; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->csrfValido($_POST['csrf'] ?? null)) {
        $flash('La sesión expiró. Vuelva a intentarlo.', 'err');
    } else {
        $accion = $_POST['accion'] ?? '';

        // ── Cambio de contraseña propia (cualquier usuario) ──
        if ($accion === 'mi_clave') {
            $act = (string)($_POST['actual'] ?? '');
            $nue = (string)($_POST['nueva'] ?? '');
            $rep = (string)($_POST['repetir'] ?? '');
            if (!$auth->verificarClave($USR['id'], $act))      $flash('La contraseña actual no es correcta.', 'err');
            elseif (strlen($nue) < 6)                          $flash('La nueva contraseña debe tener al menos 6 caracteres.', 'err');
            elseif ($nue !== $rep)                             $flash('Las contraseñas nuevas no coinciden.', 'err');
            else { $auth->cambiarClave($USR['id'], $nue);      $flash('Contraseña actualizada correctamente.'); }
        }

        // ── Acciones de administrador ──
        elseif ($admin && $accion === 'crear') {
            $u = trim((string)($_POST['usuario'] ?? ''));
            $n = trim((string)($_POST['nombre'] ?? ''));
            $c = (string)($_POST['clave'] ?? '');
            $r = ($_POST['rol'] ?? 'consulta') === 'admin' ? 'admin' : 'consulta';
            $cc= trim((string)($_POST['centro'] ?? '')) ?: null;
            if ($u === '' || $n === '')      $flash('Usuario y nombre son obligatorios.', 'err');
            elseif (strlen($c) < 6)          $flash('La contraseña debe tener al menos 6 caracteres.', 'err');
            elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $u)) $flash('El usuario solo admite letras, números, punto, guion y guion bajo.', 'err');
            elseif (!$auth->crearUsuario($u, $n, $c, $r, $cc)) $flash('Ese nombre de usuario ya existe.', 'err');
            else                             $flash("Usuario «{$u}» creado correctamente.");
        }
        elseif ($admin && $accion === 'editar') {
            $auth->actualizar((int)$_POST['id'], trim((string)$_POST['nombre']),
                ($_POST['rol'] ?? 'consulta') === 'admin' ? 'admin' : 'consulta',
                trim((string)($_POST['centro'] ?? '')) ?: null);
            $flash('Datos actualizados.');
        }
        elseif ($admin && $accion === 'reset') {
            $c = (string)($_POST['clave'] ?? '');
            if (strlen($c) < 6) $flash('La contraseña debe tener al menos 6 caracteres.', 'err');
            else { $auth->cambiarClave((int)$_POST['id'], $c); $auth->desbloquear((int)$_POST['id']);
                   $flash('Contraseña restablecida.'); }
        }
        elseif ($admin && $accion === 'activo') {
            $ok = $auth->setActivo((int)$_POST['id'], $_POST['valor'] === '1');
            $ok ? $flash('Estado actualizado.') : $flash('No puede desactivar al único administrador activo.', 'err');
        }
        elseif ($admin && $accion === 'desbloquear') {
            $auth->desbloquear((int)$_POST['id']); $flash('Cuenta desbloqueada.');
        }
    }
}

$csrf     = $auth->csrf();
$usuarios = $admin ? $auth->usuarios() : [];
$bitacora = $admin ? $auth->bitacora(15) : [];
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Usuarios · SIGA Reportes</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<script>tailwind.config={theme:{extend:{colors:{
  primary:{light:'rgb(72,230,198)',DEFAULT:'rgb(26,187,156)',dark:'rgb(20,150,125)'}}}}};</script>
<style>
  .inp{width:100%;padding:.6rem .75rem;font-size:.875rem;border:1px solid #d1d5db;border-radius:.5rem;outline:none}
  .inp:focus{box-shadow:0 0 0 2px rgba(26,187,156,.45);border-color:transparent}
  .card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem}
</style>
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">
<div class="max-w-6xl mx-auto p-4 sm:p-6">

  <header class="flex items-center justify-between gap-3 mb-5">
    <div>
      <p class="text-[10px] font-bold tracking-[.2em] text-gray-400 uppercase">SIGA · Reportes</p>
      <h1 class="text-lg font-bold">Gestión de usuarios</h1>
    </div>
    <div class="flex items-center gap-2">
      <a href="index.php" class="px-3 py-2 text-sm rounded-lg border border-gray-300 hover:bg-white">
        <i class="fa-solid fa-arrow-left mr-1"></i> Volver al reporte
      </a>
      <a href="logout.php" class="px-3 py-2 text-sm rounded-lg border border-gray-300 text-gray-600 hover:bg-red-50 hover:text-red-600 hover:border-red-200">
        <i class="fa-solid fa-right-from-bracket"></i>
      </a>
    </div>
  </header>

  <?php if ($msg): ?>
    <div class="mb-4 flex items-start gap-2 text-[13px] rounded-lg px-3 py-2.5"
         style="<?= $tipo === 'ok' ? 'background:#ecfdf5;border:1px solid #a7f3d0;color:#047857' : 'background:#fef2f2;border:1px solid #fecaca;color:#b91c1c' ?>">
      <i class="fa-solid <?= $tipo === 'ok' ? 'fa-circle-check' : 'fa-circle-exclamation' ?> mt-0.5"></i>
      <span><?= htmlspecialchars($msg) ?></span>
    </div>
  <?php endif; ?>

  <div class="grid lg:grid-cols-3 gap-4">

    <!-- ── Mi cuenta ── -->
    <section class="card p-5 lg:col-span-1">
      <h2 class="font-bold text-sm mb-1"><i class="fa-solid fa-user-shield text-primary mr-1.5"></i> Mi cuenta</h2>
      <p class="text-xs text-gray-500 mb-4">
        <?= htmlspecialchars($USR['nombre']) ?> ·
        <span class="font-mono"><?= htmlspecialchars($USR['usuario']) ?></span> ·
        <span class="px-1.5 py-0.5 rounded text-[10px] font-bold <?= $admin ? 'bg-primary/15 text-primary-dark' : 'bg-gray-100 text-gray-600' ?>"><?= htmlspecialchars($USR['rol']) ?></span>
      </p>
      <form method="post" class="space-y-3">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="accion" value="mi_clave">
        <div><label class="block text-xs text-gray-600 mb-1">Contraseña actual</label>
          <input type="password" name="actual" required class="inp"></div>
        <div><label class="block text-xs text-gray-600 mb-1">Nueva contraseña</label>
          <input type="password" name="nueva" required minlength="6" class="inp"></div>
        <div><label class="block text-xs text-gray-600 mb-1">Repetir nueva</label>
          <input type="password" name="repetir" required minlength="6" class="inp"></div>
        <button class="w-full py-2.5 rounded-lg text-sm font-semibold text-white" style="background:rgb(26,187,156)">
          <i class="fa-solid fa-key mr-1"></i> Cambiar contraseña
        </button>
      </form>
    </section>

    <?php if ($admin): ?>
    <!-- ── Nuevo usuario ── -->
    <section class="card p-5 lg:col-span-2">
      <h2 class="font-bold text-sm mb-4"><i class="fa-solid fa-user-plus text-primary mr-1.5"></i> Nuevo usuario</h2>
      <form method="post" class="grid sm:grid-cols-2 gap-3">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="accion" value="crear">
        <div><label class="block text-xs text-gray-600 mb-1">Usuario</label>
          <input name="usuario" required placeholder="jperez" class="inp"></div>
        <div><label class="block text-xs text-gray-600 mb-1">Nombre completo</label>
          <input name="nombre" required placeholder="Juan Pérez" class="inp"></div>
        <div><label class="block text-xs text-gray-600 mb-1">Contraseña</label>
          <input type="password" name="clave" required minlength="6" class="inp"></div>
        <div><label class="block text-xs text-gray-600 mb-1">Rol</label>
          <select name="rol" class="inp">
            <option value="consulta">Consulta (solo ver reportes)</option>
            <option value="admin">Administrador (gestiona usuarios)</option>
          </select></div>
        <div class="sm:col-span-2"><label class="block text-xs text-gray-600 mb-1">Centro de costo <span class="text-gray-400">(opcional, informativo)</span></label>
          <input name="centro" placeholder="104.07.03.03" class="inp"></div>
        <div class="sm:col-span-2">
          <button class="px-5 py-2.5 rounded-lg text-sm font-semibold text-white" style="background:rgb(26,187,156)">
            <i class="fa-solid fa-plus mr-1"></i> Crear usuario
          </button>
        </div>
      </form>
    </section>

    <!-- ── Listado ── -->
    <section class="card lg:col-span-3 overflow-hidden">
      <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
        <h2 class="font-bold text-sm"><i class="fa-solid fa-users text-primary mr-1.5"></i> Usuarios registrados</h2>
        <span class="text-xs text-gray-400"><?= count($usuarios) ?> cuentas</span>
      </div>
      <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead><tr class="bg-gray-50 text-gray-600 text-xs">
          <th class="text-left px-4 py-2 font-semibold">Usuario</th>
          <th class="text-left px-4 py-2 font-semibold">Nombre</th>
          <th class="text-left px-4 py-2 font-semibold">Rol</th>
          <th class="text-left px-4 py-2 font-semibold">Centro</th>
          <th class="text-left px-4 py-2 font-semibold">Último acceso</th>
          <th class="text-center px-4 py-2 font-semibold">Estado</th>
          <th class="text-right px-4 py-2 font-semibold">Acciones</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-100">
        <?php foreach ($usuarios as $u): ?>
          <tr class="<?= $u['activo'] ? '' : 'bg-gray-50/70 text-gray-400' ?>">
            <td class="px-4 py-2 font-mono text-xs font-bold"><?= htmlspecialchars($u['usuario']) ?></td>
            <td class="px-4 py-2"><?= htmlspecialchars($u['nombre']) ?></td>
            <td class="px-4 py-2">
              <span class="px-1.5 py-0.5 rounded text-[10px] font-bold <?= $u['rol']==='admin' ? 'bg-primary/15 text-primary-dark' : 'bg-gray-100 text-gray-600' ?>">
                <?= htmlspecialchars($u['rol']) ?></span>
            </td>
            <td class="px-4 py-2 text-xs font-mono"><?= htmlspecialchars($u['centro_costo'] ?? '—') ?></td>
            <td class="px-4 py-2 text-xs text-gray-500"><?= htmlspecialchars($u['ultimo_acceso'] ?? 'Nunca') ?></td>
            <td class="px-4 py-2 text-center">
              <?php if ($u['activo']): ?><span class="text-[10px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 rounded px-1.5 py-0.5">Activo</span>
              <?php else: ?><span class="text-[10px] font-bold text-gray-500 bg-gray-100 border border-gray-200 rounded px-1.5 py-0.5">Inactivo</span><?php endif; ?>
            </td>
            <td class="px-4 py-2">
              <div class="flex items-center gap-1 justify-end">
                <button type="button" class="btnReset px-2 py-1 text-xs rounded border border-gray-300 hover:bg-gray-50"
                        data-id="<?= $u['id'] ?>" data-u="<?= htmlspecialchars($u['usuario']) ?>" title="Restablecer contraseña">
                  <i class="fa-solid fa-key"></i></button>
                <form method="post" class="inline">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="accion" value="activo">
                  <input type="hidden" name="id" value="<?= $u['id'] ?>">
                  <input type="hidden" name="valor" value="<?= $u['activo'] ? '0' : '1' ?>">
                  <button class="px-2 py-1 text-xs rounded border border-gray-300 hover:bg-gray-50" title="<?= $u['activo'] ? 'Desactivar' : 'Activar' ?>">
                    <i class="fa-solid <?= $u['activo'] ? 'fa-user-slash' : 'fa-user-check' ?>"></i></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </section>

    <!-- ── Bitácora ── -->
    <section class="card lg:col-span-3 p-5">
      <h2 class="font-bold text-sm mb-3"><i class="fa-solid fa-clock-rotate-left text-primary mr-1.5"></i> Últimos accesos</h2>
      <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-1 text-xs">
        <?php foreach ($bitacora as $b): ?>
          <div class="flex items-center gap-2 border-b border-gray-100 py-1">
            <i class="fa-solid <?= $b['exito'] ? 'fa-circle-check text-emerald-500' : 'fa-circle-xmark text-red-500' ?> text-[10px]"></i>
            <span class="font-mono font-bold"><?= htmlspecialchars($b['usuario']) ?></span>
            <span class="text-gray-400 ml-auto"><?= htmlspecialchars($b['fecha']) ?></span>
          </div>
        <?php endforeach; ?>
        <?php if (!$bitacora): ?><p class="text-gray-400">Sin registros.</p><?php endif; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>

<!-- Modal restablecer contraseña -->
<div id="mReset" class="hidden fixed inset-0 z-50">
  <div class="absolute inset-0 bg-black/40" onclick="cerrarReset()"></div>
  <div class="relative max-w-sm mx-auto mt-32 bg-white rounded-xl shadow-2xl p-5">
    <h3 class="font-bold text-sm mb-1"><i class="fa-solid fa-key text-primary mr-1"></i> Restablecer contraseña</h3>
    <p class="text-xs text-gray-500 mb-4">Usuario: <b id="rUser" class="font-mono"></b></p>
    <form method="post" class="space-y-3">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="accion" value="reset">
      <input type="hidden" name="id" id="rId">
      <input type="password" name="clave" required minlength="6" placeholder="Nueva contraseña" class="inp">
      <div class="flex gap-2">
        <button type="button" onclick="cerrarReset()" class="flex-1 py-2 rounded-lg border border-gray-300 text-sm">Cancelar</button>
        <button class="flex-1 py-2 rounded-lg text-sm font-semibold text-white" style="background:rgb(26,187,156)">Guardar</button>
      </div>
    </form>
  </div>
</div>

<script>
  document.querySelectorAll('.btnReset').forEach(b=>b.addEventListener('click',()=>{
    document.getElementById('rId').value=b.dataset.id;
    document.getElementById('rUser').textContent=b.dataset.u;
    document.getElementById('mReset').classList.remove('hidden');
  }));
  function cerrarReset(){document.getElementById('mReset').classList.add('hidden');}
  document.addEventListener('keydown',e=>{if(e.key==='Escape')cerrarReset();});
</script>
</body></html>