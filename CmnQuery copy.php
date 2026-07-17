<?php
/**
 * CAPA QUERY  ·  SIGA-REPORTER
 * Acceso de solo lectura a SIGA_104. Reutiliza la consulta del CMN ya validada.
 * Sin lógica de dominio: solo trae filas.
 */
class CmnQuery
{
    private PDO $db;

    public function __construct(string $server, string $database, string $user = '', string $pass = '')
    {
        // Autenticación Windows (trusted) si user/pass van vacíos.
        $dsn = "sqlsrv:Server={$server};Database={$database};TrustServerCertificate=1";
        $this->db = new PDO($dsn, $user ?: null, $pass ?: null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /** Año de ejecución vigente (el más reciente que programa $anioProg). */
    public function ejecYear(int $anioProg, int $secEjec): ?int
    {
        $st = $this->db->prepare(
            "SELECT MAX(ANNO_EJEC) FROM SIG_CUADRO_MODIFICADO_DET
             WHERE ANNO_PROG = ? AND SEC_EJEC = ?"
        );
        $st->execute([$anioProg, $secEjec]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null) ? null : (int)$v;
    }

    /** Lista de centros de costo con datos, para el combo de navegación. */
    public function centros(int $anioProg, int $anioEjec, int $secEjec): array
    {
        $st = $this->db->prepare(
            "SELECT DISTINCT D.CENTRO_COSTO AS cod, cc.NOMBRE_DEPEND AS nombre
             FROM   SIG_CUADRO_MODIFICADO_DET D
             JOIN   SIG_CENTRO_COSTO cc
                    ON cc.SEC_EJEC=D.SEC_EJEC AND cc.ANO_EJE=D.ANNO_EJEC
                   AND cc.CENTRO_COSTO=D.CENTRO_COSTO
             WHERE  D.ANNO_PROG=? AND D.ANNO_EJEC=? AND D.SEC_EJEC=?
               AND  D.ESTADO NOT IN ('E','ET')
             ORDER BY D.CENTRO_COSTO"
        );
        $st->execute([$anioProg, $anioEjec, $secEjec]);
        return $st->fetchAll();
    }

    /** SQL interno del reporte (sin ORDER BY ni paginado), reutilizable. */
    private function innerSql(bool $withCC): string
    {
        $filtroCC = $withCC ? " AND D.CENTRO_COSTO = :ccosto " : "";
        return "
        SELECT
            D.ANNO_PROG                                             AS PROGR_ANO_1,
            ff.FUENTE_FINANC_AGREGADA                               AS FF,
            D.FUENTE_FINANC                                         AS RB,
            D.TIPO_BIEN                                             AS TIPO_BIEN,
            D.CENTRO_COSTO                                          AS CCOSTO_COD,
            cc.NOMBRE_DEPEND                                        AS CCOSTO_NOMBRE,
            D.SEC_FUNC                                              AS META,
            LEFT(REPLACE(REPLACE(D.CLASIFICADOR,'.',''),' ',''),2)  AS GENERICA,
            D.CLASIFICADOR                                          AS CLASIF_COD,
            D.TIPO_USO                                              AS TIPO_USO,
            'C' + RIGHT('0000' + CONVERT(VARCHAR,D.CODIGO_TAREA),4) AS ACTIV_OPERAT_COD,
            tar.nombre_tarea                                        AS ACTIV_OPERAT_NOMBRE,
            D.GRUPO_BIEN, D.CLASE_BIEN, D.FAMILIA_BIEN, D.ITEM_BIEN,
            cat.NOMBRE_ITEM                                         AS NOMBRE_ITEM,
            um.NOMBRE                                               AS UNIDAD_MEDIDA,
            ISNULL(ori.CANT_TOTAL,  D.CANT_TOTAL)                  AS CANTIDAD_PROG,
            ISNULL(ori.PRECIO_UNIT, D.PRECIO_UNIT)                 AS PRECIO_UNIT_PROG,
            ISNULL(ori.MNTO_TOTAL,  D.MNTO_TOTAL)                  AS IMPORTE_PROG,
            D.CANT_TOTAL                                            AS CANTIDAD_MOD,
            D.PRECIO_UNIT                                           AS PRECIO_UNIT_MOD,
            D.MNTO_TOTAL                                            AS IMPORTE_MOD,
            COALESCE(ej.ORDENES,
                     CASE WHEN cert.NRO_CERTIFICA IS NOT NULL
                          THEN 'CERTIFICADO · Cert ' + CONVERT(VARCHAR, cert.NRO_CERTIFICA)
                          ELSE 'PENDIENTE' END)                 AS ESTADO_ORDEN,
            ''                                                      AS RESPONSABLE,
            ISNULL(dev.CANT_DEV, 0)                                 AS CANTIDAD_EJEC,
            CASE WHEN ISNULL(dev.CANT_DEV,0) > 0 THEN dev.MNTO_DEV / dev.CANT_DEV ELSE 0 END AS PRECIO_UNIT_EJEC,
            ISNULL(dev.MNTO_DEV, 0)                                 AS IMPORTE_EJEC,
            D.MNTO_TOTAL - ISNULL(dev.MNTO_DEV, 0)                  AS DIFERENCIA
        FROM      SIG_CUADRO_MODIFICADO_DET D
        JOIN      SIG_CENTRO_COSTO cc
                  ON cc.SEC_EJEC=D.SEC_EJEC AND cc.ANO_EJE=D.ANNO_EJEC AND cc.CENTRO_COSTO=D.CENTRO_COSTO
        LEFT JOIN CATALOGO_BIEN_SERV cat
                  ON cat.SEC_EJEC=D.SEC_EJEC AND cat.TIPO_BIEN=D.TIPO_BIEN AND cat.GRUPO_BIEN=D.GRUPO_BIEN
                 AND cat.CLASE_BIEN=D.CLASE_BIEN AND cat.FAMILIA_BIEN=D.FAMILIA_BIEN AND cat.ITEM_BIEN=D.ITEM_BIEN
        LEFT JOIN UNIDAD_MEDIDA um ON um.UNIDAD_MEDIDA=D.UNIDAD_MEDIDA
        LEFT JOIN FUENTE_FINANC ff ON ff.ANO_EJE=D.ANNO_EJEC AND ff.FUENTE_FINANC=D.FUENTE_FINANC
        LEFT JOIN SIG_CENTRO_COSTO_TAREA tar
                  ON tar.sec_ejec=D.SEC_EJEC AND tar.ano_eje=D.ANNO_EJEC AND tar.centro_costo=D.CENTRO_COSTO
                 AND tar.codigo_tarea=D.CODIGO_TAREA AND tar.tipo_tarea=D.TIPO_TAREA AND tar.nivel_tarea=D.NIVEL_TAREA
        OUTER APPLY (
            /* CUADRO ORIGINAL (necesidad): monto programado ANTES de modificar.
               TOP 1 evita multiplicar filas si el item estuviera en varias fases. */
            SELECT TOP 1 n.PRECIO_UNIT, n.CANT_TOTAL, n.MNTO_TOTAL
            FROM   SIG_CUADRO_NECESIDAD_DET n
            WHERE  n.SEC_EJEC=D.SEC_EJEC AND n.ANO_EJE=D.ANNO_EJEC
              AND  n.CENTRO_COSTO=D.CENTRO_COSTO AND n.TIPO_BIEN=D.TIPO_BIEN
              AND  n.GRUPO_BIEN=D.GRUPO_BIEN AND n.CLASE_BIEN=D.CLASE_BIEN
              AND  n.FAMILIA_BIEN=D.FAMILIA_BIEN AND n.ITEM_BIEN=D.ITEM_BIEN
            ORDER BY n.MNTO_TOTAL DESC
        ) ori
        OUTER APPLY (
            /* CERTIFICACIÓN POR CADENA (meta + clasificador), sin pasar por la orden.
               Un item está certificado si su meta+clasificador tiene una
               certificación no anulada. TOP 1 = la más reciente. */
            SELECT TOP 1 cp.NRO_CERTIFICA
            FROM   SIG_CERTIFICACION_PPTO cp
            JOIN   SIG_CERTIFICACION c
                   ON c.ANO_EJE=cp.ANO_EJE AND c.SEC_EJEC=cp.SEC_EJEC
                  AND c.NRO_CERTIFICA=cp.NRO_CERTIFICA
            WHERE  cp.SEC_EJEC=D.SEC_EJEC AND cp.ANO_EJE=D.ANNO_EJEC
              AND  cp.SEC_FUNC=D.SEC_FUNC AND cp.CLASIFICADOR=D.CLASIFICADOR
              AND  ISNULL(c.ANULADO,0)=0
            ORDER BY cp.NRO_CERTIFICA DESC
        ) cert
        LEFT JOIN (
            /* PRECIO REAL Y ORDENES POR ITEM (a nivel de todo el año de ejecución).
               - PREC_REAL = promedio ponderado real de compra del item (SUM soles / SUM cant)
               - ORDENES   = lista de O/C u O/S donde se compró ese item
               El cruce es por ITEM + AÑO (el precio es propio del item, no del cuadro). */
            SELECT
                k.SEC_EJEC, k.ANO_EJE,
                k.TIPO_BIEN, k.GRUPO_BIEN, k.CLASE_BIEN, k.FAMILIA_BIEN, k.ITEM_BIEN,
                k.PREC_REAL,
                STUFF((
                    SELECT DISTINCT ', ' + CASE WHEN oa2.TIPO_BIEN='B' THEN 'OC ' ELSE 'OS ' END
                           + CONVERT(VARCHAR, oa2.NRO_ORDEN)
                           + CASE
                               WHEN EXISTS (
                                    SELECT 1 FROM SIG_DEVENGADO dv
                                    JOIN SIG_DEVENGADO_ITEM dvi
                                         ON dvi.SEC_EJEC=dv.SEC_EJEC AND dvi.ANO_EJE=dv.ANO_EJE
                                        AND dvi.NRO_DEVENGADO=dv.NRO_DEVENGADO
                                    WHERE dv.SEC_EJEC=oa2.SEC_EJEC AND dv.ANO_EJE=oa2.ANO_EJE
                                      AND dv.NRO_ORDEN=oa2.NRO_ORDEN AND dv.TIPO_BIEN=oa2.TIPO_BIEN
                                      AND dvi.GRUPO_BIEN=oi2.GRUPO_BIEN AND dvi.CLASE_BIEN=oi2.CLASE_BIEN
                                      AND dvi.FAMILIA_BIEN=oi2.FAMILIA_BIEN AND dvi.ITEM_BIEN=oi2.ITEM_BIEN
                               ) THEN ' · DEVENGADO'
                               WHEN ISNULL(oa2.EXP_SIAF,0) <> 0     THEN ' · COMPROMETIDO'
                               WHEN ISNULL(oa2.NRO_CERTIFICA,0) <> 0 THEN ' · CERTIFICADO'
                               ELSE ' · CON ORDEN'
                             END
                           + CASE WHEN ISNULL(oa2.NRO_CERTIFICA,0) <> 0
                                  THEN ' · Cert ' + CONVERT(VARCHAR, oa2.NRO_CERTIFICA)
                                  ELSE '' END
                    FROM   SIG_ORDEN_ADQUISICION oa2
                    JOIN   SIG_ORDEN_ITEM oi2
                           ON oi2.SEC_EJEC=oa2.SEC_EJEC AND oi2.ANO_EJE=oa2.ANO_EJE
                          AND oi2.NRO_ORDEN=oa2.NRO_ORDEN AND oi2.TIPO_BIEN=oa2.TIPO_BIEN
                    WHERE  oa2.SEC_EJEC=k.SEC_EJEC AND oa2.ANO_EJE=k.ANO_EJE AND oa2.ESTADO<>'A'
                      AND  oi2.TIPO_BIEN=k.TIPO_BIEN AND oi2.GRUPO_BIEN=k.GRUPO_BIEN
                      AND  oi2.CLASE_BIEN=k.CLASE_BIEN AND oi2.FAMILIA_BIEN=k.FAMILIA_BIEN
                      AND  oi2.ITEM_BIEN=k.ITEM_BIEN
                    FOR XML PATH(''), TYPE).value('.','NVARCHAR(MAX)'), 1, 2, '') AS ORDENES
            FROM (
                /* Solo órdenes DEVENGADAS: el precio ejecutado real es el de lo
                   efectivamente devengado, no el promedio de todas las órdenes. */
                SELECT oi.SEC_EJEC, oi.ANO_EJE,
                       oi.TIPO_BIEN, oi.GRUPO_BIEN, oi.CLASE_BIEN, oi.FAMILIA_BIEN, oi.ITEM_BIEN,
                       SUM(oi.PREC_TOT_SOLES) / NULLIF(SUM(oi.CANT_ITEM),0) AS PREC_REAL
                FROM   SIG_ORDEN_ADQUISICION oa
                JOIN   SIG_ORDEN_ITEM oi
                       ON oi.SEC_EJEC=oa.SEC_EJEC AND oi.ANO_EJE=oa.ANO_EJE
                      AND oi.NRO_ORDEN=oa.NRO_ORDEN AND oi.TIPO_BIEN=oa.TIPO_BIEN
                WHERE  oa.ESTADO <> 'A'
                  AND  EXISTS (
                        SELECT 1 FROM SIG_DEVENGADO dv
                        JOIN SIG_DEVENGADO_ITEM dvi
                             ON dvi.SEC_EJEC=dv.SEC_EJEC AND dvi.ANO_EJE=dv.ANO_EJE
                            AND dvi.NRO_DEVENGADO=dv.NRO_DEVENGADO
                        WHERE dv.SEC_EJEC=oa.SEC_EJEC AND dv.ANO_EJE=oa.ANO_EJE
                          AND dv.NRO_ORDEN=oa.NRO_ORDEN AND dv.TIPO_BIEN=oa.TIPO_BIEN
                          AND dvi.GRUPO_BIEN=oi.GRUPO_BIEN AND dvi.CLASE_BIEN=oi.CLASE_BIEN
                          AND dvi.FAMILIA_BIEN=oi.FAMILIA_BIEN AND dvi.ITEM_BIEN=oi.ITEM_BIEN
                  )
                GROUP BY oi.SEC_EJEC, oi.ANO_EJE,
                         oi.TIPO_BIEN, oi.GRUPO_BIEN, oi.CLASE_BIEN, oi.FAMILIA_BIEN, oi.ITEM_BIEN
            ) k
        ) ej
             ON ej.SEC_EJEC  = D.SEC_EJEC
            AND ej.ANO_EJE   = D.ANNO_PROG            -- las ordenes se ejecutan en el año programado
            AND ej.TIPO_BIEN = D.TIPO_BIEN  AND ej.GRUPO_BIEN=D.GRUPO_BIEN
            AND ej.CLASE_BIEN= D.CLASE_BIEN AND ej.FAMILIA_BIEN=D.FAMILIA_BIEN
            AND ej.ITEM_BIEN = D.ITEM_BIEN
        LEFT JOIN (
            /* DEVENGADO REAL por item (lo efectivamente ejecutado del gasto). */
            SELECT di.SEC_EJEC, di.ANO_EJE, di.TIPO_BIEN,
                   di.GRUPO_BIEN, di.CLASE_BIEN, di.FAMILIA_BIEN, di.ITEM_BIEN,
                   SUM(di.CANT_ARTICULO) AS CANT_DEV,
                   SUM(di.VALOR_SOLES)   AS MNTO_DEV
            FROM   SIG_DEVENGADO dv
            JOIN   SIG_DEVENGADO_ITEM di
                   ON di.SEC_EJEC=dv.SEC_EJEC AND di.ANO_EJE=dv.ANO_EJE AND di.NRO_DEVENGADO=dv.NRO_DEVENGADO
            GROUP BY di.SEC_EJEC, di.ANO_EJE, di.TIPO_BIEN,
                     di.GRUPO_BIEN, di.CLASE_BIEN, di.FAMILIA_BIEN, di.ITEM_BIEN
        ) dev
             ON dev.SEC_EJEC  = D.SEC_EJEC
            AND dev.ANO_EJE   = D.ANNO_PROG
            AND dev.TIPO_BIEN = D.TIPO_BIEN  AND dev.GRUPO_BIEN=D.GRUPO_BIEN
            AND dev.CLASE_BIEN= D.CLASE_BIEN AND dev.FAMILIA_BIEN=D.FAMILIA_BIEN
            AND dev.ITEM_BIEN = D.ITEM_BIEN
        WHERE D.ANNO_PROG = :anioProg
          AND D.ANNO_EJEC = :anioEjec
          AND D.SEC_EJEC  = :secEjec
          {$filtroCC}
          AND D.ESTADO NOT IN ('E','ET')";
    }

    /** Expresión de fase a partir del texto de estado (para filtrar/agrupar). */
    private function faseExpr(string $col): string
    {
        return "CASE WHEN {$col} LIKE '%DEVENGADO%' THEN 'DEVENGADO'
                     WHEN {$col} LIKE '%COMPROMETIDO%' THEN 'COMPROMETIDO'
                     WHEN {$col} LIKE '%CERTIFICADO%' THEN 'CERTIFICADO'
                     ELSE 'PENDIENTE' END";
    }

    private function bindBase(PDOStatement $st, int $anioProg, int $anioEjec, int $secEjec, ?string $ccosto): void
    {
        $st->bindValue(':anioProg', $anioProg, PDO::PARAM_INT);
        $st->bindValue(':anioEjec', $anioEjec, PDO::PARAM_INT);
        $st->bindValue(':secEjec',  $secEjec,  PDO::PARAM_INT);
        if ($ccosto) $st->bindValue(':ccosto', $ccosto);
    }

    /** Aplica los binds de filtros (el driver sqlsrv no permite repetir un named param). */
    private function bindFiltros(PDOStatement $st, string $tipo, string $search, string $meta = '', string $act = '', ?string $fase = null): void
    {
        if ($tipo === 'B' || $tipo === 'S') $st->bindValue(':tipo', $tipo);
        if ($search !== '') { $st->bindValue(':q1', '%'.$search.'%'); $st->bindValue(':q2', '%'.$search.'%'); $st->bindValue(':q3', '%'.$search.'%'); }
        if ($meta !== '') $st->bindValue(':meta', $meta);
        if ($act  !== '') $st->bindValue(':act', $act);
        if ($fase !== null && in_array($fase, ['PENDIENTE','CERTIFICADO','COMPROMETIDO','DEVENGADO'], true)) $st->bindValue(':fase', $fase);
    }

    private function whereFiltros(string $tipo, string $search, string $meta, string $act, string $fase): string
    {
        $w = " WHERE 1=1 ";
        if ($tipo === 'B' || $tipo === 'S') $w .= " AND T.TIPO_BIEN = :tipo ";
        if ($search !== '') $w .= " AND (T.NOMBRE_ITEM LIKE :q1 OR T.CLASIF_COD LIKE :q2 OR T.ESTADO_ORDEN LIKE :q3) ";
        if ($meta !== '') $w .= " AND CONVERT(VARCHAR(50), T.META) = :meta ";
        if ($act  !== '') $w .= " AND T.ACTIV_OPERAT_COD = :act ";
        if (in_array($fase, ['PENDIENTE','CERTIFICADO','COMPROMETIDO','DEVENGADO'], true))
            $w .= " AND (" . $this->faseExpr('T.ESTADO_ORDEN') . ") = :fase ";
        return $w;
    }

    /** Valores distintos para los desplegables (meta, actividad operativa). */
    public function opciones(int $anioProg, int $anioEjec, int $secEjec, ?string $ccosto): array
    {
        $inner = $this->innerSql(!!$ccosto);
        $run = function (string $col) use ($inner, $anioProg, $anioEjec, $secEjec, $ccosto) {
            $st = $this->db->prepare(
                "SELECT DISTINCT CONVERT(VARCHAR(50), {$col}) v
                 FROM ({$inner}) T
                 WHERE {$col} IS NOT NULL AND CONVERT(VARCHAR(50), {$col}) <> ''
                 ORDER BY 1"
            );
            $this->bindBase($st, $anioProg, $anioEjec, $secEjec, $ccosto);
            $st->execute();
            return array_column($st->fetchAll(), 'v');
        };
        return ['metas' => $run('T.META'), 'actividades' => $run('T.ACTIV_OPERAT_COD')];
    }

    /**
     * Página de filas con filtros de servidor.
     * @return array{rows: array, total: int}
     */
    public function rows(int $anioProg, int $anioEjec, int $secEjec, ?string $ccosto,
                         string $tipo = '', string $search = '', string $meta = '', string $act = '',
                         string $fase = '', string $sort = 'mod_desc', int $page = 1, int $perPage = 50): array
    {
        $inner = $this->innerSql(!!$ccosto);
        $w = $this->whereFiltros($tipo, $search, $meta, $act, $fase);
        $order = match ($sort) {
            'mod_asc'  => 'T.IMPORTE_MOD ASC',
            'item_asc' => 'T.NOMBRE_ITEM ASC',
            default    => 'T.IMPORTE_MOD DESC',
        };

        $cst = $this->db->prepare("SELECT COUNT(*) FROM ({$inner}) T {$w}");
        $this->bindBase($cst, $anioProg, $anioEjec, $secEjec, $ccosto);
        $this->bindFiltros($cst, $tipo, $search, $meta, $act, $fase);
        $cst->execute();
        $total = (int)$cst->fetchColumn();

        $offset  = max(0, ($page - 1) * $perPage);
        $sql = "SELECT * FROM ({$inner}) T {$w}
                ORDER BY {$order}, T.CCOSTO_COD, T.ITEM_BIEN
                OFFSET {$offset} ROWS FETCH NEXT " . (int)$perPage . " ROWS ONLY";
        $st = $this->db->prepare($sql);
        $this->bindBase($st, $anioProg, $anioEjec, $secEjec, $ccosto);
        $this->bindFiltros($st, $tipo, $search, $meta, $act, $fase);
        $st->execute();

        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    /** Resumen por fase (conteo + monto), aplicando filtros salvo fase y paginado. */
    public function summary(int $anioProg, int $anioEjec, int $secEjec, ?string $ccosto,
                            string $tipo = '', string $search = '', string $meta = '', string $act = ''): array
    {
        $inner = $this->innerSql(!!$ccosto);
        $fexpr = $this->faseExpr('T.ESTADO_ORDEN');
        $w = $this->whereFiltros($tipo, $search, $meta, $act, '');
        $sql = "SELECT ({$fexpr}) AS fase, COUNT(*) c, SUM(T.IMPORTE_MOD) monto
                FROM ({$inner}) T {$w} GROUP BY ({$fexpr})";
        $st = $this->db->prepare($sql);
        $this->bindBase($st, $anioProg, $anioEjec, $secEjec, $ccosto);
        $this->bindFiltros($st, $tipo, $search, $meta, $act);
        $st->execute();
        return $st->fetchAll();
    }

    /**
     * Trazabilidad de un ítem: cómo se desglosa y modifica por etapas.
     * Devuelve ['cuadro','certificacion','ordenes','fases'].
     */
    public function historial(int $anioProg, int $anioEjec, int $secEjec, string $ccosto,
                              string $tipo, string $g, string $c, string $f, string $it,
                              int $secFunc, string $clasificador): array
    {
        $b = fn($sql, $p) => (function () use ($sql, $p) {
            $s = $this->db->prepare($sql); $s->execute($p); return $s->fetchAll();
        })();

        // 1) CUADRO: original (necesidad) -> modificado (incluido/excluido)
        $cuadro = $b(
            "SELECT 'Original' AS etapa, n.CANT_TOTAL cant, n.PRECIO_UNIT precio, n.MNTO_TOTAL monto, NULL estado, NULL fecha
             FROM SIG_CUADRO_NECESIDAD_DET n
             WHERE n.SEC_EJEC=? AND n.ANO_EJE=? AND n.CENTRO_COSTO=? AND n.TIPO_BIEN=?
               AND n.GRUPO_BIEN=? AND n.CLASE_BIEN=? AND n.FAMILIA_BIEN=? AND n.ITEM_BIEN=?
             UNION ALL
             SELECT 'Modificado', d.CANT_TOTAL, d.PRECIO_UNIT, d.MNTO_TOTAL,
                    CASE d.ESTADO WHEN 'I' THEN 'Incluido' WHEN 'IT' THEN 'Incluido'
                                  WHEN 'E' THEN 'Excluido' WHEN 'ET' THEN 'Excluido'
                                  ELSE d.ESTADO END,
                    CONVERT(VARCHAR(10), d.FECHA_MOD, 103)
             FROM SIG_CUADRO_MODIFICADO_DET d
             WHERE d.SEC_EJEC=? AND d.ANNO_PROG=? AND d.ANNO_EJEC=? AND d.CENTRO_COSTO=? AND d.TIPO_BIEN=?
               AND d.GRUPO_BIEN=? AND d.CLASE_BIEN=? AND d.FAMILIA_BIEN=? AND d.ITEM_BIEN=?",
            [$secEjec,$anioEjec,$ccosto,$tipo,$g,$c,$f,$it,
             $secEjec,$anioProg,$anioEjec,$ccosto,$tipo,$g,$c,$f,$it]
        );

        // 2) CERTIFICACIÓN: por meta + clasificador
        $cert = $b(
            "SELECT cp.NRO_CERTIFICA nro, CONVERT(VARCHAR(10), cc.FECHA, 103) fecha, cp.VALOR_SOLES monto,
                    CASE WHEN ISNULL(cc.ANULADO,0)=0 THEN 'Vigente' ELSE 'Anulada' END estado
             FROM SIG_CERTIFICACION_PPTO cp
             JOIN SIG_CERTIFICACION cc ON cc.ANO_EJE=cp.ANO_EJE AND cc.SEC_EJEC=cp.SEC_EJEC AND cc.NRO_CERTIFICA=cp.NRO_CERTIFICA
             WHERE cp.SEC_EJEC=? AND cp.ANO_EJE=? AND cp.SEC_FUNC=? AND cp.CLASIFICADOR=?
             ORDER BY cp.NRO_CERTIFICA",
            [$secEjec,$anioEjec,$secFunc,$clasificador]
        );

        // 3) ÓRDENES: cómo se subdividió (proveedor, cantidad, precio real, fecha)
        $ordenes = $b(
            "SELECT CASE WHEN oa.TIPO_BIEN='B' THEN 'OC ' ELSE 'OS ' END + CONVERT(VARCHAR,oa.NRO_ORDEN) orden,
                    ISNULL(ct.NOMBRE_PROV,'—') proveedor, oi.CANT_ITEM cant, oi.PREC_UNIT_MONEDA precio,
                    oi.PREC_TOT_SOLES monto, CONVERT(VARCHAR(10), oa.FECHA_ORDEN, 103) fecha, oa.ESTADO estado
             FROM SIG_ORDEN_ADQUISICION oa
             JOIN SIG_ORDEN_ITEM oi ON oi.SEC_EJEC=oa.SEC_EJEC AND oi.ANO_EJE=oa.ANO_EJE
                  AND oi.NRO_ORDEN=oa.NRO_ORDEN AND oi.TIPO_BIEN=oa.TIPO_BIEN
             LEFT JOIN SIG_CONTRATISTAS ct ON ct.PROVEEDOR=oa.PROVEEDOR
             WHERE oa.SEC_EJEC=? AND oa.ANO_EJE=? AND oa.ESTADO<>'A'
               AND oi.TIPO_BIEN=? AND oi.GRUPO_BIEN=? AND oi.CLASE_BIEN=? AND oi.FAMILIA_BIEN=? AND oi.ITEM_BIEN=?
             ORDER BY oa.NRO_ORDEN",
            [$secEjec,$anioProg,$tipo,$g,$c,$f,$it]
        );

        // 4) FASES DEL GASTO: compromiso (orden con exp SIAF) y devengado
        $fases = $b(
            "SELECT DISTINCT 'Comprometido' fase, CASE WHEN oa.TIPO_BIEN='B' THEN 'OC ' ELSE 'OS ' END + CONVERT(VARCHAR,oa.NRO_ORDEN) doc,
                    CONVERT(VARCHAR(10), oa.FECHA_SIAF, 103) fecha, oi.PREC_TOT_SOLES monto
             FROM SIG_ORDEN_ADQUISICION oa
             JOIN SIG_ORDEN_ITEM oi ON oi.SEC_EJEC=oa.SEC_EJEC AND oi.ANO_EJE=oa.ANO_EJE
                  AND oi.NRO_ORDEN=oa.NRO_ORDEN AND oi.TIPO_BIEN=oa.TIPO_BIEN
             WHERE oa.SEC_EJEC=? AND oa.ANO_EJE=? AND ISNULL(oa.EXP_SIAF,0)<>0 AND oa.ESTADO<>'A'
               AND oi.TIPO_BIEN=? AND oi.GRUPO_BIEN=? AND oi.CLASE_BIEN=? AND oi.FAMILIA_BIEN=? AND oi.ITEM_BIEN=?
             UNION ALL
             SELECT 'Devengado', 'Deveng. ' + CONVERT(VARCHAR,dv.NRO_DEVENGADO),
                    CONVERT(VARCHAR(10), dv.FECHA_REG, 103), di.VALOR_SOLES
             FROM SIG_DEVENGADO dv
             JOIN SIG_DEVENGADO_ITEM di ON di.SEC_EJEC=dv.SEC_EJEC AND di.ANO_EJE=dv.ANO_EJE AND di.NRO_DEVENGADO=dv.NRO_DEVENGADO
             WHERE dv.SEC_EJEC=? AND dv.ANO_EJE=?
               AND di.TIPO_BIEN=? AND di.GRUPO_BIEN=? AND di.CLASE_BIEN=? AND di.FAMILIA_BIEN=? AND di.ITEM_BIEN=?",
            [$secEjec,$anioProg,$tipo,$g,$c,$f,$it, $secEjec,$anioProg,$tipo,$g,$c,$f,$it]
        );

        return ['cuadro'=>$cuadro, 'certificacion'=>$cert, 'ordenes'=>$ordenes, 'fases'=>$fases];
    }
}