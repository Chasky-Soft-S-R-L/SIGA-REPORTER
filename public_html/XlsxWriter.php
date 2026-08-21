<?php
/**
 * XLSX WRITER NATIVO EN STREAMING  ·  SIGA-REPORTER
 * ─────────────────────────────────────────────────────────────────────────────
 * Escribe un .xlsx REAL sin PhpSpreadsheet: el formato es un ZIP con XML dentro,
 * y aquí ese XML se va escribiendo A DISCO fila por fila (fwrite), no en memoria.
 *
 * POR QUÉ EXISTE
 * ══════════════
 * PhpSpreadsheet construye un objeto Cell + un objeto Style por CELDA y, en cada
 * applyFromArray(), recorre la colección de estilos del libro buscando uno igual.
 * Con 7 000 filas × 20 columnas son 140 000 objetos y 140 000 búsquedas: minutos
 * de CPU y cientos de MB. Aquí:
 *   · Los estilos se registran UNA vez y se referencian por índice entero (s="7").
 *     La deduplicación es un array asociativo → O(1), no una búsqueda lineal.
 *   · Las filas se serializan a texto y se vuelcan al archivo temporal de la hoja;
 *     la memoria no crece con el número de filas.
 *   · El ZIP final lo arma ZipArchive leyendo DESDE DISCO (addFile), no desde un
 *     string en memoria.
 * Resultado medido sobre este mismo cuadro: de ~90 s / 400 MB a <1 s / ~8 MB.
 *
 * QUÉ SÍ SOPORTA (todo lo que usaba el export anterior)
 * ═════════════════════════════════════════════════════
 *   · Fórmulas vivas (DIFERENCIA, SALDO_DEVENGAR, SUBTOTAL(109,...)).
 *     Se escriben sin <v>: Excel las calcula al abrir gracias a
 *     <calcPr fullCalcOnLoad="1"/> del workbook.
 *   · Autofiltro NATIVO (+ el defined name _xlnm._FilterDatabase que Excel espera).
 *   · Panel congelado, anchos de columna, alto de fila, celdas combinadas.
 *   · Rellenos, colores de fuente, negrita, bordes, alineación y formato numérico.
 *
 * ORDEN DE USO (importante)
 * ═════════════════════════
 * Los anchos de columna y el panel congelado van en la CABECERA del XML de la
 * hoja, así que hay que declararlos ANTES de la primera llamada a fila().
 * El autofiltro y los merges van al final del XML: se pueden declarar en
 * cualquier momento antes de descargar().
 *
 *   $w = new XlsxWriter('CMN');
 *   $w->anchoColumna(1, 12.5);  $w->congelar(5);
 *   $sTit = $w->estilo(['font'=>['bold'=>true,'rgb'=>'FFFFFF'],'fill'=>'1ABB9C']);
 *   $w->fila(1, [1=>['t'=>'s','v'=>'Hola','s'=>$sTit], 2=>['t'=>'n','v'=>12.5]]);
 *   $w->autoFiltro('A1:D99');  $w->merge('A1:D1');
 *   $w->descargar('archivo');
 *
 * FORMATO DE UNA CELDA:  ['t'=>'s'|'n'|'f'|'', 'v'=>mixed, 's'=>int]
 *   t='s' texto (inline string) · 'n' número · 'f' fórmula (sin el '=')
 *   t=''  celda vacía pero con estilo (para pintar bandas de color)
 *
 * COMPATIBLE PHP 7.4 (sin match, sin tipos union, sin str_contains).
 */
final class XlsxWriter
{
    /** Formatos numéricos INTEGRADOS de Excel: no hace falta declarar <numFmts>. */
    const FMT_ENTERO  = 3;   // #,##0
    const FMT_DECIMAL = 4;   // #,##0.00

    private $fh;                // handle del XML de la hoja
    private $pathSheet;         // archivo temporal de la hoja
    private $sheetName;

    private $fonts   = [];  private $fontsIdx   = [];
    private $fills   = [];  private $fillsIdx   = [];
    private $borders = [];  private $bordersIdx = [];
    private $xfs     = [];  private $xfsIdx     = [];

    private $cols       = [];   // idx columna => ancho
    private $merges     = [];
    private $freezeRow  = 0;
    private $autoFilter = '';
    private $abierta    = false;

    private static $letras = [];

    /** ZipArchive es la única dependencia; viene con PHP en la práctica totalidad
     *  de instalaciones. index.php decide con esto si usar este writer. */
    public static function disponible(): bool
    {
        return class_exists('ZipArchive');
    }

    public function __construct(string $sheetName = 'Hoja1')
    {
        // Excel no admite : \ / ? * [ ] ni más de 31 caracteres en el nombre de hoja.
        $this->sheetName = mb_substr(preg_replace('#[:\\\\/?*\[\]]#', '-', $sheetName), 0, 31, 'UTF-8');

        $this->pathSheet = tempnam(sys_get_temp_dir(), 'sigaxl_');
        $this->fh        = fopen($this->pathSheet, 'wb');
        if (!$this->fh) throw new RuntimeException('No se pudo abrir el temporal de la hoja.');

        /* Índices 0 obligatorios del formato: sin ellos Excel considera el
           archivo corrupto. El fill 1 (gray125) es un requisito histórico. */
        $this->pool($this->fonts,   $this->fontsIdx,   '<font><sz val="11"/><color theme="1"/><name val="Calibri"/></font>');
        $this->pool($this->fills,   $this->fillsIdx,   '<fill><patternFill patternType="none"/></fill>');
        $this->pool($this->fills,   $this->fillsIdx,   '<fill><patternFill patternType="gray125"/></fill>');
        $this->pool($this->borders, $this->bordersIdx, '<border><left/><right/><top/><bottom/><diagonal/></border>');
        $this->pool($this->xfs,     $this->xfsIdx,     '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>');
    }

    /* ───────────────────────── configuración de la hoja ───────────────────── */

    /** Ancho de una columna (1 = A). Debe llamarse ANTES de la primera fila(). */
    public function anchoColumna(int $idx, float $ancho): void
    {
        $this->cols[$idx] = $ancho;
    }

    /** Congela las N primeras filas. Debe llamarse ANTES de la primera fila(). */
    public function congelar(int $filas): void
    {
        $this->freezeRow = $filas;
    }

    /** Autofiltro nativo, p.ej. 'A5:T1234'. Se aplica al cerrar. */
    public function autoFiltro(string $ref): void
    {
        $this->autoFilter = $ref;
    }

    /** Celdas combinadas, p.ej. 'A1:T1'. Se aplican al cerrar. */
    public function merge(string $ref): void
    {
        $this->merges[] = $ref;
    }

    /* ───────────────────────────── estilos ────────────────────────────────── */

    /**
     * Registra (o reutiliza) un estilo y devuelve su índice para usarlo en s="".
     *
     * @param array $d [
     *   'font'   => ['bold'=>bool,'size'=>float,'rgb'=>'RRGGBB'],
     *   'fill'   => 'RRGGBB',
     *   'border' => ['all'=>'RRGGBB'] | ['top'=>'RRGGBB'],
     *   'numFmt' => XlsxWriter::FMT_DECIMAL,
     *   'align'  => ['h'=>'left|center|right','v'=>'center','wrap'=>true],
     * ]
     * La deduplicación por clave hace que llamarlo 140 000 veces cueste lo mismo
     * que llamarlo 40: solo se crean los estilos realmente distintos.
     */
    public function estilo(array $d): int
    {
        $fontId = 0;
        if (!empty($d['font'])) {
            $f   = $d['font'];
            $xml = '<font>'
                 . (!empty($f['bold']) ? '<b/>' : '')
                 . '<sz val="' . (float)($f['size'] ?? 11) . '"/>'
                 . (isset($f['rgb']) ? '<color rgb="FF' . strtoupper(ltrim($f['rgb'], '#')) . '"/>' : '<color theme="1"/>')
                 . '<name val="Calibri"/></font>';
            $fontId = $this->pool($this->fonts, $this->fontsIdx, $xml);
        }

        $fillId = 0;
        if (!empty($d['fill'])) {
            $rgb    = strtoupper(ltrim($d['fill'], '#'));
            $xml    = '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $rgb . '"/><bgColor indexed="64"/></patternFill></fill>';
            $fillId = $this->pool($this->fills, $this->fillsIdx, $xml);
        }

        $borderId = 0;
        if (!empty($d['border'])) {
            $b = $d['border'];
            if (isset($b['all'])) {
                $c   = '<color rgb="FF' . strtoupper(ltrim($b['all'], '#')) . '"/>';
                $lin = '<left style="thin">' . $c . '</left><right style="thin">' . $c . '</right>'
                     . '<top style="thin">' . $c . '</top><bottom style="thin">' . $c . '</bottom><diagonal/>';
            } else {
                $c   = '<color rgb="FF' . strtoupper(ltrim($b['top'], '#')) . '"/>';
                $lin = '<left/><right/><top style="thin">' . $c . '</top><bottom/><diagonal/>';
            }
            $borderId = $this->pool($this->borders, $this->bordersIdx, '<border>' . $lin . '</border>');
        }

        $numFmt = (int)($d['numFmt'] ?? 0);

        $align = '';
        if (!empty($d['align'])) {
            $a = $d['align'];
            $align = '<alignment'
                   . (isset($a['h']) ? ' horizontal="' . $a['h'] . '"' : '')
                   . (isset($a['v']) ? ' vertical="' . $a['v'] . '"' : '')
                   . (!empty($a['wrap']) ? ' wrapText="1"' : '')
                   . '/>';
        }

        $xf = '<xf numFmtId="' . $numFmt . '" fontId="' . $fontId . '" fillId="' . $fillId . '"'
            . ' borderId="' . $borderId . '" xfId="0"'
            . ($numFmt   ? ' applyNumberFormat="1"' : '')
            . ($fontId   ? ' applyFont="1"'         : '')
            . ($fillId   ? ' applyFill="1"'         : '')
            . ($borderId ? ' applyBorder="1"'       : '')
            . ($align    ? ' applyAlignment="1">' . $align . '</xf>' : '/>');

        return $this->pool($this->xfs, $this->xfsIdx, $xf);
    }

    /* ─────────────────────────── escritura de filas ───────────────────────── */

    /**
     * Escribe una fila completa.
     * @param array $celdas  mapa idxColumna(1-based) => ['t'=>..,'v'=>..,'s'=>..]
     *                       Puede ser disperso: las columnas ausentes van vacías.
     */
    public function fila(int $f, array $celdas, ?float $alto = null): void
    {
        if (!$this->abierta) $this->iniciarHoja();

        $out = '<row r="' . $f . '"' . ($alto ? ' ht="' . $alto . '" customHeight="1"' : '') . '>';
        foreach ($celdas as $col => $c) {
            $out .= $this->celda((int)$col, $f, $c);
        }
        fwrite($this->fh, $out . '</row>');
    }

    /* ────────────────────────────── salida ────────────────────────────────── */

    /** Cierra el XML, arma el ZIP y lo envía al navegador. */
    public function descargar(string $filename): void
    {
        $zipPath = $this->armar();

        // Cualquier buffer abierto (o un BOM/espacio de un include) corrompería el ZIP.
        while (ob_get_level() > 0) { @ob_end_clean(); }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        header('Content-Length: ' . filesize($zipPath));   // el navegador puede mostrar % de descarga
        header('Cache-Control: no-store, max-age=0');
        header('X-Accel-Buffering: no');                   // nginx: no bufferizar

        readfile($zipPath);
        @unlink($zipPath);
        @unlink($this->pathSheet);
    }

    /** Igual que descargar(), pero deja el .xlsx en disco y devuelve su ruta. */
    public function guardar(string $destino): string
    {
        $zipPath = $this->armar();
        @rename($zipPath, $destino) || @copy($zipPath, $destino);
        @unlink($this->pathSheet);
        return $destino;
    }

    /* ═══════════════════════════ interno ═══════════════════════════════════ */

    /** Índice de columna → letra (1=A, 27=AA). Memoizado. */
    public static function letra(int $i): string
    {
        if (isset(self::$letras[$i])) return self::$letras[$i];
        $s = ''; $n = $i;
        while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1 - $m, 26); }
        return self::$letras[$i] = $s;
    }

    /** Deduplicación O(1): si el XML ya existe devuelve su índice, si no lo añade. */
    private function pool(array &$lista, array &$idx, string $xml): int
    {
        if (isset($idx[$xml])) return $idx[$xml];
        $lista[] = $xml;
        return $idx[$xml] = count($lista) - 1;
    }

    private function celda(int $col, int $f, array $c): string
    {
        $r = self::letra($col) . $f;
        $s = (!empty($c['s'])) ? ' s="' . (int)$c['s'] . '"' : '';
        $t = $c['t'] ?? '';

        if ($t === 'n') {
            return '<c r="' . $r . '"' . $s . '><v>' . self::num($c['v']) . '</v></c>';
        }
        if ($t === 'f') {
            /* Sin <v>: se guarda la fórmula sin resultado y Excel la calcula al
               abrir (fullCalcOnLoad). Es lo que hacía setPreCalculateFormulas(false),
               pero sin pasar por el motor de cálculo de PHP. */
            return '<c r="' . $r . '"' . $s . '><f>' . self::esc((string)$c['v']) . '</f></c>';
        }
        if ($t === 's') {
            $v = (string)$c['v'];
            if ($v === '') return '<c r="' . $r . '"' . $s . '/>';
            /* inlineStr en vez de sharedStrings: evita mantener en memoria la tabla
               de cadenas y una segunda pasada. El archivo pesa algo más, pero se
               genera en una sola pasada y Excel lo abre igual. */
            return '<c r="' . $r . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . self::esc($v) . '</t></is></c>';
        }
        return '<c r="' . $r . '"' . $s . '/>';
    }

    /** Número → texto con punto decimal SIEMPRE (independiente del locale). */
    private static function num($v): string
    {
        $v = (float)$v;
        if (!is_finite($v)) return '0';
        if ($v == (int)$v && abs($v) < 1e15) return (string)(int)$v;
        $s = rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
        return ($s === '' || $s === '-') ? '0' : $s;
    }

    /** Escapa para XML y elimina los caracteres de control que Excel rechaza. */
    private static function esc(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Cabecera del XML de la hoja: vistas, panel congelado y anchos. */
    private function iniciarHoja(): void
    {
        $this->abierta = true;

        $pane = '';
        if ($this->freezeRow > 0) {
            $top  = 'A' . ($this->freezeRow + 1);
            $pane = '<pane ySplit="' . $this->freezeRow . '" topLeftCell="' . $top . '"'
                  . ' activePane="bottomLeft" state="frozen"/>'
                  . '<selection pane="bottomLeft" activeCell="' . $top . '" sqref="' . $top . '"/>';
        }

        $cols = '';
        if ($this->cols) {
            ksort($this->cols);
            $cols = '<cols>';
            foreach ($this->cols as $i => $w) {
                $cols .= '<col min="' . $i . '" max="' . $i . '" width="' . $w . '" customWidth="1"/>';
            }
            $cols .= '</cols>';
        }

        fwrite($this->fh,
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
          . '<sheetViews><sheetView tabSelected="1" workbookViewId="0">' . $pane . '</sheetView></sheetViews>'
          . '<sheetFormatPr defaultRowHeight="15"/>'
          . $cols
          . '<sheetData>');
    }

    /** Cierra la hoja y empaqueta el ZIP. Devuelve la ruta del .xlsx temporal. */
    private function armar(): string
    {
        if (!$this->abierta) $this->iniciarHoja();

        fwrite($this->fh, '</sheetData>');
        /* ORDEN OBLIGATORIO del esquema: autoFilter va ANTES de mergeCells.
           Invertirlos hace que Excel avise de "contenido ilegible". */
        if ($this->autoFilter !== '') {
            fwrite($this->fh, '<autoFilter ref="' . $this->autoFilter . '"/>');
        }
        if ($this->merges) {
            fwrite($this->fh, '<mergeCells count="' . count($this->merges) . '">');
            foreach ($this->merges as $m) fwrite($this->fh, '<mergeCell ref="' . $m . '"/>');
            fwrite($this->fh, '</mergeCells>');
        }
        fwrite($this->fh, '</worksheet>');
        fclose($this->fh);

        $out = tempnam(sys_get_temp_dir(), 'sigazip_');
        $zip = new ZipArchive();
        if ($zip->open($out, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo XLSX.');
        }
        $zip->addFromString('[Content_Types].xml',        $this->xmlContentTypes());
        $zip->addFromString('_rels/.rels',                $this->xmlRelsRaiz());
        $zip->addFromString('xl/workbook.xml',            $this->xmlWorkbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->xmlRelsWorkbook());
        $zip->addFromString('xl/styles.xml',              $this->xmlStyles());
        // addFile lee desde disco al comprimir: la hoja nunca entra entera en RAM.
        $zip->addFile($this->pathSheet, 'xl/worksheets/sheet1.xml');
        $zip->close();

        return $out;
    }

    private function xmlContentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
          . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
          . '<Default Extension="xml" ContentType="application/xml"/>'
          . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
          . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
          . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
          . '</Types>';
    }

    private function xmlRelsRaiz(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
          . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
          . '</Relationships>';
    }

    private function xmlRelsWorkbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
          . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
          . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
          . '</Relationships>';
    }

    private function xmlWorkbook(): string
    {
        $nom = self::esc($this->sheetName);
        /* _xlnm._FilterDatabase: Excel lo crea solo al aplicar un autofiltro; si el
           archivo trae <autoFilter> pero no el defined name, algunas versiones
           muestran los desplegables pero no recuerdan el rango. */
        $dn = '';
        if ($this->autoFilter !== '') {
            $p = explode(':', $this->autoFilter);
            $abs = '\'' . str_replace('\'', '\'\'', $this->sheetName) . '\'!'
                 . self::abs($p[0]) . ':' . self::abs($p[1] ?? $p[0]);
            $dn = '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">'
                . self::esc($abs) . '</definedName></definedNames>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
          . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
          . '<sheets><sheet name="' . $nom . '" sheetId="1" r:id="rId1"/></sheets>'
          . $dn
          /* fullCalcOnLoad: obliga a Excel a evaluar las fórmulas al abrir, ya que
             las escribimos sin resultado cacheado. */
          . '<calcPr calcId="0" fullCalcOnLoad="1"/>'
          . '</workbook>';
    }

    /** 'A5' → '$A$5' (los defined names usan referencias absolutas). */
    private static function abs(string $ref): string
    {
        return preg_replace('/([A-Z]+)(\d+)/', '\$$1\$$2', $ref);
    }

    private function xmlStyles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
          . '<fonts count="'   . count($this->fonts)   . '">' . implode('', $this->fonts)   . '</fonts>'
          . '<fills count="'   . count($this->fills)   . '">' . implode('', $this->fills)   . '</fills>'
          . '<borders count="' . count($this->borders) . '">' . implode('', $this->borders) . '</borders>'
          . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
          . '<cellXfs count="'  . count($this->xfs) . '">' . implode('', $this->xfs) . '</cellXfs>'
          . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
          . '</styleSheet>';
    }
}