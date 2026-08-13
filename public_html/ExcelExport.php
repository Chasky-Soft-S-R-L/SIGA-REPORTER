<?php
/**
 * EXPORTADOR XLSX  ·  SIGA-REPORTER
 * Genera el Cuadro de Necesidades como XLSX REAL usando PhpSpreadsheet.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * POR QUÉ EXISTE (y por qué no basta el export HTML de ExportService)
 * ══════════════════════════════════════════════════════════════════════════
 * ExportService::excel() escribe una tabla HTML que Excel interpreta. Es rápido
 * y sin dependencias, pero hay tres cosas que ese formato NO puede dar:
 *   · AUTOFILTRO fiable. Se declara por XML (<x:AutoFilter>, _FilterDatabase)
 *     y las distintas versiones de Excel lo ignoran de forma inconsistente.
 *   · FÓRMULAS. En HTML todo llega como valor fijo: si el usuario filtra o
 *     edita una cantidad, los totales no se recalculan.
 *   · VARIAS HOJAS en un solo archivo.
 * Aquí sí: el archivo es un XLSX de verdad.
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
 * se recalculan solos con lo que queda a la vista — que es justo lo que no
 * lograba el export HTML. Además SUBTOTAL ignora otros SUBTOTAL anidados, así
 * que el TOTAL GENERAL puede abarcar todo el rango sin sumar dos veces los
 * Sub Total de cada bloque.
 *
 * Si una fila tiene sus columnas de origen ocultas (el usuario quitó
 * IMPORTE_PROG del selector de campos), la fórmula no se puede armar y se
 * escribe el valor calculado. Ver formulaOValor().
 *
 * ══════════════════════════════════════════════════════════════════════════
 * INSTALACIÓN
 * ══════════════════════════════════════════════════════════════════════════
 *   composer require phpoffice/phpspreadsheet:^1.29
 *
 * La 1.x es la que soporta PHP 7.4 (la 2.x exige PHP 8.1+). El require_once
 * del autoload va en index.php, no aquí.
 *
 * Si PhpSpreadsheet no está instalado, disponible() devuelve false e index.php
 * cae al export HTML de siempre: el sistema nunca se queda sin exportar.
 */

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

require_once __DIR__ . '/column_labels.php';
require_once __DIR__ . '/ExportService.php';

class ExcelExport
{
    /** Formato numérico de los importes: miles con coma y 2 decimales. */
    private const FMT_NUM = '#,##0.00';
    private const FMT_INT = '#,##0';

    /** Fila donde van los títulos de columna (1-3 cabecera, 4 en blanco). */
    private const FILA_HEAD = 5;

    /** ¿Está PhpSpreadsheet instalado? index.php decide con esto. */
    public static function disponible(): bool
    {
        return class_exists(Spreadsheet::class);
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
        $cols    = $meta['cols'] ?? array_keys(Labels::COLUMNS);
        $cols    = array_values(array_intersect($cols, array_keys(Labels::COLUMNS)));
        if (!$cols) $cols = array_keys(Labels::COLUMNS);

        $agrupar = $meta['agrupar'] ?? true;
        $by      = $meta['groupBy'] ?? 'ACTIV_OPERAT_COD';
        $titulo  = $meta['titulo']  ?? 'CUADRO DE NECESIDADES';
        $centro  = $meta['centro']  ?? 'TODOS LOS CENTROS';

        $ss = new Spreadsheet();
        $sh = $ss->getActiveSheet();
        $sh->setTitle('CMN');

        $nCols   = count($cols);
        $ultCol  = Coordinate::stringFromColumnIndex($nCols);
        /* Índice de columna por clave, para armar las fórmulas: la posición
           depende de qué campos eligió el usuario, no es fija. */
        $colDe   = [];
        foreach ($cols as $i => $k) { $colDe[$k] = Coordinate::stringFromColumnIndex($i + 1); }

        // ── Cabecera del documento (filas 1-3) ──
        self::cabecera($sh, $titulo, $centro, $ultCol);

        // ── Títulos de columna (fila 5) ──
        self::titulosColumna($sh, $cols);

        $fila = self::FILA_HEAD + 1;   // primera fila de datos
        $iniDatos = $fila;
        $filasSubtotal = [];           // para el TOTAL GENERAL

        if ($agrupar) {
            foreach (self::agruparFilas($rows, $by) as $clave => $items) {
                // Cabecera del bloque: una celda combinada con el nombre del grupo
                $lbl = ExportService::grupoLabelPublico($items, $by, (string)$clave);
                $sh->setCellValue('A' . $fila, $lbl . '   ·   ' . count($items) . ' ítems');
                $sh->mergeCells('A' . $fila . ':' . $ultCol . $fila);
                [$fuerte, $claro] = ExportService::actColorPublico((string)$clave);
                self::estiloBloque($sh, 'A' . $fila . ':' . $ultCol . $fila, $fuerte);
                $fila++;

                $ini = $fila;
                foreach ($items as $r) { self::escribirFila($sh, $fila++, $cols, $colDe, $r, $claro); }
                $fin = $fila - 1;

                // Sub Total del bloque
                self::filaTotales($sh, $fila, $cols, $colDe, $ini, $fin, 'Sub Total  ·  ' . $clave, $fuerte, $claro);
                $filasSubtotal[] = $fila;
                $fila++;
            }
        } else {
            foreach ($rows as $r) { self::escribirFila($sh, $fila++, $cols, $colDe, $r, null); }
        }

        $finDatos = $fila - 1;

        // ── TOTAL GENERAL · SUBTOTAL sobre todo el rango ──
        // SUBTOTAL ignora los SUBTOTAL anidados de cada bloque, así que no
        // hace falta excluir esas filas del rango: no se suman dos veces.
        self::filaTotales($sh, $fila, $cols, $colDe, $iniDatos, $finDatos, 'TOTAL GENERAL', '1F2937', 'D1D5DB', true);
        $filaTotal = $fila;

        // ── Anchos, autofiltro, panel congelado ──
        foreach ($cols as $i => $k) {
            $sh->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))
               ->setWidth(self::anchoExcel($k));
        }
        // Autofiltro NATIVO: aquí sí es una propiedad real del XLSX.
        $sh->setAutoFilter('A' . self::FILA_HEAD . ':' . $ultCol . $finDatos);
        // Congela cabecera: todo lo de arriba de la fila 6 queda fijo.
        $sh->freezePane('A' . (self::FILA_HEAD + 1));
        $sh->setSelectedCell('A' . (self::FILA_HEAD + 1));

        // Excel debe recalcular al abrir: las fórmulas se guardan sin resultado.
        $sh->setShowGridlines(true);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        header('Cache-Control: max-age=0');
        $writer = new Xlsx($ss);
        $writer->setPreCalculateFormulas(false);  // que las calcule Excel, no PHP
        $writer->save('php://output');
        $ss->disconnectWorksheets();
        unset($ss);
    }

    /** Filas 1-3: título, centro (con responsable) y fecha de generación. */
    private static function cabecera($sh, string $titulo, string $centro, string $ultCol): void
    {
        $lineas = [
            [1, $titulo,                              '14967D', 12, 22],
            [2, $centro,                              '334155', 10, 18],
            [3, 'Generado: ' . date('d/m/Y H:i'),     '64748B', 10, 16],
        ];
        foreach ($lineas as [$f, $txt, $color, $size, $alto]) {
            $sh->setCellValue('A' . $f, $txt);
            $sh->mergeCells('A' . $f . ':' . $ultCol . $f);
            $sh->getStyle('A' . $f)->applyFromArray([
                'font'      => ['name' => 'Calibri', 'bold' => true, 'size' => $size,
                                'color' => ['rgb' => $color]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT,
                                'vertical'   => Alignment::VERTICAL_CENTER],
            ]);
            $sh->getRowDimension($f)->setRowHeight($alto);
        }
    }

    /** Fila 5: títulos de columna, con el color de fase donde corresponde. */
    private static function titulosColumna($sh, array $cols): void
    {
        $f = self::FILA_HEAD;
        foreach ($cols as $i => $k) {
            $cel = Coordinate::stringFromColumnIndex($i + 1) . $f;
            $sh->setCellValue($cel, Labels::COLUMNS[$k] ?? $k);

            $fase = ExportService::colFasePublico($k);
            /* DIFERENCIA comparte el amarillo de PROGRAMADO, igual que en
               pantalla: se lee como parte del mismo bloque de saldo. */
            $bg   = $fase ? ExportService::faseHexPublico($fase)[0]
                          : ($k === 'DIFERENCIA' ? 'FFFF00' : '1ABB9C');
            $txt  = ($fase || $k === 'DIFERENCIA') ? '1E3A8A' : 'FFFFFF';

            $sh->getStyle($cel)->applyFromArray([
                'font'      => ['bold' => true, 'size' => 10, 'color' => ['rgb' => $txt]],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                                'vertical'   => Alignment::VERTICAL_CENTER,
                                'wrapText'   => true],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                                 'color' => ['rgb' => '0F766E']]],
            ]);
        }
        $sh->getRowDimension($f)->setRowHeight(30);
    }

    /**
     * Escribe una fila de ítem. DIFERENCIA y SALDO_DEVENGAR van como FÓRMULA
     * cuando sus columnas de origen están visibles; si el usuario las ocultó,
     * se escribe el valor ya calculado (una fórmula que apunta a una columna
     * inexistente daría #¡REF!).
     */
    private static function escribirFila($sh, int $f, array $cols, array $colDe, array $r, ?string $tinte): void
    {
        $excluido = ExportService::estadoEjec($r) === 'Excluido';

        foreach ($cols as $i => $k) {
            $letra = Coordinate::stringFromColumnIndex($i + 1);
            $cel   = $letra . $f;

            if ($k === 'DIFERENCIA') {
                // Programado − Ejecutado
                self::formulaOValor($sh, $cel, $colDe, 'IMPORTE_PROG', 'IMPORTE_EJEC', $f,
                                    (float)($r['DIFERENCIA'] ?? 0));
            } elseif ($k === 'SALDO_DEVENGAR') {
                // Ejecutado − Devengado
                self::formulaOValor($sh, $cel, $colDe, 'IMPORTE_EJEC', 'DEVENGADO', $f,
                                    (float)($r['SALDO_DEVENGAR'] ?? 0));
            } elseif ($k === 'CERT_SIGA') {
                $nro = (int)($r['CERT_SIGA'] ?? 0);
                $sh->setCellValueExplicit($cel, $nro > 0 ? 'SI' : '',
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            } elseif ($k === 'ESTADO_EJEC') {
                $sh->setCellValue($cel, mb_strtoupper(ExportService::estadoEjec($r), 'UTF-8'));
            } elseif ($k === 'ESTADO_CMN') {
                $sh->setCellValue($cel, ExportService::cmnAbrevPublico((string)($r[$k] ?? '')));
            } elseif ($k === 'ESTADO_FASE') {
                $sh->setCellValue($cel, ExportService::faseEjecucionPublico($r));
            } elseif ($k === 'ESTADO_ORDEN') {
                $sh->setCellValue($cel, ExportService::resumenOrdenesPublico((string)($r[$k] ?? '')));
            } elseif (in_array($k, Labels::NUMERIC_COLUMNS, true)) {
                $sh->setCellValue($cel, (float)($r[$k] ?? 0));
            } else {
                /* Texto explícito: evita que Excel convierta los clasificadores
                   ("2.3.2 9.1 1") o los códigos con ceros a la izquierda. */
                $sh->setCellValueExplicit($cel, (string)($r[$k] ?? ''),
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }

        self::estiloFila($sh, $f, $cols, $tinte, $excluido);
    }

    /**
     * Escribe una FÓRMULA de resta si ambas columnas están visibles; si no,
     * el valor ya calculado. Así el usuario puede editar una cantidad en Excel
     * y ver el saldo recalcularse, sin arriesgar un #¡REF! cuando ocultó
     * alguna de las dos columnas en el selector de campos.
     */
    private static function formulaOValor($sh, string $cel, array $colDe, string $a, string $b, int $f, float $valor): void
    {
        if (isset($colDe[$a], $colDe[$b])) {
            $sh->setCellValue($cel, '=' . $colDe[$a] . $f . '-' . $colDe[$b] . $f);
        } else {
            $sh->setCellValue($cel, $valor);
        }
    }

    /** Formato de una fila de datos: tintes de fase, números y rojo si está excluida. */
    private static function estiloFila($sh, int $f, array $cols, ?string $tinte, bool $excluido): void
    {
        foreach ($cols as $i => $k) {
            $cel  = Coordinate::stringFromColumnIndex($i + 1) . $f;
            $fase = ExportService::colFasePublico($k);
            $bg   = $fase ? ExportService::faseHexPublico($fase)[1] : $tinte;

            $st = ['font' => ['size' => 10]];
            if ($bg) {
                $st['fill'] = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ltrim($bg, '#')]];
            }
            /* Fila EXCLUIDA: todo el texto en rojo. Es un indicador visual —
               el ítem salió del cuadro y no se va a comprar. */
            if ($excluido) $st['font']['color'] = ['rgb' => 'C00000'];

            $sh->getStyle($cel)->applyFromArray($st);

            if (in_array($k, Labels::NUMERIC_COLUMNS, true)) {
                $fmt = in_array($k, Labels::INT_COLUMNS, true) ? self::FMT_INT : self::FMT_NUM;
                $sh->getStyle($cel)->getNumberFormat()->setFormatCode($fmt);
                $sh->getStyle($cel)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            if ($k === 'CERT_SIGA' || $k === 'ESTADO_EJEC') {
                $sh->getStyle($cel)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
        }
    }

    /**
     * Fila de totales (Sub Total de bloque o TOTAL GENERAL).
     * Usa SUBTOTAL(109; rango): suma IGNORANDO las filas ocultas por el
     * autofiltro, así que al filtrar los totales se recalculan solos. El 109
     * además ignora los SUBTOTAL anidados, por lo que el TOTAL GENERAL puede
     * abarcar todo el rango sin contar dos veces los Sub Total de cada bloque.
     */
    private static function filaTotales($sh, int $f, array $cols, array $colDe,
                                        int $ini, int $fin, string $label,
                                        string $colorFuerte, string $colorClaro,
                                        bool $general = false): void
    {
        /* Columnas que llevan total. El resto de la fila queda vacío, salvo la
           primera celda, que lleva la etiqueta. */
        $suma = ['IMPORTE_PROG','IMPORTE_MOD','IMPORTE_EJEC','DIFERENCIA','DEVENGADO','SALDO_DEVENGAR'];

        $sh->setCellValue('A' . $f, $label);
        foreach ($cols as $i => $k) {
            $letra = Coordinate::stringFromColumnIndex($i + 1);
            $cel   = $letra . $f;
            if (in_array($k, $suma, true)) {
                $sh->setCellValue($cel, '=SUBTOTAL(109,' . $letra . $ini . ':' . $letra . $fin . ')');
                $sh->getStyle($cel)->getNumberFormat()->setFormatCode(self::FMT_NUM);
                $sh->getStyle($cel)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
        }

        $rango = 'A' . $f . ':' . Coordinate::stringFromColumnIndex(count($cols)) . $f;
        $sh->getStyle($rango)->applyFromArray([
            'font' => ['bold' => true, 'size' => 10,
                       'color' => ['rgb' => $general ? 'FFFFFF' : ltrim($colorFuerte, '#')]],
            'fill' => ['fillType' => Fill::FILL_SOLID,
                       'startColor' => ['rgb' => $general ? '1F2937' : ltrim($colorClaro, '#')]],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN,
                                    'color' => ['rgb' => ltrim($colorFuerte, '#')]]],
        ]);
    }

    /** Cabecera de bloque: banda de color con el nombre del grupo. */
    private static function estiloBloque($sh, string $rango, string $color): void
    {
        $sh->getStyle($rango)->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => ltrim($color, '#')]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
    }

    /** Agrupa las filas igual que el export HTML (misma clave, mismo orden). */
    private static function agruparFilas(array $rows, string $by): array
    {
        $g = [];
        foreach ($rows as $r) { $g[ExportService::grpKeyPublico($r, $by)][] = $r; }
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
}