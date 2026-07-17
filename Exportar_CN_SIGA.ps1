<#
================================================================================
  Exportar_CN_SIGA.ps1
  Genera el Excel del Cuadro Modificado de Necesidades (CMN) directo desde
  SIGA_104, sin digitar nada a mano.
--------------------------------------------------------------------------------
  USO (desde PowerShell, parado donde quieras que salga el archivo):

    # Un solo centro de costo:
    .\Exportar_CN_SIGA.ps1 -Anio 2026 -CentroCosto "104.07.17.06"

    # Todos los centros (una hoja por centro):
    .\Exportar_CN_SIGA.ps1 -Anio 2026

  REQUISITOS (instalar una sola vez, si no los tienes):
    Install-Module SqlServer   -Scope CurrentUser -Force
    Install-Module ImportExcel -Scope CurrentUser -Force
================================================================================
#>

param(
    [int]    $Anio        = 2026,
    [string] $CentroCosto = $null,                 # vacio = todos
    [string] $Servidor    = "localhost",
    [string] $BaseDatos   = "SIGA_104",
    [string] $Salida      = ".\CN_SIGA_$((Get-Date).ToString('yyyyMMdd_HHmm')).xlsx"
)

# --- La consulta (misma logica que CN_SIGA_export.sql) ---
$sql = @"
DECLARE @ANO      INT         = $Anio;
DECLARE @CCOSTO   VARCHAR(15) = $(if([string]::IsNullOrWhiteSpace($CentroCosto)){'NULL'}else{"'$CentroCosto'"});
DECLARE @SEC_EJEC NUMERIC(5)  = NULL;
IF @SEC_EJEC IS NULL SELECT TOP 1 @SEC_EJEC = SEC_EJEC FROM SIG_CENTRO_COSTO;

SELECT
    D.ANNO_PROG                                              AS PROGR_ANO_1,
    ff.FUENTE_FINANC_AGREGADA                                AS FF,
    D.FUENTE_FINANC                                          AS RB,
    D.TIPO_BIEN                                              AS TIPO_BIEN,
    D.CENTRO_COSTO                                           AS CCOSTO_COD,
    cc.NOMBRE_DEPEND                                         AS CCOSTO_NOMBRE,
    m.meta                                                   AS META,
    LEFT(REPLACE(REPLACE(D.CLASIFICADOR,'.',''),' ',''),2)   AS GENERICA,
    D.CLASIFICADOR                                           AS CLASIF_COD,
    D.TIPO_USO                                               AS TIPO_USO,
    'C' + RIGHT('0000' + CONVERT(VARCHAR,D.CODIGO_TAREA),4)  AS ACTIV_OPERAT_COD,
    tar.nombre_tarea                                         AS ACTIV_OPERAT_NOMBRE,
    D.GRUPO_BIEN, D.CLASE_BIEN, D.FAMILIA_BIEN, D.ITEM_BIEN,
    cat.NOMBRE_ITEM                                          AS NOMBRE_ITEM,
    um.NOMBRE                                                AS UNIDAD_MEDIDA,
    D.CANT_TOTAL                                             AS CANTIDAD_PROG,
    D.PRECIO_UNIT                                            AS PRECIO_UNIT_PROG,
    D.MNTO_TOTAL                                             AS IMPORTE_CMN_PROG,
    ISNULL(ej.CANT_EJEC,0)                                   AS CANTIDAD_EJEC,
    CASE WHEN ISNULL(ej.CANT_EJEC,0)>0 THEN ej.MNTO_EJEC/ej.CANT_EJEC ELSE 0 END AS PRECIO_UNIT_EJEC,
    ISNULL(ej.MNTO_EJEC,0)                                   AS IMPORTE_CMN_EJEC,
    D.MNTO_TOTAL - ISNULL(ej.MNTO_EJEC,0)                    AS DIFERENCIA
FROM      SIG_CUADRO_MODIFICADO_DET D
JOIN      SIG_CENTRO_COSTO cc
          ON cc.SEC_EJEC=D.SEC_EJEC AND cc.ANO_EJE=D.ANNO_EJEC AND cc.CENTRO_COSTO=D.CENTRO_COSTO
LEFT JOIN CATALOGO_BIEN_SERV cat
          ON cat.SEC_EJEC=D.SEC_EJEC AND cat.TIPO_BIEN=D.TIPO_BIEN AND cat.GRUPO_BIEN=D.GRUPO_BIEN
         AND cat.CLASE_BIEN=D.CLASE_BIEN AND cat.FAMILIA_BIEN=D.FAMILIA_BIEN AND cat.ITEM_BIEN=D.ITEM_BIEN
LEFT JOIN UNIDAD_MEDIDA um       ON um.UNIDAD_MEDIDA=D.UNIDAD_MEDIDA
LEFT JOIN META m                 ON m.sec_ejec=D.SEC_EJEC AND m.ano_eje=D.ANNO_EJEC AND m.sec_func=D.SEC_FUNC
LEFT JOIN FUENTE_FINANC ff       ON ff.ANO_EJE=D.ANNO_EJEC AND ff.FUENTE_FINANC=D.FUENTE_FINANC
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
) ej ON ej.SEC_EJEC=D.SEC_EJEC AND ej.ANO_EJE=D.ANNO_EJEC AND ej.TIPO_BIEN=D.TIPO_BIEN
     AND ej.GRUPO_BIEN=D.GRUPO_BIEN AND ej.CLASE_BIEN=D.CLASE_BIEN
     AND ej.FAMILIA_BIEN=D.FAMILIA_BIEN AND ej.ITEM_BIEN=D.ITEM_BIEN
WHERE D.ANNO_EJEC=@ANO
  AND (@CCOSTO IS NULL OR D.CENTRO_COSTO=@CCOSTO)
  AND D.SEC_EJEC=@SEC_EJEC
  AND D.ESTADO='A'
ORDER BY D.CENTRO_COSTO, tar.codigo_tarea, D.CLASIFICADOR, D.ITEM_BIEN;
"@

Write-Host "Consultando SIGA ($BaseDatos en $Servidor)..." -ForegroundColor Cyan
$rows = Invoke-Sqlcmd -ServerInstance $Servidor -Database $BaseDatos -Query $sql -QueryTimeout 300

if (-not $rows) { Write-Warning "La consulta no devolvio filas. Revisa Anio/CentroCosto/estado."; return }

Write-Host ("Filas obtenidas: {0}" -f $rows.Count) -ForegroundColor Green

# Exportar: una hoja por centro de costo (o una sola si filtraste)
$rows | Group-Object CCOSTO_COD | ForEach-Object {
    $hoja = ($_.Name -replace '[\\/\*\?\[\]:]','_')          # nombre de hoja valido
    if ($hoja.Length -gt 31) { $hoja = $hoja.Substring(0,31) }
    $_.Group | Export-Excel -Path $Salida -WorksheetName $hoja -AutoSize -FreezeTopRow -BoldTopRow -TableStyle Medium2
}

Write-Host ("Listo -> {0}" -f (Resolve-Path $Salida)) -ForegroundColor Green