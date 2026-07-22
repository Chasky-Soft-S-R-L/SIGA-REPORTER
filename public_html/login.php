<?php
/**
 * VISTA · Login del SIGA-REPORTER
 * Rediseño con la identidad visual de pagos.unas.edu.pe
 */
require __DIR__ . '/Auth.php';

$auth = new Auth();
if ($auth->logueado()) { header('Location: index.php'); exit; }

$error = '';
$usuarioPrev = '';
$next = $_GET['next'] ?? ($_POST['next'] ?? 'index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuarioPrev = trim((string)($_POST['usuario'] ?? ''));
    if (!$auth->csrfValido($_POST['csrf'] ?? null)) {
        $error = 'La sesión expiró. Vuelva a intentarlo.';
    } else {
        $r = $auth->login($usuarioPrev, (string)($_POST['clave'] ?? ''));
        if ($r['ok']) { header('Location: ' . (str_starts_with($next, 'http') ? 'index.php' : $next)); exit; }
        $error = $r['msg'];
    }
}
$csrf = $auth->csrf();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Acceso · SIGA Reportes</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<script>
tailwind.config={theme:{extend:{colors:{
  primary:{light:'rgb(72,230,198)',DEFAULT:'rgb(26,187,156)',dark:'rgb(20,150,125)'}},
  fontFamily:{display:['Poppins','sans-serif'],sans:['Inter','sans-serif']}}}};
</script>
<style>
  :root{ --teal:rgb(26,187,156); --teal-dark:rgb(20,150,125); }
  body{font-family:'Inter',system-ui,sans-serif;}
  .display{font-family:'Poppins',sans-serif;}

  /* Panel izquierdo */
  .panel{
    background:linear-gradient(160deg,rgb(38,201,170) 0%,var(--teal) 45%,var(--teal-dark) 100%);
    position:relative; overflow:hidden;
  }
  /* Anillos decorativos (igual que el portal de pagos) */
  .panel::after{
    content:''; position:absolute; right:-140px; bottom:-160px;
    width:520px; height:520px; border-radius:50%;
    border:1px solid rgba(255,255,255,.18);
    box-shadow:0 0 0 60px rgba(255,255,255,.05), inset 0 0 0 60px rgba(255,255,255,.05);
  }
  .feature{
    background:rgba(255,255,255,.10);
    border:1px solid rgba(255,255,255,.14);
    backdrop-filter:blur(2px);
  }
  .dot{ animation:pulse 2s ease-in-out infinite }
  @keyframes pulse{0%,100%{opacity:1}50%{opacity:.35}}

  .rise{animation:rise .45s cubic-bezier(.2,.8,.2,1) both}
  .rise-2{animation-delay:.08s}
  @keyframes rise{from{transform:translateY(14px);opacity:0}to{transform:none;opacity:1}}

  .shake{animation:sh .35s}
  @keyframes sh{0%,100%{transform:translateX(0)}25%{transform:translateX(-5px)}75%{transform:translateX(5px)}}

  @media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
</style>
</head>
<body class="min-h-screen bg-white text-slate-800">

<div class="min-h-screen grid lg:grid-cols-[42%_58%]">

  <!-- ══════════ IZQUIERDA · Marca ══════════ -->
  <aside class="panel hidden lg:flex flex-col justify-between p-10 xl:p-14 text-white">

    <div class="flex items-center gap-3">
      <div class="w-11 h-11 rounded-full bg-white/15 border border-white/25 grid place-items-center">
        <i class="fa-solid fa-building-columns text-white"></i>
      </div>
      <div class="leading-tight">
        <p class="display font-extrabold tracking-wide text-lg">UNAS</p>
        <p class="text-[11px] tracking-[.18em] text-white/80">SIGA · REPORTES</p>
      </div>
    </div>

    <div class="relative z-10 max-w-md">
      <span class="inline-flex items-center gap-2 text-[10px] tracking-[.16em] font-semibold
                   px-3 py-1.5 rounded-full bg-white/15 border border-white/25">
        <span class="dot w-1.5 h-1.5 rounded-full bg-white"></span> SISTEMA ACTIVO
      </span>

      <h2 class="display mt-6 text-5xl xl:text-[3.4rem] font-extrabold leading-[1.05] tracking-tight">
        Consulta tus<br>reportes<br>
        <span class="text-white/55">al instante</span>
      </h2>

      <p class="mt-6 text-white/85 leading-relaxed">
        Cuadro de Necesidades y Ejecución del Gasto.<br>
        Información del SIGA actualizada, lista para exportar.
      </p>
    </div>

    <div class="relative z-10 space-y-3">
      <div class="feature flex items-center gap-3 rounded-xl px-4 py-3 text-sm">
        <i class="fa-solid fa-chart-column w-4 text-white/90"></i>
        <span>Cuadro de Necesidades por centro de costo</span>
      </div>
      <div class="feature flex items-center gap-3 rounded-xl px-4 py-3 text-sm">
        <i class="fa-solid fa-coins w-4 text-white/90"></i>
        <span>Seguimiento de la Ejecución del Gasto</span>
      </div>
      <div class="feature flex items-center gap-3 rounded-xl px-4 py-3 text-sm">
        <i class="fa-solid fa-file-excel w-4 text-white/90"></i>
        <span>Exportación a Excel y PDF</span>
      </div>
    </div>
  </aside>

  <!-- ══════════ DERECHA · Formulario ══════════ -->
  <main class="flex items-center justify-center p-6 sm:p-10">
    <div class="w-full max-w-md rise">

      <!-- Marca compacta solo en móvil -->
      <div class="lg:hidden flex items-center gap-3 mb-8">
        <div class="w-11 h-11 rounded-2xl grid place-items-center shadow-lg"
             style="background:linear-gradient(135deg,var(--teal),var(--teal-dark))">
          <i class="fa-solid fa-building-columns text-white"></i>
        </div>
        <div class="leading-tight">
          <p class="display font-extrabold tracking-wide text-slate-800">UNAS</p>
          <p class="text-[11px] tracking-[.18em] text-slate-400">SIGA · REPORTES</p>
        </div>
      </div>

      <h1 class="display text-3xl font-extrabold tracking-tight text-slate-800">¿Quién ingresa?</h1>
      <p class="text-sm text-slate-500 mt-1.5">Usa tu usuario institucional para continuar</p>

      <form method="post" class="mt-8 rise rise-2<?= $error ? ' shake' : '' ?>">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

        <div class="rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
          <!-- Cabecera de la tarjeta -->
          <div class="flex items-center gap-3 px-5 py-4 text-white"
               style="background:linear-gradient(135deg,var(--teal),var(--teal-dark))">
            <div class="w-9 h-9 rounded-lg bg-white/20 grid place-items-center">
              <i class="fa-solid fa-id-card-clip text-sm"></i>
            </div>
            <div class="leading-tight">
              <p class="text-[13px] font-bold tracking-wide uppercase">Credenciales de acceso</p>
              <p class="text-[11px] text-white/80">Personal autorizado de la UNAS</p>
            </div>
          </div>

          <div class="p-5 space-y-4 bg-white">
            <?php if ($error): ?>
              <div class="flex items-start gap-2 text-[12px] rounded-lg px-3 py-2"
                   style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c">
                <i class="fa-solid fa-circle-exclamation mt-0.5"></i>
                <span><?= htmlspecialchars($error) ?></span>
              </div>
            <?php endif; ?>

            <div>
              <label for="usuario" class="block text-[11px] font-semibold tracking-wide uppercase text-slate-500 mb-1.5">Usuario</label>
              <div class="relative">
                <i class="fa-solid fa-user absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                <input id="usuario" name="usuario" value="<?= htmlspecialchars($usuarioPrev) ?>" required autofocus autocomplete="username"
                       class="w-full pl-10 pr-3 py-3 text-sm bg-slate-50 border border-slate-200 rounded-xl
                              focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent focus:bg-white transition">
              </div>
            </div>

            <div>
              <label for="clave" class="block text-[11px] font-semibold tracking-wide uppercase text-slate-500 mb-1.5">Contraseña</label>
              <div class="relative">
                <i class="fa-solid fa-lock absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                <input type="password" name="clave" id="clave" required autocomplete="current-password"
                       class="w-full pl-10 pr-10 py-3 text-sm bg-slate-50 border border-slate-200 rounded-xl
                              focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent focus:bg-white transition">
                <button type="button" id="ver" tabindex="-1" aria-label="Mostrar contraseña"
                        class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 text-xs">
                  <i class="fa-solid fa-eye"></i>
                </button>
              </div>
            </div>
          </div>
        </div>

        <button class="mt-5 w-full py-3.5 rounded-xl text-sm font-bold tracking-wide text-white shadow-md
                       hover:brightness-105 active:brightness-95 transition"
                style="background:linear-gradient(135deg,var(--teal),var(--teal-dark))">
          <i class="fa-solid fa-right-to-bracket mr-2"></i> Ingresar al sistema
        </button>
      </form>

      <p class="text-center text-[11px] text-slate-400 mt-8 leading-relaxed">
        Acceso restringido · Universidad Nacional Agraria de la Selva<br>
        Oficina de Abastecimiento
      </p>
    </div>
  </main>
</div>

<script>
  document.getElementById('ver').addEventListener('click',()=>{
    const i=document.getElementById('clave'), ic=document.querySelector('#ver i');
    const p=i.type==='password'; i.type=p?'text':'password';
    ic.className=p?'fa-solid fa-eye-slash':'fa-solid fa-eye'; i.focus();
  });
</script>
</body>
</html>