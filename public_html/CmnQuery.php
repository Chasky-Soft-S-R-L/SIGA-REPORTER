<?php
/**
 * CAPA QUERY  ·  SIGA-REPORTER
 * Acceso de solo lectura a SIGA_104. Reutiliza la consulta del CMN ya validada.
 * Sin lógica de dominio: solo trae filas.
 *
 * ESTADOS (3): Programado · Modificado · Ejecutado. Se clasifican por montos:
 *   - Ejecutado  = tiene devengado real  (IMPORTE_EJEC > 0)
 *   - Modificado = el vigente difiere del original (IMPORTE_MOD <> IMPORTE_PROG)
 *   - Programado = el resto
 *
 * ATRIBUCIÓN DEL EJECUTADO (fix definitivo del "diferencia negativa"):
 * Las tablas de devengado/orden son consolidadas y NO guardan el centro de costo,
 * así que atribuir por ítem inflaba el ejecutado (el papel de toda la entidad caía
 * en una sola línea). La fuente correcta es la cadena del CUADRO DE ADQUISICIÓN:
 *   SIG_CUADRO_ADQUISICION -> SIG_DETALLE_BSERV_CUADRO (línea del ítem)
 *   -> SIG_DEPEN_META_CUADRO (reparto real por CENTRO + TAREA, donde MNTO_SOLES
 *      refleja lo efectivamente ejecutado de ese centro).
 * Es el dato registrado por el propio SIGA (cuadra con la pantalla Área Usuaria),
 * con la granularidad exacta del CMN. Solo cuadros con orden generada y no anulada.
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
               AND  ISNULL(D.ESTADO,'') NOT IN ('E','ET')
             ORDER BY D.CENTRO_COSTO"
        );
        $st->execute([$anioProg, $anioEjec, $secEjec]);
        return $st->fetchAll();
    }

    /** SQL interno del reporte (sin ORDER BY ni paginado), reutilizable. */
    private function innerSql(bool $withCC): string
    {
        $filtroCC = $withCC ? " AND d.CENTRO_COSTO = :ccosto " : "";
        /* Factor de reparto del ejecutado/comprometido entre filas que comparten
           centro+tarea+ítem (proporcional al importe vigente; partes iguales si vigente 0). */
        $repTI = "(CASE WHEN ISNULL(D.MOD_TI,0) > 0 THEN D.MNTO_TOTAL / D.MOD_TI
                        ELSE 1.0 / D.GRUPOS_TI END)";
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
            /* Código de producto = grupo+clase+familia+item concatenado (como en el SIGA). */
            D.GRUPO_BIEN + D.CLASE_BIEN + D.FAMILIA_BIEN + D.ITEM_BIEN AS COD_PRODUCTO,
            REPLACE(REPLACE(D.CLASIFICADOR,'.',''),' ','')          AS CLASIF_PLANO,
            /* Nombre de la fuente: la mayoría de instalaciones del SIGA lo guardan en
               FUENTE_FINANC.NOMBRE. Si en esta base la columna se llamara distinto,
               cambiar aquí (p.ej. ff.DESCRIPCION). Se protege con ISNULL. */
            ISNULL(ff.NOMBRE, D.FUENTE_FINANC)                      AS FF_NOMBRE,
            cat.NOMBRE_ITEM                                         AS NOMBRE_ITEM,
            um.NOMBRE                                               AS UNIDAD_MEDIDA,
            /* PROGRAMADO = solo lo APROBADO en el cuadro de necesidades, repartido
               entre las actividades del ítem (ver OUTER APPLY tot). Los ítems añadidos
               por modificación (I) no existen en la necesidad => programado 0.
               Si el ítem está en UNA sola actividad se toma el importe exacto (sin
               multiplicar, para no arrastrar decimales); si está en varias, se reparte
               y se redondea a céntimos. Servicios: 1 × importe. */
            CASE WHEN D.TIPO_BIEN='S'
                 THEN CASE WHEN ISNULL(ori.MNTO_TOTAL,0) > 0 THEN 1 ELSE 0 END
                 WHEN D.GRUPOS_ITEM <= 1 THEN ISNULL(ori.CANT_TOTAL, 0)
                 ELSE ROUND(ISNULL(ori.CANT_TOTAL, 0)
                      * CASE WHEN ISNULL(D.MOD_ITEM,0) > 0 THEN D.MNTO_TOTAL / D.MOD_ITEM
                             ELSE 1.0 / D.GRUPOS_ITEM END, 4)
            END                                                     AS CANTIDAD_PROG,
            CASE WHEN D.TIPO_BIEN='S'
                 THEN CASE WHEN D.GRUPOS_ITEM <= 1 THEN ISNULL(ori.MNTO_TOTAL, 0)
                           ELSE ROUND(ISNULL(ori.MNTO_TOTAL, 0)
                                * CASE WHEN ISNULL(D.MOD_ITEM,0) > 0 THEN D.MNTO_TOTAL / D.MOD_ITEM
                                       ELSE 1.0 / D.GRUPOS_ITEM END, 2) END
                 ELSE ISNULL(ori.PRECIO_UNIT, 0) END                AS PRECIO_UNIT_PROG,
            CASE WHEN D.GRUPOS_ITEM <= 1 THEN ISNULL(ori.MNTO_TOTAL, 0)
                 ELSE ROUND(ISNULL(ori.MNTO_TOTAL, 0)
                      * CASE WHEN ISNULL(D.MOD_ITEM,0) > 0 THEN D.MNTO_TOTAL / D.MOD_ITEM
                             ELSE 1.0 / D.GRUPOS_ITEM END, 2)
            END                                                     AS IMPORTE_PROG,
            CASE WHEN D.TIPO_BIEN='S' THEN 1 ELSE D.CANT_TOTAL END  AS CANTIDAD_MOD,
            /* MODIFICADO VIGENTE: fiel al CMN del SIGA (suma de sus líneas I + base).
               El precio real del consolidado PAAC NO altera esta columna; queda como
               trazabilidad en el historial (sección Consolidado) para explicar las
               diferencias de precio. Servicios: 1 × importe. */
            CASE WHEN D.TIPO_BIEN='S' THEN D.MNTO_TOTAL
                 ELSE D.PRECIO_UNIT END                             AS PRECIO_UNIT_MOD,
            D.MNTO_TOTAL                                            AS IMPORTE_MOD,
            COALESCE(ej.ORDENES,
                     CASE WHEN cert.NRO_CERTIFICA IS NOT NULL
                          THEN 'CERTIFICADO · Cert ' + CONVERT(VARCHAR, cert.NRO_CERTIFICA)
                          ELSE 'PENDIENTE' END)                 AS ESTADO_ORDEN,
            ''                                                      AS RESPONSABLE,
            /* ESTADO DE LÍNEA DEL CMN (columna Incl/Excl del SIGA + ajuste de montos):
                 ANTIGUO    = venía del cuadro base aprobado (sin marca I/E)
                 INCLUIDO   = añadido por modificación (I / IT)
                 EXCLUIDO   = alguna línea del ítem fue retirada (E / ET)
                 MODIFICADO = el importe vigente difiere del original (el SIGA lo
                              recalcula solo al cambiar cantidad o precio). */
            STUFF(  CASE WHEN D.LIN_APROB > 0 OR D.LIN_OTRO > 0 THEN ', ANTIGUO' ELSE '' END
                  + CASE WHEN D.LIN_INCL  > 0 THEN ', INCLUIDO' ELSE '' END
                  + CASE WHEN D.LIN_EXCL  > 0 THEN ', EXCLUIDO' ELSE '' END
                  + CASE WHEN ISNULL(ori.MNTO_TOTAL,0) > 0
                              AND ABS(D.MNTO_TOTAL - ori.MNTO_TOTAL) > 0.005
                         THEN ', MODIFICADO' ELSE '' END
                  , 1, 2, '')                                       AS ESTADO_CMN,
            D.NRO_LINEAS                                            AS NRO_LINEAS,
            /* EJECUTADO repartido: el gasto se atribuye por centro+tarea+ítem, pero
               una fila del reporte es centro+meta+clasificador+tarea+ítem. Si el mismo
               ítem+tarea está en varias metas/clasificadores, sin repartir el importe
               se contaría una vez por cada combinación (duplicando el ejecutado).
               El factor {$repTI} reparte proporcional al importe vigente de la fila. */
            CASE WHEN D.TIPO_BIEN='S'
                 THEN CASE WHEN ISNULL(dev.MNTO_DEV,0) > 0 THEN 1 ELSE 0 END
                 WHEN D.GRUPOS_TI <= 1 THEN ISNULL(dev.CANT_DEV, 0)
                 ELSE ROUND(ISNULL(dev.CANT_DEV, 0) * {$repTI}, 4)
            END                                                     AS CANTIDAD_EJEC,
            CASE WHEN D.TIPO_BIEN='S'
                 THEN CASE WHEN D.GRUPOS_TI <= 1 THEN ISNULL(dev.MNTO_DEV, 0)
                           ELSE ROUND(ISNULL(dev.MNTO_DEV, 0) * {$repTI}, 2) END
                 WHEN ISNULL(dev.CANT_DEV,0) > 0 THEN dev.MNTO_DEV / dev.CANT_DEV
                 ELSE 0 END                                         AS PRECIO_UNIT_EJEC,
            /* IMPORTE_EJEC = DEVENGADO (ejecución real). Es la columna histórica; se
               conserva el nombre para no romper el resto del código. */
            CASE WHEN D.GRUPOS_TI <= 1 THEN ISNULL(dev.MNTO_DEV, 0)
                 ELSE ROUND(ISNULL(dev.MNTO_DEV, 0) * {$repTI}, 2)
            END                                                     AS IMPORTE_EJEC,
            CASE WHEN D.GRUPOS_TI <= 1 THEN ISNULL(dev.MNTO_COMP, 0)
                 ELSE ROUND(ISNULL(dev.MNTO_COMP, 0) * {$repTI}, 2)
            END                                                     AS IMPORTE_COMP,
            /* DEVENGADO = ejecución real (idéntico a IMPORTE_EJEC; nombre propio para la lista). */
            CASE WHEN D.GRUPOS_TI <= 1 THEN ISNULL(dev.MNTO_DEV, 0)
                 ELSE ROUND(ISNULL(dev.MNTO_DEV, 0) * {$repTI}, 2)
            END                                                     AS DEVENGADO,
            /* DIFERENCIA = PRESUPUESTO VIGENTE − DEVENGADO.
               El presupuesto vigente es el MODIFICADO si el ítem fue modificado
               (D.MNTO_TOTAL > 0); si no, el PROGRAMADO original. Así se compara lo
               ejecutado contra el presupuesto que realmente rige.
               Negativa = SOBREGIRO (se ejecutó más de lo presupuestado). */
            (CASE WHEN D.MNTO_TOTAL > 0 THEN D.MNTO_TOTAL
                  WHEN D.GRUPOS_ITEM <= 1 THEN ISNULL(ori.MNTO_TOTAL, 0)
                  ELSE ROUND(ISNULL(ori.MNTO_TOTAL, 0)
                       * CASE WHEN ISNULL(D.MOD_ITEM,0) > 0 THEN D.MNTO_TOTAL / D.MOD_ITEM
                              ELSE 1.0 / D.GRUPOS_ITEM END, 2) END)
            -
            (CASE WHEN D.GRUPOS_TI <= 1 THEN ISNULL(dev.MNTO_DEV, 0)
                  ELSE ROUND(ISNULL(dev.MNTO_DEV, 0) * {$repTI}, 2) END)  AS DIFERENCIA,
          
            (CASE WHEN D.GRUPOS_TI <= 1 THEN ISNULL(dev.MNTO_COMP, 0)
                  ELSE ROUND(ISNULL(dev.MNTO_COMP, 0) * {$repTI}, 2) END)
            -
            (CASE WHEN D.GRUPOS_TI <= 1 THEN ISNULL(dev.MNTO_DEV, 0)
                  ELSE ROUND(ISNULL(dev.MNTO_DEV, 0) * {$repTI}, 2) END)  AS SALDO_DEVENGAR
        FROM (
            /* AGRUPADO FINAL por centro + meta + tarea + clasificador + ítem
               (granularidad del reporte y de la atribución del ejecutado). */
            SELECT SEC_EJEC, ANNO_PROG, ANNO_EJEC, FUENTE_FINANC, TIPO_BIEN, CENTRO_COSTO,
                   SEC_FUNC, CLASIFICADOR, TIPO_USO, TIPO_TAREA, NIVEL_TAREA, CODIGO_TAREA,
                   GRUPO_BIEN, CLASE_BIEN, FAMILIA_BIEN, ITEM_BIEN, UNIDAD_MEDIDA,
                   SUM(CANT_VIG) AS CANT_TOTAL,
                   SUM(MNTO_VIG) AS MNTO_TOTAL,
                   CASE WHEN SUM(CANT_VIG) > 0 THEN SUM(MNTO_VIG) / SUM(CANT_VIG)
                        ELSE MAX(PRECIO_UNIT) END               AS PRECIO_UNIT,
                   MAX(SEC_CUA_MOD_SAL) AS SEC_CUA_MOD_SAL,
                   /* ── FACTORES DE REPARTO (ventanas sobre el resultado agrupado) ──
                      MOD_ITEM/GRUPOS_ITEM: el ítem en el centro puede estar en varias
                        actividades -> reparte el PROGRAMADO (la necesidad no guarda tarea).
                      MOD_TI/GRUPOS_TI: el mismo ítem+tarea puede estar en varias
                        metas/clasificadores -> reparte el EJECUTADO (que se atribuye por
                        centro+tarea+ítem). Sin esto el gasto se contaba una vez por combo. */
                   SUM(SUM(MNTO_VIG)) OVER (PARTITION BY CENTRO_COSTO, TIPO_BIEN,
                        GRUPO_BIEN, CLASE_BIEN, FAMILIA_BIEN, ITEM_BIEN)          AS MOD_ITEM,
                   COUNT(*)           OVER (PARTITION BY CENTRO_COSTO, TIPO_BIEN,
                        GRUPO_BIEN, CLASE_BIEN, FAMILIA_BIEN, ITEM_BIEN)          AS GRUPOS_ITEM,
                   SUM(SUM(MNTO_VIG)) OVER (PARTITION BY CENTRO_COSTO, TIPO_BIEN,
                        TIPO_TAREA, NIVEL_TAREA, CODIGO_TAREA,
                        GRUPO_BIEN, CLASE_BIEN, FAMILIA_BIEN, ITEM_BIEN)          AS MOD_TI,
                   COUNT(*)           OVER (PARTITION BY CENTRO_COSTO, TIPO_BIEN,
                        TIPO_TAREA, NIVEL_TAREA, CODIGO_TAREA,
                        GRUPO_BIEN, CLASE_BIEN, FAMILIA_BIEN, ITEM_BIEN)          AS GRUPOS_TI,
                   SUM(NRO_LINEAS) AS NRO_LINEAS,
                   SUM(LIN_INCL)   AS LIN_INCL,
                   SUM(LIN_EXCL)   AS LIN_EXCL,
                   SUM(LIN_APROB)  AS LIN_APROB,
                   SUM(LIN_OTRO)   AS LIN_OTRO
            FROM (
                /* ── LÍNEA LÓGICA DEL CMN (una por SEC_CUA_MOD_SAL) ──
                   SIG_CUADRO_MODIFICADO_DET guarda el HISTORIAL de modificaciones:
                   una misma línea puede tener varias filas (p.ej. el servicio 6961 con
                   23,000 + 9,300 + 9,300 registradas con minutos de diferencia y
                   FLAG_MODIFICADO=1 en la última). Sumarlas infla el cuadro.
                   La CANTIDAD VIGENTE está en SIG_CUADRO_MODIFICADO_SALDO.CANT_TOTAL
                   (para ese caso: 32,300, que es justo lo que muestra el SIGA).
                   Verificado al céntimo contra las pantallas de dos centros distintos. */
                SELECT d.SEC_EJEC, d.ANNO_PROG, d.ANNO_EJEC, d.SEC_CUA_MOD_SAL,
                       MAX(d.FUENTE_FINANC) AS FUENTE_FINANC, MAX(d.TIPO_BIEN)    AS TIPO_BIEN,
                       MAX(d.CENTRO_COSTO)  AS CENTRO_COSTO,   MAX(d.SEC_FUNC)    AS SEC_FUNC,
                       MAX(d.CLASIFICADOR)  AS CLASIFICADOR,   MAX(d.TIPO_USO)    AS TIPO_USO,
                       MAX(d.TIPO_TAREA)    AS TIPO_TAREA,     MAX(d.NIVEL_TAREA) AS NIVEL_TAREA,
                       MAX(d.CODIGO_TAREA)  AS CODIGO_TAREA,   MAX(d.GRUPO_BIEN)  AS GRUPO_BIEN,
                       MAX(d.CLASE_BIEN)    AS CLASE_BIEN,     MAX(d.FAMILIA_BIEN) AS FAMILIA_BIEN,
                       MAX(d.ITEM_BIEN)     AS ITEM_BIEN,      MAX(d.UNIDAD_MEDIDA) AS UNIDAD_MEDIDA,
                       MAX(d.PRECIO_UNIT)   AS PRECIO_UNIT,
                       /* Si TODAS las filas de la línea están excluidas, la línea no rige. */
                       CASE WHEN SUM(CASE WHEN ISNULL(d.ESTADO,'') NOT IN ('E','ET') THEN 1 ELSE 0 END) = 0 THEN 0
                            ELSE COALESCE(MAX(s.CANT_TOTAL),
                                          SUM(CASE WHEN ISNULL(d.ESTADO,'') NOT IN ('E','ET') THEN d.CANT_TOTAL ELSE 0 END))
                       END AS CANT_VIG,
                       CASE WHEN SUM(CASE WHEN ISNULL(d.ESTADO,'') NOT IN ('E','ET') THEN 1 ELSE 0 END) = 0 THEN 0
                            ELSE COALESCE(MAX(s.CANT_TOTAL),
                                          SUM(CASE WHEN ISNULL(d.ESTADO,'') NOT IN ('E','ET') THEN d.CANT_TOTAL ELSE 0 END))
                                 * MAX(d.PRECIO_UNIT)
                       END AS MNTO_VIG,
                       COUNT(*) AS NRO_LINEAS,
                       SUM(CASE WHEN d.ESTADO IN ('I','IT') THEN 1 ELSE 0 END)                             AS LIN_INCL,
                       SUM(CASE WHEN d.ESTADO IN ('E','ET') THEN 1 ELSE 0 END)                             AS LIN_EXCL,
                       SUM(CASE WHEN d.ESTADO = 'C' THEN 1 ELSE 0 END)                                     AS LIN_APROB,
                       SUM(CASE WHEN ISNULL(d.ESTADO,'') NOT IN ('I','IT','C','E','ET') THEN 1 ELSE 0 END) AS LIN_OTRO
                FROM   SIG_CUADRO_MODIFICADO_DET d
                LEFT JOIN SIG_CUADRO_MODIFICADO_SALDO s
                       ON s.SEC_EJEC=d.SEC_EJEC AND s.ANNO_EJEC=d.ANNO_EJEC
                      AND s.SEC_CUA_MOD_SAL=d.SEC_CUA_MOD_SAL
                WHERE  d.ANNO_PROG = :anioProg
                  AND  d.ANNO_EJEC = :anioEjec
                  AND  d.SEC_EJEC  = :secEjec
                  {$filtroCC}
                GROUP BY d.SEC_EJEC, d.ANNO_PROG, d.ANNO_EJEC, d.SEC_CUA_MOD_SAL
            ) L
            GROUP BY SEC_EJEC, ANNO_PROG, ANNO_EJEC, FUENTE_FINANC, TIPO_BIEN, CENTRO_COSTO,
                     SEC_FUNC, CLASIFICADOR, TIPO_USO, TIPO_TAREA, NIVEL_TAREA, CODIGO_TAREA,
                     GRUPO_BIEN, CLASE_BIEN, FAMILIA_BIEN, ITEM_BIEN, UNIDAD_MEDIDA
            /* Los ítems totalmente excluidos SÍ se listan (con importe vigente 0 y
               badge EXCLUIDO): forman parte del cuadro aprobado y el SIGA los
               considera en la Fase de Consolidación y Aprobación. */
        ) D
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
               Se SUMAN todas las líneas del ítem en el centro (puede haber varias,
               igual que en el modificado); precio = promedio ponderado. */
            SELECT SUM(n.CANT_TOTAL)  AS CANT_TOTAL,
                   SUM(n.MNTO_TOTAL)  AS MNTO_TOTAL,
                   SUM(n.MNTO_TOTAL) / NULLIF(SUM(n.CANT_TOTAL), 0) AS PRECIO_UNIT
            FROM   SIG_CUADRO_NECESIDAD_DET n
            WHERE  n.SEC_EJEC=D.SEC_EJEC AND n.ANO_EJE=D.ANNO_EJEC
              AND  n.CENTRO_COSTO=D.CENTRO_COSTO AND n.TIPO_BIEN=D.TIPO_BIEN
              AND  n.GRUPO_BIEN=D.GRUPO_BIEN AND n.CLASE_BIEN=D.CLASE_BIEN
              AND  n.FAMILIA_BIEN=D.FAMILIA_BIEN AND n.ITEM_BIEN=D.ITEM_BIEN
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
            /* LISTA DE ÓRDENES POR META + CLASIFICADOR + ITEM (para el detalle/estado).
               Antes se listaba por item a secas -> aparecían órdenes de otras metas.
               Ahora cada línea del CMN ve solo las órdenes atribuidas a SU meta. */
            SELECT
                g.SEC_EJEC, g.ANO_EJE, g.SEC_FUNC, g.CLASIFICADOR,
                g.TIPO_BIEN, g.GRUPO_BIEN, g.CLASE_BIEN, g.FAMILIA_BIEN, g.ITEM_BIEN,
                STUFF((
                    SELECT DISTINCT ', ' + CASE WHEN oa2.TIPO_BIEN='B' THEN 'OC ' ELSE 'OS ' END
                           + CONVERT(VARCHAR, oa2.NRO_ORDEN)
                           + CASE
                               WHEN EXISTS (
                                    SELECT 1 FROM SIG_DEVENGADO dv2
                                    JOIN SIG_DEVENGADO_ITEM dvi2
                                         ON dvi2.SEC_EJEC=dv2.SEC_EJEC AND dvi2.ANO_EJE=dv2.ANO_EJE
                                        AND dvi2.NRO_DEVENGADO=dv2.NRO_DEVENGADO
                                    WHERE dv2.SEC_EJEC=oa2.SEC_EJEC AND dv2.ANO_EJE=oa2.ANO_EJE
                                      AND dv2.NRO_ORDEN=oa2.NRO_ORDEN AND dv2.TIPO_BIEN=oa2.TIPO_BIEN
                                      AND dvi2.GRUPO_BIEN=oi2.GRUPO_BIEN AND dvi2.CLASE_BIEN=oi2.CLASE_BIEN
                                      AND dvi2.FAMILIA_BIEN=oi2.FAMILIA_BIEN AND dvi2.ITEM_BIEN=oi2.ITEM_BIEN
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
                    JOIN   SIG_ORDEN_ITEM_PPTO oip2
                           ON oip2.SEC_EJEC=oa2.SEC_EJEC AND oip2.ANO_EJE=oa2.ANO_EJE
                          AND oip2.NRO_ORDEN=oa2.NRO_ORDEN AND oip2.TIPO_BIEN=oa2.TIPO_BIEN
                          AND oip2.SEC_FUNC=g.SEC_FUNC AND oip2.CLASIFICADOR=g.CLASIFICADOR
                    WHERE  oa2.SEC_EJEC=g.SEC_EJEC AND oa2.ANO_EJE=g.ANO_EJE AND oa2.ESTADO<>'A'
                      AND  oi2.TIPO_BIEN=g.TIPO_BIEN AND oi2.GRUPO_BIEN=g.GRUPO_BIEN
                      AND  oi2.CLASE_BIEN=g.CLASE_BIEN AND oi2.FAMILIA_BIEN=g.FAMILIA_BIEN
                      AND  oi2.ITEM_BIEN=g.ITEM_BIEN
                    FOR XML PATH(''), TYPE).value('.','NVARCHAR(MAX)'), 1, 2, '') AS ORDENES
            FROM (
                /* Combinaciones (meta, clasificador, item) que efectivamente tienen orden. */
                SELECT DISTINCT
                       oip.SEC_EJEC, oip.ANO_EJE, oip.SEC_FUNC, oip.CLASIFICADOR,
                       oi.TIPO_BIEN, oi.GRUPO_BIEN, oi.CLASE_BIEN, oi.FAMILIA_BIEN, oi.ITEM_BIEN
                FROM   SIG_ORDEN_ADQUISICION oa
                JOIN   SIG_ORDEN_ITEM oi
                       ON oi.SEC_EJEC=oa.SEC_EJEC AND oi.ANO_EJE=oa.ANO_EJE
                      AND oi.NRO_ORDEN=oa.NRO_ORDEN AND oi.TIPO_BIEN=oa.TIPO_BIEN
                JOIN   SIG_ORDEN_ITEM_PPTO oip
                       ON oip.SEC_EJEC=oa.SEC_EJEC AND oip.ANO_EJE=oa.ANO_EJE
                      AND oip.NRO_ORDEN=oa.NRO_ORDEN AND oip.TIPO_BIEN=oa.TIPO_BIEN
                WHERE  oa.ESTADO<>'A'
            ) g
        ) ej
             ON ej.SEC_EJEC     = D.SEC_EJEC
            AND ej.ANO_EJE      = D.ANNO_PROG          -- las ordenes se ejecutan en el año programado
            AND ej.SEC_FUNC     = D.SEC_FUNC
            AND ej.CLASIFICADOR = D.CLASIFICADOR
            AND ej.TIPO_BIEN    = D.TIPO_BIEN  AND ej.GRUPO_BIEN=D.GRUPO_BIEN
            AND ej.CLASE_BIEN   = D.CLASE_BIEN AND ej.FAMILIA_BIEN=D.FAMILIA_BIEN
            AND ej.ITEM_BIEN    = D.ITEM_BIEN
        LEFT JOIN (
            /* EJECUTADO REAL por CENTRO + TAREA + ITEM, desde el cuadro de adquisición.
               Cadena oficial del SIGA (verificada contra pantalla Área Usuaria):
                 SIG_CUADRO_ADQUISICION  = cuadros consolidados con su NRO_ORDEN
                 SIG_DETALLE_BSERV_CUADRO= línea del ítem dentro del cuadro (SEC_CUADRO+SECUENCIA)
                 SIG_DEPEN_META_CUADRO   = reparto REAL de esa línea por centro de costo y tarea:
                     CANT_DEPEND = cantidad asignada al centro
                     MNTO_SOLES  = monto EJECUTADO del centro (se actualiza con la ejecución:
                                   ej. orden 507 -> VALOR_DEPEND 20,000 comprometido,
                                   MNTO_SOLES 5,000 devengado)
               Sin prorrateos ni estimaciones: es el dato registrado por el propio SIGA,
               con la granularidad exacta del CMN (centro + tarea + item). Solo cuadros
               con orden generada y no anulada. */
            SELECT
                ca.SEC_EJEC, ca.ANO_EJE,
                dm.CENTRO_COSTO, dm.TIPO_TAREA, dm.NIVEL_TAREA, dm.CODIGO_TAREA,
                db.TIPO_BIEN, db.GRUPO_BIEN, db.CLASE_BIEN, db.FAMILIA_BIEN, db.ITEM_BIEN,
                SUM(dm.CANT_DEPEND) AS CANT_DEV,
                SUM(dm.MNTO_SOLES)  AS MNTO_DEV,
                SUM(dm.VALOR_DEPEND) AS MNTO_COMP
            FROM   SIG_CUADRO_ADQUISICION ca
            JOIN   SIG_DETALLE_BSERV_CUADRO db
                   ON db.SEC_EJEC=ca.SEC_EJEC AND db.ANO_EJE=ca.ANO_EJE
                  AND db.TIPO_BIEN=ca.TIPO_BIEN AND db.SEC_CUADRO=ca.SEC_CUADRO
            JOIN   SIG_DEPEN_META_CUADRO dm
                   ON dm.SEC_EJEC=db.SEC_EJEC AND dm.ANO_EJE=db.ANO_EJE
                  AND dm.TIPO_BIEN=db.TIPO_BIEN AND dm.SEC_CUADRO=db.SEC_CUADRO
                  AND dm.SECUENCIA=db.SECUENCIA
            WHERE  ISNULL(ca.NRO_ORDEN, 0) > 0
              AND  ca.ESTADO <> 'A'
              AND  EXISTS (
                    /* La orden del cuadro debe existir y no estar anulada. */
                    SELECT 1 FROM SIG_ORDEN_ADQUISICION oa
                    WHERE oa.SEC_EJEC=ca.SEC_EJEC AND oa.ANO_EJE=ca.ANO_EJE
                      AND oa.NRO_ORDEN=ca.NRO_ORDEN AND oa.TIPO_BIEN=ca.TIPO_BIEN
                      AND oa.ESTADO<>'A'
              )
            GROUP BY ca.SEC_EJEC, ca.ANO_EJE,
                     dm.CENTRO_COSTO, dm.TIPO_TAREA, dm.NIVEL_TAREA, dm.CODIGO_TAREA,
                     db.TIPO_BIEN, db.GRUPO_BIEN, db.CLASE_BIEN, db.FAMILIA_BIEN, db.ITEM_BIEN
        ) dev
             ON dev.SEC_EJEC     = D.SEC_EJEC
            AND dev.ANO_EJE      = D.ANNO_PROG
            AND dev.CENTRO_COSTO = D.CENTRO_COSTO
            AND dev.TIPO_TAREA   = D.TIPO_TAREA
            AND dev.NIVEL_TAREA  = D.NIVEL_TAREA
            AND dev.CODIGO_TAREA = D.CODIGO_TAREA
            AND dev.TIPO_BIEN    = D.TIPO_BIEN  AND dev.GRUPO_BIEN=D.GRUPO_BIEN
            AND dev.CLASE_BIEN   = D.CLASE_BIEN AND dev.FAMILIA_BIEN=D.FAMILIA_BIEN
            AND dev.ITEM_BIEN    = D.ITEM_BIEN";
    }

    /**
     * Estado consolidado del ítem según montos: PROGRAMADO · MODIFICADO · EJECUTADO.
     * Se calcula sobre las columnas de la subconsulta T (no sobre el texto de ESTADO_ORDEN).
     *   - EJECUTADO  : hay devengado real (IMPORTE_EJEC > 0)
     *   - MODIFICADO : el vigente difiere del original (tolerancia de céntimo)
     *   - PROGRAMADO : el resto
     */
    private function faseExpr(string $t = 'T'): string
    {
        return "CASE
                    WHEN {$t}.IMPORTE_EJEC > 0                               THEN 'EJECUTADO'
                    WHEN ABS({$t}.IMPORTE_MOD - {$t}.IMPORTE_PROG) > 0.005  THEN 'MODIFICADO'
                    ELSE 'PROGRAMADO'
                END";
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
        if ($search !== '') {
            $st->bindValue(':q1', '%'.$search.'%');
            $st->bindValue(':q2', '%'.$search.'%');
            $st->bindValue(':q3', '%'.$search.'%');
            /* Versión sin puntos ni espacios: "2 3 5 1 1" o "2.3.5.1.1" → "23511". */
            $plano = preg_replace('/[.\s]/', '', $search);
            $st->bindValue(':q4', '%'.$plano.'%');
        }
        if ($meta !== '') $st->bindValue(':meta', $meta);
        if ($act  !== '') $st->bindValue(':act', $act);
        if ($fase !== null && in_array($fase, ['PROGRAMADO','MODIFICADO','EJECUTADO'], true)) $st->bindValue(':fase', $fase);
    }

    private function whereFiltros(string $tipo, string $search, string $meta, string $act, string $fase): string
    {
        $w = " WHERE 1=1 ";
        if ($tipo === 'B' || $tipo === 'S') $w .= " AND T.TIPO_BIEN = :tipo ";
        if ($search !== '') $w .= " AND (T.NOMBRE_ITEM LIKE :q1 OR T.CLASIF_COD LIKE :q2 OR T.ESTADO_ORDEN LIKE :q3 OR T.CLASIF_PLANO LIKE :q4 OR T.COD_PRODUCTO LIKE :q4) ";
        if ($meta !== '') $w .= " AND CONVERT(VARCHAR(50), T.META) = :meta ";
        if ($act  !== '') $w .= " AND T.ACTIV_OPERAT_COD = :act ";
        if (in_array($fase, ['PROGRAMADO','MODIFICADO','EJECUTADO'], true))
            $w .= " AND (" . $this->faseExpr('T') . ") = :fase ";
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
     * Añade la columna ESTADO_FASE (Programado/Modificado/Ejecutado) ya clasificada.
     * @return array{rows: array, total: int}
     */
    public function rows(int $anioProg, int $anioEjec, int $secEjec, ?string $ccosto,
                         string $tipo = '', string $search = '', string $meta = '', string $act = '',
                         string $fase = '', string $sort = 'mod_desc', int $page = 1, int $perPage = 50): array
    {
        $inner = $this->innerSql(!!$ccosto);
        $fexpr = $this->faseExpr('T');
        $w = $this->whereFiltros($tipo, $search, $meta, $act, $fase);
        $order = match ($sort) {
            'mod_asc'  => 'T.IMPORTE_MOD ASC',
            'item_asc' => 'T.NOMBRE_ITEM ASC',
            /* Agrupado: actividad operativa y, dentro de ella, código de bien. */
            'act_item' => 'T.ACTIV_OPERAT_COD ASC, T.GRUPO_BIEN ASC, T.CLASE_BIEN ASC, T.FAMILIA_BIEN ASC, T.ITEM_BIEN ASC',
            default    => 'T.IMPORTE_MOD DESC',
        };
        /* El desempate no debe repetir columnas ya presentes en $order
           (SQL Server no admite columnas duplicadas en el ORDER BY). */
        $tie = ($sort === 'act_item') ? '' : ', T.CCOSTO_COD, T.ITEM_BIEN';

        $cst = $this->db->prepare("SELECT COUNT(*) FROM ({$inner}) T {$w}");
        $this->bindBase($cst, $anioProg, $anioEjec, $secEjec, $ccosto);
        $this->bindFiltros($cst, $tipo, $search, $meta, $act, $fase);
        $cst->execute();
        $total = (int)$cst->fetchColumn();

        $offset  = max(0, ($page - 1) * $perPage);
        $sql = "SELECT T.*, ({$fexpr}) AS ESTADO_FASE
                FROM ({$inner}) T {$w}
                ORDER BY {$order}{$tie}
                OFFSET {$offset} ROWS FETCH NEXT " . (int)$perPage . " ROWS ONLY";
        $st = $this->db->prepare($sql);
        $this->bindBase($st, $anioProg, $anioEjec, $secEjec, $ccosto);
        $this->bindFiltros($st, $tipo, $search, $meta, $act, $fase);
        $st->execute();

        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    /** Resumen por estado (conteo + monto), aplicando filtros salvo fase y paginado. */
    public function summary(int $anioProg, int $anioEjec, int $secEjec, ?string $ccosto,
                            string $tipo = '', string $search = '', string $meta = '', string $act = ''): array
    {
        $inner = $this->innerSql(!!$ccosto);
        $fexpr = $this->faseExpr('T');
        $w = $this->whereFiltros($tipo, $search, $meta, $act, '');
        $sql = "SELECT ({$fexpr}) AS fase, COUNT(*) c,
                       SUM(T.IMPORTE_PROG) prog,
                       SUM(T.IMPORTE_MOD)  monto,
                       SUM(T.IMPORTE_EJEC) ejec
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

        // 2b) CONSOLIDADO PAAC: dónde y cuándo se fijó el precio real de la línea.
        //     Trazabilidad: línea CMN (SEC_CUA_MOD_SAL) -> SIG_CUADRO_MODIFICADO_CMN
        //     -> SIG_PAAC_ITEM (precio + fecha) + SIG_PAAC_CONSOLIDADO (cert, estado).
        $consolidado = $b(
            "SELECT DISTINCT cmn.NRO_CONSOLID nro,
                    CONVERT(VARCHAR(10), pi.FECHA_PRECIO, 103) fecha_precio,
                    pi.CANTIDAD cant, pi.PRECIO_UNIT precio, pi.VALOR monto,
                    pc.NRO_CERTIFICA nro_cert,
                    CONVERT(VARCHAR(10), pc.FECHA_CONS, 103) fecha_consolid
             FROM SIG_CUADRO_MODIFICADO_DET d
             JOIN SIG_CUADRO_MODIFICADO_CMN cmn
                  ON cmn.SEC_EJEC=d.SEC_EJEC AND cmn.ANNO_EJEC=d.ANNO_EJEC
                 AND cmn.SEC_CUA_MOD_SAL=d.SEC_CUA_MOD_SAL
             JOIN SIG_PAAC_ITEM pi
                  ON pi.SEC_EJEC=cmn.SEC_EJEC AND pi.ANO_EJE=cmn.ANNO_EJEC
                 AND pi.TIPO_CONSOLID=cmn.TIPO_CONSOLID AND pi.NRO_CONSOLID=cmn.NRO_CONSOLID
                 AND pi.TIPO_GENERACION=cmn.TIPO_GENERACION AND pi.TIPO_BIEN=cmn.TIPO_BIEN
                 AND pi.SEC_CONSOLID=cmn.SEC_CONSOLID AND pi.SEC_RESUMEN=cmn.SEC_RESUMEN
                 AND pi.GRUPO_BIEN=d.GRUPO_BIEN AND pi.CLASE_BIEN=d.CLASE_BIEN
                 AND pi.FAMILIA_BIEN=d.FAMILIA_BIEN AND pi.ITEM_BIEN=d.ITEM_BIEN
             LEFT JOIN SIG_PAAC_CONSOLIDADO pc
                  ON pc.SEC_EJEC=cmn.SEC_EJEC AND pc.ANO_EJE=cmn.ANNO_EJEC
                 AND pc.TIPO_CONSOLID=cmn.TIPO_CONSOLID AND pc.NRO_CONSOLID=cmn.NRO_CONSOLID
                 AND pc.TIPO_BIEN=cmn.TIPO_BIEN
             WHERE d.SEC_EJEC=? AND d.ANNO_PROG=? AND d.ANNO_EJEC=? AND d.CENTRO_COSTO=? AND d.TIPO_BIEN=?
               AND d.GRUPO_BIEN=? AND d.CLASE_BIEN=? AND d.FAMILIA_BIEN=? AND d.ITEM_BIEN=?
             ORDER BY cmn.NRO_CONSOLID",
            [$secEjec,$anioProg,$anioEjec,$ccosto,$tipo,$g,$c,$f,$it]
        );

        // 3) ÓRDENES: cómo se subdividió (proveedor, cantidad, precio real, fecha).
        //    Acotado a la meta + clasificador de la línea (vía SIG_ORDEN_ITEM_PPTO).
        $ordenes = $b(
            "SELECT CASE WHEN oa.TIPO_BIEN='B' THEN 'OC ' ELSE 'OS ' END + CONVERT(VARCHAR,oa.NRO_ORDEN) orden,
                    ISNULL(ct.NOMBRE_PROV,'—') proveedor, oi.CANT_ITEM cant, oi.PREC_UNIT_MONEDA precio,
                    oi.PREC_TOT_SOLES monto, CONVERT(VARCHAR(10), oa.FECHA_ORDEN, 103) fecha, oa.ESTADO estado
             FROM SIG_ORDEN_ADQUISICION oa
             JOIN SIG_ORDEN_ITEM oi ON oi.SEC_EJEC=oa.SEC_EJEC AND oi.ANO_EJE=oa.ANO_EJE
                  AND oi.NRO_ORDEN=oa.NRO_ORDEN AND oi.TIPO_BIEN=oa.TIPO_BIEN
             JOIN SIG_ORDEN_ITEM_PPTO oip ON oip.SEC_EJEC=oa.SEC_EJEC AND oip.ANO_EJE=oa.ANO_EJE
                  AND oip.NRO_ORDEN=oa.NRO_ORDEN AND oip.TIPO_BIEN=oa.TIPO_BIEN
                  AND oip.SEC_FUNC=? AND oip.CLASIFICADOR=?
             LEFT JOIN SIG_CONTRATISTAS ct ON ct.PROVEEDOR=oa.PROVEEDOR
             WHERE oa.SEC_EJEC=? AND oa.ANO_EJE=? AND oa.ESTADO<>'A'
               AND oi.TIPO_BIEN=? AND oi.GRUPO_BIEN=? AND oi.CLASE_BIEN=? AND oi.FAMILIA_BIEN=? AND oi.ITEM_BIEN=?
             ORDER BY oa.NRO_ORDEN",
            [$secFunc,$clasificador,$secEjec,$anioProg,$tipo,$g,$c,$f,$it]
        );

        // 4) FASES DEL GASTO: compromiso (orden con exp SIAF) y devengado.
        //    Ambos acotados a la meta + clasificador de la línea.
        $fases = $b(
            "SELECT DISTINCT 'Comprometido' fase, CASE WHEN oa.TIPO_BIEN='B' THEN 'OC ' ELSE 'OS ' END + CONVERT(VARCHAR,oa.NRO_ORDEN) doc,
                    oa.NRO_ORDEN nro_orden,
                    CONVERT(VARCHAR(10), oa.FECHA_SIAF, 103) fecha, oi.PREC_TOT_SOLES monto
             FROM SIG_ORDEN_ADQUISICION oa
             JOIN SIG_ORDEN_ITEM oi ON oi.SEC_EJEC=oa.SEC_EJEC AND oi.ANO_EJE=oa.ANO_EJE
                  AND oi.NRO_ORDEN=oa.NRO_ORDEN AND oi.TIPO_BIEN=oa.TIPO_BIEN
             JOIN SIG_ORDEN_ITEM_PPTO oip ON oip.SEC_EJEC=oa.SEC_EJEC AND oip.ANO_EJE=oa.ANO_EJE
                  AND oip.NRO_ORDEN=oa.NRO_ORDEN AND oip.TIPO_BIEN=oa.TIPO_BIEN
                  AND oip.SEC_FUNC=? AND oip.CLASIFICADOR=?
             WHERE oa.SEC_EJEC=? AND oa.ANO_EJE=? AND ISNULL(oa.EXP_SIAF,0)<>0 AND oa.ESTADO<>'A'
               AND oi.TIPO_BIEN=? AND oi.GRUPO_BIEN=? AND oi.CLASE_BIEN=? AND oi.FAMILIA_BIEN=? AND oi.ITEM_BIEN=?
             UNION ALL
             SELECT 'Devengado', 'Deveng. ' + CONVERT(VARCHAR,dv.NRO_DEVENGADO),
                    dv.NRO_ORDEN nro_orden,
                    CONVERT(VARCHAR(10), dv.FECHA_REG, 103), di.VALOR_SOLES
             FROM SIG_DEVENGADO dv
             JOIN SIG_DEVENGADO_ITEM di ON di.SEC_EJEC=dv.SEC_EJEC AND di.ANO_EJE=dv.ANO_EJE AND di.NRO_DEVENGADO=dv.NRO_DEVENGADO
             JOIN SIG_ORDEN_ADQUISICION oa2 ON oa2.SEC_EJEC=dv.SEC_EJEC AND oa2.ANO_EJE=dv.ANO_EJE
                  AND oa2.NRO_ORDEN=dv.NRO_ORDEN AND oa2.TIPO_BIEN=dv.TIPO_BIEN AND oa2.ESTADO<>'A'
             JOIN SIG_ORDEN_ITEM_PPTO oip2 ON oip2.SEC_EJEC=oa2.SEC_EJEC AND oip2.ANO_EJE=oa2.ANO_EJE
                  AND oip2.NRO_ORDEN=oa2.NRO_ORDEN AND oip2.TIPO_BIEN=oa2.TIPO_BIEN
                  AND oip2.SEC_FUNC=? AND oip2.CLASIFICADOR=?
             WHERE dv.SEC_EJEC=? AND dv.ANO_EJE=?
               AND di.TIPO_BIEN=? AND di.GRUPO_BIEN=? AND di.CLASE_BIEN=? AND di.FAMILIA_BIEN=? AND di.ITEM_BIEN=?",
            [$secFunc,$clasificador,$secEjec,$anioProg,$tipo,$g,$c,$f,$it,
             $secFunc,$clasificador,$secEjec,$anioProg,$tipo,$g,$c,$f,$it]
        );

        return ['cuadro'=>$cuadro, 'certificacion'=>$cert, 'consolidado'=>$consolidado, 'ordenes'=>$ordenes, 'fases'=>$fases];
    }
}