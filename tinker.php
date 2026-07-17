<?php
/**
 * tinker.php  ·  Mini consola interactiva para explorar SIGA_104 (PHP nativo)
 * Uso:  php tinker.php
 * Escribe una consulta SQL y Enter. Muestra los resultados en tabla.
 * Comandos: 'estados' (atajo), 'salir' para terminar.
 */

$pdo = new PDO(
    "sqlsrv:Server=localhost;Database=SIGA_104;TrustServerCertificate=1",
    null, null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

function dump(PDO $pdo, string $sql): void {
    try {
        $rows = $pdo->query($sql)->fetchAll();
        if (!$rows) { echo "(sin filas)\n"; return; }
        $cols = array_keys($rows[0]);
        echo implode("\t| ", $cols) . "\n";
        echo str_repeat('-', 60) . "\n";
        foreach ($rows as $r) echo implode("\t| ", array_map(fn($v) => (string)$v, $r)) . "\n";
        echo "\n" . count($rows) . " fila(s)\n";
    } catch (Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}

echo "== Consola SIGA_104 ==  (escribe 'estados', SQL, o 'salir')\n";
while (true) {
    echo "\nsiga> ";
    $line = trim(fgets(STDIN));
    if ($line === '' ) continue;
    if (in_array(strtolower($line), ['salir','exit','quit'])) break;

    if (strtolower($line) === 'estados') {
        echo "\n-- Estados usados por las ordenes --\n";
        dump($pdo, "SELECT ESTADO, COUNT(*) c FROM SIG_ORDEN_ADQUISICION GROUP BY ESTADO ORDER BY c DESC");
        echo "\n-- Maestro de estados (posibles coincidencias) --\n";
        dump($pdo, "SELECT cod_maestro, cod_detalle, nombre, abreviatura
                    FROM SIG_MAESTRO_ESTADO
                    WHERE cod_detalle IN ('A','C','P','G','T','X','I','E')
                    ORDER BY cod_maestro, cod_detalle");
        continue;
    }
    dump($pdo, $line);
}
echo "Chau.\n";