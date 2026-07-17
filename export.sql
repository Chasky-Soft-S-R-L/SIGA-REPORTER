/* ============================================================================
   EXPORTA CUADRO MODIFICADO DE NECESIDADES (CMN) DESDE SIGA_104
   ----------------------------------------------------------------------------
   El CN se elabora en un ANIO DE EJECUCION (ANNO_EJEC) pero PROGRAMA para uno
   o mas anios (ANNO_PROG). El "CN 2026" = filas con ANNO_PROG = 2026.
   El anio de ejecucion vigente se detecta solo (el mas reciente).
   ============================================================================ */

USE SIGA_104;
GO

/* -------- PARAMETROS -------- */
DECLARE @ANO_PROG INT          = 2026;              -- Anio que se PROGRAMA (el del Excel)
DECLARE @CCOSTO   VARCHAR(15)  = '104.07.17.06';    -- Centro de costo. NULL = TODOS
DECLARE @SEC_EJEC NUMERIC(5)   = 104;               -- Ejecutora (tu base = 104)
DECLARE @ANO_EJEC INT;                              -- Se calcula abajo (no tocar)

/* Anio de ejecucion vigente = el mas reciente que programa @ANO_PROG */
SELECT @ANO_EJEC = MAX(ANNO_EJEC)
FROM   SIG_CUADRO_MODIFICADO_DET
WHERE  ANNO_PROG = @ANO_PROG AND SEC_EJEC = @SEC_EJEC;

/* ---------------------------------------------------------------------------
   CONSULTA PRINCIPAL
   --------------------------------------------------------------------------- */
SELECT
    D.ANNO_PROG                                                  AS PROGR_ANO_1,
    ff.FUENTE_FINANC_AGREGADA                                    AS FF,
    D.FUENTE_FINANC                                              AS RB,
    D.TIPO_BIEN                                                  AS TIPO_BIEN,
    D.CENTRO_COSTO                                               AS CCOSTO_COD,
    cc.NOMBRE_DEPEND                                             AS CCOSTO_NOMBRE,
    m.meta                                                       AS META,
    LEFT(REPLACE(REPLACE(D.CLASIFICADOR,'.',''),' ',''),2)       AS GENERICA,
    D.CLASIFICADOR                                               AS CLASIF_COD,
    D.TIPO_USO                                                   AS TIPO_USO,
    'C' + RIGHT('0000' + CONVERT(VARCHAR,D.CODIGO_TAREA),4)      AS ACTIV_OPERAT_COD,
    tar.nombre_tarea                                             AS ACTIV_OPERAT_NOMBRE,
    D.GRUPO_BIEN                                                 AS GRUPO_BIEN,
    D.CLASE_BIEN                                                 AS CLASE_BIEN,
    D.FAMILIA_BIEN                                               AS FAMILIA_BIEN,
    D.ITEM_BIEN                                                  AS ITEM_BIEN,
    cat.NOMBRE_ITEM                                              AS NOMBRE_ITEM,
    um.NOMBRE                                                    AS UNIDAD_MEDIDA,
    /* ---- CMN PROGRAMADO / MODIFICADO (vigente) ---- */
    D.CANT_TOTAL                                                 AS CANTIDAD_PROG,
    D.PRECIO_UNIT                                                AS PRECIO_UNIT_PROG,
    D.MNTO_TOTAL                                                 AS IMPORTE_CMN_PROG,
    /* ---- EJECUTADO (ordenes del anio programado) - VALIDAR ---- */
    ISNULL(ej.CANT_EJEC,0)                                       AS CANTIDAD_EJEC,
    CASE WHEN ISNULL(ej.CANT_EJEC,0) > 0
         THEN ej.MNTO_EJEC / ej.CANT_EJEC ELSE 0 END            AS PRECIO_UNIT_EJEC,
    ISNULL(ej.MNTO_EJEC,0)                                       AS IMPORTE_CMN_EJEC,
    D.MNTO_TOTAL - ISNULL(ej.MNTO_EJEC,0)                        AS DIFERENCIA
FROM      SIG_CUADRO_MODIFICADO_DET D
JOIN      SIG_CENTRO_COSTO cc
          ON cc.SEC_EJEC=D.SEC_EJEC AND cc.ANO_EJE=D.ANNO_EJEC AND cc.CENTRO_COSTO=D.CENTRO_COSTO
LEFT JOIN CATALOGO_BIEN_SERV cat
          ON cat.SEC_EJEC=D.SEC_EJEC AND cat.TIPO_BIEN=D.TIPO_BIEN AND cat.GRUPO_BIEN=D.GRUPO_BIEN
         AND cat.CLASE_BIEN=D.CLASE_BIEN AND cat.FAMILIA_BIEN=D.FAMILIA_BIEN AND cat.ITEM_BIEN=D.ITEM_BIEN
LEFT JOIN UNIDAD_MEDIDA um    ON um.UNIDAD_MEDIDA=D.UNIDAD_MEDIDA
LEFT JOIN META m              ON m.sec_ejec=D.SEC_EJEC AND m.ano_eje=D.ANNO_EJEC AND m.sec_func=D.SEC_FUNC
LEFT JOIN FUENTE_FINANC ff    ON ff.ANO_EJE=D.ANNO_EJEC AND ff.FUENTE_FINANC=D.FUENTE_FINANC
LEFT JOIN SIG_CENTRO_COSTO_TAREA tar
          ON tar.sec_ejec=D.SEC_EJEC AND tar.ano_eje=D.ANNO_EJEC AND tar.centro_costo=D.CENTRO_COSTO
         AND tar.codigo_tarea=D.CODIGO_TAREA AND tar.tipo_tarea=D.TIPO_TAREA AND tar.nivel_tarea=D.NIVEL_TAREA
LEFT JOIN (
    SELECT oi.SEC_EJEC, oi.ANO_EJE, oi.TIPO_BIEN, oi.GRUPO_BIEN, oi.CLASE_BIEN,
           oi.FAMILIA_BIEN, oi.ITEM_BIEN,
           SUM(oi.CANT_ITEM) AS CANT_EJEC, SUM(oi.PREC_TOT_SOLES) AS MNTO_EJEC
    FROM   SIG_ORDEN_ITEM oi
    JOIN   SIG_ORDEN_ADQUISICION oa
           ON oa.SEC_EJEC=oi.SEC_EJEC AND oa.ANO_EJE=oi.ANO_EJE
          AND oa.NRO_ORDEN=oi.NRO_ORDEN AND oa.TIPO_BIEN=oi.TIPO_BIEN
    WHERE  oa.ESTADO <> 'A'
    GROUP BY oi.SEC_EJEC, oi.ANO_EJE, oi.TIPO_BIEN, oi.GRUPO_BIEN, oi.CLASE_BIEN,
             oi.FAMILIA_BIEN, oi.ITEM_BIEN
) ej ON ej.SEC_EJEC=D.SEC_EJEC AND ej.ANO_EJE=D.ANNO_PROG AND ej.TIPO_BIEN=D.TIPO_BIEN
     AND ej.GRUPO_BIEN=D.GRUPO_BIEN AND ej.CLASE_BIEN=D.CLASE_BIEN
     AND ej.FAMILIA_BIEN=D.FAMILIA_BIEN AND ej.ITEM_BIEN=D.ITEM_BIEN
WHERE D.ANNO_PROG = @ANO_PROG
  AND D.ANNO_EJEC = @ANO_EJEC
  AND D.SEC_EJEC  = @SEC_EJEC
  AND (@CCOSTO IS NULL OR D.CENTRO_COSTO = @CCOSTO)
  AND D.ESTADO NOT IN ('E','ET')            -- excluye items EXCLUIDOS del CMN
ORDER BY D.CENTRO_COSTO, tar.codigo_tarea, D.CLASIFICADOR, D.ITEM_BIEN;
GO