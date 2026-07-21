<?php
/**
 * config.php  ·  SIGA-REPORTER
 * Carga las variables de .env y define las constantes de la aplicación.
 * Sin dependencias: no requiere Composer ni vlucas/phpdotenv.
 *
 * Uso:  require_once __DIR__ . '/config.php';
 *       (al inicio de index.php, dashboard.php, dashboard-api.php, Auth.php…)
 *
 * Es idempotente: puede incluirse varias veces sin errores de redeclaración,
 * porque Auth.php y las vistas lo van a pedir por su cuenta.
 *
 * El archivo .env NO se versiona (ver .gitignore). Cada máquina tiene el suyo,
 * copiado de .env.example.
 */

if (defined('SIGA_CONFIG_CARGADO')) return;
define('SIGA_CONFIG_CARGADO', true);

/* ── Lector de .env ─────────────────────────────────────────────
   Formato admitido:   CLAVE=valor
                       CLAVE="valor con espacios"
                       # comentario de línea
   Las líneas vacías y los comentarios se ignoran. */
if (!class_exists('Env')) {
    final class Env
    {
        private static array $vars = [];
        private static bool  $cargado = false;

        public static function cargar(string $ruta): void
        {
            if (self::$cargado) return;
            self::$cargado = true;

            if (!is_readable($ruta)) return;   // sin .env se usan los valores por defecto

            foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
                $linea = trim($linea);
                if ($linea === '' || $linea[0] === '#' || !str_contains($linea, '=')) continue;

                [$clave, $valor] = explode('=', $linea, 2);
                $clave = trim($clave);
                $valor = trim($valor);

                // Quita comillas envolventes si las hay
                $len = strlen($valor);
                if ($len >= 2 && (($valor[0] === '"' && $valor[$len-1] === '"')
                               || ($valor[0] === "'" && $valor[$len-1] === "'"))) {
                    $valor = substr($valor, 1, -1);
                }
                self::$vars[$clave] = $valor;
            }
        }

        /** Valor de una variable, con valor por defecto y casteo básico. */
        public static function get(string $clave, mixed $porDefecto = null): mixed
        {
            $v = self::$vars[$clave] ?? getenv($clave);
            if ($v === false || $v === null || $v === '') return $porDefecto;

            return match (strtolower((string)$v)) {
                'true','on','yes'  => true,
                'false','off','no' => false,
                'null'             => null,
                default            => $v,
            };
        }

        public static function int(string $clave, int $porDefecto): int
        {
            $v = self::get($clave, null);
            return $v === null ? $porDefecto : (int)$v;
        }
    }
}

Env::cargar(__DIR__ . '/.env');

/* ── Base de datos ───────────────────────────────────────────────
   DB_USER y DB_PASS vacíos = autenticación Windows (trusted connection),
   que es como está configurado el servidor del SIGA. */
define('DB_SERVER', Env::get('DB_SERVER', 'localhost'));
define('DB_NAME',   Env::get('DB_NAME',   'SIGA_104'));
define('DB_USER',   Env::get('DB_USER',   ''));
define('DB_PASS',   Env::get('DB_PASS',   ''));

/* ── Entidad ejecutora ──────────────────────────────────────────
   SEC_EJEC 104 = Universidad Nacional Agraria de la Selva. */
define('SEC_EJEC',   Env::int('SEC_EJEC',   104));
define('ANIO_PROG',  Env::int('ANIO_PROG',  (int)date('Y') + 1));

/* ── Aplicación ─────────────────────────────────────────────────── */
define('APP_NOMBRE',     Env::get('APP_NOMBRE', 'SIGA · REPORTES'));
define('APP_ENTIDAD',    Env::get('APP_ENTIDAD', 'Universidad Nacional Agraria de la Selva'));
define('APP_DEBUG',      (bool)Env::get('APP_DEBUG', false));
define('CACHE_TTL',      Env::int('CACHE_TTL', 300));
define('MAX_ROWS',       Env::int('MAX_ROWS', 100000));
define('SESION_MINUTOS', Env::int('SESION_MINUTOS', 120));

/* ── Errores ────────────────────────────────────────────────────
   En producción nunca se muestran en pantalla: podrían filtrar la cadena
   de conexión o nombres de tablas del SIGA. Se registran en el log. */
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
    ini_set('error_log', $logDir . '/php-error.log');
}

date_default_timezone_set(Env::get('APP_TZ', 'America/Lima'));