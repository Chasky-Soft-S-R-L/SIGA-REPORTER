<?php
/**
 * EXPORTADOR XLSX  ·  SIGA-REPORTER
 * Genera el Cuadro de Necesidades como XLSX REAL, escribiendo el archivo
 * directamente en streaming (ver XlsxWriter.php). Sin PhpSpreadsheet.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * POR QUÉ SE REESCRIBIÓ (v2) · el export se colgaba con 7 000 filas
 * ══════════════════════════════════════════════════════════════════════════
 * La versión anterior usaba PhpSpreadsheet. El problema NO era el volumen de
 * datos sino el COSTE POR CELDA de esa librería:
 *   · Cada setCellValue() crea un objeto Cell y lo indexa en la colección.
 *   · Cada getStyle($cel)->applyFromArray() construye un objeto Style y lo
 *     compara contra TODOS los estilos ya registrados del libro (búsqueda
 *     lineal). Se llamaba 3-4 veces por celda en estiloFila().
 * Con 7 000 filas × ~20 columnas: ~140 000 celdas y más de medio millón de
 * comparaciones de estilo. De ahí el cuelgue y los cientos de MB.
 *
 * QUÉ CAMBIÓ, punto por punto
 * ═══════════════════════════
 * 1. XlsxWriter escribe el XML de la hoja a DISCO fila a fila. La memoria ya
 *    no crece con el número de filas.
 * 2. Los estilos se resuelven a un ÍNDICE ENTERO y se cachean por clave
 *    ($columna|$fondo|$excluido|$estado). Los ~40 estilos reales del reporte
 *    se crean una vez; las 140 000 celdas solo escriben s="7".
 * 3. Lo que antes se recalculaba POR CELDA ahora se precalcula POR COLUMNA:
 *    si es numérica, si es entera, y su tinte de fase. Antes eso eran dos
 *    llamadas a ExportService + un in_array() por celda.
 * 4. estadoEjec() se calcula UNA vez por fila (antes: dos veces por fila,
 *    más una por celda dentro de estiloFila()).
 * 5. Se envía Content-Length: el navegador muestra el progreso real de la
 *    descarga en lugar de quedarse "esperando" sin señal de vida.
 *
 * EL RESULTADO ES IDÉNTICO al anterior: mismas columnas, mismos colores,
 * mismas fórmulas vivas, mismo autofiltro, mismo panel congelado.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * FÓRMULAS QUE ESCRIBE (no valores fijos)
 * ══════════════════════════════════════════════════════════════════════════
 *   DIFERENCIA      = IMPORTE_PROG − IMPORTE_EJEC     (por fila)
 *   SALDO_DEVENGAR  = IMPORTE_EJEC − DEVENGADO        (por fila)
 *   Sub Total       = SUBTOTAL(109; rango del bloque)
 *   TOTAL GENERAL   = SUBTOTAL(109; todo el rango de datos)
 *
 * El 109 de SUBTOTAL es SUMA IGNORANDO FILAS OCULTAS: al filtrar, los totales
 * se recalculan solos con lo que queda a la vista. Además SUBTOTAL ignora
 * otros SUBTOTAL anidados, así que el TOTAL GENERAL puede abarcar todo el
 * rango sin sumar dos veces los Sub Total de cada bloque.
 *
 * Si el usuario ocultó alguna columna de origen en el selector de campos, la
 * fórmula no se puede armar (daría #¡REF!) y se escribe el valor calculado.
 * Ver celdaFormula().
 *
 * ══════════════════════════════════════════════════════════════════════════
 * INSTALACIÓN
 * ══════════════════════════════════════════════════════════════════════════
 * NINGUNA. Ya no hace falta composer ni phpoffice/phpspreadsheet: la única
 * dependencia es la extensión zip, incluida en PHP por defecto. El vendor/
 * puede quedarse (otras pantallas quizá lo usen) o borrarse.
 */

require_once __DIR__ . '/XlsxWriter.php';
require_once __DIR__ . '/column_labels.php';
require_once __DIR__ . '/ExportService.php';

class ExcelExport
{
    /** Fila donde van los títulos de columna (1-3 cabecera, 4 en blanco). */
    private const FILA_HEAD = 5;

    /** Columnas que llevan total en las filas Sub Total / TOTAL GENERAL. */
    private const COLS_SUMA = ['IMPORTE_PROG','IMPORTE_MOD','IMPORTE_EJEC',
                               'DIFERENCIA','DEVENGADO','SALDO_DEVENGAR'];

    /** ¿Se puede generar XLSX? Solo necesita ZipArchive. index.php decide con esto. */
    public static function disponible(): bool
    {
        return XlsxWriter::disponible();
    }

    /** Ancho de columna de Excel a partir de los px de Labels::WIDTHS.
     *  Excel mide en "caracteres"; ~7 px por carácter es la equivalencia
     *  estándar. Se acota para que ninguna columna quede inusable. */
    private static function anchoExcel(string $key): float
    {
        $px = (int)(Labels::WIDTHS[$key] ?? 90);
        return max(6, min(60, round($px / 7, 1)));
    }

    /**
     * Genera y descarga el XLSX.
     *
     * @param array $rows  filas ya consultadas
     * @param array $meta  ['titulo','centro','anio','agrupar','groupBy','cols']
     */
    public static function generar(array $rows, string $filename, array $meta = []): void
    {
        @set_time_limit(0);
        @ignore_user_abort(false);

        $cols = $meta['cols'] ?? array_keys(Labels::COLUMNS);
        $cols = array_values(array_intersect($cols, array_keys(Labels::COLUMNS)));
        if (!$cols) $cols = array_keys(Labels::COLUMNS);

        $agrupar = $meta['agrupar'] ?? true;
        $by      = $meta['groupBy'] ?? 'ACTIV_OPERAT_COD';
        $titulo  = $meta['titulo']  ?? 'CUADRO DE NECESIDADES';
        $centro  = $meta['centro']  ?? 'TODOS LOS CENTROS';

        $w      = new XlsxWriter('CMN');
        $nCols  = count($cols);
        $ultCol = XlsxWriter::letra($nCols);

        /* ── PRECÁLCULO POR COLUMNA ──────────────────────────────────────────
           Todo esto se resolvía antes DENTRO del bucle de celdas, o sea 140 000
           veces. Aquí se hace una vez por columna (≈20 veces en total). */
        $letraDe = [];   // clave → letra, para armar las fórmulas
        $esNum   = [];   // clave → ¿columna numérica?
        $esInt   = [];   // clave → ¿formato entero (sin decimales)?
        $tinte   = [];   // clave → color de fondo de su fase, o null
        $numSet  = array_flip(Labels::NUMERIC_COLUMNS);
        $intSet  = array_flip(Labels::INT_COLUMNS);
        foreach ($cols as $i => $k) {
            $letraDe[$k] = XlsxWriter::letra($i + 1);
            $esNum[$k]   = isset($numSet[$k]);
            $esInt[$k]   = isset($intSet[$k]);
            $fase        = ExportService::colFasePublico($k);
            $tinte[$k]   = $fase ? ltrim(ExportService::faseHexPublico($fase)[1], '#') : null;
        }

        // Anchos y panel congelado: OBLIGATORIO declararlos antes de la 1.ª fila.
        foreach ($cols as $i => $k) { $w->anchoColumna($i + 1, self::anchoExcel($k)); }
        $w->congelar(self::FILA_HEAD);

        // ── Cabecera del documento (filas 1-3) ──
        self::cabecera($w, $nCols, $ultCol, $titulo, $centro);

        // ── Títulos de columna (fila 5) ──
        self::titulosColumna($w, $cols);

        /* ── CACHÉ DE ESTILOS DE CELDA DE DATO ───────────────────────────────
           Clave: columna | color de fondo | ¿fila excluida? | estado (solo donde
           el estado influye en el color). El reporte completo genera unas pocas
           decenas de entradas; a partir de ahí cada celda es un array lookup. */
        $xfCache = [];
        $xfDato = function (string $k, ?string $bgBloque, bool $exc, string $est)
                  use (&$xfCache, $w, $esNum, $esInt, $tinte): int {
            // El tinte de fase de la columna manda sobre el color del bloque.
            $bg  = $tinte[$k] !== null ? $tinte[$k] : $bgBloque;
            $key = $k . '|' . ($bg ?? '') . '|' . ($exc ? '1' : '0')
                 . '|' . ($k === 'ESTADO_EJEC' ? $est : '');
            if (isset($xfCache[$key])) return $xfCache[$key];

            $d = [];
            if ($k === 'ESTADO_EJEC') {
                /* Colores de la columna ESTADO, los mismos que el export HTML:
                     Ejecutado → azul (ya se compró)
                     Pendiente → negro (vigente, aún sin comprar)
                     Excluido  → rojo (salió del cuadro) */
                $c = ($est === 'Ejecutado') ? '0000FF' : (($est === 'Excluido') ? 'C00000' : '000000');
                $d['font']  = ['size' => 10, 'bold' => true, 'rgb' => $c];
                $d['align'] = ['h' => 'center'];
            } elseif ($k === 'CERT_SIGA') {
                // Verde el "SI", para que se distinga de las celdas vacías.
                $d['font']  = ['size' => 10, 'bold' => true, 'rgb' => $exc ? 'C00000' : '059669'];
                $d['align'] = ['h' => 'center'];
            } else {
                /* Fila EXCLUIDA: todo el texto en rojo. Es un indicador visual —
                   el ítem salió del cuadro y no se va a comprar. */
                $d['font'] = $exc ? ['size' => 10, 'rgb' => 'C00000'] : ['size' => 10];
                if ($esNum[$k]) {
                    $d['numFmt'] = $esInt[$k] ? XlsxWriter::FMT_ENTERO : XlsxWriter::FMT_DECIMAL;
                    $d['align']  = ['h' => 'right'];
                }
            }
            if ($bg) $d['fill'] = $bg;

            return $xfCache[$key] = $w->estilo($d);
        };

        $fila     = self::FILA_HEAD + 1;   // primera fila de datos
        $iniDatos = $fila;

        if ($agrupar) {
            foreach (self::agruparFilas($rows, $by) as $clave => $items) {
                // Cabecera del bloque: banda de color con el nombre del grupo.
                [$fuerte, $claro] = ExportService::actColorPublico((string)$clave);
                $fuerte = ltrim($fuerte, '#');
                $claro  = ltrim($claro,  '#');

                $lbl = ExportService::grupoLabelPublico($items, $by, (string)$clave);
                self::bandaBloque($w, $fila, $nCols, $ultCol, $fuerte,
                                  $lbl . '   ·   ' . count($items) . ' ítems');
                $fila++;

                $ini = $fila;
                foreach ($items as $r) {
                    self::escribirFila($w, $fila++, $cols, $letraDe, $esNum, $r, $claro, $xfDato);
                }
                $fin = $fila - 1;

                self::filaTotales($w, $fila, $cols, $ini, $fin,
                                  'Sub Total  ·  ' . $clave, $fuerte, $claro, false);
                $fila++;
            }
        } else {
            foreach ($rows as $r) {
                self::escribirFila($w, $fila++, $cols, $letraDe, $esNum, $r, null, $xfDato);
            }
        }

        $finDatos = $fila - 1;

        // ── TOTAL GENERAL · SUBTOTAL sobre todo el rango ──
        // SUBTOTAL ignora los SUBTOTAL anidados de cada bloque: no se duplica.
        self::filaTotales($w, $fila, $cols, $iniDatos, $finDatos,
                          'TOTAL GENERAL', '1F2937', 'D1D5DB', true);

        // Autofiltro NATIVO sobre la cabecera + los datos.
        $w->autoFiltro('A' . self::FILA_HEAD . ':' . $ultCol . $finDatos);

        $w->descargar($filename);
    }

    /** Filas 1-3: título, centro (con responsable) y fecha de generación. */
    private static function cabecera(XlsxWriter $w, int $nCols, string $ultCol,
                                     string $titulo, string $centro): void
    {
        $lineas = [
            [1, $titulo,                          '14967D', 12, 22.0],
            [2, $centro,                          '334155', 10, 18.0],
            [3, 'Generado: ' . date('d/m/Y H:i'), '64748B', 10, 16.0],
        ];
        foreach ($lineas as [$f, $txt, $color, $size, $alto]) {
            $s = $w->estilo([
                'font'  => ['bold' => true, 'size' => $size, 'rgb' => $color],
                'align' => ['h' => 'left', 'v' => 'center'],
            ]);
            // Solo la celda A lleva texto; el resto del rango combinado va vacío.
            $celdas = [1 => ['t' => 's', 'v' => $txt, 's' => $s]];
            for ($i = 2; $i <= $nCols; $i++) $celdas[$i] = ['t' => '', 's' => $s];
            $w->fila($f, $celdas, $alto);
            $w->merge('A' . $f . ':' . $ultCol . $f);
        }
    }

    /** Fila 5: títulos de columna, con el color de fase donde corresponde. */
    private static function titulosColumna(XlsxWriter $w, array $cols): void
    {
        $celdas = [];
        foreach ($cols as $i => $k) {
            $fase = ExportService::colFasePublico($k);
            /* DIFERENCIA comparte el amarillo de PROGRAMADO, igual que en
               pantalla: se lee como parte del mismo bloque de saldo. */
            $bg = $fase ? ltrim(ExportService::faseHexPublico($fase)[0], '#')
                        : ($k === 'DIFERENCIA' ? 'FFFF00' : '1ABB9C');
            $tx = ($fase || $k === 'DIFERENCIA') ? '1E3A8A' : 'FFFFFF';

            $s = $w->estilo([
                'font'   => ['bold' => true, 'size' => 10, 'rgb' => $tx],
                'fill'   => $bg,
                'align'  => ['h' => 'center', 'v' => 'center', 'wrap' => true],
                'border' => ['all' => '0F766E'],
            ]);
            $celdas[$i + 1] = ['t' => 's', 'v' => (string)(Labels::COLUMNS[$k] ?? $k), 's' => $s];
        }
        $w->fila(self::FILA_HEAD, $celdas, 30.0);
    }

    /** Cabecera de bloque: banda de color a todo el ancho con el nombre del grupo. */
    private static function bandaBloque(XlsxWriter $w, int $f, int $nCols,
                                        string $ultCol, string $color, string $label): void
    {
        $s = $w->estilo([
            'font'  => ['bold' => true, 'size' => 10, 'rgb' => 'FFFFFF'],
            'fill'  => $color,
            'align' => ['v' => 'center'],
        ]);
        $celdas = [1 => ['t' => 's', 'v' => $label, 's' => $s]];
        for ($i = 2; $i <= $nCols; $i++) $celdas[$i] = ['t' => '', 's' => $s];
        $w->fila($f, $celdas);
        $w->merge('A' . $f . ':' . $ultCol . $f);
    }

    /**
     * Escribe una fila de ítem. DIFERENCIA y SALDO_DEVENGAR van como FÓRMULA
     * cuando sus columnas de origen están visibles; si el usuario las ocultó en
     * el selector de campos, se escribe el valor ya calculado (una fórmula que
     * apunta a una columna inexistente daría #¡REF!).
     */
    private static function escribirFila(XlsxWriter $w, int $f, array $cols, array $letraDe,
                                         array $esNum, array $r, ?string $bgBloque, callable $xf): void
    {
        // UNA sola vez por fila (antes se llamaba 2 veces por fila + 1 por celda).
        $est = ExportService::estadoEjec($r);
        $exc = ($est === 'Excluido');

        $celdas = [];
        foreach ($cols as $i => $k) {
            $col = $i + 1;
            $s   = $xf($k, $bgBloque, $exc, $est);

            if ($k === 'DIFERENCIA') {                       // Programado − Ejecutado
                $celdas[$col] = self::celdaFormula($letraDe, 'IMPORTE_PROG', 'IMPORTE_EJEC',
                                                   $f, (float)($r['DIFERENCIA'] ?? 0), $s);
            } elseif ($k === 'SALDO_DEVENGAR') {             // Ejecutado − Devengado
                $celdas[$col] = self::celdaFormula($letraDe, 'IMPORTE_EJEC', 'DEVENGADO',
                                                   $f, (float)($r['SALDO_DEVENGAR'] ?? 0), $s);
            } elseif ($k === 'CERT_SIGA') {
                $celdas[$col] = ['t' => 's', 'v' => ((int)($r['CERT_SIGA'] ?? 0) > 0 ? 'SI' : ''), 's' => $s];
            } elseif ($k === 'ESTADO_EJEC') {
                $celdas[$col] = ['t' => 's', 'v' => mb_strtoupper($est, 'UTF-8'), 's' => $s];
            } elseif ($k === 'ESTADO_CMN') {
                $celdas[$col] = ['t' => 's', 'v' => ExportService::cmnAbrevPublico((string)($r[$k] ?? '')), 's' => $s];
            } elseif ($k === 'ESTADO_FASE') {
                $celdas[$col] = ['t' => 's', 'v' => ExportService::faseEjecucionPublico($r), 's' => $s];
            } elseif ($k === 'ESTADO_ORDEN') {
                $celdas[$col] = ['t' => 's', 'v' => ExportService::resumenOrdenesPublico((string)($r[$k] ?? '')), 's' => $s];
            } elseif ($esNum[$k]) {
                $celdas[$col] = ['t' => 'n', 'v' => ($r[$k] ?? 0), 's' => $s];
            } else {
                /* Texto explícito (inlineStr): evita que Excel convierta los
                   clasificadores ("2.3.2 9.1 1") o los códigos con ceros a la
                   izquierda en números o fechas. */
                $celdas[$col] = ['t' => 's', 'v' => (string)($r[$k] ?? ''), 's' => $s];
            }
        }
        $w->fila($f, $celdas);
    }

    /** Fórmula de resta si ambas columnas están visibles; si no, el valor fijo. */
    private static function celdaFormula(array $letraDe, string $a, string $b,
                                         int $f, float $valor, int $s): array
    {
        if (isset($letraDe[$a], $letraDe[$b])) {
            return ['t' => 'f', 'v' => $letraDe[$a] . $f . '-' . $letraDe[$b] . $f, 's' => $s];
        }
        return ['t' => 'n', 'v' => $valor, 's' => $s];
    }

    /**
     * Fila de totales (Sub Total de bloque o TOTAL GENERAL).
     * Usa SUBTOTAL(109; rango): suma IGNORANDO las filas ocultas por el
     * autofiltro, así que al filtrar los totales se recalculan solos. El 109
     * además ignora los SUBTOTAL anidados, por lo que el TOTAL GENERAL puede
     * abarcar todo el rango sin contar dos veces los Sub Total de cada bloque.
     */
    private static function filaTotales(XlsxWriter $w, int $f, array $cols,
                                        int $ini, int $fin, string $label,
                                        string $colorFuerte, string $colorClaro,
                                        bool $general): void
    {
        $base = [
            'font'   => ['bold' => true, 'size' => 10, 'rgb' => $general ? 'FFFFFF' : $colorFuerte],
            'fill'   => $general ? '1F2937' : $colorClaro,
            'border' => ['top' => $colorFuerte],
        ];
        $sTxt = $w->estilo($base);
        $sNum = $w->estilo($base + ['numFmt' => XlsxWriter::FMT_DECIMAL, 'align' => ['h' => 'right']]);

        $suma   = array_flip(self::COLS_SUMA);
        $celdas = [1 => ['t' => 's', 'v' => $label, 's' => $sTxt]];
        foreach ($cols as $i => $k) {
            $col = $i + 1;
            if ($col === 1) continue;                 // la A lleva la etiqueta
            if (isset($suma[$k])) {
                $l = XlsxWriter::letra($col);
                $celdas[$col] = ['t' => 'f', 'v' => 'SUBTOTAL(109,' . $l . $ini . ':' . $l . $fin . ')', 's' => $sNum];
            } else {
                $celdas[$col] = ['t' => '', 's' => $sTxt];   // vacía, pero con la banda de color
            }
        }
        $w->fila($f, $celdas);
    }

    /** Agrupa las filas igual que el export HTML (misma clave, mismo orden). */
    private static function agruparFilas(array $rows, string $by): array
    {
        $g = [];
        foreach ($rows as $r) { $g[ExportService::grpKeyPublico($r, $by)][] = $r; }
        ksort($g);
        /* Orden interno por código de bien. Se precalcula la clave (patrón
           Schwartzian): antes strcmp reconstruía las dos claves en CADA
           comparación, o sea O(n log n) concatenaciones por bloque. */
        foreach ($g as &$items) {
            $tmp = [];
            foreach ($items as $idx => $r) {
                $tmp[] = [($r['GRUPO_BIEN'] ?? '') . ($r['CLASE_BIEN'] ?? '')
                        . ($r['FAMILIA_BIEN'] ?? '') . ($r['ITEM_BIEN'] ?? ''), $idx, $r];
            }
            usort($tmp, function ($a, $b) {
                $c = strcmp($a[0], $b[0]);
                return $c !== 0 ? $c : ($a[1] <=> $b[1]);   // orden estable
            });
            $items = array_column($tmp, 2);
        }
        unset($items);
        return $g;
    }
}