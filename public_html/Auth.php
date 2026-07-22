<?php
/**
 * CAPA AUTH  ·  SIGA-REPORTER
 * Autenticación con SQLite (sin dependencias). Crea la base y el usuario
 * administrador la primera vez que se ejecuta.
 *
 * Seguridad: password_hash/verify, regeneración de sesión al entrar,
 * token CSRF y bloqueo temporal por intentos fallidos.
 */
class Auth
{
    private PDO $db;
    private const MAX_INTENTOS = 5;      // intentos antes de bloquear
    private const BLOQUEO_SEG  = 300;    // 5 minutos de bloqueo

    public function __construct(?string $dbPath = null)
    {
        $dbPath = $dbPath ?: __DIR__ . '/data/siga_reporter.sqlite';
        $dir = dirname($dbPath);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        $this->db = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->crearEsquema();

        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
            session_start();
        }
    }

    /** Tablas + usuario administrador inicial. */
    private function crearEsquema(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS usuarios (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                usuario        TEXT    NOT NULL UNIQUE,
                nombre         TEXT    NOT NULL,
                clave_hash     TEXT    NOT NULL,
                rol            TEXT    NOT NULL DEFAULT 'consulta',
                centro_costo   TEXT,
                activo         INTEGER NOT NULL DEFAULT 1,
                intentos       INTEGER NOT NULL DEFAULT 0,
                bloqueado_hasta INTEGER,
                ultimo_acceso  TEXT,
                creado         TEXT    NOT NULL DEFAULT (datetime('now','localtime'))
            )");
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS accesos (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                usuario  TEXT NOT NULL,
                exito    INTEGER NOT NULL,
                ip       TEXT,
                fecha    TEXT NOT NULL DEFAULT (datetime('now','localtime'))
            )");

        $n = (int)$this->db->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
        if ($n === 0) {
            $st = $this->db->prepare(
                "INSERT INTO usuarios (usuario, nombre, clave_hash, rol) VALUES (?,?,?,?)"
            );
            // Comillas simples: la clave contiene '$' y no debe interpolarse.
            $st->execute(['admin', 'Administrador', password_hash('$ecret123', PASSWORD_DEFAULT), 'admin']);
        }
    }

    /* ─────────── Sesión ─────────── */

    public function logueado(): bool
    {
        return !empty($_SESSION['uid']);
    }

    public function usuario(): ?array
    {
        if (!$this->logueado()) return null;
        return [
            'id'     => $_SESSION['uid'],
            'usuario'=> $_SESSION['uusuario'] ?? '',
            'nombre' => $_SESSION['unombre']  ?? '',
            'rol'    => $_SESSION['urol']     ?? 'consulta',
        ];
    }

    /** Corta la ejecución y manda al login si no hay sesión. */
    public function exigirLogin(string $loginUrl = 'login.php'): void
    {
        if ($this->logueado()) return;
        $destino = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: ' . $loginUrl . ($destino ? '?next=' . urlencode($destino) : ''));
        exit;
    }

    /* ─────────── Login / logout ─────────── */

    /** @return array{ok:bool, msg:string} */
    public function login(string $usuario, string $clave): array
    {
        $usuario = trim($usuario);
        $st = $this->db->prepare("SELECT * FROM usuarios WHERE usuario = ?");
        $st->execute([$usuario]);
        $u = $st->fetch();

        if (!$u) {
            $this->registrar($usuario, false);
            return ['ok' => false, 'msg' => 'Usuario o contraseña incorrectos.'];
        }
        if (!$u['activo']) {
            $this->registrar($usuario, false);
            return ['ok' => false, 'msg' => 'La cuenta está desactivada. Contacte al administrador.'];
        }
        if ($u['bloqueado_hasta'] && (int)$u['bloqueado_hasta'] > time()) {
            $min = (int)ceil(((int)$u['bloqueado_hasta'] - time()) / 60);
            return ['ok' => false, 'msg' => "Cuenta bloqueada por intentos fallidos. Reintente en {$min} min."];
        }
        if (!password_verify($clave, $u['clave_hash'])) {
            $intentos = (int)$u['intentos'] + 1;
            $hasta = $intentos >= self::MAX_INTENTOS ? time() + self::BLOQUEO_SEG : null;
            $this->db->prepare("UPDATE usuarios SET intentos = ?, bloqueado_hasta = ? WHERE id = ?")
                     ->execute([$intentos >= self::MAX_INTENTOS ? 0 : $intentos, $hasta, $u['id']]);
            $this->registrar($usuario, false);
            $restan = self::MAX_INTENTOS - $intentos;
            return ['ok' => false, 'msg' => 'Usuario o contraseña incorrectos.'
                . ($restan > 0 && $restan <= 2 ? " Le quedan {$restan} intento(s)." : '')];
        }

        // Éxito
        $this->db->prepare("UPDATE usuarios SET intentos = 0, bloqueado_hasta = NULL,
                            ultimo_acceso = datetime('now','localtime') WHERE id = ?")
                 ->execute([$u['id']]);
        session_regenerate_id(true);
        $_SESSION['uid']      = (int)$u['id'];
        $_SESSION['uusuario'] = $u['usuario'];
        $_SESSION['unombre']  = $u['nombre'];
        $_SESSION['urol']     = $u['rol'];
        $this->registrar($usuario, true);
        return ['ok' => true, 'msg' => ''];
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    private function registrar(string $usuario, bool $exito): void
    {
        $this->db->prepare("INSERT INTO accesos (usuario, exito, ip) VALUES (?,?,?)")
                 ->execute([$usuario, $exito ? 1 : 0, $_SERVER['REMOTE_ADDR'] ?? '']);
    }

    /* ─────────── CSRF ─────────── */

    public function csrf(): string
    {
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }

    public function csrfValido(?string $token): bool
    {
        return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
    }

    /* ─────────── Gestión de usuarios ─────────── */

    public function crearUsuario(string $usuario, string $nombre, string $clave, string $rol = 'consulta', ?string $centro = null): bool
    {
        try {
            $this->db->prepare("INSERT INTO usuarios (usuario, nombre, clave_hash, rol, centro_costo) VALUES (?,?,?,?,?)")
                     ->execute([trim($usuario), $nombre, password_hash($clave, PASSWORD_DEFAULT), $rol, $centro]);
            return true;
        } catch (PDOException $e) { return false; }
    }

    public function cambiarClave(int $id, string $nueva): bool
    {
        return $this->db->prepare("UPDATE usuarios SET clave_hash = ? WHERE id = ?")
                        ->execute([password_hash($nueva, PASSWORD_DEFAULT), $id]);
    }

    public function usuarios(): array
    {
        return $this->db->query("SELECT id, usuario, nombre, rol, activo, centro_costo, ultimo_acceso, creado
                                 FROM usuarios ORDER BY activo DESC, usuario")->fetchAll();
    }

    public function buscar(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM usuarios WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function actualizar(int $id, string $nombre, string $rol, ?string $centro): bool
    {
        return $this->db->prepare("UPDATE usuarios SET nombre = ?, rol = ?, centro_costo = ? WHERE id = ?")
                        ->execute([$nombre, $rol, $centro, $id]);
    }

    /** Activa/desactiva. Nunca deja al sistema sin administradores activos. */
    public function setActivo(int $id, bool $activo): bool
    {
        if (!$activo) {
            $u = $this->buscar($id);
            if ($u && $u['rol'] === 'admin') {
                $n = (int)$this->db->query("SELECT COUNT(*) FROM usuarios WHERE rol='admin' AND activo=1")->fetchColumn();
                if ($n <= 1) return false;
            }
        }
        $this->db->prepare("UPDATE usuarios SET activo = ?, intentos = 0, bloqueado_hasta = NULL WHERE id = ?")
                 ->execute([$activo ? 1 : 0, $id]);
        return true;
    }

    /** Quita el bloqueo por intentos fallidos. */
    public function desbloquear(int $id): void
    {
        $this->db->prepare("UPDATE usuarios SET intentos = 0, bloqueado_hasta = NULL WHERE id = ?")->execute([$id]);
    }

    public function esAdmin(): bool
    {
        return ($this->usuario()['rol'] ?? '') === 'admin';
    }

    /** Verifica la clave actual de un usuario (para el cambio propio). */
    public function verificarClave(int $id, string $clave): bool
    {
        $u = $this->buscar($id);
        return $u && password_verify($clave, $u['clave_hash']);
    }

    /** Últimos accesos registrados (auditoría). */
    public function bitacora(int $limite = 30): array
    {
        $st = $this->db->prepare("SELECT usuario, exito, ip, fecha FROM accesos ORDER BY id DESC LIMIT ?");
        $st->bindValue(1, $limite, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }
}