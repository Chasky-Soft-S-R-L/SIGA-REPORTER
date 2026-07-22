<?php
/**
 * CAPA SERVICE  ·  SIGA-REPORTER
 * Servicios de salida (Excel / PDF). NO es servicio de dominio: solo transforma
 * las filas ya consultadas en un archivo descargable. Cero dependencias => rápido.
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
        'PROGR_ANO_1'         => 'PROGR_ANO_1',
        'FF'                  => 'FF',
        'RB'                  => 'RB',
        'TIPO_BIEN'           => 'TIPO_BIEN',
        'CCOSTO_COD'          => 'CCOSTO_COD',
        'CCOSTO_NOMBRE'       => 'CCOSTO_NOMBRE',
        'META'                => 'META',
        'GENERICA'            => 'GENÉRICA',
        'CLASIF_COD'          => 'CLASIF_COD',
        'TIPO_USO'            => 'TIPO_USO',
        'ACTIV_OPERAT_COD'    => 'ACTIV_OPERAT_COD',
        'GRUPO_BIEN'          => 'GRUPO_BIEN',
        'CLASE_BIEN'          => 'CLASE_BIEN',
        'FAMILIA_BIEN'        => 'FAMILIA_BIEN',
        'ITEM_BIEN'           => 'ITEM_BIEN',
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
    ];

    /** Columnas numéricas (para formateo/alineación). */
    public const NUM = [
        'CANTIDAD_PROG','PRECIO_UNIT_PROG','IMPORTE_PROG',
        'CANTIDAD_MOD','PRECIO_UNIT_MOD','IMPORTE_MOD',
        'CANTIDAD_EJEC','PRECIO_UNIT_EJEC','IMPORTE_EJEC','DIFERENCIA',
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
    private static function agrupar(array $rows): array
    {
        $g = [];
        foreach ($rows as $r) {
            $g[$r['ACTIV_OPERAT_COD'] ?? '—'][] = $r;
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

    /** Suma los cuatro importes de un conjunto de filas. */
    private static function totales(array $rows): array
    {
        $t = ['prog' => 0.0, 'mod' => 0.0, 'ejec' => 0.0, 'dif' => 0.0];
        foreach ($rows as $r) {
            $t['prog'] += (float)($r['IMPORTE_PROG'] ?? 0);
            $t['mod']  += (float)($r['IMPORTE_MOD']  ?? 0);
            $t['ejec'] += (float)($r['IMPORTE_EJEC'] ?? 0);
            $t['dif']  += (float)($r['DIFERENCIA']   ?? 0);
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
            $v = match ($k) {
                'IMPORTE_PROG' => $t['prog'],
                'IMPORTE_MOD'  => $t['mod'],
                'IMPORTE_EJEC' => $t['ejec'],
                'DIFERENCIA'   => $t['dif'],
                default        => null,
            };
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
            if (in_array($key, self::NUM, true)) {
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
            foreach (self::agrupar($rows) as $act => $items) {
                [$fuerte, $claro] = self::actColor((string)$act);
                $nombre = $items[0]['ACTIV_OPERAT_NOMBRE'] ?? '';
                // Cabecera del bloque
                echo '<tr><td colspan="' . $nCols . '" style="background:' . $fuerte
                   . ';color:#fff;font-weight:bold;font-size:11px">'
                   . htmlspecialchars($act . '   ' . $nombre) . '   ·   ' . count($items) . ' ítems</td></tr>';
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
        $nCols   = count(self::HEADERS);
        $agrupar = $meta['agrupar'] ?? true;
        $centro  = $meta['centro']  ?? 'TODOS LOS CENTROS';

        echo '<!doctype html><html lang="es"><head><meta charset="utf-8">';
        echo '<title>' . htmlspecialchars($titulo) . '</title>';
        echo '<style>
            body{font-family:Arial,Helvetica,sans-serif;font-size:8px;margin:10px;color:#111827}
            h2{font-size:13px;color:#14967d;margin:0}
            .sub{font-size:10px;color:#334155;margin:2px 0 1px}
            .fec{font-size:8px;color:#64748b;margin-bottom:8px}
            table{width:100%;border-collapse:collapse}
            th,td{border:1px solid #cbd5e1;padding:2px 3px}
            th{background:#1abb9c;color:#fff;font-size:8px}
            td.num,th.num{text-align:right}
            tr.ghead td{color:#fff;font-weight:bold;font-size:9px;padding:3px 4px}
            tr.gsub td{font-weight:bold}
            tfoot td{font-weight:bold;background:#1f2937;color:#fff}
            .neg{color:#dc2626}
            @media print{@page{size:A4 landscape;margin:7mm} tr{page-break-inside:avoid}}
        </style></head><body onload="window.print()">';

        echo '<h2>' . htmlspecialchars($titulo) . '</h2>';
        echo '<div class="sub">' . htmlspecialchars($centro) . '</div>';
        echo '<div class="fec">Generado: ' . date('d/m/Y H:i') . '</div>';

        echo '<table><thead><tr>';
        foreach (self::HEADERS as $key => $label) {
            $c = in_array($key, self::NUM, true) ? ' class="num"' : '';
            echo "<th{$c}>" . htmlspecialchars($label) . '</th>';
        }
        echo '</tr></thead><tbody>';

        $celdas = function (array $r, string $bg): string {
            $out = '';
            foreach (self::HEADERS as $key => $_) {
                $v = $r[$key] ?? '';
                if (in_array($key, self::NUM, true)) {
                    $neg = ($key === 'DIFERENCIA' && (float)$v < -0.005) ? ' neg' : '';
                    $out .= '<td class="num' . $neg . '" style="background:' . $bg . '">' . number_format((float)$v, 2) . '</td>';
                } else {
                    $out .= '<td style="background:' . $bg . '">' . htmlspecialchars((string)$v) . '</td>';
                }
            }
            return $out;
        };

        $filaTot = function (string $label, array $t, string $style, string $lblStyle): string {
            $keys = array_keys(self::HEADERS);
            $idx  = self::idxImportes();
            $out  = '<tr class="gsub"><td colspan="' . $idx . '" style="' . $lblStyle . '">' . htmlspecialchars($label) . '</td>';
            for ($i = $idx, $n = count($keys); $i < $n; $i++) {
                $k = $keys[$i];
                $v = match ($k) {
                    'IMPORTE_PROG' => $t['prog'], 'IMPORTE_MOD' => $t['mod'],
                    'IMPORTE_EJEC' => $t['ejec'], 'DIFERENCIA'  => $t['dif'], default => null,
                };
                $out .= $v === null
                    ? '<td style="' . $style . '"></td>'
                    : '<td class="num" style="' . $style . '">' . number_format($v, 2) . '</td>';
            }
            return $out . '</tr>';
        };

        if ($agrupar) {
            foreach (self::agrupar($rows) as $act => $items) {
                [$fuerte, $claro] = self::actColor((string)$act);
                $nombre = $items[0]['ACTIV_OPERAT_NOMBRE'] ?? '';
                echo '<tr class="ghead"><td colspan="' . $nCols . '" style="background:' . $fuerte . '">'
                   . htmlspecialchars($act . '   ' . $nombre) . '   ·   ' . count($items) . ' ítems</td></tr>';
                foreach ($items as $r) { echo '<tr>' . $celdas($r, $claro) . '</tr>'; }
                echo $filaTot(
                    'Sub Total  ·  ' . $act,
                    self::totales($items),
                    'background:' . $claro . ';color:' . $fuerte . ';border-top:1px solid ' . $fuerte,
                    'background:' . $claro . ';color:' . $fuerte . ';text-align:right;border-top:1px solid ' . $fuerte
                );
            }
        } else {
            foreach ($rows as $r) { echo '<tr>' . $celdas($r, '#ffffff') . '</tr>'; }
        }

        echo '</tbody><tfoot>';
        $t = self::totales($rows);
        $keys = array_keys(self::HEADERS);
        $idx  = self::idxImportes();
        echo '<tr><td colspan="' . $idx . '" style="text-align:right">TOTAL GENERAL</td>';
        for ($i = $idx, $n = count($keys); $i < $n; $i++) {
            $k = $keys[$i];
            $v = match ($k) {
                'IMPORTE_PROG' => $t['prog'], 'IMPORTE_MOD' => $t['mod'],
                'IMPORTE_EJEC' => $t['ejec'], 'DIFERENCIA'  => $t['dif'], default => null,
            };
            echo $v === null ? '<td></td>' : '<td class="num">' . number_format($v, 2) . '</td>';
        }
        echo '</tr></tfoot></table></body></html>';
    }
}