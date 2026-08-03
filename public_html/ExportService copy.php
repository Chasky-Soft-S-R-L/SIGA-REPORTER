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
 */
class ExportService
{
    /** Encabezados visibles del reporte (orden = columnas de la vista web). */
    public const HEADERS = [
        'ESTADO_CMN'          => 'ESTADO CMN',
        'ESTADO_FASE'         => 'FASE',
        'NRO_LINEAS'          => 'N° LÍNEAS',
        'PROGR_ANO_1'         => 'PROGR_ANO_1',
        'FF'                  => 'FF',
        'FF_NOMBRE'           => 'FUENTE FINANCIAMIENTO',
        'RB'                  => 'RB',
        'TIPO_BIEN'           => 'TIPO_BIEN',
        'CCOSTO_COD'          => 'CCOSTO_COD',
        'CCOSTO_NOMBRE'       => 'CCOSTO_NOMBRE',
        'META'                => 'META',
        'GENERICA'            => 'GENÉRICA',
        'CLASIF_COD'          => 'CLASIF_COD',
        'CLASIF_NOMBRE'       => 'CLASIFICADOR',
        'TIPO_USO'            => 'TIPO_USO',
        'ACTIV_OPERAT_COD'    => 'ACTIV_OPERAT_COD',
        'ACTIV_OPERAT_NOMBRE' => 'ACTIVIDAD OPERATIVA',
        'GRUPO_BIEN'          => 'GRUPO_BIEN',
        'CLASE_BIEN'          => 'CLASE_BIEN',
        'FAMILIA_BIEN'        => 'FAMILIA_BIEN',
        'ITEM_BIEN'           => 'ITEM_BIEN',
        'COD_PRODUCTO'        => 'CÓDIGO PRODUCTO',
        'NOMBRE_ITEM'         => 'NOMBRE_ITEM',
        'UNIDAD_MEDIDA'       => 'UNIDAD_MEDIDA',
        'CANTIDAD_PROG'       => 'CANTIDAD',
        'PRECIO_UNIT_PROG'    => 'PRECIO_UNIT',
        'IMPORTE_PROG'        => 'IMPORTE CMN PROGRAMADO',
        'CANTIDAD_MOD'        => 'CANTIDAD',
        'PRECIO_UNIT_MOD'     => 'PRECIO_UNIT',
        'IMPORTE_MOD'         => 'IMPORTE CMN MODIFICADO',
        'ESTADO_ORDEN'        => 'ESTADO',
        'RESPONSABLE'         => 'RESPONSABLE',
        'CANTIDAD_EJEC'       => 'CANTIDAD',
        'PRECIO_UNIT_EJEC'    => 'PRECIO_UNIT',
        'IMPORTE_EJEC'        => 'IMPORTE CMN EJECUTADO',
        'DIFERENCIA'          => 'DIFERENCIA',
        'DEVENGADO'           => 'DEVENGADO',
        'SALDO_DEVENGAR'      => 'SALDO POR DEVENGAR',
    ];

    /** Columnas enteras (sin decimales). */
    public const INT = ['NRO_LINEAS'];

    /** Columnas numéricas (para formateo/alineación). */
    public const NUM = [
        'NRO_LINEAS',
        'CANTIDAD_PROG','PRECIO_UNIT_PROG','IMPORTE_PROG',
        'CANTIDAD_MOD','PRECIO_UNIT_MOD','IMPORTE_MOD',
        'CANTIDAD_EJEC','PRECIO_UNIT_EJEC','IMPORTE_EJEC','DEVENGADO','DIFERENCIA','SALDO_DEVENGAR',
    ];

    /** Paleta de la vista web: [color fuerte, color claro] por actividad. */
    private const PALETA = [
        ['#059669','#ecfdf5'], ['#0284c7','#eff6ff'], ['#6d28d9','#f5f3ff'], ['#b45309','#fffbeb'],
        ['#dc2626','#fef2f2'], ['#0f766e','#f0fdfa'], ['#a21caf','#fdf4ff'], ['#4d7c0f','#f7fee7'],
    ];

    /** Color estable por código de actividad (mismo criterio que el frontend). */
    private static function actColor(string $cod): array
    {
        $h = 0;
        for ($i = 0, $n = strlen($cod); $i < $n; $i++) {
            $h = ($h * 31 + ord($cod[$i])) % 4294967296;
        }
        return self::PALETA[$h % count(self::PALETA)];
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
        $out  = '<tr><td colspan="' . $idx . '" style="' . $lblStyle . '">' . htmlspecialchars($label) . '</td>';
        for ($i = $idx, $n = count($keys); $i < $n; $i++) {
            $k = $keys[$i];
            $v = ($k === 'IMPORTE_PROG') ? $t['prog']
               : (($k === 'IMPORTE_MOD')  ? $t['mod']
               : (($k === 'IMPORTE_EJEC') ? $t['ejec']
               : (($k === 'DIFERENCIA')   ? $t['dif']
               : (($k === 'DEVENGADO')    ? $t['dev']
               : (($k === 'SALDO_DEVENGAR') ? $t['saldo'] : null)))));
            $out .= $v === null
                ? '<td style="' . $tdStyle . '"></td>'
                : '<td style="' . $tdStyle . 'mso-number-format:\'#,##0.00\';text-align:right">'
                  . number_format($v, 2, '.', '') . '</td>';
        }
        return $out . '</tr>';
    }

    /** Celdas de una fila de ítem. */
    private static function celdasItem(array $r, string $bg): string
    {
        $out = '';
        foreach (self::HEADERS as $key => $_) {
            $v = $r[$key] ?? '';
            if (in_array($key, self::INT, true)) {
                $out .= '<td style="background:' . $bg . ';mso-number-format:\'0\';text-align:right">'
                      . (int)$v . '</td>';
            } elseif (in_array($key, self::NUM, true)) {
                $out .= '<td style="background:' . $bg . ';mso-number-format:\'#,##0.00\';text-align:right">'
                      . number_format((float)$v, 2, '.', '') . '</td>';
            } else {
                $out .= '<td style="background:' . $bg . ';mso-number-format:\'@\'">'
                      . htmlspecialchars((string)$v) . '</td>';
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

        echo '<table border="0" cellspacing="0" cellpadding="3">';

        // ── Cabecera institucional ──
        echo '<tr><td colspan="' . $nCols . '" style="font-size:15px;font-weight:bold;color:#14967d">'
           . htmlspecialchars($titulo) . '</td></tr>';
        echo '<tr><td colspan="' . $nCols . '" style="font-size:11px;color:#334155">'
           . htmlspecialchars($centro) . '</td></tr>';
        echo '<tr><td colspan="' . $nCols . '" style="font-size:9px;color:#64748b">Generado: '
           . date('d/m/Y H:i') . '</td></tr>';
        echo '<tr><td colspan="' . $nCols . '"></td></tr>';

        // ── Encabezados de columna ──
        echo '<tr>';
        foreach (self::HEADERS as $key => $label) {
            $al = in_array($key, self::NUM, true) ? 'right' : 'left';
            echo '<th style="background:#1abb9c;color:#fff;font-weight:bold;border:1px solid #0f766e;text-align:' . $al . '">'
               . htmlspecialchars($label) . '</th>';
        }
        echo '</tr>';

        $tot = self::totales($rows);

        if ($agrupar) {
            foreach (self::agrupar($rows, $by) as $act => $items) {
                [$fuerte, $claro] = self::actColor((string)$act);
                $lbl = self::grupoLabel($items, $by, (string)$act);
                // Cabecera del bloque
                echo '<tr><td colspan="' . $nCols . '" style="background:' . $fuerte
                   . ';color:#fff;font-weight:bold;font-size:11px">'
                   . htmlspecialchars($lbl) . '   ·   ' . count($items) . ' ítems</td></tr>';
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

        echo '<!doctype html><html lang="es"><head><meta charset="utf-8">';
        echo '<title>Ejecución por Área Usuaria ' . htmlspecialchars((string)$anio) . '</title>';
        echo '<style>
            body{font-family:Arial,Helvetica,sans-serif;font-size:9px;margin:12px;color:#111827}
            .hdr{display:flex;justify-content:space-between;font-size:8px;color:#334155}
            h2{font-size:14px;text-align:center;margin:6px 0 0;letter-spacing:.5px}
            .yr{text-align:center;font-size:9px;margin:0 0 8px}
            .meta{font-size:8.5px;margin:1px 0}
            .flt{display:flex;justify-content:space-between;font-size:8.5px;margin:6px 0 3px;border-top:1px solid #94a3b8;border-bottom:1px solid #94a3b8;padding:3px 0}
            table{width:100%;border-collapse:collapse;margin-top:2px}
            th,td{border:1px solid #94a3b8;padding:3px 5px;vertical-align:top}
            th{background:#f1f5f9;font-size:8.5px;text-align:center}
            td.num,th.num{text-align:right}
            tfoot td{font-weight:bold;background:#f8fafc}
            @media print{@page{size:A4 landscape;margin:8mm}}
        </style></head><body onload="window.print()">';

        // Cabecera tipo SIGA
        echo '<div class="hdr"><div>'
           . '<b>Sistema Integrado de Gestión Administrativa</b><br>Módulo de Logística'
           . '</div><div style="text-align:right">Fecha: ' . date('d/m/Y') . '<br>Hora: ' . date('H:i') . '</div></div>';
        echo '<h2>EJECUCIÓN POR ÁREA USUARIA</h2>';
        echo '<p class="yr">Año : ' . htmlspecialchars((string)$anio) . '</p>';
        if ($entidad) echo '<p class="meta"><b>UNIDAD EJECUTORA</b> : ' . htmlspecialchars($entidad) . '</p>';
        echo '<div class="flt"><span>FF/Rb : Todos</span><span>Tipo de Meta : Todos</span><span>Clasificador de Gasto : Todos</span></div>';
        echo '<p class="meta"><b>Área Usuaria</b> : ' . htmlspecialchars($centro) . '</p>';

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
                    'clas'  => ($r['CLASIF_COD'] ?? '') . '  ' . ($r['CLASIF_NOMBRE'] ?? ''),
                    'area'  => ($r['CCOSTO_COD'] ?? '') . ' ' . ($r['CCOSTO_NOMBRE'] ?? ''),
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
               . '<td>' . htmlspecialchars($g['meta']) . '</td>'
               . '<td>' . htmlspecialchars($g['clas']) . '</td>'
               . '<td>' . htmlspecialchars($g['area']) . '</td>'
               . '<td class="num">' . number_format($g['comp'], 2) . '</td>'
               . '</tr>';
        }

        echo '</tbody><tfoot><tr>'
           . '<td colspan="4" style="text-align:right">TOTAL &nbsp; S/.</td>'
           . '<td class="num">' . number_format($total, 2) . '</td>'
           . '</tr></tfoot></table>';
        echo '<p style="font-size:7.5px;color:#64748b;margin-top:6px">Reporte generado desde SIGA-REPORTER · muestra únicamente los ítems con fase de compromiso ejecutada.</p>';
        echo '</body></html>';
    }
}