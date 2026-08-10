<?php
/**
 * CAPA SERVICE  ·  SIGA-REPORTER
 * Servicios de salida (Excel / PDF). NO es servicio de dominio: solo transforma
 * las filas ya consultadas en un archivo descargable. Cero dependencias => rápido.
 *
 * COMPATIBLE PHP 7.4: sin match(). Los match() se reemplazaron por ternarios.
 *
 * La salida replica la vista web: columna ESTADO CMN, bloques por ACTIVIDAD
 * OPERATIVA con color, ítems ordenados por código de bien, Sub Total por bloque
 * y TOTAL GENERAL al cierre.
 *
 * NOMBRES DE COLUMNA: HEADERS/NUM/INT ya NO se definen aquí — vienen de
 * Labels::COLUMNS / Labels::NUMERIC_COLUMNS / Labels::INT_COLUMNS en
 * column_labels.php, la misma fuente que usa index.php para la tabla web.
 * Para renombrar una columna (o cambiar cuáles son numéricas/enteras),
 * edita SOLO column_labels.php — nunca este archivo.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * UNA FILA POR ÍTEM · SIN SALTOS DE LÍNEA  (regla dura de este export)
 * ══════════════════════════════════════════════════════════════════════════
 * Cada ítem del cuadro DEBE ocupar exactamente una fila de la hoja. Tres cosas
 * lo rompían y las tres están resueltas aquí:
 *
 *   1. SALTOS DENTRO DEL DATO. Los nombres del SIGA a veces traen \r\n, tabs o
 *      dobles espacios. Excel los interpreta como fin de celda y parte el ítem
 *      en dos filas. unaLinea() los colapsa a un espacio simple ANTES de
 *      escribir la celda. También se declara `br{mso-data-placement:same-cell}`
 *      por si algún dato trae un <br> literal.
 *   2. AJUSTE DE TEXTO. Sin ancho declarado, Excel autoajusta y envuelve el
 *      texto largo, agrandando el alto de la fila. Ahora se emite un <colgroup>
 *      con el ancho de Labels::WIDTHS (la MISMA fuente que la tabla web) y
 *      `white-space:nowrap` en todas las celdas: el texto que no entra se
 *      RECORTA visualmente contra la columna vecina, igual que en pantalla.
 *      El dato sigue completo en la celda; solo se oculta lo que no cabe.
 *   3. DATOS EJECUCION DEMASIADO LARGO. Un ítem con 12 órdenes generaba una
 *      celda kilométrica que deformaba la grilla. resumenOrdenes() muestra las
 *      primeras MAX_ORDENES y resume el resto como "(+N más)". El detalle
 *      completo se consulta en el expediente de la pantalla, que es donde
 *      tiene sentido leerlo.
 *
 * ESTADO CMN (una sola celda, abreviado):
 *   El texto crudo trae varios estados separados por coma ("PROGRAMADO,
 *   MODIFICADO"). Antes se ponía uno por línea con <br mso-data-placement>,
 *   pero varias versiones de Excel tratan ese <br> como fin de celda y el
 *   ítem salía partido en 2 filas. Ahora va TODO en una sola línea, separado
 *   por comas y ABREVIADO (PRG / INC / EXC / -MOD) desde Labels::CMN_ESTADO.
 *
 * COLUMNA FASE (ESTADO_FASE): muestra la FASE DE EJECUCIÓN (SIAF) —
 *   Certificado / Comprometido / Devengado — es decir, la ÚLTIMA fase que
 *   alcanzó el ítem. Vacío si aún no llegó a ninguna. Es DISTINTA del ESTADO
 *   CMN (Programado / Modificado / Incluido / Excluido). Se deriva por fila de
 *   DEVENGADO / IMPORTE_EJEC / DATOS EJECUCION (ESTADO_ORDEN), no del valor
 *   crudo Programado/Modificado/Ejecutado que trae el SQL.
 */
require_once __DIR__ . '/column_labels.php';

class ExportService
{
    /**
     * Encabezados visibles del reporte (orden = columnas de la vista web).
     * Alias de Labels::COLUMNS: se mantiene el nombre HEADERS por compatibilidad
     * con el resto de este archivo (y con index.php, que también lo referencia
     * en algún punto del selector de campos vía array_keys/array_intersect).
     */
    public const HEADERS = Labels::COLUMNS;

    /** Columnas enteras (sin decimales). Alias de Labels::INT_COLUMNS. */
    public const INT = Labels::INT_COLUMNS;

    /** Columnas numéricas (para formateo/alineación). Alias de Labels::NUMERIC_COLUMNS. */
    public const NUM = Labels::NUMERIC_COLUMNS;

    /**
     * Cuántas órdenes se listan en DATOS EJECUCION antes de resumir el resto.
     * Un ítem con muchas órdenes generaba una celda tan larga que deformaba la
     * grilla; el listado completo vive en el expediente de la pantalla.
     */
    private const MAX_ORDENES = 3;

    /**
     * Corte duro de cualquier celda de texto. Es una red de seguridad contra
     * datos anómalos (descripciones enormes pegadas en un campo corto); en
     * operación normal ninguna columna llega a este límite.
     */
    private const MAX_TEXTO = 300;

    /** Ancho por defecto (px) si una columna no está en Labels::WIDTHS. */
    private const ANCHO_DEF = 90;

    /** Paleta de la vista web: [color fuerte, color claro] por actividad. */
    private const PALETA = [
        ['#059669','#ecfdf5'], ['#0284c7','#eff6ff'], ['#6d28d9','#f5f3ff'], ['#b45309','#fffbeb'],
        ['#dc2626','#fef2f2'], ['#0f766e','#f0fdfa'], ['#a21caf','#fdf4ff'], ['#4d7c0f','#f7fee7'],
    ];

    /** Color [fuerte, claro] por etapa: Programado=amarillo · Modificado=naranja
     *  · Ejecutado=verde. Mismos valores que el frontend, para que el Excel
     *  se vea idéntico a la pantalla. */
    private const FASE_HEX = [
        'PROGRAMADO' => ['#FFFF00', '#fefce8'],
        'MODIFICADO' => ['#FFC000', '#fff7ed'],
        'EJECUTADO'  => ['#47D359', '#ecfdf5'],
    ];

    /** Qué columna pertenece a qué etapa (CANTIDAD/PRECIO_UNIT/IMPORTE de cada una). */
    private const COLFASE = [
        'CANTIDAD_PROG' => 'PROGRAMADO', 'PRECIO_UNIT_PROG' => 'PROGRAMADO', 'IMPORTE_PROG' => 'PROGRAMADO',
        'CANTIDAD_MOD'  => 'MODIFICADO', 'PRECIO_UNIT_MOD'  => 'MODIFICADO', 'IMPORTE_MOD'  => 'MODIFICADO',
        'CANTIDAD_EJEC' => 'EJECUTADO',  'PRECIO_UNIT_EJEC' => 'EJECUTADO',  'IMPORTE_EJEC' => 'EJECUTADO',
    ];

    /** Estilo de las celdas de DATO: una línea, sin autoajuste, recorte visual. */
    private const NOWRAP = 'white-space:nowrap;vertical-align:middle;';

    /**
     * Estilo de las CABECERAS: al revés que los datos, el texto SÍ envuelve.
     * Es el mismo criterio del frontend (`#thead th{white-space:normal;
     * word-break:break-word;vertical-align:bottom}`): con nowrap, etiquetas
     * largas como "IMPORTE CMN PROGRAMADO" se recortaban contra la columna
     * vecina y en la hoja se leía "CANTIDAD RECIO UNITARIO TE CMN PROGRAMADO".
     * Envolviendo a dos líneas el encabezado se lee completo sin ensanchar
     * las columnas de dato.
     */
    private const WRAPHEAD = 'white-space:normal;word-break:break-word;vertical-align:bottom;line-height:1.15;';

    /** Color estable por código de actividad (mismo criterio que el frontend). */
    private static function actColor(string $cod): array
    {
        $h = 0;
        for ($i = 0, $n = strlen($cod); $i < $n; $i++) {
            $h = ($h * 31 + ord($cod[$i])) % 4294967296;
        }
        return self::PALETA[$h % count(self::PALETA)];
    }

    /** Ancho declarado de una columna (px), desde Labels::WIDTHS. */
    private static function ancho(string $key): int
    {
        return (int)(Labels::WIDTHS[$key] ?? self::ANCHO_DEF);
    }

    /**
     * Normaliza cualquier texto a UNA sola línea.
     * Los datos del SIGA traen \r\n, tabs y dobles espacios; Excel interpreta
     * el salto como fin de celda y parte el ítem en dos filas. Esto lo colapsa
     * todo a un espacio simple y recorta a MAX_TEXTO por seguridad.
     */
    private static function unaLinea($v): string
    {
        $s = (string)$v;
        $s = str_replace(["\r\n", "\r", "\n", "\t", "\x0B", "\x0C"], ' ', $s);
        $s = preg_replace('/\s{2,}/u', ' ', $s);
        $s = trim((string)$s);
        if (mb_strlen($s, 'UTF-8') > self::MAX_TEXTO) {
            $s = mb_substr($s, 0, self::MAX_TEXTO - 1, 'UTF-8') . '…';
        }
        return $s;
    }

    /**
     * DATOS EJECUCION resumido: las primeras MAX_ORDENES y el resto como
     * "(+N más)". El texto viene de CmnQuery (bloque `ej`) con las órdenes
     * separadas por coma y los tramos internos por " · ", p.ej.:
     *   "OS 13 · DEVENGADO · Cert SIGA 12 · SIAF 56, OS 14 · DEVENGADO · …"
     * Por eso el split es por coma, nunca por el punto medio.
     */
    private static function resumenOrdenes(string $crudo): string
    {
        $partes = array_values(array_filter(array_map('trim', explode(',', $crudo)), 'strlen'));
        $n = count($partes);
        if ($n === 0) return '';
        if ($n <= self::MAX_ORDENES) return implode(', ', $partes);
        return implode(', ', array_slice($partes, 0, self::MAX_ORDENES))
             . '  (+' . ($n - self::MAX_ORDENES) . ' más)';
    }

    /**
     * Fase de EJECUCIÓN (SIAF) de una fila: CERTIFICADO / COMPROMETIDO /
     * DEVENGADO — la última fase alcanzada. Vacío si el ítem aún no llegó a
     * ninguna. Distinta del ESTADO CMN (Programado/Modificado/Incluido/Excluido).
     * Se deriva de los datos reales de la fila:
     *   - DEVENGADO > 0                                   → DEVENGADO
     *   - IMPORTE_EJEC > 0  ó "COMPROMETIDO" en la orden  → COMPROMETIDO
     *   - "CERTIFICADO" ó "CON ORDEN" en la orden         → CERTIFICADO
     *   - resto                                            → '' (sin fase todavía)
     */
    private static function faseEjecucion(array $r): string
    {
        $dev  = (float)($r['DEVENGADO']    ?? 0);
        $ejec = (float)($r['IMPORTE_EJEC'] ?? 0);
        $ord  = mb_strtoupper((string)($r['ESTADO_ORDEN'] ?? ''), 'UTF-8');
        if ($dev > 0.005)                                                     return 'DEVENGADO';
        if ($ejec > 0.005 || strpos($ord, 'COMPROMETIDO') !== false)          return 'COMPROMETIDO';
        if (strpos($ord, 'CERTIFICAD') !== false || strpos($ord, 'CON ORDEN') !== false) return 'CERTIFICADO';
        return '';
    }

    /**
     * ESTADO CMN abreviado y en UNA sola línea: "PROGRAMADO, MODIFICADO" →
     * "PRG, -MOD". Las abreviaturas salen de Labels::CMN_ESTADO (misma fuente
     * que la pantalla), así Excel y web nunca se desincronizan.
     */
    private static function cmnAbrev(string $crudo): string
    {
        $partes = array_filter(array_map('trim', explode(',', $crudo)));
        $abrev  = array_map(function ($p) {
            $k = mb_strtoupper($p, 'UTF-8');
            return isset(Labels::CMN_ESTADO[$k]) ? Labels::CMN_ESTADO[$k][0] : $p;
        }, $partes);
        return implode(', ', $abrev);
    }

    /** Agrupa las filas por actividad operativa y ordena por código de bien. */
    private static function agrupar(array $rows, string $by = 'ACTIV_OPERAT_COD'): array
    {
        $g = [];
        foreach ($rows as $r) {
            $g[$r[$by] ?? '—'][] = $r;
        }
        ksort($g);
        foreach ($g as &$items) {
            usort($items, function ($a, $b) {
                $ka = ($a['GRUPO_BIEN'] ?? '').($a['CLASE_BIEN'] ?? '').($a['FAMILIA_BIEN'] ?? '').($a['ITEM_BIEN'] ?? '');
                $kb = ($b['GRUPO_BIEN'] ?? '').($b['CLASE_BIEN'] ?? '').($b['FAMILIA_BIEN'] ?? '').($b['ITEM_BIEN'] ?? '');
                return strcmp($ka, $kb);
            });
        }
        unset($items);
        return $g;
    }

    /** Etiqueta legible del grupo según el campo por el que se agrupó. */
    private static function grupoLabel(array $items, string $by, string $key): string
    {
        if ($by === 'ACTIV_OPERAT_COD') return $key.'   '.($items[0]['ACTIV_OPERAT_NOMBRE'] ?? '');
        if ($by === 'CLASIF_COD')       return $key.'   '.($items[0]['CLASIF_NOMBRE'] ?? '');
        if ($by === 'FF')               return $key.'   '.($items[0]['FF_NOMBRE'] ?? '');
        if ($by === 'META')             return 'Meta '.$key;
        if ($by === 'GENERICA')         return 'Genérica '.$key;
        if ($by === 'ESTADO_FASE')      return $key;
        return $key;
    }

    /** Suma los cuatro importes de un conjunto de filas. */
    private static function totales(array $rows): array
    {
        $t = ['prog' => 0.0, 'mod' => 0.0, 'ejec' => 0.0, 'dif' => 0.0, 'dev' => 0.0, 'saldo' => 0.0];
        foreach ($rows as $r) {
            $t['prog']  += (float)($r['IMPORTE_PROG']   ?? 0);
            $t['mod']   += (float)($r['IMPORTE_MOD']    ?? 0);
            $t['ejec']  += (float)($r['IMPORTE_EJEC']   ?? 0);
            $t['dif']   += (float)($r['DIFERENCIA']     ?? 0);
            $t['dev']   += (float)($r['DEVENGADO']      ?? 0);
            $t['saldo'] += (float)($r['SALDO_DEVENGAR'] ?? 0);
        }
        return $t;
    }

    /** Índice (0-based) de la primera columna de importes. */
    private static function idxImportes(): int
    {
        return (int)array_search('IMPORTE_PROG', array_keys(self::HEADERS), true);
    }

    /** Fila de totales alineada bajo las columnas de importe. */
    private static function filaTotales(string $label, array $t, string $tdStyle, string $lblStyle): string
    {
        $keys = array_keys(self::HEADERS);
        $idx  = self::idxImportes();
        $out  = '<tr><td colspan="' . $idx . '" style="' . self::NOWRAP . $lblStyle . '">'
              . htmlspecialchars(self::unaLinea($label)) . '</td>';
        for ($i = $idx, $n = count($keys); $i < $n; $i++) {
            $k = $keys[$i];
            $v = ($k === 'IMPORTE_PROG') ? $t['prog']
               : (($k === 'IMPORTE_MOD')  ? $t['mod']
               : (($k === 'IMPORTE_EJEC') ? $t['ejec']
               : (($k === 'DIFERENCIA')   ? $t['dif']
               : (($k === 'DEVENGADO')    ? $t['dev']
               : (($k === 'SALDO_DEVENGAR') ? $t['saldo'] : null)))));
            $out .= $v === null
                ? '<td style="' . self::NOWRAP . $tdStyle . '"></td>'
                : '<td style="' . self::NOWRAP . $tdStyle . 'mso-number-format:\'#,##0.00\';text-align:right">'
                  . number_format($v, 2, '.', '') . '</td>';
        }
        return $out . '</tr>';
    }

    /** Celdas de una fila de ítem. Las columnas de Programado/Modificado/Ejecutado
     *  usan su tinte de fase (amarillo/naranja/verde claro) en vez del color de
     *  actividad, igual que en pantalla. Toda celda de texto pasa por unaLinea()
     *  para garantizar que el ítem ocupe UNA sola fila de la hoja. */
    private static function celdasItem(array $r, string $bg): string
    {
        $out = '';
        foreach (self::HEADERS as $key => $_) {
            $v      = $r[$key] ?? '';
            $fase   = self::COLFASE[$key] ?? null;
            $cellBg = $fase ? self::FASE_HEX[$fase][1] : $bg;
            $base   = self::NOWRAP . 'background:' . $cellBg . ';';
            if (in_array($key, self::INT, true)) {
                $out .= '<td style="' . $base . 'mso-number-format:\'0\';text-align:right">'
                      . (int)$v . '</td>';
            } elseif (in_array($key, self::NUM, true)) {
                $out .= '<td style="' . $base . 'mso-number-format:\'#,##0.00\';text-align:right">'
                      . number_format((float)$v, 2, '.', '') . '</td>';
            } elseif ($key === 'ESTADO_EJEC') {
                // "Ejecutado" en azul y negrita · "Pendiente" en negrita (negro),
                // igual que en la hoja de referencia del área usuaria. La
                // comparación de estado usa el texto original (por si cambia
                // de mayúsc/minúsc en el futuro); solo lo que se IMPRIME va en
                // mayúsculas.
                $texto = (string)$v;
                $esEjecutado = strcasecmp($texto, 'Ejecutado') === 0;
                $color = $esEjecutado ? '#0000FF' : '#000000';
                $out .= '<td style="' . $base . 'mso-number-format:\'@\';color:' . $color . ';font-weight:bold">'
                      . htmlspecialchars(mb_strtoupper(self::unaLinea($texto), 'UTF-8')) . '</td>';
            } elseif ($key === 'ESTADO_FASE') {
                // FASE DE EJECUCIÓN (SIAF): Certificado / Comprometido / Devengado
                // (la última alcanzada). Vacío si aún no llegó a ninguna. Distinta
                // del ESTADO CMN. Se deriva de los datos reales de la fila, no del
                // valor crudo Programado/Modificado/Ejecutado del SQL.
                $out .= '<td style="' . $base . 'mso-number-format:\'@\'">'
                      . htmlspecialchars(self::faseEjecucion($r)) . '</td>';
            } elseif ($key === 'ESTADO_CMN') {
                // UNA sola celda, UNA sola línea, separado por comas y ABREVIADO
                // (PRG / INC / EXC / -MOD). El <br> anterior partía el ítem en 2
                // filas en varias versiones de Excel; por eso se eliminó.
                $out .= '<td style="' . $base . 'mso-number-format:\'@\'">'
                      . htmlspecialchars(self::cmnAbrev(self::unaLinea($v))) . '</td>';
            } elseif ($key === 'ESTADO_ORDEN') {
                // DATOS EJECUCION: se listan las primeras MAX_ORDENES y el resto
                // se resume como "(+N más)". Con el ancho declarado en el
                // <colgroup> + nowrap, lo que no entra queda oculto contra la
                // columna vecina en vez de envolver y agrandar la fila.
                $out .= '<td style="' . $base . 'mso-number-format:\'@\'">'
                      . htmlspecialchars(self::resumenOrdenes(self::unaLinea($v))) . '</td>';
            } else {
                $out .= '<td style="' . $base . 'mso-number-format:\'@\'">'
                      . htmlspecialchars(self::unaLinea($v)) . '</td>';
            }
        }
        return $out;
    }

    /**
     * Excel sin librerías: tabla HTML con cabeceras de MS-Excel.
     * @param array $meta ['titulo'=>, 'centro'=>, 'anio'=>, 'agrupar'=>bool]
     */
    public static function excel(array $rows, string $filename, array $meta = []): void
    {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        header('Pragma: no-cache');
        echo "\xEF\xBB\xBF"; // BOM UTF-8 para tildes

        $nCols   = count(self::HEADERS);
        $agrupar = $meta['agrupar'] ?? true;
        $by      = $meta['groupBy'] ?? 'ACTIV_OPERAT_COD';
        $titulo  = $meta['titulo']  ?? 'CUADRO DE NECESIDADES';
        $centro  = $meta['centro']  ?? 'TODOS LOS CENTROS';

        /* Fila donde están los <th> (título + centro + generado + fila en
           blanco + encabezado = 5 filas fijas antes de que empiecen los datos).
           Si algún día se agrega o quita una fila de cabecera arriba, este
           número hay que actualizarlo junto con ella. */
        $filaEncabezado = 5;

        /* ── Congelar encabezado ──────────────────────────────────────────
           Este bloque XML (namespace "urn:schemas-microsoft-com:office:excel")
           es la forma NO-binaria de decirle a Excel "las primeras N filas se
           quedan fijas al hacer scroll" — Excel lo lee de <head> incluso en
           este export basado en tabla HTML, sin necesidad de generar un
           archivo binario XLSX ni agregar ninguna librería.
           LÍMITE REAL DE EXCEL (no de este código): solo se pueden fijar
           filas de ARRIBA y/o columnas de la IZQUIERDA. No existe forma de
           fijar la última fila (el "TOTAL GENERAL") mientras se hace scroll
           por el medio de la tabla — Excel no tiene esa función. */
        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" '
           . 'xmlns:x="urn:schemas-microsoft-com:office:excel" '
           . 'xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<head><meta charset="UTF-8">';
        /* ── Una fila por ítem ──
           · td{white-space:nowrap} → Excel NO autoajusta ni envuelve el dato: lo
             recorta contra la columna vecina, igual que la tabla web.
           · th{white-space:normal} → las CABECERAS sí envuelven, como en el
             frontend. Con nowrap, "IMPORTE CMN PROGRAMADO" se cortaba y la hoja
             mostraba "CANTIDAD RECIO UNITARIO TE CMN PROGRAMADO".
           · br{mso-data-placement:same-cell} → si algún dato trae un <br>
             literal, Excel lo deja DENTRO de la celda en vez de cortar la fila.
           · mso-number-format:'@' en cada celda de texto evita que Excel
             reinterprete códigos como números o fechas (los clasificadores
             tipo "2.3.2 9.1 1" y los códigos de ítem con ceros a la izquierda). */
        echo '<style>'
           . 'td{white-space:nowrap;vertical-align:middle;mso-rotate:0}'
           . 'th{white-space:normal;word-break:break-word;vertical-align:bottom;line-height:1.15}'
           . 'br{mso-data-placement:same-cell}'
           . '</style>';
        echo '<!--[if gte mso 9]><xml>'
           . '<x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>'
           . '<x:Name>CMN</x:Name>'
           . '<x:WorksheetOptions>'
           . '<x:FreezePanes/>'
           . '<x:FrozenNoSplit/>'
           . '<x:SplitHorizontal>' . $filaEncabezado . '</x:SplitHorizontal>'
           . '<x:TopRowBottomPane>' . $filaEncabezado . '</x:TopRowBottomPane>'
           . '<x:ActivePane>2</x:ActivePane>'
           . '<x:Panes><x:Pane><x:Number>3</x:Number></x:Pane><x:Pane><x:Number>2</x:Number></x:Pane></x:Panes>'
           . '<x:ProtectContents>False</x:ProtectContents>'
           . '<x:ProtectObjects>False</x:ProtectObjects>'
           . '<x:ProtectScenarios>False</x:ProtectScenarios>'
           . '</x:WorksheetOptions>'
           . '</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook>'
           . '</xml><![endif]-->';
        echo '</head><body>';

        echo '<table border="0" cellspacing="0" cellpadding="3" style="table-layout:fixed">';

        /* ── Anchos de columna ──
           Se declaran explícitamente desde Labels::WIDTHS (la MISMA fuente que
           usa la tabla web), para que el Excel salga con las columnas ya
           dimensionadas y el texto largo quede recortado en vez de envuelto.
           Para cambiar un ancho: edita column_labels.php, no este archivo. */
        echo '<colgroup>';
        foreach (self::HEADERS as $key => $_) {
            echo '<col width="' . self::ancho($key) . '" style="width:' . self::ancho($key) . 'px">';
        }
        echo '</colgroup>';

        // ── Cabecera institucional ──
        echo '<tr><td colspan="' . $nCols . '" style="' . self::NOWRAP . 'font-size:15px;font-weight:bold;color:#14967d">'
           . htmlspecialchars(self::unaLinea($titulo)) . '</td></tr>';
        // El centro ya viene con el responsable del área anexado desde index.php
        // ("104.07.03.01 · OFICINA … · Resp.: BAUTISTA MELENDEZ VICTOR MANUEL").
        echo '<tr><td colspan="' . $nCols . '" style="' . self::NOWRAP . 'font-size:11px;color:#334155">'
           . htmlspecialchars(self::unaLinea($centro)) . '</td></tr>';
        echo '<tr><td colspan="' . $nCols . '" style="' . self::NOWRAP . 'font-size:9px;color:#64748b">Generado: '
           . date('d/m/Y H:i') . '</td></tr>';
        echo '<tr><td colspan="' . $nCols . '"></td></tr>';

        $tot = self::totales($rows);

        // ── Encabezados de columna: las de Programado/Modificado/Ejecutado van
        //    en amarillo/naranja/verde con texto azul, igual que en pantalla. ──
        echo '<tr>';
        foreach (self::HEADERS as $key => $label) {
            $al     = in_array($key, self::NUM, true) ? 'right' : 'left';
            $fase   = self::COLFASE[$key] ?? null;
            $bgH    = $fase ? self::FASE_HEX[$fase][0] : '#1abb9c';
            $colH   = $fase ? '#1e3a8a' : '#fff';
            $border = $fase ? $bgH : '#0f766e';
            echo '<th style="' . self::WRAPHEAD . 'background:' . $bgH . ';color:' . $colH
               . ';font-weight:bold;border:1px solid ' . $border . ';text-align:' . $al . '">'
               . htmlspecialchars(self::unaLinea($label)) . '</th>';
        }
        echo '</tr>';

        /* TOTAL GENERAL duplicado aquí, pegado al encabezado: Excel no permite
           fijar la última fila de la hoja (eso NO existe como función), pero sí
           fijar filas de arriba — así que la forma de que el total quede
           siempre visible al hacer scroll es ponerlo también acá arriba, junto
           con los títulos de columna, dentro del mismo bloque congelado.
           La fila de TOTAL GENERAL de siempre (al final de los datos) se
           mantiene igual, para cuando se imprime o se revisa el cierre. */
        echo self::filaTotales(
            'TOTAL GENERAL',
            $tot,
            'background:#374151;color:#fff;font-weight:bold;',
            'background:#374151;color:#fff;font-weight:bold;text-align:right'
        );

        if ($agrupar) {
            foreach (self::agrupar($rows, $by) as $act => $items) {
                [$fuerte, $claro] = self::actColor((string)$act);
                $lbl = self::grupoLabel($items, $by, (string)$act);
                // Cabecera del bloque
                echo '<tr><td colspan="' . $nCols . '" style="' . self::NOWRAP . 'background:' . $fuerte
                   . ';color:#fff;font-weight:bold;font-size:11px">'
                   . htmlspecialchars(self::unaLinea($lbl)) . '   ·   ' . count($items) . ' ítems</td></tr>';
                // Ítems del bloque
                foreach ($items as $r) {
                    echo '<tr>' . self::celdasItem($r, $claro) . '</tr>';
                }
                // Sub total del bloque
                echo self::filaTotales(
                    'Sub Total  ·  ' . $act,
                    self::totales($items),
                    'background:' . $claro . ';font-weight:bold;color:' . $fuerte . ';border-top:1px solid ' . $fuerte . ';',
                    'background:' . $claro . ';font-weight:bold;color:' . $fuerte . ';text-align:right;border-top:1px solid ' . $fuerte
                );
            }
        } else {
            foreach ($rows as $r) {
                echo '<tr>' . self::celdasItem($r, '#ffffff') . '</tr>';
            }
        }

        // ── Total general ──
        echo self::filaTotales(
            'TOTAL GENERAL',
            $tot,
            'background:#1f2937;color:#fff;font-weight:bold;',
            'background:#1f2937;color:#fff;font-weight:bold;text-align:right'
        );

        echo '</table>';
        echo '</body></html>';
    }

    /**
     * Detalle de la DIFERENCIA (Programado - Ejecutado) con el MISMO nivel de
     * agrupación que la tabla oficial (FF/Rb + Meta + Clasificador + Área), no
     * solo agregado por clasificador. Motivo: dentro de un mismo clasificador,
     * un ítem puede haberse ejecutado al 100% y otro haber quedado con saldo
     * (p.ej. se compró más barato de lo programado) — ese saldo también es
     * real y usable, y se pierde si solo se ve el total del clasificador.
     *
     * Se incluyen Programado y Ejecutado como columnas propias (no solo el
     * Saldo ya calculado), para que quede explícito CÓMO se llega a cada
     * monto: Saldo = Programado - Ejecutado, fila por fila. Por el mismo
     * motivo se incluyen TODOS los grupos, también los que quedaron con saldo
     * negativo (sobregiro) — no se ocultan ni se resumen aparte, se ven en su
     * propia fila con el detalle completo.
     *
     * Se calcula sobre TODAS las filas del cuadro (no solo las que ya tienen
     * ejecución). Reutilizado por pdf(); sin consulta aparte al SIGA.
     */
    private static function saldoPorGrupo(array $rows): array
    {
        $g = [];
        foreach ($rows as $r) {
            $k = ($r['FF'] ?? '').'|'.($r['RB'] ?? '').'|'.($r['META'] ?? '').'|'.($r['CLASIF_COD'] ?? '').'|'.($r['CCOSTO_COD'] ?? '');
            if (!isset($g[$k])) {
                // Mismo formato que la tabla oficial: FF/Rb combina fuente + rubro.
                $ffrb = trim((string)($r['FF'] ?? ''));
                if (($r['RB'] ?? '') !== '') $ffrb .= '-' . $r['RB'];
                $g[$k] = [
                    'ff'    => $ffrb,
                    'meta'  => str_pad((string)($r['META'] ?? ''), 4, '0', STR_PAD_LEFT),
                    'clas'  => self::unaLinea(($r['CLASIF_COD'] ?? '') . '  ' . ($r['CLASIF_NOMBRE'] ?? '')),
                    'area'  => self::unaLinea(($r['CCOSTO_COD'] ?? '') . ' ' . ($r['CCOSTO_NOMBRE'] ?? '')),
                    'prog'  => 0.0,
                    'ejec'  => 0.0,
                    'saldo' => 0.0,
                ];
            }
            $g[$k]['prog']  += (float)($r['IMPORTE_PROG'] ?? 0);
            $g[$k]['ejec']  += (float)($r['IMPORTE_EJEC'] ?? 0);
            $g[$k]['saldo'] += (float)($r['DIFERENCIA']   ?? 0);
        }
        $g = array_values($g);
        usort($g, function ($a, $b) {
            return [$a['ff'], $a['meta'], $a['clas'], $a['area']]
               <=> [$b['ff'], $b['meta'], $b['clas'], $b['area']];
        });
        return $g;
    }

    /**
     * PDF: vista optimizada para impresión que dispara el diálogo de imprimir
     * (Guardar como PDF). Sin dependencias.
     * @param array $meta ['centro'=>, 'agrupar'=>bool]
     */
    public static function pdf(array $rows, string $titulo, array $meta = []): void
    {
        /* VERSION: PDF-AGRUPADO-v2 · consolida por FF+Meta+Clasificador+Área.
           Si ves el reporte desglosado, este archivo NO se reemplazó en el servidor. */
        $entidad = $meta['entidad'] ?? '';
        $centro  = $meta['centro']  ?? 'TODOS LOS CENTROS';
        $anio    = $meta['anio']    ?? date('Y');

        // Reporte oficial "Ejecución por Área Usuaria": SOLO filas con compromiso
        // ejecutado (> 0). Los ítems sin ejecución no aparecen (igual que el SIGA).
        $ejec = array_values(array_filter($rows, fn($r) => (float)($r['IMPORTE_EJEC'] ?? 0) > 0.005));
        // Orden: por FF, Meta, Clasificador, Área — como el reporte oficial.
        usort($ejec, function ($a, $b) {
            return [$a['FF']??'', (string)($a['META']??''), $a['CLASIF_COD']??'', $a['CCOSTO_COD']??'']
               <=> [$b['FF']??'', (string)($b['META']??''), $b['CLASIF_COD']??'', $b['CCOSTO_COD']??''];
        });
        $total = array_sum(array_map(fn($r) => (float)($r['IMPORTE_EJEC'] ?? 0), $ejec));

        // Detalle de la DIFERENCIA (Programado - Ejecutado) con el mismo nivel
        // de agrupación que la tabla oficial (FF/Rb+Meta+Clasificador+Área):
        // usa TODAS las filas ($rows), no solo $ejec, e incluye también los
        // grupos con saldo negativo (sobregiro) — cada fila trae Programado y
        // Ejecutado propios, así se ve cómo se llega al Saldo sin necesidad de
        // un resumen aparte.
        $saldos     = self::saldoPorGrupo($rows);
        $totalProg  = array_sum(array_column($saldos, 'prog'));
        $totalEjecG = array_sum(array_column($saldos, 'ejec'));
        $totalSaldo = array_sum(array_column($saldos, 'saldo'));

        echo '<!doctype html><html lang="es"><head><meta charset="utf-8">';
        echo '<title>Ejecución por Área Usuaria ' . htmlspecialchars((string)$anio) . '</title>';
        echo '<style>
            body{font-family:Arial,Helvetica,sans-serif;font-size:9px;margin:12px;color:#111827}
            .hdr{display:flex;justify-content:space-between;font-size:8px;color:#334155}
            h2{font-size:14px;text-align:center;margin:6px 0 0;letter-spacing:.5px}
            .yr{text-align:center;font-size:9px;margin:0 0 8px}
            .meta{font-size:8.5px;margin:1px 0}
            .flt{display:flex;justify-content:space-between;font-size:8.5px;margin:6px 0 3px;border-top:1px solid #94a3b8;border-bottom:1px solid #94a3b8;padding:3px 0}
            table{width:100%;border-collapse:collapse;margin-top:2px;table-layout:fixed}
            th,td{border:1px solid #94a3b8;padding:3px 5px;vertical-align:middle}
            /* Una línea por fila: el texto que no entra se recorta con "…" en vez
               de envolver y descuadrar la altura de la tabla impresa. */
            td{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
            th{background:#f1f5f9;font-size:8.5px;text-align:center;white-space:nowrap}
            td.num,th.num{text-align:right}
            tfoot td{font-weight:bold;background:#f8fafc}
            .pendTitulo{background:#1f2937;color:#fff;font-weight:bold;font-size:9.5px;padding:5px 7px;margin-top:16px;
                        display:flex;align-items:center;gap:6px;page-break-inside:avoid}
            .pendTabla{page-break-inside:auto}
            .pendTabla tr{page-break-inside:avoid;page-break-after:auto}
            @media print{@page{size:A4 landscape;margin:8mm}}
        </style></head><body onload="window.print()">';

        // Cabecera tipo SIGA
        echo '<div class="hdr"><div>'
           . '<b>Sistema Integrado de Gestión Administrativa</b><br>Módulo de Logística'
           . '</div><div style="text-align:right">Fecha: ' . date('d/m/Y') . '<br>Hora: ' . date('H:i') . '</div></div>';
        echo '<h2>EJECUCIÓN POR ÁREA USUARIA</h2>';
        echo '<p class="yr">Año : ' . htmlspecialchars((string)$anio) . '</p>';
        if ($entidad) echo '<p class="meta"><b>UNIDAD EJECUTORA</b> : ' . htmlspecialchars(self::unaLinea($entidad)) . '</p>';
        echo '<div class="flt"><span>FF/Rb : Todos</span><span>Tipo de Meta : Todos</span><span>Clasificador de Gasto : Todos</span></div>';
        // $centro ya trae el responsable del área anexado desde index.php.
        echo '<p class="meta"><b>Área Usuaria</b> : ' . htmlspecialchars(self::unaLinea($centro)) . '</p>';

        // Consolidar por FF + Meta + Clasificador + Área (como el reporte oficial):
        // una sola fila por combinación, sumando el compromiso ejecutado.
        $grupos = [];
        foreach ($ejec as $r) {
            $k = ($r['FF'] ?? '').'|'.($r['RB'] ?? '').'|'.($r['META'] ?? '').'|'.($r['CLASIF_COD'] ?? '').'|'.($r['CCOSTO_COD'] ?? '');
            if (!isset($grupos[$k])) {
                // FF/Rb combina fuente agregada + rubro: "1-00" como el reporte oficial.
                $ffrb = trim((string)($r['FF'] ?? ''));
                if (($r['RB'] ?? '') !== '') $ffrb .= '-' . $r['RB'];
                $grupos[$k] = [
                    'ff'    => $ffrb,
                    'meta'  => str_pad((string)($r['META'] ?? ''), 4, '0', STR_PAD_LEFT),
                    'clas'  => self::unaLinea(($r['CLASIF_COD'] ?? '') . '  ' . ($r['CLASIF_NOMBRE'] ?? '')),
                    'area'  => self::unaLinea(($r['CCOSTO_COD'] ?? '') . ' ' . ($r['CCOSTO_NOMBRE'] ?? '')),
                    'comp'  => 0.0,
                ];
            }
            $grupos[$k]['comp'] += (float)($r['IMPORTE_EJEC'] ?? 0);
        }

        echo '<table><thead><tr>'
           . '<th style="width:6%">FF/Rb</th>'
           . '<th style="width:22%">Meta</th>'
           . '<th style="width:38%">Clasificador de Gasto</th>'
           . '<th style="width:22%">Área Usuaria</th>'
           . '<th class="num" style="width:12%">Fase Compromiso</th>'
           . '</tr></thead><tbody>';

        foreach ($grupos as $g) {
            echo '<tr>'
               . '<td>' . htmlspecialchars($g['ff']) . '</td>'
               . '<td title="' . htmlspecialchars($g['meta']) . '">' . htmlspecialchars($g['meta']) . '</td>'
               . '<td title="' . htmlspecialchars($g['clas']) . '">' . htmlspecialchars($g['clas']) . '</td>'
               . '<td title="' . htmlspecialchars($g['area']) . '">' . htmlspecialchars($g['area']) . '</td>'
               . '<td class="num">' . number_format($g['comp'], 2) . '</td>'
               . '</tr>';
        }

        echo '</tbody><tfoot><tr>'
           . '<td colspan="4" style="text-align:right">TOTAL &nbsp; S/.</td>'
           . '<td class="num">' . number_format($total, 2) . '</td>'
           . '</tr></tfoot></table>';
        echo '<p style="font-size:7.5px;color:#64748b;margin-top:6px">Reporte generado desde SIGA-REPORTER · muestra únicamente los ítems con fase de compromiso ejecutada.</p>';

        // ── DIFERENCIA POR FF/Rb · META · CLASIFICADOR · ÁREA (debajo de la tabla oficial) ──
        // Se detalla con columnas (Programado, Ejecutado, Saldo) en vez de un
        // resumen aparte: así se ve fila por fila cómo se llega a cada monto,
        // Saldo = Programado - Ejecutado. Incluye TODOS los grupos, también
        // los sobregirados (Saldo negativo, resaltado en rojo).
        if ($saldos) {
            echo '<div class="pendTitulo">DIFERENCIA (PROGRAMADO − EJECUTADO) POR FF/Rb · META · CLASIFICADOR · ÁREA</div>';
            echo '<table class="pendTabla"><thead><tr>'
               . '<th style="width:5%">FF/Rb</th>'
               . '<th style="width:16%">Meta</th>'
               . '<th style="width:32%">Clasificador de Gasto</th>'
               . '<th style="width:17%">Área Usuaria</th>'
               . '<th class="num" style="width:10%">Programado (S/)</th>'
               . '<th class="num" style="width:10%">Ejecutado (S/)</th>'
               . '<th class="num" style="width:10%">Saldo (S/)</th>'
               . '</tr></thead><tbody>';
            foreach ($saldos as $s) {
                $neg = $s['saldo'] < -0.005;
                echo '<tr' . ($neg ? ' style="background:#fef2f2"' : '') . '>'
                   . '<td>' . htmlspecialchars($s['ff']) . '</td>'
                   . '<td title="' . htmlspecialchars($s['meta']) . '">' . htmlspecialchars($s['meta']) . '</td>'
                   . '<td title="' . htmlspecialchars($s['clas']) . '">' . htmlspecialchars($s['clas']) . '</td>'
                   . '<td title="' . htmlspecialchars($s['area']) . '">' . htmlspecialchars($s['area']) . '</td>'
                   . '<td class="num">' . number_format($s['prog'], 2) . '</td>'
                   . '<td class="num">' . number_format($s['ejec'], 2) . '</td>'
                   . '<td class="num" style="' . ($neg ? 'color:#b91c1c;font-weight:bold' : '') . '">' . number_format($s['saldo'], 2) . '</td>'
                   . '</tr>';
            }
            echo '</tbody><tfoot><tr>'
               . '<td colspan="4" style="text-align:right">TOTAL &nbsp; S/.</td>'
               . '<td class="num">' . number_format($totalProg, 2) . '</td>'
               . '<td class="num">' . number_format($totalEjecG, 2) . '</td>'
               . '<td class="num">' . number_format($totalSaldo, 2) . '</td>'
               . '</tr></tfoot></table>';
            echo '<p style="font-size:7.5px;color:#64748b;margin-top:4px">Saldo = Importe CMN Programado − Importe CMN Ejecutado, agrupado igual que el reporte oficial (FF/Rb + Meta + Clasificador + Área). Filas en rojo = saldo negativo (sobregiro: se ejecutó más de lo programado).</p>';
        }

        echo '</body></html>';
    }
}