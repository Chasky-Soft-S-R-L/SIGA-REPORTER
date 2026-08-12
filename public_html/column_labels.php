<?php
/**
 * column_labels.php · Única fuente de verdad para columnas del módulo CMN:
 * nombre visible, ancho, tipo numérico/entero y demás textos de la UI
 * (fases, agrupado, orden, estado CMN).
 *
 * ORIGEN DE LOS DATOS
 * --------------------
 * Las 39 columnas reales de COLUMNS/NUMERIC_COLUMNS/INT_COLUMNS son una copia
 * EXACTA (mismas claves, mismos labels) de lo que antes vivía hardcodeado en
 * ExportService::HEADERS / ExportService::NUM / ExportService::INT.
 * ExportService.php lee de aquí (ver el require_once en ese archivo) en vez
 * de mantener su propia copia — así Excel, PDF y la pantalla web nunca
 * pueden desincronizarse.
 *
 * Además de las 39 reales hay 2 columnas VIRTUALES que solo existen en la
 * pantalla (no en el Excel/PDF, no vienen del SQL): __FASE y __CMN.
 * 39 + 2 = 41 columnas totales, tal como se ven en el selector de campos.
 *
 * ORDEN DE COLUMNAS: el orden en que aparecen aquí abajo en COLUMNS es el
 * mismo orden en que se ven las columnas TANTO en la tabla web COMO en el
 * Excel — ExportService itera este array tal cual, y el frontend lee
 * Object.keys() del mismo objeto (PHP y JS conservan el orden de inserción
 * de las claves). Para reordenar columnas: reordena las líneas de COLUMNS.
 *
 * PARA RENOMBRAR O CAMBIAR EL ANCHO DE UNA COLUMNA: edita solo este archivo.
 */

final class Labels
{
    /**
     * Columnas reales del cuadro, EN EL ORDEN EXACTO en que deben verse en
     * pantalla y en el Excel/PDF. clave = nombre de columna en la fila de
     * datos (viene del SQL) · valor = label visible.
     */
    public const COLUMNS = [

        'CCOSTO_COD'          => 'CCOSTO_COD',
        'CCOSTO_NOMBRE'       => 'CCOSTO_NOMBRE',
         'CERT_SIGA'           => 'CERT',
        'ESTADO_CMN'          => 'ESTADO CMN',
        'ESTADO_FASE'         => 'FASE',
        // 'NRO_LINEAS'          => 'N° LÍNEAS',
        'PROGR_ANO_1'         => 'PROGR_ANO_1',
        'FF'                  => 'FF',
       // 'FF_NOMBRE'           => 'FUENTE FINANCIAMIENTO',
        'RB'                  => 'RB',
        'META'                => 'META',
        'GENERICA'            => 'GENÉRICA',
        'CLASIF_COD'          => 'CLASIF_COD',
        'CLASIF_NOMBRE'       => 'CLASIFICADOR',
        'TIPO_BIEN'           => 'TIPO BIEN',
        'TIPO_USO'            => 'TIPO USO',
        'ACTIV_OPERAT_COD'    => 'ACTIV_OPERAT_COD',
        'ACTIV_OPERAT_NOMBRE' => 'ACTIVIDAD OPERATIVA',
        'GRUPO_BIEN'          => 'GRUPO',
        'CLASE_BIEN'          => 'CLASE',
        'FAMILIA_BIEN'        => 'FAMILIA',
        'ITEM_BIEN'           => 'ITEM',
        'COD_PRODUCTO'        => 'CÓDIGO ITEM',
        'NOMBRE_ITEM'         => 'NOMBRE ITEM',
        'UNIDAD_MEDIDA'       => 'UNIDAD MEDIDA',
        'CANTIDAD_PROG'       => 'CANTIDAD',
        'PRECIO_UNIT_PROG'    => 'PRECIO UNITARIO',
        'IMPORTE_PROG'        => 'IMPORTE CMN PROGRAMADO',
        'CANTIDAD_MOD'        => 'CANTIDAD',
        'PRECIO_UNIT_MOD'     => 'PRECIO UNITARIO',
        'IMPORTE_MOD'         => 'IMPORTE CMN MODIFICADO',
        'CANTIDAD_EJEC'       => 'CANTIDAD',
        'PRECIO_UNIT_EJEC'    => 'PRECIO UNITARIO',
        'IMPORTE_EJEC'        => 'IMPORTE CMN EJECUTADO',
        'DIFERENCIA'          => 'SALDO PROG-EJEC',
        'ESTADO_EJEC'         => 'ESTADO',
        'RESPONSABLE'         => 'RESPONSABLE',
        'DEVENGADO'           => 'DEVENGADO',
        'SALDO_DEVENGAR'      => 'SALDO POR DEVENGAR',
        'ESTADO_ORDEN'        => 'DATOS EJECUCION',
    ];

    /** Columnas que sólo existen en pantalla (no vienen del SQL, no van al Excel/PDF). */
    public const VIRTUAL_COLUMNS = [
        '__FASE' => 'Indicador de fase',
        // '__CMN'  => 'Estado CMN',
    ];

    /** Columnas enteras (sin decimales). Copia exacta de ExportService::INT. */
    public const INT_COLUMNS = ['NRO_LINEAS'];

    /** Columnas numéricas (formato/alineación). Copia exacta de ExportService::NUM. */
    public const NUMERIC_COLUMNS = [
        'NRO_LINEAS',
        'CANTIDAD_PROG', 'PRECIO_UNIT_PROG', 'IMPORTE_PROG',
        'CANTIDAD_MOD',  'PRECIO_UNIT_MOD',  'IMPORTE_MOD',
        'CANTIDAD_EJEC', 'PRECIO_UNIT_EJEC', 'IMPORTE_EJEC',
        'DEVENGADO', 'DIFERENCIA', 'SALDO_DEVENGAR',
    ];

    /**
     * Ancho de cada columna en la TABLA WEB (px). Excel/PDF calculan su
     * propio ancho de celda por su cuenta (HTML de tabla / mso-number-format)
     * y no usan este mapa; esto es solo para el <th>/<td> del navegador.
     * El orden aquí no afecta nada (es un lookup por clave); se mantiene en
     * el mismo orden que COLUMNS solo por prolijidad al leer el archivo.
     */
    public const WIDTHS = [
        'CERT_SIGA'           => 60,
        'CCOSTO_COD'          => 90,
        'CCOSTO_NOMBRE'       => 190,
        'ESTADO_CMN'          => 100,
        'ESTADO_FASE'         => 110,
        'NRO_LINEAS'          => 70,
        'PROGR_ANO_1'         => 90,
        'FF'                  => 30,
        'FF_NOMBRE'           => 170,
        'RB'                  => 35,
        'META'                => 50,
        'GENERICA'            => 75,
        'CLASIF_COD'          => 90,
        'CLASIF_NOMBRE'       => 220,
        'TIPO_BIEN'           => 60,
        'TIPO_USO'            => 60,
        'ACTIV_OPERAT_COD'    => 110,
        'ACTIV_OPERAT_NOMBRE' => 200,
        'GRUPO_BIEN'          => 60,
        'CLASE_BIEN'          => 60,
        'FAMILIA_BIEN'        => 65,
        'ITEM_BIEN'           => 80,
        'COD_PRODUCTO'        => 110,
        'NOMBRE_ITEM'         => 260,
        'UNIDAD_MEDIDA'       => 90,
        'CANTIDAD_PROG'       => 80,
        'PRECIO_UNIT_PROG'    => 90,
        'IMPORTE_PROG'        => 130,
        'CANTIDAD_MOD'        => 80,
        'PRECIO_UNIT_MOD'     => 90,
        'IMPORTE_MOD'         => 130,
        'CANTIDAD_EJEC'       => 80,
        'PRECIO_UNIT_EJEC'    => 90,
        'IMPORTE_EJEC'        => 130,
        'DIFERENCIA'          => 110,
        'ESTADO_EJEC'         => 100,
        'RESPONSABLE'         => 150,
        'DEVENGADO'           => 110,
        'SALDO_DEVENGAR'      => 130,
        'ESTADO_ORDEN'        => 170,
        // virtuales
        '__FASE'              => 30,
        // '__CMN'               => 150,
    ];

    /** Las 3 fases del gasto: clave interna → texto mostrado en chip/kanban/grupo. */
    public const FASES = [
        'PROGRAMADO' => 'PROGRAMADO',
        'MODIFICADO' => 'MODIFICADO',
        'EJECUTADO'  => 'EJECUTADO',
    ];

    /** Campos por los que se puede agrupar la tabla → texto largo (panel de campos). */
    public const GROUP_BY = [
                         'ACT_FF_RB_META_USO' => 'Actividad + FF + Rubro + Meta + Tipo uso',
        'ACTIV_OPERAT_COD' => 'Actividad operativa',
        'CLASIF_COD'       => 'Clasificador',
        'META'             => 'Meta',
        'GENERICA'         => 'Genérica',
        'FF'               => 'Fuente financiamiento',
        'ESTADO_FASE'      => 'Fase (estado)',
        'CLASIF_FF'        => 'Clasificador + Fuente',
          'ACT_CLASIF_FF'    => 'Actividad + Clasificador + Fuente',

    ];

    /** Mismos campos, texto corto para el <select id="groupBy"> ("por X"). */
    public const GROUP_BY_SELECT = [
               'ACT_FF_RB_META_USO' => 'por Act. + FF + Meta + Uso',
        'ACTIV_OPERAT_COD' => 'por Actividad',
        'CLASIF_COD'       => 'por Clasificador',
        'META'             => 'por Meta',
        'GENERICA'         => 'por Genérica',
        'FF'               => 'por Fuente Financ.',
        'ESTADO_FASE'      => 'por Fase',
        'CLASIF_FF'        => 'por Clasif. + Fuente',
         'ACT_CLASIF_FF'    => 'por Act. + Clasif. + FF',
  
    ];

    /** Opciones del <select id="sort"> · clave = valor enviado al servidor. */
    public const SORT_OPTIONS = [
        'mod_desc' => 'Mayor importe',
        'mod_asc'  => 'Menor importe',
        'item_asc' => 'Nombre A-Z',
        'act_item' => 'Actividad + código ítem',
        'clasif'   => 'Clasificador + ítem',
    ];

    /**
     * Estado de línea del CMN: clave → [texto ABREVIADO, tooltip].
     *
     * ¡OJO CON LAS CLAVES! Deben coincidir EXACTAMENTE con el texto que emite
     * el SQL en la columna ESTADO_CMN (ver el STUFF de CmnQuery::innerSql).
     * El SQL emite: PROGRAMADO · INCLUIDO · EXCLUIDO · MODIFICADO.
     *
     * Antes aquí figuraba 'ANTIGUO' en vez de 'PROGRAMADO'. Como esa clave
     * nunca llega, el mapeo no encontraba coincidencia y la celda mostraba el
     * texto crudo completo ("PROGRAMADO, MOD") en lugar de la abreviatura
     * ("PRG, -MOD"). Se conserva 'ANTIGUO' como alias por compatibilidad, por
     * si algún dato viejo o algún otro reporte todavía lo usa.
     *
     * Estas abreviaturas las consumen los tres lados a la vez:
     *   · la tabla web (cmnBadge / cmnTexto en index.php)
     *   · el Excel     (ExportService::cmnAbrev)
     *   · el grafo SVG (cmnBadgeSvg en index.php)
     */
    public const CMN_ESTADO = [
        'PROGRAMADO' => ['PRG',  'Ya venía en el cuadro base aprobado'],
        'ANTIGUO'    => ['PRG',  'Ya venía en el cuadro base aprobado (alias antiguo)'],
        'INCLUIDO'   => ['INC',  'Añadido después por modificación (I)'],
        'EXCLUIDO'   => ['EXC',  'Alguna línea del ítem fue retirada (E)'],
        'MODIFICADO' => ['-MOD', 'Cambió respecto del original: en servicios el monto, en bienes la cantidad'],
    ];

    /** Textos del placeholder de los filtros multi-select. */
    public const MSF = [
        'meta'   => ['todos' => 'Todas las metas',   'singular' => 'meta'],
        'act'    => ['todos' => 'Toda actividad',    'singular' => 'act'],
        'clasif' => ['todos' => 'Todo clasificador', 'singular' => 'clasif'],
        'fuente' => ['todos' => 'Toda fuente',        'singular' => 'fuente'],
    ];

    /** Todo lo anterior, listo para volcar a JS en un solo <script> tag. */
    public static function toJs(): string
    {
        $payload = [
            'columns'        => self::COLUMNS,
            'virtualColumns' => self::VIRTUAL_COLUMNS,
            'numericColumns' => array_values(self::NUMERIC_COLUMNS),
            'intColumns'     => array_values(self::INT_COLUMNS),
            'widths'         => self::WIDTHS,
            'fases'          => self::FASES,
            'groupBy'        => self::GROUP_BY,
            'groupBySelect'  => self::GROUP_BY_SELECT,
            'sortOptions'    => self::SORT_OPTIONS,
            'cmnEstado'      => self::CMN_ESTADO,
            'msf'            => self::MSF,
        ];
        return json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        );
    }
}