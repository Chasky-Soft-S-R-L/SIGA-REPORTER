<?php
/**
 * CAPA SERVICE  ·  SIGA-REPORTER
 * Servicios de salida (Excel / PDF). NO es servicio de dominio: solo transforma
 * las filas ya consultadas en un archivo descargable. Cero dependencias => rápido.
 */
class ExportService
{
    /** Encabezados visibles del reporte (orden = columnas del Excel institucional). */
    public const HEADERS = [
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

    /**
     * Excel sin librerías: tabla HTML con cabeceras de MS-Excel.
     * Abre directo en Excel. Rápido y sin instalar nada.
     */
    public static function excel(array $rows, string $filename): void
    {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        header('Pragma: no-cache');
        echo "\xEF\xBB\xBF"; // BOM UTF-8 para tildes

        echo '<table border="1"><thead><tr>';
        foreach (self::HEADERS as $label) {
            echo '<th style="background:#1abb9c;color:#fff;font-weight:bold">'
                . htmlspecialchars($label) . '</th>';
        }
        echo '</tr></thead><tbody>';

        $tProg = $tMod = $tEjec = $tDif = 0.0;
        foreach ($rows as $r) {
            echo '<tr>';
            foreach (self::HEADERS as $key => $_) {
                $v = $r[$key] ?? '';
                if (in_array($key, self::NUM, true)) {
                    $v = number_format((float)$v, 2, '.', '');
                    echo '<td style="mso-number-format:\'0.00\'">' . $v . '</td>';
                } else {
                    // prefijo ="" evita que Excel convierta códigos a número/fecha
                    echo '<td style="mso-number-format:\'@\'">' . htmlspecialchars((string)$v) . '</td>';
                }
            }
            echo '</tr>';
            $tProg += (float)($r['IMPORTE_PROG'] ?? 0);
            $tMod  += (float)($r['IMPORTE_MOD']  ?? 0);
            $tEjec += (float)($r['IMPORTE_EJEC'] ?? 0);
            $tDif  += (float)($r['DIFERENCIA']  ?? 0);
        }

        // Fila de totales (posición dinámica por columna)
        $keys = array_keys(self::HEADERS);
        $idxProg = array_search('IMPORTE_PROG', $keys, true);
        echo '<tr><td colspan="' . $idxProg . '" style="font-weight:bold;text-align:right">PRESUPUESTO PROGRAMADO</td>';
        for ($i = $idxProg; $i < count($keys); $i++) {
            $k = $keys[$i];
            if ($k === 'IMPORTE_PROG')      echo '<td style="font-weight:bold">' . number_format($tProg, 2, '.', '') . '</td>';
            elseif ($k === 'IMPORTE_MOD')   echo '<td style="font-weight:bold">' . number_format($tMod, 2, '.', '') . '</td>';
            elseif ($k === 'IMPORTE_EJEC')  echo '<td style="font-weight:bold">' . number_format($tEjec, 2, '.', '') . '</td>';
            elseif ($k === 'DIFERENCIA')    echo '<td style="font-weight:bold">' . number_format($tDif, 2, '.', '') . '</td>';
            else                            echo '<td></td>';
        }
        echo '</tr>';

        echo '</tbody></table>';
    }

    /**
     * PDF: vista optimizada para impresión que dispara el diálogo de imprimir
     * (Guardar como PDF). Sin dependencias. Si tienes Dompdf, se puede cambiar aquí.
     */
    public static function pdf(array $rows, string $titulo): void
    {
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8">';
        echo '<title>' . htmlspecialchars($titulo) . '</title>';
        echo '<style>
            body{font-family:Arial,Helvetica,sans-serif;font-size:9px;margin:12px}
            h2{font-size:13px;color:#14967d;margin:0 0 8px}
            table{width:100%;border-collapse:collapse}
            th,td{border:1px solid #cbd5e1;padding:2px 4px}
            th{background:#1abb9c;color:#fff}
            td.num,th.num{text-align:right}
            tfoot td{font-weight:bold;background:#f1f5f9}
            @media print{@page{size:A4 landscape;margin:8mm}}
        </style></head><body onload="window.print()">';
        echo '<h2>' . htmlspecialchars($titulo) . '</h2><table><thead><tr>';
        foreach (self::HEADERS as $key => $label) {
            $c = in_array($key, self::NUM, true) ? ' class="num"' : '';
            echo "<th{$c}>" . htmlspecialchars($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        $tProg = $tMod = $tEjec = $tDif = 0.0;
        foreach ($rows as $r) {
            echo '<tr>';
            foreach (self::HEADERS as $key => $_) {
                $v = $r[$key] ?? '';
                if (in_array($key, self::NUM, true)) {
                    echo '<td class="num">' . number_format((float)$v, 2) . '</td>';
                } else {
                    echo '<td>' . htmlspecialchars((string)$v) . '</td>';
                }
            }
            echo '</tr>';
            $tProg += (float)($r['IMPORTE_PROG'] ?? 0);
            $tMod  += (float)($r['IMPORTE_MOD']  ?? 0);
            $tEjec += (float)($r['IMPORTE_EJEC'] ?? 0);
            $tDif  += (float)($r['DIFERENCIA']  ?? 0);
        }
        echo '</tbody><tfoot><tr>';
        $keys = array_keys(self::HEADERS);
        $idxProg = array_search('IMPORTE_PROG', $keys, true);
        echo '<td colspan="' . $idxProg . '" style="text-align:right">PRESUPUESTO PROGRAMADO</td>';
        for ($i = $idxProg; $i < count($keys); $i++) {
            $k = $keys[$i];
            if ($k === 'IMPORTE_PROG')      echo '<td class="num">' . number_format($tProg, 2) . '</td>';
            elseif ($k === 'IMPORTE_MOD')   echo '<td class="num">' . number_format($tMod, 2) . '</td>';
            elseif ($k === 'IMPORTE_EJEC')  echo '<td class="num">' . number_format($tEjec, 2) . '</td>';
            elseif ($k === 'DIFERENCIA')    echo '<td class="num">' . number_format($tDif, 2) . '</td>';
            else                            echo '<td></td>';
        }
        echo '</tr></tfoot></table></body></html>';
    }
}