<?php
/**
 * CAPA QUERY  ·  SIGA-REPORTER
 * Acceso de solo lectura a SIGA_104. Reutiliza la consulta del CMN ya validada.
 * Sin lógica de dominio: solo trae filas.
 *
 * COMPATIBLE PHP 7.4: sin match(), sin str_starts_with, sin tipos mixed.
 *
 * ESTADOS (3): Programado · Modificado · Ejecutado. Se clasifican así:
 *   - Ejecutado  = tiene ejecución real  (IMPORTE_EJEC > 0)
 *   - Modificado = para SERVICIOS, el vigente difiere del original en MONTO
 *                  (IMPORTE_MOD <> IMPORTE_PROG); para BIENES, difiere en
 *                  CANTIDAD (CANTIDAD_MOD <> CANTIDAD_PROG). En un bien lo que
 *                  se modifica es la cantidad, no el precio: comparar por monto
 *                  marcaba MODIFICADO aunque solo cambiara el precio unitario.
 *                  Comparación "de la línea": directa CANTIDAD_MOD vs
 *                  CANTIDAD_PROG (ítem de una sola línea de clasificador).
 *   - Programado = el resto
 *
 * FILTRO EJECUTADO/NO EJECUTADO ($ejec): 'si' = solo ítems con IMPORTE_EJEC > 0
 * (lo que sí se compró) · 'no' = solo ítems con IMPORTE_EJEC = 0 (lo que NO se
 * compró) · '' = ambos. Se aplica en whereFiltros() y por lo tanto afecta a
 * rows() y summary() por igual.
 *
 * MODELO DE MONTOS (vista de Presupuestos):
 *   IMPORTE_EJEC   = compromiso ejecutado del cuadro (dm.MNTO_SOLES)
 *   DEVENGADO      = devengado contable real (SIG_DEVENGADO)   ← fase posterior
 *   DIFERENCIA     = Programado (original) - Ejecutado         ← confirmado por el
 *                    área usuaria; negativo = sobregiro respecto al CMN original
 *   SALDO_DEVENGAR = Ejecutado - Devengado                     ← ejecutado aún sin devengar
 *   (la columna Compromiso bruto = VALOR_DEPEND ya NO se expone: no interesa)
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * CÓMO FUNCIONA LA CERTIFICACIÓN (CCP) · imprescindible para leer el bloque `ej`
 * ══════════════════════════════════════════════════════════════════════════════
 * El Certificado de Crédito Presupuestario es el acto que reserva el crédito
 * antes de comprometer el gasto (sin certificación no hay compra, sea cual sea
 * el monto). Cuatro hechos que condicionan este archivo, todos verificados
 * contra la base 104 y contra las guías del MEF:
 *
 * 1. UN CCP CUBRE MUCHAS LÍNEAS Y MUCHOS CENTROS. Al registrarlo se agregan
 *    varias metas + clasificadores dentro del MISMO certificado. Ejemplo real:
 *    la cert. 211/2024 reparte importes entre ~12 centros de costo distintos.
 *    → Por eso NUNCA se puede identificar "el certificado de un ítem" buscando
 *      por SEC_FUNC + CLASIFICADOR: ese era el bug del antiguo OUTER APPLY
 *      `cert` (mostraba certificados de otros ítems de la misma línea), y es un
 *      error CONCEPTUAL, no de join. El único vínculo fiable ítem→certificado
 *      pasa por la ORDEN (SIG_ORDEN_ADQUISICION.NRO_CERTIFICA).
 *
 * 2. DOS CORRELATIVOS PARA EL MISMO DOCUMENTO, RELACIÓN 1:1.
 *      · SIGA = NRO_CERTIFICA      (correlativo interno de logística)
 *      · SIAF = NRO_CERTIFICA_SIAF (correlativo del expediente en el MEF)
 *    El SIGA transmite el certificado a la plataforma SIAF web de forma
 *    individual y recibe de vuelta su número. Verificado 2022-2026: ninguna
 *    certificación tiene más de un NRO_CERTIFICA_SIAF distinto.
 *
 * 3. NRO_CERTIFICA SE REINICIA CADA AÑO. La cert. 211 de 2024 tiene SIAF 448;
 *    la 211 de 2025 tiene SIAF 327. Son documentos distintos.
 *    → El JOIN a SIG_CERTIFICACION DEBE llevar ANO_EJE además de NRO_CERTIFICA.
 *      Si alguien "simplifica" esa condición, la mitad de los badges mostrarán
 *      el SIAF de otro año.
 *
 * 4. LAS TABLAS DE DETALLE NO SIRVEN PARA ATRIBUIR EL CERTIFICADO A UNA FILA.
 *      · SIG_CERTIFICACION_FASE  → SECUENCIA_FASE es la ETAPA del trámite
 *        (Certificación, Compromiso Anual, y las secuencias de anulación /
 *        rebaja / ampliación), no una línea. Repite la misma línea una vez por
 *        fase y trae NRO_ORDEN vacío.
 *      · SIG_CERTIFICACION_PPTO  → llega a meta+clasificador, pero duplicada por
 *        fase; sumarla sin acotar la secuencia da importes al doble.
 *      · SIG_CERTIFICACION_DEPE  → llega a CENTRO_COSTO, pero TIPO_TAREA /
 *        NIVEL_TAREA / CODIGO_TAREA vienen VACÍOS y la tabla dejó de poblarse
 *        después de 2024 (17 725 filas, todas de ese año).
 *    → Conclusión: un ítem CERTIFICADO PERO SIN ORDEN no puede mostrar su
 *      certificado. Se queda en 'PENDIENTE', que es preferible a inventar uno.
 *
 * 5. ANULADO ES INDEPENDIENTE DEL ESTADO SIAF. ESTADO_CERTIFICA_SIAF = 3
 *    ("aprobado en el SIAF") concentra el 99 % de los casos, pero 575 de esas
 *    aprobadas están además ANULADO<>0. El estado 4 NO es "anulado" (26 de 27
 *    tienen ANULADO=0). Por eso el badge marca la anulación leyendo ANULADO,
 *    no el estado SIAF.
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * NOTA SOBRE CANT_VIG / MNTO_VIG (fix POLLO BEBE, sec_cua_mod_sal con saldo 0):
 *   El vigente sale de COALESCE(NULLIF(MAX(s.CANT_TOTAL),0), SUM(det no excluido)).
 *   El NULLIF(...,0) es clave: SIG_CUADRO_MODIFICADO_SALDO a veces trae CANT_TOTAL=0
 *   (saldo sin poblar) para un ítem que SÍ está vigente en el DET (estado 'C', 300
 *   und). Sin el NULLIF, COALESCE(0, 300) = 0 y el importe modificado caía a 0
 *   (el precio se salvaba por otra rama, por eso se veía 3.00 × 0 = 0). Verificado
 *   en el centro 104.07.13.03.16: 0 casos de "saldo 0 legítimo", así que tratar el
 *   saldo 0 como "sin dato" (y caer al DET) es correcto. El CASE externo
 *   "... = 0 THEN 0" sigue cubriendo el caso legítimo de todo-excluido → vigente 0.
 *
 * FIX DATOS_EJECUCION (ESTADO_ORDEN) · billing scope bug:
 *   El bloque `ej` (lista de órdenes tipo "OS 171 · DEVENGADO · Cert SIGA 211 ·
 *   SIAF 327" que se ve en la columna DATOS EJECUCION) antes hacía match SOLO por
 *   SEC_FUNC + CLASIFICADOR + ítem, vía SIG_ORDEN_ITEM_PPTO. Esa combinación NO
 *   identifica una fila única del cuadro: si el mismo ítem+meta+clasificador se usa
 *   en OTRA actividad operativa o en OTRO centro de costo (común con ítems genéricos
 *   como "servicio de mano de obra no calificada"), `ej` traía las órdenes de esas
 *   OTRAS actividades/centros también — la lista no coincidía con IMPORTE_EJEC/
 *   DEVENGADO de la fila (que sí están correctamente filtrados por centro+tarea
 *   vía `dev`/SIG_DEPEN_META_CUADRO). Se corrigió `ej` para que agregue por la
 *   MISMA clave que `dev` (centro + tarea + ítem, a través de
 *   SIG_CUADRO_ADQUISICION → SIG_DETALLE_BSERV_CUADRO → SIG_DEPEN_META_CUADRO)
 *   en vez de meta+clasificador+ítem.
 *
 * FIX CERTIFICADO INEXISTENTE (ESTADO_ORDEN):
 *   Antes, si el ítem no tenía órdenes propias (`ej` vacío), ESTADO_ORDEN caía
 *   a un OUTER APPLY `cert` que buscaba una certificación por SEC_FUNC +
 *   CLASIFICADOR (nivel de línea presupuestal, NO de ítem). Como una línea
 *   certifica varios ítems, mostraba "CERTIFICADO · Cert N" de un certificado
 *   que no pertenecía a ese ítem. Se eliminó ese fallback (y el OUTER APPLY
 *   `cert`, que quedó sin uso): ahora, sin órdenes propias, se muestra
 *   'PENDIENTE'. Ver el punto 1 y el punto 4 del bloque de certificación de
 *   arriba: no es un bug reparable, es que el dato no existe a nivel de ítem.
 *   La certificación real del ítem se ve en el expediente, ligada a las órdenes
 *   del ítem (ver historial()).
 */
class CmnQuery
{
    private $db;

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

    /**
     * Lista de centros de costo con datos, para el combo de navegación.
     * Incluye el RESPONSABLE del centro (el jefe formal, SIG_CENTRO_COSTO.EMPLEADO
     * resuelto contra SIG_PERSONAL). OJO: es distinto de la columna RESPONSABLE de
     * la tabla, que es por FILA (el empleado del consolidado PAAC de ese ítem).
     * Unos 64 de 317 centros no tienen jefe asignado (órganos colegiados como la
     * Asamblea o el Consejo Universitario): ahí llega cadena vacía, nunca NULL.
     */
    public function centros(int $anioProg, int $anioEjec, int $secEjec): array
    {
        $st = $this->db->prepare(
            "SELECT DISTINCT D.CENTRO_COSTO AS cod, cc.NOMBRE_DEPEND AS nombre,
                    ISNULL(per.nombre_completo, '') AS responsable
             FROM   SIG_CUADRO_MODIFICADO_DET D
             JOIN   SIG_CENTRO_COSTO cc
                    ON cc.SEC_EJEC=D.SEC_EJEC AND cc.ANO_EJE=D.ANNO_EJEC
                   AND cc.CENTRO_COSTO=D.CENTRO_COSTO
             LEFT JOIN SIG_PERSONAL per
                    ON per.sec_ejec=cc.SEC_EJEC AND per.empleado=cc.EMPLEADO
             WHERE  D.ANNO_PROG=? AND D.ANNO_EJEC=? AND D.SEC_EJEC=?
               AND  ISNULL(D.ESTADO,'') NOT IN ('E','ET')
             ORDER BY D.CENTRO_COSTO"
        );
        $st->execute([$anioProg, $anioEjec, $secEjec]);
        return $st->fetchAll();
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * FUENTE Y META DE CADA FILA DE EJECUCIÓN · sin prorrateo
     * ═══════════════════════════════════════════════════════════════════════
     * PROBLEMA: una misma línea del cuadro (centro + tarea + ítem) puede estar
     * programada con DOS fuentes distintas (240 grupos así en 2026), y una orden
     * puede repartirse entre varias metas y centros. La cadena
     * SIG_CUADRO_ADQUISICION → SIG_DETALLE_BSERV_CUADRO → SIG_DEPEN_META_CUADRO
     * NO lleva fuente ni meta, así que agregando solo por centro+tarea+ítem las
     * dos filas del cuadro recibían la ejecución COMPLETA y los totales salían
     * al doble.
     *
     * SOLUCIÓN: SIG_DEPEN_META_CUADRO.SEC_META es un índice local (1..N dentro
     * del cuadro) que apunta a SIG_DETALLE_METAS_CUADRO, donde SÍ están
     * sec_func (meta), CLASIFICADOR y FUENTE_FINANC de esa porción. La relación
     * es 1:1 y exacta: en la OC 51 del ítem 710600010015, SEC_META=4 → meta 39,
     * fuente 00, 20 unidades, S/ 78.59 — que es literalmente la fila del cuadro
     * del centro 104.07.08.03.02. Basta con unir esa tabla y agregar por
     * fuente + meta; NO hace falta repartir nada.
     *
     * INTENTO ANTERIOR (descartado, quedó documentado para que nadie lo repita):
     * se ponderaba con un PESO = monto de la fuente / monto total, calculado
     * desde SIG_ORDEN_ITEM_PPTO agrupando por orden+ítem. Falla por dos motivos:
     *   · Las filas de SIG_ORDEN_ITEM_PPTO están a nivel de META+clasificador+
     *     fuente, no de centro. Agrupar solo por orden+ítem mezcla las metas y
     *     diluye el peso: la fila de 78.59 (peso real 1.0) recibía 0.9242 y
     *     mostraba 72.64. El importe quedaba SUBESTIMADO.
     *   · Aplicar un peso MONETARIO a CANT_DEPEND produce cantidades
     *     fraccionarias (18.49 botas), imposibles en el SIGA: los CANT_DEPEND
     *     son todos enteros.
     * La lección: cuando el reparto ya está registrado en una tabla, buscarlo;
     * prorratear es siempre la segunda mejor opción y arrastra errores.
     */

    /** SQL interno del reporte (sin ORDER BY ni paginado), reutilizable. */
    private function innerSql(bool $withCC): string
    {
        $filtroCC = $withCC ? " AND d.CENTRO_COSTO = :ccosto " : "";
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
            clg.NOMBRE_CLASIF                                       AS CLASIF_NOMBRE,
            D.TIPO_USO                                              AS TIPO_USO,
            'C' + RIGHT('0000' + CONVERT(VARCHAR,D.CODIGO_TAREA),4) AS ACTIV_OPERAT_COD,
            D.TIPO_TAREA                                            AS TIPO_TAREA,
            D.NIVEL_TAREA                                           AS NIVEL_TAREA,
            D.CODIGO_TAREA                                          AS CODIGO_TAREA,
            tar.nombre_tarea                                        AS ACTIV_OPERAT_NOMBRE,
            D.GRUPO_BIEN, D.CLASE_BIEN, D.FAMILIA_BIEN, D.ITEM_BIEN,
            D.GRUPO_BIEN + D.CLASE_BIEN + D.FAMILIA_BIEN + D.ITEM_BIEN AS COD_PRODUCTO,
            REPLACE(REPLACE(D.CLASIFICADOR,'.',''),' ','')          AS CLASIF_PLANO,
            ISNULL(ff.NOMBRE, D.FUENTE_FINANC)                      AS FF_NOMBRE,
            cat.NOMBRE_ITEM                                         AS NOMBRE_ITEM,
            um.NOMBRE                                               AS UNIDAD_MEDIDA,
            /* Las tres columnas de PROGRAMADO van a 0 cuando la fila es solo una
               inclusión (ES_INCL): no existía en el cuadro original, así que no
               tiene nada programado. Ver la nota de ES_INCL más abajo. */
            CASE WHEN D.ES_INCL = 1 THEN 0
                 WHEN D.TIPO_BIEN='S'
                 THEN CASE WHEN ISNULL(ori.MNTO_TOTAL,0) > 0 THEN 1 ELSE 0 END
                 WHEN D.GRUPOS_ITEM <= 1 THEN ISNULL(ori.CANT_TOTAL, 0)
                 WHEN ISNULL(D.MOD_ITEM,0) > 0
                      THEN ROUND(ISNULL(ori.CANT_TOTAL, 0)
                           * (CAST(D.MNTO_TOTAL AS FLOAT) / CAST(D.MOD_ITEM AS FLOAT)), 4)
                 ELSE ROUND(ISNULL(ori.CANT_TOTAL, 0) / NULLIF(CAST(D.GRUPOS_ITEM AS FLOAT),0), 4)
            END                                                     AS CANTIDAD_PROG,
            CASE WHEN D.ES_INCL = 1 THEN 0
                 WHEN D.TIPO_BIEN='S'
                 THEN CASE WHEN D.GRUPOS_ITEM <= 1 THEN ISNULL(ori.MNTO_TOTAL, 0)
                           WHEN ISNULL(D.MOD_ITEM,0) > 0
                                THEN ISNULL(ori.MNTO_TOTAL, 0)
                                     * (CAST(D.MNTO_TOTAL AS FLOAT) / CAST(D.MOD_ITEM AS FLOAT))
                           ELSE ISNULL(ori.MNTO_TOTAL, 0) / NULLIF(CAST(D.GRUPOS_ITEM AS FLOAT),0) END
                 ELSE ISNULL(ori.PRECIO_UNIT, 0) END                AS PRECIO_UNIT_PROG,
            /* IMPORTE_PROG: SIN ROUND en la rama prorrateada. Redondear cada
               línea a 2 decimales hacía que la suma de las partes no diera el
               total del original (el reporte marcaba 194 346.31 contra los
               194 346.39 del SIGA). Guardando el valor exacto, cada celda se
               muestra redondeada pero el TOTAL cuadra al céntimo. */
            CASE WHEN D.ES_INCL = 1 THEN 0
                 WHEN D.GRUPOS_ITEM <= 1 THEN ISNULL(ori.MNTO_TOTAL, 0)
                 WHEN ISNULL(D.MOD_ITEM,0) > 0
                      /* CAST A FLOAT EN EL COCIENTE · no es cosmético.
                         MOD_ITEM sale de SUM(SUM(MNTO_VIG)) OVER(...), que acumula
                         precisión numeric alta. Cuando el resultado de la división
                         pasaría de precisión 38, SQL Server RECORTA la escala hasta
                         su mínimo de 6 decimales: 36000/79200 = 0.454545454545… se
                         trunca a 0.454545 y al multiplicar por 79200 da 35 999.964
                         en vez de 36 000. Repetido en varias líneas, eso producía
                         los 0.08 de descuadre contra el SIGA (194 346.31 vs
                         194 346.39). El FLOAT conserva ~15 dígitos significativos,
                         de sobra para importes de esta magnitud. */
                      THEN ISNULL(ori.MNTO_TOTAL, 0)
                           * (CAST(D.MNTO_TOTAL AS FLOAT) / CAST(D.MOD_ITEM AS FLOAT))
                 ELSE ISNULL(ori.MNTO_TOTAL, 0) / NULLIF(CAST(D.GRUPOS_ITEM AS FLOAT),0)
            END                                                     AS IMPORTE_PROG,
            CASE WHEN D.TIPO_BIEN='S' THEN 1 ELSE D.CANT_TOTAL END  AS CANTIDAD_MOD,
            CASE WHEN D.TIPO_BIEN='S' THEN D.MNTO_TOTAL
                 ELSE D.PRECIO_UNIT END                             AS PRECIO_UNIT_MOD,
            D.MNTO_TOTAL                                            AS IMPORTE_MOD,
            /* ESTADO_ORDEN: solo las órdenes reales del ítem (`ej`, acotado a
               centro+tarea+ítem). Si el ítem no tiene órdenes propias → PENDIENTE.
               Ya NO se usa el fallback de certificación por SEC_FUNC+CLASIFICADOR
               (mostraba certificados de otros ítems de la misma línea). */
            COALESCE(ej.ORDENES, 'PENDIENTE')                       AS ESTADO_ORDEN,
            ISNULL(per.nombre_completo, '')                        AS RESPONSABLE,
            STUFF(  CASE WHEN D.LIN_APROB > 0 OR D.LIN_OTRO > 0 THEN ', PROGRAMADO' ELSE '' END
                  + CASE WHEN D.LIN_INCL  > 0 THEN ', INCLUIDO' ELSE '' END
                  + CASE WHEN D.LIN_EXCL  > 0 THEN ', EXCLUIDO' ELSE '' END
                  /* MODIFICADO: servicios por MONTO, bienes por CANTIDAD. En un
                     bien lo que se modifica es la cantidad, no el precio; comparar
                     por monto marcaba MODIFICADO aunque solo variara el precio
                     unitario. Comparación directa de la línea (ítem de una sola
                     línea de clasificador). */
                  + CASE
                      WHEN D.TIPO_BIEN='S'
                           AND ISNULL(ori.MNTO_TOTAL,0) > 0
                           AND ABS(D.MNTO_TOTAL - ori.MNTO_TOTAL) > 0.005
                      THEN ', MODIFICADO'
                      WHEN D.TIPO_BIEN='B'
                           AND ISNULL(ori.CANT_TOTAL,0) > 0
                           AND ABS(D.CANT_TOTAL - ori.CANT_TOTAL) > 0.005
                      THEN ', MODIFICADO'
                      ELSE '' END
                  , 1, 2, '')                                       AS ESTADO_CMN,
            D.NRO_LINEAS                                            AS NRO_LINEAS,
            /* CANTIDAD_EJEC/PRECIO_UNIT_EJEC/IMPORTE_EJEC/DEVENGADO ya NO usan
               el prorrateo repTI por presupuesto (D.MOD_TI/GRUPOS_TI): `dev`
               (más abajo) calcula el monto EXACTO de esta línea usando el peso
               real declarado en SIG_ORDEN_ITEM_PPTO por cada orden (0%, 100% o
               proporcional según lo que el propio SIGA registró), en vez de
               repartir por proporción del presupuesto planeado. Cuando el ítem
               solo vive en una línea de clasificador, el peso siempre es 1 y
               el resultado es idéntico al de antes; cuando vive en varias
               líneas (el bug original: cabecera vs. tabla no coincidían), el
               peso ahora refleja qué orden pertenece a cuál línea de verdad. */
            CASE WHEN D.TIPO_BIEN='S'
                 THEN CASE WHEN ISNULL(dev.MNTO_EJEC,0) > 0 THEN 1 ELSE 0 END
                 ELSE ISNULL(dev.CANT_DEV, 0)
            END                                                     AS CANTIDAD_EJEC,
            /* PRECIO_UNIT_EJEC: precio unitario según el EJECUTADO del cuadro. */
            CASE WHEN D.TIPO_BIEN='S'
                 THEN ISNULL(dev.MNTO_EJEC, 0)
                 WHEN ISNULL(dev.CANT_DEV,0) > 0 THEN dev.MNTO_EJEC / dev.CANT_DEV
                 ELSE 0 END                                         AS PRECIO_UNIT_EJEC,
            /* IMPORTE_EJEC = compromiso ejecutado del cuadro (dm.MNTO_SOLES,
               ponderado por el peso real de clasificador que calcula `dev`). */
            ISNULL(dev.MNTO_EJEC, 0)                               AS IMPORTE_EJEC,
            /* DEVENGADO = devengado contable real (SIG_DEVENGADO), mismo criterio. */
            ISNULL(dev.MNTO_DEVREAL, 0)                            AS DEVENGADO,
            /* DIFERENCIA = Programado (CMN original) - Ejecutado. Confirmado por el
               área usuaria: la diferencia se mide contra lo ORIGINALMENTE programado,
               no contra el vigente/modificado. Negativo = se ejecutó más de lo que
               se programó (sobregiro respecto al cuadro original). Se repite aquí la
               misma expresión de IMPORTE_PROG porque SQL Server no permite referenciar
               el alias de una columna dentro del mismo SELECT. */
            (CASE WHEN D.ES_INCL = 1 THEN 0
                  WHEN D.GRUPOS_ITEM <= 1 THEN ISNULL(ori.MNTO_TOTAL, 0)
                  WHEN ISNULL(D.MOD_ITEM,0) > 0
                       THEN ISNULL(ori.MNTO_TOTAL, 0)
                            * (CAST(D.MNTO_TOTAL AS FLOAT) / CAST(D.MOD_ITEM AS FLOAT))
                  ELSE ISNULL(ori.MNTO_TOTAL, 0) / NULLIF(CAST(D.GRUPOS_ITEM AS FLOAT),0)
             END)
            -
            ISNULL(dev.MNTO_EJEC, 0)                               AS DIFERENCIA,
            /* SALDO_DEVENGAR = Ejecutado - Devengado  (ejecutado aún sin devengar). */
            ISNULL(dev.MNTO_EJEC, 0) - ISNULL(dev.MNTO_DEVREAL, 0)  AS SALDO_DEVENGAR,
            /* GRUPOS_TI: cuántas líneas del cuadro (mismo centro+actividad+ítem,
               distinto clasificador O DISTINTA FUENTE) comparten este ítem.
               La partición NO incluye FUENTE_FINANC, pero la subconsulta D sí
               agrupa por fuente: por eso GRUPOS_TI = 2 delata las líneas
               multi-fuente (240 grupos en 2026). Fue el detector de que `dev`
               y `ej` duplicaban la ejecución; con el peso por fuente ya
               corregido, queda como dato informativo — pero sigue siendo la
               forma rápida de encontrar estos casos al depurar. */
            D.GRUPOS_TI                                            AS GRUPOS_TI
        FROM (
            SELECT SEC_EJEC, ANNO_PROG, ANNO_EJEC, FUENTE_FINANC, TIPO_BIEN, CENTRO_COSTO,
                   SEC_FUNC, CLASIFICADOR, TIPO_USO, TIPO_TAREA, NIVEL_TAREA, CODIGO_TAREA,
                   GRUPO_BIEN, CLASE_BIEN, FAMILIA_BIEN, ITEM_BIEN, UNIDAD_MEDIDA,
                   SUM(CANT_VIG) AS CANT_TOTAL,
                   SUM(MNTO_VIG) AS MNTO_TOTAL,
                   CASE WHEN SUM(CANT_VIG) > 0 THEN SUM(MNTO_VIG) / SUM(CANT_VIG)
                        ELSE MAX(PRECIO_UNIT) END               AS PRECIO_UNIT,
                   MAX(SEC_CUA_MOD_SAL) AS SEC_CUA_MOD_SAL,
                   /* ES_INCL = esta fila del cuadro es SOLO una inclusión: todas
                      sus líneas entraron por modificación (I/IT) y ninguna venía
                      del cuadro aprobado. Una inclusión NO existía en el cuadro
                      original, así que su Importe Programado debe ser 0 — no una
                      porción prorrateada del original de otra línea del mismo
                      ítem. Reportado por el área usuaria: 3 inclusiones de
                      servicios recibían importe programado que no les tocaba. */
                   CASE WHEN SUM(LIN_APROB) + SUM(LIN_OTRO) = 0 AND SUM(LIN_INCL) > 0
                        THEN 1 ELSE 0 END                                         AS ES_INCL,
                   /* MOD_ITEM / GRUPOS_ITEM son el denominador del prorrateo del
                      cuadro original entre las líneas que comparten centro+ítem.
                      Las inclusiones se EXCLUYEN del reparto (aportan 0): el
                      original pertenece solo a las líneas que ya venían del
                      cuadro aprobado. Antes se repartía entre todas y las
                      inclusiones se llevaban una parte, descuadrando el total
                      contra el SIGA. */
                   SUM(CASE WHEN SUM(LIN_APROB) + SUM(LIN_OTRO) = 0 AND SUM(LIN_INCL) > 0
                            THEN 0 ELSE SUM(MNTO_VIG) END)
                        OVER (PARTITION BY CENTRO_COSTO, TIPO_BIEN,
                        GRUPO_BIEN, CLASE_BIEN, FAMILIA_BIEN, ITEM_BIEN)          AS MOD_ITEM,
                   SUM(CASE WHEN SUM(LIN_APROB) + SUM(LIN_OTRO) = 0 AND SUM(LIN_INCL) > 0
                            THEN 0 ELSE 1 END)
                        OVER (PARTITION BY CENTRO_COSTO, TIPO_BIEN,
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
                SELECT d.SEC_EJEC, d.ANNO_PROG, d.ANNO_EJEC, d.SEC_CUA_MOD_SAL,
                       MAX(d.FUENTE_FINANC) AS FUENTE_FINANC, MAX(d.TIPO_BIEN)    AS TIPO_BIEN,
                       MAX(d.CENTRO_COSTO)  AS CENTRO_COSTO,   MAX(d.SEC_FUNC)    AS SEC_FUNC,
                       MAX(d.CLASIFICADOR)  AS CLASIFICADOR,   MAX(d.TIPO_USO)    AS TIPO_USO,
                       MAX(d.TIPO_TAREA)    AS TIPO_TAREA,     MAX(d.NIVEL_TAREA) AS NIVEL_TAREA,
                       MAX(d.CODIGO_TAREA)  AS CODIGO_TAREA,   MAX(d.GRUPO_BIEN)  AS GRUPO_BIEN,
                       MAX(d.CLASE_BIEN)    AS CLASE_BIEN,     MAX(d.FAMILIA_BIEN) AS FAMILIA_BIEN,
                       MAX(d.ITEM_BIEN)     AS ITEM_BIEN,      MAX(d.UNIDAD_MEDIDA) AS UNIDAD_MEDIDA,
                       MAX(d.PRECIO_UNIT)   AS PRECIO_UNIT,
                       /* CANT_VIG: NULLIF(...,0) trata un saldo=0 como \"sin dato de
                          saldo\" y cae a la suma del DET (fix POLLO BEBE). El CASE
                          externo sigue devolviendo 0 solo si TODO está excluido. */
                       CASE WHEN SUM(CASE WHEN ISNULL(d.ESTADO,'') NOT IN ('E','ET') THEN 1 ELSE 0 END) = 0 THEN 0
                            ELSE COALESCE(NULLIF(MAX(s.CANT_TOTAL), 0),
                                          SUM(CASE WHEN ISNULL(d.ESTADO,'') NOT IN ('E','ET') THEN d.CANT_TOTAL ELSE 0 END))
                       END AS CANT_VIG,
                       /* MNTO_VIG: misma corrección; es la cantidad vigente × precio. */
                       CASE WHEN SUM(CASE WHEN ISNULL(d.ESTADO,'') NOT IN ('E','ET') THEN 1 ELSE 0 END) = 0 THEN 0
                            ELSE COALESCE(NULLIF(MAX(s.CANT_TOTAL), 0),
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
        ) D
        JOIN      SIG_CENTRO_COSTO cc
                  ON cc.SEC_EJEC=D.SEC_EJEC AND cc.ANO_EJE=D.ANNO_EJEC AND cc.CENTRO_COSTO=D.CENTRO_COSTO
        LEFT JOIN CATALOGO_BIEN_SERV cat
                  ON cat.SEC_EJEC=D.SEC_EJEC AND cat.TIPO_BIEN=D.TIPO_BIEN AND cat.GRUPO_BIEN=D.GRUPO_BIEN
                 AND cat.CLASE_BIEN=D.CLASE_BIEN AND cat.FAMILIA_BIEN=D.FAMILIA_BIEN AND cat.ITEM_BIEN=D.ITEM_BIEN
        LEFT JOIN UNIDAD_MEDIDA um ON um.UNIDAD_MEDIDA=D.UNIDAD_MEDIDA
        LEFT JOIN FUENTE_FINANC ff ON ff.ANO_EJE=D.ANNO_EJEC AND ff.FUENTE_FINANC=D.FUENTE_FINANC
        LEFT JOIN SIG_CLASIFICADOR_GASTO clg
                  ON clg.ANO_EJE=D.ANNO_EJEC
                 AND REPLACE(REPLACE(clg.CLASIFICADOR,'.',''),' ','')
                   = REPLACE(REPLACE(D.CLASIFICADOR,'.',''),' ','')
        LEFT JOIN SIG_CENTRO_COSTO_TAREA tar
                  ON tar.sec_ejec=D.SEC_EJEC AND tar.ano_eje=D.ANNO_EJEC AND tar.centro_costo=D.CENTRO_COSTO
                 AND tar.codigo_tarea=D.CODIGO_TAREA AND tar.tipo_tarea=D.TIPO_TAREA AND tar.nivel_tarea=D.NIVEL_TAREA
        OUTER APPLY (
            SELECT SUM(n.CANT_TOTAL)  AS CANT_TOTAL,
                   SUM(n.MNTO_TOTAL)  AS MNTO_TOTAL,
                   SUM(n.MNTO_TOTAL) / NULLIF(SUM(n.CANT_TOTAL), 0) AS PRECIO_UNIT
            FROM   SIG_CUADRO_NECESIDAD_DET n
            WHERE  n.SEC_EJEC=D.SEC_EJEC AND n.ANO_EJE=D.ANNO_EJEC
              AND  n.CENTRO_COSTO=D.CENTRO_COSTO AND n.TIPO_BIEN=D.TIPO_BIEN
              AND  n.GRUPO_BIEN=D.GRUPO_BIEN AND n.CLASE_BIEN=D.CLASE_BIEN
              AND  n.FAMILIA_BIEN=D.FAMILIA_BIEN AND n.ITEM_BIEN=D.ITEM_BIEN
        ) ori
        /* RESPONSABLE: el empleado que figura en SIG_PAAC_CONSOLIDADO para el
           consolidado de esta fila del cuadro (vía SIG_CUADRO_MODIFICADO_CMN,
           que enlaza D.SEC_CUA_MOD_SAL con el consolidado). Un mismo consolidado
           puede tener 2 filas en SIG_PAAC_CONSOLIDADO (TIPO_CONSOLID=1 original,
           =2 actualizado); se toma el EMPLEADO del TIPO_CONSOLID más alto
           (ROW_NUMBER, no MAX(EMPLEADO) — un MAX de texto no garantiza que sea
           el del tipo más reciente, solo el que ordena más alto alfabéticamente).
           SIG_PERSONAL.empleado.nombre_completo trae el nombre completo. */
        LEFT JOIN (
            SELECT SEC_EJEC, ANNO_EJEC, SEC_CUA_MOD_SAL, EMPLEADO
            FROM (
                SELECT cmn.SEC_EJEC, cmn.ANNO_EJEC, cmn.SEC_CUA_MOD_SAL, pc.EMPLEADO,
                       ROW_NUMBER() OVER (
                           PARTITION BY cmn.SEC_EJEC, cmn.ANNO_EJEC, cmn.SEC_CUA_MOD_SAL
                           ORDER BY cmn.TIPO_CONSOLID DESC
                       ) AS rn
                FROM   SIG_CUADRO_MODIFICADO_CMN cmn
                JOIN   SIG_PAAC_CONSOLIDADO pc
                       ON pc.SEC_EJEC=cmn.SEC_EJEC AND pc.ANO_EJE=cmn.ANNO_EJEC
                      AND pc.TIPO_CONSOLID=cmn.TIPO_CONSOLID AND pc.NRO_CONSOLID=cmn.NRO_CONSOLID
                      AND pc.TIPO_BIEN=cmn.TIPO_BIEN
            ) x
            WHERE x.rn = 1
        ) resp
             ON resp.SEC_EJEC=D.SEC_EJEC AND resp.ANNO_EJEC=D.ANNO_EJEC AND resp.SEC_CUA_MOD_SAL=D.SEC_CUA_MOD_SAL
        LEFT JOIN SIG_PERSONAL per
             ON per.sec_ejec=D.SEC_EJEC AND per.empleado=resp.EMPLEADO
        LEFT JOIN (
            /* FIX: agrupado por CENTRO + TAREA + ÍTEM (misma clave que `dev`,
               vía SIG_DEPEN_META_CUADRO) en vez de META + CLASIFICADOR + ÍTEM.
               Antes esto mezclaba órdenes de otras actividades/centros que
               compartían el mismo ítem+meta+clasificador. */
            SELECT
                ca.SEC_EJEC, ca.ANO_EJE,
                dm.CENTRO_COSTO, dm.TIPO_TAREA, dm.NIVEL_TAREA, dm.CODIGO_TAREA,
                db.TIPO_BIEN, db.GRUPO_BIEN, db.CLASE_BIEN, db.FAMILIA_BIEN, db.ITEM_BIEN,
                /* FUENTE + META reales de esta porción de la orden, desde
                   SIG_DETALLE_METAS_CUADRO (vía dm.SEC_META). Sin esto, las dos
                   filas del cuadro (misma línea, distinto RB) mostraban las
                   mismas órdenes repetidas en DATOS EJECUCION. */
                dme.FUENTE_FINANC,
                dme.sec_func                                AS SEC_FUNC,
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
                                      AND dvi2.GRUPO_BIEN=db2.GRUPO_BIEN AND dvi2.CLASE_BIEN=db2.CLASE_BIEN
                                      AND dvi2.FAMILIA_BIEN=db2.FAMILIA_BIEN AND dvi2.ITEM_BIEN=db2.ITEM_BIEN
                               ) THEN ' · DEVENGADO'
                               WHEN ISNULL(oa2.EXP_SIAF,0) <> 0     THEN ' · COMPROMETIDO'
                               WHEN ISNULL(oa2.NRO_CERTIFICA,0) <> 0 THEN ' · CERTIFICADO'
                               ELSE ' · CON ORDEN'
                             END
                           /* CERTIFICACIÓN · el MISMO documento tiene DOS correlativos:
                                · SIGA = NRO_CERTIFICA      (correlativo interno de logística)
                                · SIAF = NRO_CERTIFICA_SIAF (correlativo del expediente MEF)
                              Se leen de la CABECERA (cab2) a propósito: las tablas de
                              detalle (_FASE / _PPTO / _DEPE) repiten la misma línea una
                              vez por FASE del trámite (Certificación, Compromiso Anual,
                              anulación, rebaja, ampliación) y no bajan a nivel de ítem,
                              así que unirse a ellas duplicaría filas sin ganar precisión.
                              Ver el bloque CÓMO FUNCIONA LA CERTIFICACIÓN arriba.
                              ANULADA: ANULADO es independiente de ESTADO_CERTIFICA_SIAF
                              (hay 575 certificaciones aprobadas en el SIAF que están
                              anuladas); por eso la marca se lee de ANULADO. */
                           + CASE WHEN ISNULL(oa2.NRO_CERTIFICA,0) <> 0
                                  THEN ' · Cert SIGA ' + CONVERT(VARCHAR, oa2.NRO_CERTIFICA)
                                       + ISNULL(' · SIAF ' + CONVERT(VARCHAR,
                                           NULLIF(cab2.NRO_CERTIFICA_SIAF, 0)), '')
                                       + CASE WHEN ISNULL(cab2.ANULADO,0) <> 0
                                              THEN ' · CERT ANULADA' ELSE '' END
                                  ELSE '' END
                    FROM   SIG_CUADRO_ADQUISICION ca2
                    JOIN   SIG_DETALLE_BSERV_CUADRO db2
                           ON db2.SEC_EJEC=ca2.SEC_EJEC AND db2.ANO_EJE=ca2.ANO_EJE
                          AND db2.TIPO_BIEN=ca2.TIPO_BIEN AND db2.SEC_CUADRO=ca2.SEC_CUADRO
                    JOIN   SIG_DEPEN_META_CUADRO dm2
                           ON dm2.SEC_EJEC=db2.SEC_EJEC AND dm2.ANO_EJE=db2.ANO_EJE
                          AND dm2.TIPO_BIEN=db2.TIPO_BIEN AND dm2.SEC_CUADRO=db2.SEC_CUADRO
                          AND dm2.SECUENCIA=db2.SECUENCIA
                    JOIN   SIG_ORDEN_ADQUISICION oa2
                           ON oa2.SEC_EJEC=ca2.SEC_EJEC AND oa2.ANO_EJE=ca2.ANO_EJE
                          AND oa2.NRO_ORDEN=ca2.NRO_ORDEN AND oa2.TIPO_BIEN=ca2.TIPO_BIEN
                          AND oa2.ESTADO<>'A'
                    /* OJO: el JOIN lleva ANO_EJE ADEMÁS de NRO_CERTIFICA porque el
                       correlativo SIGA se REINICIA CADA AÑO — la cert. 211 de 2024
                       tiene SIAF 448 y la 211 de 2025 tiene SIAF 327. Sin el año,
                       la mitad de los badges mostraría el SIAF de otro ejercicio. */
                    LEFT JOIN SIG_CERTIFICACION cab2
                           ON cab2.SEC_EJEC=oa2.SEC_EJEC AND cab2.ANO_EJE=oa2.ANO_EJE
                          AND cab2.NRO_CERTIFICA=oa2.NRO_CERTIFICA
                    /* Solo las órdenes imputadas a la MISMA fuente y meta que la
                       fila del cuadro. dme viene del FROM externo (correlación). */
                    JOIN   SIG_DETALLE_METAS_CUADRO dme2
                           ON dme2.sec_ejec=db2.SEC_EJEC AND dme2.ANO_EJE=db2.ANO_EJE
                          AND dme2.TIPO_BIEN=db2.TIPO_BIEN AND dme2.SEC_CUADRO=db2.SEC_CUADRO
                          AND dme2.SECUENCIA=db2.SECUENCIA AND dme2.SEC_META=dm2.SEC_META
                          AND dme2.FUENTE_FINANC=dme.FUENTE_FINANC
                          AND dme2.sec_func=dme.sec_func
                    WHERE  ca2.SEC_EJEC=ca.SEC_EJEC AND ca2.ANO_EJE=ca.ANO_EJE AND ca2.ESTADO<>'A'
                      AND  dm2.CENTRO_COSTO=dm.CENTRO_COSTO
                      AND  dm2.TIPO_TAREA=dm.TIPO_TAREA AND dm2.NIVEL_TAREA=dm.NIVEL_TAREA
                      AND  dm2.CODIGO_TAREA=dm.CODIGO_TAREA
                      AND  db2.TIPO_BIEN=db.TIPO_BIEN AND db2.GRUPO_BIEN=db.GRUPO_BIEN
                      AND  db2.CLASE_BIEN=db.CLASE_BIEN AND db2.FAMILIA_BIEN=db.FAMILIA_BIEN
                      AND  db2.ITEM_BIEN=db.ITEM_BIEN
                    FOR XML PATH(''), TYPE).value('.','NVARCHAR(MAX)'), 1, 2, '') AS ORDENES
            FROM   SIG_CUADRO_ADQUISICION ca
            JOIN   SIG_DETALLE_BSERV_CUADRO db
                   ON db.SEC_EJEC=ca.SEC_EJEC AND db.ANO_EJE=ca.ANO_EJE
                  AND db.TIPO_BIEN=ca.TIPO_BIEN AND db.SEC_CUADRO=ca.SEC_CUADRO
            JOIN   SIG_DEPEN_META_CUADRO dm
                   ON dm.SEC_EJEC=db.SEC_EJEC AND dm.ANO_EJE=db.ANO_EJE
                  AND dm.TIPO_BIEN=db.TIPO_BIEN AND dm.SEC_CUADRO=db.SEC_CUADRO
                  AND dm.SECUENCIA=db.SECUENCIA
            /* SEC_META traduce la porción de la orden a su meta + clasificador +
               fuente reales. Relación 1:1 con dm, sin prorrateo. */
            JOIN   SIG_DETALLE_METAS_CUADRO dme
                   ON dme.sec_ejec=db.SEC_EJEC AND dme.ANO_EJE=db.ANO_EJE
                  AND dme.TIPO_BIEN=db.TIPO_BIEN AND dme.SEC_CUADRO=db.SEC_CUADRO
                  AND dme.SECUENCIA=db.SECUENCIA AND dme.SEC_META=dm.SEC_META
            WHERE  ca.ESTADO<>'A'
              AND  EXISTS (
                    SELECT 1 FROM SIG_ORDEN_ADQUISICION oa
                    WHERE oa.SEC_EJEC=ca.SEC_EJEC AND oa.ANO_EJE=ca.ANO_EJE
                      AND oa.NRO_ORDEN=ca.NRO_ORDEN AND oa.TIPO_BIEN=ca.TIPO_BIEN
                      AND oa.ESTADO<>'A'
              )
            GROUP BY ca.SEC_EJEC, ca.ANO_EJE,
                     dm.CENTRO_COSTO, dm.TIPO_TAREA, dm.NIVEL_TAREA, dm.CODIGO_TAREA,
                     db.TIPO_BIEN, db.GRUPO_BIEN, db.CLASE_BIEN, db.FAMILIA_BIEN, db.ITEM_BIEN,
                     dme.FUENTE_FINANC, dme.sec_func
        ) ej
             ON ej.SEC_EJEC     = D.SEC_EJEC
            AND ej.ANO_EJE      = D.ANNO_PROG
            AND ej.CENTRO_COSTO = D.CENTRO_COSTO
            AND ej.TIPO_TAREA   = D.TIPO_TAREA
            AND ej.NIVEL_TAREA  = D.NIVEL_TAREA
            AND ej.CODIGO_TAREA = D.CODIGO_TAREA
            AND ej.TIPO_BIEN    = D.TIPO_BIEN  AND ej.GRUPO_BIEN=D.GRUPO_BIEN
            AND ej.CLASE_BIEN   = D.CLASE_BIEN AND ej.FAMILIA_BIEN=D.FAMILIA_BIEN
            AND ej.ITEM_BIEN    = D.ITEM_BIEN
            /* FUENTE: D.FUENTE_FINANC es el RB detallado (09/00), que es lo que
               guarda SIG_DETALLE_METAS_CUADRO — NO el FF agregado (1/2) de la
               vista. META: D.SEC_FUNC. Ambas son parte de la identidad de la
               fila del cuadro; sin ellas una orden repartida entre varias metas
               o fuentes se atribuía entera a cada fila. */
            AND ej.FUENTE_FINANC = D.FUENTE_FINANC
            AND ej.SEC_FUNC      = D.SEC_FUNC
        LEFT JOIN (
            /* Cadena de EJECUCIÓN del cuadro (compromiso ejecutado = dm.MNTO_SOLES),
               con el DEVENGADO CONTABLE real (SIG_DEVENGADO) traído aparte por orden.
               Ambos se agregan por la misma clave centro+tarea+ítem. */
            SELECT
                ca.SEC_EJEC, ca.ANO_EJE,
                dm.CENTRO_COSTO, dm.TIPO_TAREA, dm.NIVEL_TAREA, dm.CODIGO_TAREA,
                db.TIPO_BIEN, db.GRUPO_BIEN, db.CLASE_BIEN, db.FAMILIA_BIEN, db.ITEM_BIEN,
                /* FUENTE + META reales de esta porción de la orden (vía
                   dm.SEC_META → SIG_DETALLE_METAS_CUADRO). Son parte de la
                   identidad de la fila del cuadro: sin ellas, una orden
                   repartida entre varias metas/fuentes se atribuía COMPLETA a
                   cada fila y los totales salían al doble.
                   Los importes van SIN ponderar: dm.MNTO_SOLES ya es el monto
                   exacto de esta meta+fuente en este centro (verificado: la
                   OC 51 del ítem 710600010015 da 78.59 para la meta 39/fuente
                   00, que es exactamente la fila del centro 104.07.08.03.02).
                   Y CANT_DEPEND se suma tal cual: son unidades enteras. */
                dme.FUENTE_FINANC,
                dme.sec_func                            AS SEC_FUNC,
                SUM(dm.CANT_DEPEND)                     AS CANT_DEV,
                SUM(dm.MNTO_SOLES)                      AS MNTO_EJEC,     -- compromiso ejecutado
                SUM(dm.VALOR_DEPEND)                    AS MNTO_COMP,     -- compromiso bruto (ya no se expone)
                /* DEVENGADO por línea = devengado real de la orden PRORRATEADO por la
                   proporción del ejecutado de esta línea sobre el ejecutado total de la
                   orden+ítem. El devengado del SIGA vive a nivel de ORDEN (no por
                   dependencia): una orden de almacén (papel bond, OC 103) surte a decenas
                   de centros con un único devengado global. El prorrateo es exacto en
                   ambos casos: en servicios (orden = un solo centro) el factor es 1, así
                   que devengado_línea = devengado_real (p.ej. OS 706 = 1 500, no 4 500);
                   en bienes reparte el devengado real según lo ejecutado por centro
                   (papel bond: 51 870 × 140/51 870 = 140).
                   NOTA: SQL Server no admite SUM(...subquery...); por eso el devengado
                   real y el ejecutado total de la orden se calculan por fila en el
                   OUTER APPLY dvf, y aquí sólo se prorratea dentro del SUM. */
                SUM(CASE WHEN dvf.EJEC_TOT_ORDEN > 0
                         THEN dm.MNTO_SOLES * dvf.DEV_REAL_ORDEN / dvf.EJEC_TOT_ORDEN
                         ELSE 0 END)                    AS MNTO_DEVREAL
            FROM   SIG_CUADRO_ADQUISICION ca
            JOIN   SIG_DETALLE_BSERV_CUADRO db
                   ON db.SEC_EJEC=ca.SEC_EJEC AND db.ANO_EJE=ca.ANO_EJE
                  AND db.TIPO_BIEN=ca.TIPO_BIEN AND db.SEC_CUADRO=ca.SEC_CUADRO
            JOIN   SIG_DEPEN_META_CUADRO dm
                   ON dm.SEC_EJEC=db.SEC_EJEC AND dm.ANO_EJE=db.ANO_EJE
                  AND dm.TIPO_BIEN=db.TIPO_BIEN AND dm.SEC_CUADRO=db.SEC_CUADRO
                  AND dm.SECUENCIA=db.SECUENCIA
            /* SEC_META traduce la porción de la orden a su meta + clasificador +
               fuente reales. Relación 1:1 con dm, sin prorrateo. */
            JOIN   SIG_DETALLE_METAS_CUADRO dme
                   ON dme.sec_ejec=db.SEC_EJEC AND dme.ANO_EJE=db.ANO_EJE
                  AND dme.TIPO_BIEN=db.TIPO_BIEN AND dme.SEC_CUADRO=db.SEC_CUADRO
                  AND dme.SECUENCIA=db.SECUENCIA AND dme.SEC_META=dm.SEC_META
            /* Por fila: devengado real de la orden+ítem (a nivel de orden completa) y
               ejecutado total de esa orden+ítem en TODOS los centros (denominador del
               prorrateo). Ambos escalares, resueltos aquí para no meter subquery en el SUM. */
            OUTER APPLY (
                SELECT
                    ISNULL((
                        SELECT SUM(di.VALOR_SOLES)
                        FROM   SIG_DEVENGADO dv
                        JOIN   SIG_DEVENGADO_ITEM di
                               ON di.SEC_EJEC=dv.SEC_EJEC AND di.ANO_EJE=dv.ANO_EJE
                              AND di.NRO_DEVENGADO=dv.NRO_DEVENGADO
                        WHERE  dv.SEC_EJEC=ca.SEC_EJEC AND dv.ANO_EJE=ca.ANO_EJE
                          AND  dv.NRO_ORDEN=ca.NRO_ORDEN AND dv.TIPO_BIEN=ca.TIPO_BIEN
                          AND  di.GRUPO_BIEN=db.GRUPO_BIEN AND di.CLASE_BIEN=db.CLASE_BIEN
                          AND  di.FAMILIA_BIEN=db.FAMILIA_BIEN AND di.ITEM_BIEN=db.ITEM_BIEN
                    ), 0)                                                    AS DEV_REAL_ORDEN,
                    ISNULL((
                        SELECT SUM(dm2.MNTO_SOLES)
                        FROM   SIG_CUADRO_ADQUISICION ca2
                        JOIN   SIG_DETALLE_BSERV_CUADRO db2
                               ON db2.SEC_EJEC=ca2.SEC_EJEC AND db2.ANO_EJE=ca2.ANO_EJE
                              AND db2.TIPO_BIEN=ca2.TIPO_BIEN AND db2.SEC_CUADRO=ca2.SEC_CUADRO
                        JOIN   SIG_DEPEN_META_CUADRO dm2
                               ON dm2.SEC_EJEC=db2.SEC_EJEC AND dm2.ANO_EJE=db2.ANO_EJE
                              AND dm2.TIPO_BIEN=db2.TIPO_BIEN AND dm2.SEC_CUADRO=db2.SEC_CUADRO
                              AND dm2.SECUENCIA=db2.SECUENCIA
                        WHERE  ca2.SEC_EJEC=ca.SEC_EJEC AND ca2.ANO_EJE=ca.ANO_EJE
                          AND  ca2.NRO_ORDEN=ca.NRO_ORDEN AND ca2.TIPO_BIEN=ca.TIPO_BIEN
                          AND  ca2.ESTADO<>'A'
                          AND  db2.GRUPO_BIEN=db.GRUPO_BIEN AND db2.CLASE_BIEN=db.CLASE_BIEN
                          AND  db2.FAMILIA_BIEN=db.FAMILIA_BIEN AND db2.ITEM_BIEN=db.ITEM_BIEN
                    ), 0)                                                    AS EJEC_TOT_ORDEN
            ) dvf
            WHERE  ISNULL(ca.NRO_ORDEN, 0) > 0
              AND  ca.ESTADO <> 'A'
              AND  EXISTS (
                    SELECT 1 FROM SIG_ORDEN_ADQUISICION oa
                    WHERE oa.SEC_EJEC=ca.SEC_EJEC AND oa.ANO_EJE=ca.ANO_EJE
                      AND oa.NRO_ORDEN=ca.NRO_ORDEN AND oa.TIPO_BIEN=ca.TIPO_BIEN
                      AND oa.ESTADO<>'A'
              )
            GROUP BY ca.SEC_EJEC, ca.ANO_EJE,
                     dm.CENTRO_COSTO, dm.TIPO_TAREA, dm.NIVEL_TAREA, dm.CODIGO_TAREA,
                     db.TIPO_BIEN, db.GRUPO_BIEN, db.CLASE_BIEN, db.FAMILIA_BIEN, db.ITEM_BIEN,
                     dme.FUENTE_FINANC, dme.sec_func
        ) dev
             ON dev.SEC_EJEC     = D.SEC_EJEC
            AND dev.ANO_EJE      = D.ANNO_PROG
            AND dev.CENTRO_COSTO = D.CENTRO_COSTO
            AND dev.TIPO_TAREA   = D.TIPO_TAREA
            AND dev.NIVEL_TAREA  = D.NIVEL_TAREA
            AND dev.CODIGO_TAREA = D.CODIGO_TAREA
            AND dev.TIPO_BIEN    = D.TIPO_BIEN  AND dev.GRUPO_BIEN=D.GRUPO_BIEN
            AND dev.CLASE_BIEN   = D.CLASE_BIEN AND dev.FAMILIA_BIEN=D.FAMILIA_BIEN
            AND dev.ITEM_BIEN    = D.ITEM_BIEN
            /* FUENTE + META completan la identidad de la fila del cuadro. Sin
               ellas, las 240 líneas multi-fuente de 2026 contaban su ejecución
               dos veces, y las órdenes repartidas entre varias metas atribuían
               el total a cada meta. Se compara contra el RB detallado
               (D.FUENTE_FINANC), no contra el FF agregado. */
            AND dev.FUENTE_FINANC = D.FUENTE_FINANC
            AND dev.SEC_FUNC      = D.SEC_FUNC";
    }

    private function faseExpr(string $t = 'T'): string
    {
        /* MODIFICADO: servicios por MONTO, bienes por CANTIDAD (comparación
           directa de la línea). En un bien lo que se modifica es la cantidad,
           no el precio; comparar por monto marcaba MODIFICADO aunque solo
           variara el precio unitario. */
        return "CASE
                    WHEN {$t}.IMPORTE_EJEC > 0                                     THEN 'EJECUTADO'
                    WHEN {$t}.TIPO_BIEN='S'
                         AND ABS({$t}.IMPORTE_MOD  - {$t}.IMPORTE_PROG)  > 0.005   THEN 'MODIFICADO'
                    WHEN {$t}.TIPO_BIEN='B'
                         AND ABS({$t}.CANTIDAD_MOD - {$t}.CANTIDAD_PROG) > 0.005   THEN 'MODIFICADO'
                    ELSE 'PROGRAMADO'
                END";
    }

    private function bindBase($st, int $anioProg, int $anioEjec, int $secEjec, ?string $ccosto): void
    {
        $st->bindValue(':anioProg', $anioProg, PDO::PARAM_INT);
        $st->bindValue(':anioEjec', $anioEjec, PDO::PARAM_INT);
        $st->bindValue(':secEjec',  $secEjec,  PDO::PARAM_INT);
        if ($ccosto) $st->bindValue(':ccosto', $ccosto);
    }

    /* Convierte "a,b,c" en array limpio de valores no vacíos. */
    private function toList($v): array
    {
        if (is_array($v)) $items = $v;
        else $items = explode(',', (string)$v);
        $out = [];
        foreach ($items as $x) { $x = trim((string)$x); if ($x !== '') $out[] = $x; }
        return array_values(array_unique($out));
    }

    /* Arma "col IN (:p0,:p1,...)" para una lista; devuelve [sql, [placeholder=>valor]]. */
    private function inClause(string $expr, string $prefix, array $vals): array
    {
        if (!$vals) return ['', []];
        $ph = []; $binds = [];
        foreach ($vals as $i => $v) { $k = ':'.$prefix.$i; $ph[] = $k; $binds[$k] = $v; }
        return [" AND {$expr} IN (".implode(',', $ph).") ", $binds];
    }

    private function bindFiltros($st, string $tipo, string $search, $meta = '', $act = '', ?string $fase = null,
                                $clasif = '', $fuente = ''): void
    {
        if ($tipo === 'B' || $tipo === 'S') $st->bindValue(':tipo', $tipo);
        if ($search !== '') {
            // Colapsa espacios múltiples a uno solo (coincide con la normalización
            // de NOMBRE_ITEM en el WHERE, para tolerar el doble espacio del SIGA).
            $norm = preg_replace('/\s+/', ' ', trim($search));
            $st->bindValue(':q1', '%'.$norm.'%');
            $st->bindValue(':q2', '%'.$search.'%');
            $st->bindValue(':q3', '%'.$search.'%');
            $plano = preg_replace('/[.\s]/', '', $search);
            $st->bindValue(':q4', '%'.$plano.'%');
            // PDO_SQLSRV no reusa un parámetro nombrado: COD_PRODUCTO necesita el suyo.
            $st->bindValue(':q5', '%'.$plano.'%');
        }
        // Filtros multi-select: bindear cada valor de cada lista.
        foreach ($this->inClause('X', 'meta',   $this->toList($meta))[1]   as $k=>$v) $st->bindValue($k, $v);
        foreach ($this->inClause('X', 'act',    $this->toList($act))[1]    as $k=>$v) $st->bindValue($k, $v);
        foreach ($this->inClause('X', 'clasif', $this->toList($clasif))[1] as $k=>$v) $st->bindValue($k, $v);
        foreach ($this->inClause('X', 'fuente', $this->toList($fuente))[1] as $k=>$v) $st->bindValue($k, $v);
        if ($fase !== null && in_array($fase, ['PROGRAMADO','MODIFICADO','EJECUTADO'], true)) $st->bindValue(':fase', $fase);
    }

    /**
     * $ejec: 'si' = solo ítems con ejecución (IMPORTE_EJEC > 0, "lo que sí se
     * compró") · 'no' = solo ítems sin ejecución (IMPORTE_EJEC = 0, "lo que NO
     * se compró") · '' = sin filtrar (ambos).
     * $sobre: 'si' = solo ítems SOBREGIRADOS (DIFERENCIA = Programado -
     * Ejecutado negativa, o sea se ejecutó más de lo programado) · '' = sin
     * filtrar. Ambos son literales fijos por whitelist (in_array), no
     * necesitan bind param.
     */
    private function whereFiltros(string $tipo, string $search, $meta, $act, string $fase,
                                  $clasif = '', $fuente = '', string $ejec = '', string $sobre = ''): string
    {
        $w = " WHERE 1=1 ";
        if ($tipo === 'B' || $tipo === 'S') $w .= " AND T.TIPO_BIEN = :tipo ";
        // Colapsa cualquier secuencia de espacios a uno solo, para que el nombre con
        // doble espacio del SIGA (p.ej. 'EN  ALMACEN') coincida al buscar con espacio
        // simple. El término :q1 ya viene normalizado desde bindFiltros.
        $normNombre = "REPLACE(REPLACE(REPLACE(T.NOMBRE_ITEM,'  ',' '+CHAR(7)),CHAR(7)+' ',''),CHAR(7),'')";
        if ($search !== '') $w .= " AND ({$normNombre} LIKE :q1 OR T.CLASIF_COD LIKE :q2 OR T.ESTADO_ORDEN LIKE :q3 OR T.CLASIF_PLANO LIKE :q4 OR T.COD_PRODUCTO LIKE :q5) ";
        // Multi-select: cada filtro es una lista; se traduce a IN (...).
        $w .= $this->inClause('CONVERT(VARCHAR(50), T.META)', 'meta',   $this->toList($meta))[0];
        $w .= $this->inClause('T.ACTIV_OPERAT_COD',          'act',    $this->toList($act))[0];
        $w .= $this->inClause('T.CLASIF_COD',                'clasif', $this->toList($clasif))[0];
        $w .= $this->inClause('T.FF',                        'fuente', $this->toList($fuente))[0];
        if (in_array($fase, ['PROGRAMADO','MODIFICADO','EJECUTADO'], true))
            $w .= " AND (" . $this->faseExpr('T') . ") = :fase ";
        if ($ejec === 'si')      $w .= " AND T.IMPORTE_EJEC > 0.005 ";
        elseif ($ejec === 'no')  $w .= " AND ISNULL(T.IMPORTE_EJEC,0) <= 0.005 ";
        if ($sobre === 'si')     $w .= " AND (T.IMPORTE_PROG - T.IMPORTE_EJEC) < -0.005 ";
        return $w;
    }

    public function opciones(int $anioProg, int $anioEjec, int $secEjec, ?string $ccosto): array
    {
        $inner = $this->innerSql(!!$ccosto);
        $run = function (string $col) use ($inner, $anioProg, $anioEjec, $secEjec, $ccosto) {
            $st = $this->db->prepare(
                "SELECT DISTINCT CONVERT(VARCHAR(80), {$col}) v
                 FROM ({$inner}) T
                 WHERE {$col} IS NOT NULL AND CONVERT(VARCHAR(80), {$col}) <> ''
                 ORDER BY 1"
            );
            $this->bindBase($st, $anioProg, $anioEjec, $secEjec, $ccosto);
            $st->execute();
            return array_column($st->fetchAll(), 'v');
        };
        // Fuente y clasificador con etiqueta (código + nombre) para el dropdown.
        $runPair = function (string $cod, string $nom) use ($inner, $anioProg, $anioEjec, $secEjec, $ccosto) {
            $st = $this->db->prepare(
                "SELECT DISTINCT CONVERT(VARCHAR(80), {$cod}) v, MAX(CONVERT(VARCHAR(200), {$nom})) etq
                 FROM ({$inner}) T
                 WHERE {$cod} IS NOT NULL AND CONVERT(VARCHAR(80), {$cod}) <> ''
                 GROUP BY CONVERT(VARCHAR(80), {$cod})
                 ORDER BY 1"
            );
            $this->bindBase($st, $anioProg, $anioEjec, $secEjec, $ccosto);
            $st->execute();
            return $st->fetchAll();
        };
        return [
            'metas'         => $run('T.META'),
            'actividades'   => $run('T.ACTIV_OPERAT_COD'),
            'clasificadores'=> $runPair('T.CLASIF_COD', 'T.CLASIF_NOMBRE'),
            'fuentes'       => $runPair('T.FF', 'T.FF_NOMBRE'),
        ];
    }

    public function rows(int $anioProg, int $anioEjec, int $secEjec, ?string $ccosto,
                         string $tipo = '', string $search = '', $meta = '', $act = '',
                         string $fase = '', string $sort = 'mod_desc', int $page = 1, int $perPage = 50,
                         $clasif = '', $fuente = '', string $ejec = '', string $sobre = ''): array
    {
        $inner = $this->innerSql(!!$ccosto);
        $fexpr = $this->faseExpr('T');
        $w = $this->whereFiltros($tipo, $search, $meta, $act, $fase, $clasif, $fuente, $ejec, $sobre);
        // PHP 7.4: match() reemplazado por if/else.
        $order = 'T.IMPORTE_MOD DESC';
        if ($sort === 'mod_asc')       $order = 'T.IMPORTE_MOD ASC';
        elseif ($sort === 'item_asc')  $order = 'T.NOMBRE_ITEM ASC';
        elseif ($sort === 'act_item')  $order = 'T.ACTIV_OPERAT_COD ASC, T.GRUPO_BIEN ASC, T.CLASE_BIEN ASC, T.FAMILIA_BIEN ASC, T.ITEM_BIEN ASC';
        elseif ($sort === 'clasif')    $order = 'T.CLASIF_COD ASC, T.GRUPO_BIEN ASC, T.CLASE_BIEN ASC, T.FAMILIA_BIEN ASC, T.ITEM_BIEN ASC';

        $tie = ($sort === 'act_item' || $sort === 'clasif') ? '' : ', T.CCOSTO_COD, T.ITEM_BIEN';

        $cst = $this->db->prepare("SELECT COUNT(*) FROM ({$inner}) T {$w}");
        $this->bindBase($cst, $anioProg, $anioEjec, $secEjec, $ccosto);
        $this->bindFiltros($cst, $tipo, $search, $meta, $act, $fase, $clasif, $fuente);
        $cst->execute();
        $total = (int)$cst->fetchColumn();

        $offset  = max(0, ($page - 1) * $perPage);
        $sql = "SELECT T.*, ({$fexpr}) AS ESTADO_FASE
                FROM ({$inner}) T {$w}
                ORDER BY {$order}{$tie}
                OFFSET {$offset} ROWS FETCH NEXT " . (int)$perPage . " ROWS ONLY";
        $st = $this->db->prepare($sql);
        $this->bindBase($st, $anioProg, $anioEjec, $secEjec, $ccosto);
        $this->bindFiltros($st, $tipo, $search, $meta, $act, $fase, $clasif, $fuente);
        $st->execute();

        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    public function summary(int $anioProg, int $anioEjec, int $secEjec, ?string $ccosto,
                            string $tipo = '', string $search = '', $meta = '', $act = '',
                            $clasif = '', $fuente = '', string $ejec = ''): array
    {
        $inner = $this->innerSql(!!$ccosto);
        $fexpr = $this->faseExpr('T');
        $w = $this->whereFiltros($tipo, $search, $meta, $act, '', $clasif, $fuente, $ejec);
        $sql = "SELECT ({$fexpr}) AS fase, COUNT(*) c,
                       SUM(T.IMPORTE_PROG) prog,
                       SUM(T.IMPORTE_MOD)  monto,
                       SUM(T.IMPORTE_EJEC) ejec
                FROM ({$inner}) T {$w} GROUP BY ({$fexpr})";
        $st = $this->db->prepare($sql);
        $this->bindBase($st, $anioProg, $anioEjec, $secEjec, $ccosto);
        $this->bindFiltros($st, $tipo, $search, $meta, $act, null, $clasif, $fuente);
        $st->execute();
        return $st->fetchAll();
    }

    public function historial(int $anioProg, int $anioEjec, int $secEjec, string $ccosto,
                              string $tipo, string $g, string $c, string $f, string $it,
                              int $secFunc, string $clasificador): array
    {
        $b = function ($sql, $p) {
            $s = $this->db->prepare($sql); $s->execute($p); return $s->fetchAll();
        };

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

        /* Certificaciones REALES del ítem: solo las ligadas a las órdenes de este
           ítem (grupo/clase/familia/ítem), no las de toda la meta+clasificador
           (que agrupa decenas de ítems e inflaba el conteo a >100 certificaciones).
           Monto = comprometido del ítem en esa certificación (suma PREC_TOT_SOLES
           de las órdenes de la cert para este ítem). Enlace por ÍTEM, robusto.

           nro / nro_siaf = los DOS correlativos del mismo documento (SIGA interno
           y SIAF del MEF, relación 1:1). El JOIN a SIG_CERTIFICACION ya lleva
           ANO_EJE, imprescindible porque NRO_CERTIFICA se reinicia cada año. */
        $cert = $b(
            "SELECT oa.NRO_CERTIFICA nro,
                    MAX(CONVERT(VARCHAR, NULLIF(cc.NRO_CERTIFICA_SIAF,0))) nro_siaf,
                    MAX(CONVERT(VARCHAR(10), cc.FECHA, 103)) fecha,
                    SUM(oi.PREC_TOT_SOLES) monto,
                    MAX(CASE WHEN ISNULL(cc.ANULADO,0)=0 THEN 'Vigente' ELSE 'Anulada' END) estado
             FROM SIG_ORDEN_ADQUISICION oa
             JOIN SIG_ORDEN_ITEM oi ON oi.SEC_EJEC=oa.SEC_EJEC AND oi.ANO_EJE=oa.ANO_EJE
                  AND oi.NRO_ORDEN=oa.NRO_ORDEN AND oi.TIPO_BIEN=oa.TIPO_BIEN
             LEFT JOIN SIG_CERTIFICACION cc ON cc.SEC_EJEC=oa.SEC_EJEC AND cc.ANO_EJE=oa.ANO_EJE
                  AND cc.NRO_CERTIFICA=oa.NRO_CERTIFICA
             WHERE oa.SEC_EJEC=? AND oa.ANO_EJE=? AND oa.ESTADO<>'A'
               AND ISNULL(oa.NRO_CERTIFICA,0)<>0
               AND oi.TIPO_BIEN=? AND oi.GRUPO_BIEN=? AND oi.CLASE_BIEN=? AND oi.FAMILIA_BIEN=? AND oi.ITEM_BIEN=?
             GROUP BY oa.NRO_CERTIFICA
             ORDER BY oa.NRO_CERTIFICA",
            [$secEjec,$anioProg,$tipo,$g,$c,$f,$it]
        );

        /* Consolidado PAAC del ítem: el estudio de mercado donde Logística fija el
           precio real. Se agrupa por consolidado (deduplicado) y se omite el enlace
           a certificación aquí (la certificación real se muestra en su propio paso,
           ligada a las órdenes del ítem). Solo interesa el precio/cantidad fijados. */
        $consolidado = $b(
            "SELECT cmn.NRO_CONSOLID nro,
                    MAX(CONVERT(VARCHAR(10), pi.FECHA_PRECIO, 103)) fecha_precio,
                    SUM(pi.CANTIDAD) cant,
                    CASE WHEN SUM(pi.CANTIDAD)>0 THEN SUM(pi.VALOR)/SUM(pi.CANTIDAD) ELSE MAX(pi.PRECIO_UNIT) END precio,
                    SUM(pi.VALOR) monto,
                    NULL nro_cert,
                    MAX(CONVERT(VARCHAR(10), pc.FECHA_CONS, 103)) fecha_consolid
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
             GROUP BY cmn.NRO_CONSOLID
             ORDER BY cmn.NRO_CONSOLID",
            [$secEjec,$anioProg,$anioEjec,$ccosto,$tipo,$g,$c,$f,$it]
        );

        $ordenes = $b(
            "SELECT CASE WHEN oa.TIPO_BIEN='B' THEN 'OC ' ELSE 'OS ' END + CONVERT(VARCHAR,oa.NRO_ORDEN) orden,
                    ISNULL(ct.NOMBRE_PROV,'—') proveedor, oi.CANT_ITEM cant, oi.PREC_UNIT_MONEDA precio,
                    oi.PREC_TOT_SOLES monto, CONVERT(VARCHAR(10), oa.FECHA_ORDEN, 103) fecha, oa.ESTADO estado,
                    oa.NRO_CERTIFICA nro_cert,
                    NULLIF(oa.EXP_SIAF,0) exp_siaf,
                    oa.ESTADO_SIAF estado_siaf
             FROM SIG_ORDEN_ADQUISICION oa
             JOIN SIG_ORDEN_ITEM oi ON oi.SEC_EJEC=oa.SEC_EJEC AND oi.ANO_EJE=oa.ANO_EJE
                  AND oi.NRO_ORDEN=oa.NRO_ORDEN AND oi.TIPO_BIEN=oa.TIPO_BIEN
             /* SEC_ITEM es OBLIGATORIO: SIG_ORDEN_ITEM_PPTO tiene una fila por
                ítem de la orden, así que sin él el join es un producto cartesiano
                (una orden de 47 ítems de la misma meta+clasificador multiplicaba
                por 47; la OS 398 del ítem 071100381505 salía 2 veces). */
             JOIN SIG_ORDEN_ITEM_PPTO oip ON oip.SEC_EJEC=oa.SEC_EJEC AND oip.ANO_EJE=oa.ANO_EJE
                  AND oip.NRO_ORDEN=oa.NRO_ORDEN AND oip.TIPO_BIEN=oa.TIPO_BIEN
                  AND oip.SEC_ITEM=oi.SEC_ITEM
                  AND oip.SEC_FUNC=? AND oip.CLASIFICADOR=?
             LEFT JOIN SIG_CONTRATISTAS ct ON ct.PROVEEDOR=oa.PROVEEDOR
             WHERE oa.SEC_EJEC=? AND oa.ANO_EJE=? AND oa.ESTADO<>'A'
               AND oi.TIPO_BIEN=? AND oi.GRUPO_BIEN=? AND oi.CLASE_BIEN=? AND oi.FAMILIA_BIEN=? AND oi.ITEM_BIEN=?
             ORDER BY oa.NRO_ORDEN",
            [$secFunc,$clasificador,$secEjec,$anioProg,$tipo,$g,$c,$f,$it]
        );

        $fases = $b(
            "SELECT DISTINCT 'Comprometido' fase, CASE WHEN oa.TIPO_BIEN='B' THEN 'OC ' ELSE 'OS ' END + CONVERT(VARCHAR,oa.NRO_ORDEN) doc,
                    oa.NRO_ORDEN nro_orden,
                    CONVERT(VARCHAR(10), oa.FECHA_SIAF, 103) fecha, oi.PREC_TOT_SOLES monto
             FROM SIG_ORDEN_ADQUISICION oa
             JOIN SIG_ORDEN_ITEM oi ON oi.SEC_EJEC=oa.SEC_EJEC AND oi.ANO_EJE=oa.ANO_EJE
                  AND oi.NRO_ORDEN=oa.NRO_ORDEN AND oi.TIPO_BIEN=oa.TIPO_BIEN
             /* SEC_ITEM obligatorio (ver nota en la consulta de órdenes). */
             JOIN SIG_ORDEN_ITEM_PPTO oip ON oip.SEC_EJEC=oa.SEC_EJEC AND oip.ANO_EJE=oa.ANO_EJE
                  AND oip.NRO_ORDEN=oa.NRO_ORDEN AND oip.TIPO_BIEN=oa.TIPO_BIEN
                  AND oip.SEC_ITEM=oi.SEC_ITEM
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
             /* Aquí no hay SIG_ORDEN_ITEM (el ítem viene de SIG_DEVENGADO_ITEM),
                así que no se puede unir por SEC_ITEM. Se usa EXISTS en vez de
                JOIN: filtra igual por meta+clasificador pero NO multiplica las
                filas del devengado por cada ítem de la orden. */
             WHERE EXISTS (
                    SELECT 1 FROM SIG_ORDEN_ITEM_PPTO oip2
                    WHERE oip2.SEC_EJEC=oa2.SEC_EJEC AND oip2.ANO_EJE=oa2.ANO_EJE
                      AND oip2.NRO_ORDEN=oa2.NRO_ORDEN AND oip2.TIPO_BIEN=oa2.TIPO_BIEN
                      AND oip2.SEC_FUNC=? AND oip2.CLASIFICADOR=?
             )
               AND dv.SEC_EJEC=? AND dv.ANO_EJE=?
               AND di.TIPO_BIEN=? AND di.GRUPO_BIEN=? AND di.CLASE_BIEN=? AND di.FAMILIA_BIEN=? AND di.ITEM_BIEN=?",
            [$secFunc,$clasificador,$secEjec,$anioProg,$tipo,$g,$c,$f,$it,
             $secFunc,$clasificador,$secEjec,$anioProg,$tipo,$g,$c,$f,$it]
        );

        return ['cuadro'=>$cuadro, 'certificacion'=>$cert, 'consolidado'=>$consolidado, 'ordenes'=>$ordenes, 'fases'=>$fases];
    }

    /**
     * ÓRDENES DE UN ÍTEM · vista de Presupuestos.
     *   Ejecutado  = compromiso ejecutado del cuadro (dm.MNTO_SOLES)
     *   Devengado  = devengado real de la orden PRORRATEADO por la proporción del
     *                ejecutado de esta orden en este centro/tarea sobre el ejecutado
     *                total de la orden+ítem (todos los centros).
     *   Pendiente  = Ejecutado - Devengado
     * El devengado del SIGA vive a nivel de ORDEN (no por dependencia). El prorrateo
     * es exacto: en servicios (orden = un solo centro) el factor es 1 y devengado =
     * devengado real (p.ej. OS 706 = 1 500, no 4 500); en bienes de almacén reparte
     * el devengado real según lo ejecutado por centro (papel bond OC 103: 140).
     */
    public function ordenesItem(int $anioEjec, int $secEjec, string $ccosto,
                                string $tipo, string $g, string $c, string $f, string $it,
                                int $tipoTarea, string $nivelTarea, int $codigoTarea,
                                string $fuente = '', int $meta = 0): array
    {
        /* $fuente = RB detallado (09/00) y $meta = SEC_FUNC de la fila del cuadro.
           Si vienen vacíos (llamada antigua) NO se filtra y se mantiene el
           comportamiento previo, pero en líneas multi-fuente o en órdenes
           repartidas entre metas el modal mostraría órdenes que no son de la fila. */
        $filtroFF = $fuente !== '' ? " AND dme.FUENTE_FINANC = :ff " : "";
        $filtroMT = $meta > 0      ? " AND dme.sec_func = :mt "      : "";
        $sql = "
            SELECT
                ca.NRO_ORDEN                                   AS nro,
                MAX(CASE WHEN oa.TIPO_BIEN='B' THEN 'OC ' ELSE 'OS ' END
                    + CONVERT(VARCHAR, ca.NRO_ORDEN))          AS orden,
                MAX(ISNULL(ct.NOMBRE_PROV, '—'))               AS proveedor,
                MAX(CONVERT(VARCHAR(10), oa.FECHA_ORDEN, 103)) AS fecha,
                /* Certificación de la orden · los dos correlativos (SIGA / SIAF).
                   El JOIN a SIG_CERTIFICACION lleva ANO_EJE porque NRO_CERTIFICA
                   se reinicia cada ejercicio. */
                MAX(NULLIF(oa.NRO_CERTIFICA,0))                AS nro_cert,
                MAX(NULLIF(cab.NRO_CERTIFICA_SIAF,0))          AS nro_cert_siaf,
                MAX(CASE WHEN ISNULL(cab.ANULADO,0)=0 THEN 0 ELSE 1 END) AS cert_anulada,
                /* Importes EXACTOS de esta meta+fuente en este centro (dm.MNTO_SOLES
                   ya viene desglosado así); nada de prorrateo. */
                SUM(dm.MNTO_SOLES)                             AS ejecutado,
                /* Devengado prorrateado: devengado real de la orden × (ejecutado de
                   esta línea / ejecutado total de la orden+ítem). SQL Server no admite
                   SUM(...subquery...): los escalares se resuelven por fila en dvf. */
                SUM(CASE WHEN dvf.EJEC_TOT_ORDEN > 0
                         THEN dm.MNTO_SOLES * dvf.DEV_REAL_ORDEN / dvf.EJEC_TOT_ORDEN
                         ELSE 0 END)                          AS devengado
            FROM   SIG_CUADRO_ADQUISICION ca
            JOIN   SIG_DETALLE_BSERV_CUADRO db
                   ON db.SEC_EJEC=ca.SEC_EJEC AND db.ANO_EJE=ca.ANO_EJE
                  AND db.TIPO_BIEN=ca.TIPO_BIEN AND db.SEC_CUADRO=ca.SEC_CUADRO
            JOIN   SIG_DEPEN_META_CUADRO dm
                   ON dm.SEC_EJEC=db.SEC_EJEC AND dm.ANO_EJE=db.ANO_EJE
                  AND dm.TIPO_BIEN=db.TIPO_BIEN AND dm.SEC_CUADRO=db.SEC_CUADRO
                  AND dm.SECUENCIA=db.SECUENCIA
            JOIN   SIG_ORDEN_ADQUISICION oa
                   ON oa.SEC_EJEC=ca.SEC_EJEC AND oa.ANO_EJE=ca.ANO_EJE
                  AND oa.NRO_ORDEN=ca.NRO_ORDEN AND oa.TIPO_BIEN=ca.TIPO_BIEN
                  AND oa.ESTADO<>'A'
            LEFT JOIN SIG_CERTIFICACION cab
                   ON cab.SEC_EJEC=oa.SEC_EJEC AND cab.ANO_EJE=oa.ANO_EJE
                  AND cab.NRO_CERTIFICA=oa.NRO_CERTIFICA
            JOIN   SIG_DETALLE_METAS_CUADRO dme
                   ON dme.sec_ejec=db.SEC_EJEC AND dme.ANO_EJE=db.ANO_EJE
                  AND dme.TIPO_BIEN=db.TIPO_BIEN AND dme.SEC_CUADRO=db.SEC_CUADRO
                  AND dme.SECUENCIA=db.SECUENCIA AND dme.SEC_META=dm.SEC_META
                  {$filtroFF}
                  {$filtroMT}
            LEFT JOIN SIG_CONTRATISTAS ct ON ct.PROVEEDOR=oa.PROVEEDOR
            /* Por fila: devengado real de la orden+ítem y ejecutado total de esa
               orden+ítem en TODOS los centros (denominador del prorrateo). */
            OUTER APPLY (
                SELECT
                    ISNULL((
                        SELECT SUM(di.VALOR_SOLES)
                        FROM   SIG_DEVENGADO dv
                        JOIN   SIG_DEVENGADO_ITEM di
                               ON di.SEC_EJEC=dv.SEC_EJEC AND di.ANO_EJE=dv.ANO_EJE
                              AND di.NRO_DEVENGADO=dv.NRO_DEVENGADO
                        WHERE  dv.SEC_EJEC=ca.SEC_EJEC AND dv.ANO_EJE=ca.ANO_EJE
                          AND  dv.NRO_ORDEN=ca.NRO_ORDEN AND dv.TIPO_BIEN=ca.TIPO_BIEN
                          AND  di.GRUPO_BIEN=db.GRUPO_BIEN AND di.CLASE_BIEN=db.CLASE_BIEN
                          AND  di.FAMILIA_BIEN=db.FAMILIA_BIEN AND di.ITEM_BIEN=db.ITEM_BIEN
                    ), 0)                                                    AS DEV_REAL_ORDEN,
                    ISNULL((
                        SELECT SUM(dm2.MNTO_SOLES)
                        FROM   SIG_CUADRO_ADQUISICION ca2
                        JOIN   SIG_DETALLE_BSERV_CUADRO db2
                               ON db2.SEC_EJEC=ca2.SEC_EJEC AND db2.ANO_EJE=ca2.ANO_EJE
                              AND db2.TIPO_BIEN=ca2.TIPO_BIEN AND db2.SEC_CUADRO=ca2.SEC_CUADRO
                        JOIN   SIG_DEPEN_META_CUADRO dm2
                               ON dm2.SEC_EJEC=db2.SEC_EJEC AND dm2.ANO_EJE=db2.ANO_EJE
                              AND dm2.TIPO_BIEN=db2.TIPO_BIEN AND dm2.SEC_CUADRO=db2.SEC_CUADRO
                              AND dm2.SECUENCIA=db2.SECUENCIA
                        WHERE  ca2.SEC_EJEC=ca.SEC_EJEC AND ca2.ANO_EJE=ca.ANO_EJE
                          AND  ca2.NRO_ORDEN=ca.NRO_ORDEN AND ca2.TIPO_BIEN=ca.TIPO_BIEN
                          AND  ca2.ESTADO<>'A'
                          AND  db2.GRUPO_BIEN=db.GRUPO_BIEN AND db2.CLASE_BIEN=db.CLASE_BIEN
                          AND  db2.FAMILIA_BIEN=db.FAMILIA_BIEN AND db2.ITEM_BIEN=db.ITEM_BIEN
                    ), 0)                                                    AS EJEC_TOT_ORDEN
            ) dvf
            WHERE  ISNULL(ca.NRO_ORDEN,0) > 0 AND ca.ESTADO<>'A'
              AND  dm.CENTRO_COSTO = :cc
              AND  dm.TIPO_TAREA   = :tt AND dm.NIVEL_TAREA = :nt AND dm.CODIGO_TAREA = :ct
              AND  db.TIPO_BIEN=:tb AND db.GRUPO_BIEN=:g AND db.CLASE_BIEN=:c
              AND  db.FAMILIA_BIEN=:f AND db.ITEM_BIEN=:it
            GROUP BY ca.NRO_ORDEN
            ORDER BY ca.NRO_ORDEN";
        $st = $this->db->prepare($sql);
        $st->bindValue(':cc', $ccosto);
        $st->bindValue(':tt', $tipoTarea, PDO::PARAM_INT);
        $st->bindValue(':nt', $nivelTarea);
        $st->bindValue(':ct', $codigoTarea, PDO::PARAM_INT);
        $st->bindValue(':tb', $tipo);
        $st->bindValue(':g',  $g);  $st->bindValue(':c',  $c);
        $st->bindValue(':f',  $f);  $st->bindValue(':it', $it);
        if ($fuente !== '') $st->bindValue(':ff', $fuente);
        if ($meta > 0)      $st->bindValue(':mt', $meta, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            // Pendiente por devengar = ejecutado - devengado (mínimo 0 por seguridad).
            $r['pendiente'] = max(0, (float)$r['ejecutado'] - (float)$r['devengado']);
            // Compatibilidad: el front antiguo leía 'compromiso' y 'saldo'.
            $r['compromiso'] = $r['ejecutado'];
            $r['saldo']      = $r['pendiente'];
        }
        return $rows;
    }

    /**
     * TRAZA GLOBAL DE UN ÍTEM · para el grafo-árbol de trazabilidad.
     * Devuelve TODAS las órdenes del ítem en la entidad (no filtra por centro),
     * cada una con su certificación (SIGA + SIAF), expediente SIAF, estado y
     * devengado. El enlace es por ÍTEM (grupo/clase/familia/ítem), nunca por
     * CLASIFICADOR: el clasificador se guarda con espacios inconsistentes (p.ej.
     * '2.3. 1  5. 1  2' con doble espacio) y romperia el match. Estructura de cada fila:
     *   nro_certifica · nro_certifica_siaf · cert_anulada · orden (OC/OS n) ·
     *   proveedor · fecha · exp_siaf · estado_siaf (0=sin compromiso, 2=comprometido) ·
     *   comprometido · ejecutado · devengado · multa
     * El front arma el árbol Certificación → Orden(es) → Devengado a partir de esto.
     *
     * NOTA: esta traza es DELIBERADAMENTE global (toda la entidad, sin filtrar
     * por centro/actividad) — a diferencia de DATOS EJECUCION (ver el fix arriba
     * en innerSql/ej), que ahora sí está acotado a centro+tarea. Por eso el árbol
     * puede seguir mostrando más órdenes que la fila puntual: aquí es la
     * intención del diseño, no el bug que se corrigió.
     */
    public function trazaItem(int $anioEjec, int $secEjec,
                              string $tipo, string $g, string $c, string $f, string $it): array
    {
        $sql = "
            SELECT
                oa.NRO_CERTIFICA                                AS nro_certifica,
                /* Correlativo SIAF del MISMO certificado (relación 1:1 con el SIGA).
                   El JOIN lleva ANO_EJE: NRO_CERTIFICA se reinicia cada año. */
                NULLIF(cab.NRO_CERTIFICA_SIAF, 0)               AS nro_certifica_siaf,
                CASE WHEN ISNULL(cab.ANULADO,0)=0 THEN 0 ELSE 1 END AS cert_anulada,
                CASE WHEN oa.TIPO_BIEN='B' THEN 'OC ' ELSE 'OS ' END
                    + CONVERT(VARCHAR, oa.NRO_ORDEN)            AS orden,
                oa.NRO_ORDEN                                    AS nro_orden,
                oa.TIPO_BIEN                                    AS tipo_bien,
                ISNULL(ct.NOMBRE_PROV, '—')                     AS proveedor,
                CONVERT(VARCHAR(10), oa.FECHA_ORDEN, 103)       AS fecha,
                ISNULL(oa.EXP_SIAF, 0)                          AS exp_siaf,
                ISNULL(oa.ESTADO_SIAF, 0)                       AS estado_siaf,
                ISNULL(oa.DOCUMENTO_SIAF, '')                   AS doc_siaf,
                /* Comprometido = valor de la orden para este ítem (compromiso bruto). */
                ISNULL((
                    SELECT SUM(oi2.PREC_TOT_SOLES)
                    FROM   SIG_ORDEN_ITEM oi2
                    WHERE  oi2.SEC_EJEC=oa.SEC_EJEC AND oi2.ANO_EJE=oa.ANO_EJE
                      AND  oi2.NRO_ORDEN=oa.NRO_ORDEN AND oi2.TIPO_BIEN=oa.TIPO_BIEN
                      AND  oi2.GRUPO_BIEN=:g2 AND oi2.CLASE_BIEN=:c2
                      AND  oi2.FAMILIA_BIEN=:f2 AND oi2.ITEM_BIEN=:it2
                ), 0)                                           AS comprometido,
                /* Ejecutado = compromiso EJECUTADO del cuadro (dm.MNTO_SOLES) para
                   esta orden+ítem en todos los centros. Es el 30 800, no el bruto. */
                ISNULL((
                    SELECT SUM(dm.MNTO_SOLES)
                    FROM   SIG_CUADRO_ADQUISICION ca
                    JOIN   SIG_DETALLE_BSERV_CUADRO db
                           ON db.SEC_EJEC=ca.SEC_EJEC AND db.ANO_EJE=ca.ANO_EJE
                          AND db.TIPO_BIEN=ca.TIPO_BIEN AND db.SEC_CUADRO=ca.SEC_CUADRO
                    JOIN   SIG_DEPEN_META_CUADRO dm
                           ON dm.SEC_EJEC=db.SEC_EJEC AND dm.ANO_EJE=db.ANO_EJE
                          AND dm.TIPO_BIEN=db.TIPO_BIEN AND dm.SEC_CUADRO=db.SEC_CUADRO
                          AND dm.SECUENCIA=db.SECUENCIA
                    WHERE  ca.SEC_EJEC=oa.SEC_EJEC AND ca.ANO_EJE=oa.ANO_EJE
                      AND  ca.NRO_ORDEN=oa.NRO_ORDEN AND ca.TIPO_BIEN=oa.TIPO_BIEN
                      AND  ca.ESTADO<>'A'
                      AND  db.GRUPO_BIEN=:g4 AND db.CLASE_BIEN=:c4
                      AND  db.FAMILIA_BIEN=:f4 AND db.ITEM_BIEN=:it4
                ), 0)                                           AS ejecutado,
                /* Devengado real de la orden para este ítem (a nivel de orden). */
                ISNULL((
                    SELECT SUM(di.VALOR_SOLES)
                    FROM   SIG_DEVENGADO dv
                    JOIN   SIG_DEVENGADO_ITEM di
                           ON di.SEC_EJEC=dv.SEC_EJEC AND di.ANO_EJE=dv.ANO_EJE
                          AND di.NRO_DEVENGADO=dv.NRO_DEVENGADO
                    WHERE  dv.SEC_EJEC=oa.SEC_EJEC AND dv.ANO_EJE=oa.ANO_EJE
                      AND  dv.NRO_ORDEN=oa.NRO_ORDEN AND dv.TIPO_BIEN=oa.TIPO_BIEN
                      AND  di.GRUPO_BIEN=:g3 AND di.CLASE_BIEN=:c3
                      AND  di.FAMILIA_BIEN=:f3 AND di.ITEM_BIEN=:it3
                ), 0)                                           AS devengado,
                /* Multa/penalidad de la orden para este ítem (si la OS incumplió plazo).
                   Se usa MULTA_FINAL (el importe efectivo); 0 si no hubo. */
                ISNULL((
                    SELECT SUM(di.MULTA_FINAL)
                    FROM   SIG_DEVENGADO dv
                    JOIN   SIG_DEVENGADO_ITEM di
                           ON di.SEC_EJEC=dv.SEC_EJEC AND di.ANO_EJE=dv.ANO_EJE
                          AND di.NRO_DEVENGADO=dv.NRO_DEVENGADO
                    WHERE  dv.SEC_EJEC=oa.SEC_EJEC AND dv.ANO_EJE=oa.ANO_EJE
                      AND  dv.NRO_ORDEN=oa.NRO_ORDEN AND dv.TIPO_BIEN=oa.TIPO_BIEN
                      AND  di.GRUPO_BIEN=:g5 AND di.CLASE_BIEN=:c5
                      AND  di.FAMILIA_BIEN=:f5 AND di.ITEM_BIEN=:it5
                ), 0)                                           AS multa
            FROM   SIG_ORDEN_ADQUISICION oa
            /* Enlace por ÍTEM (robusto), vía SIG_ORDEN_ITEM. DISTINCT porque una
               orden puede tener el ítem en varias líneas/dependencias. */
            JOIN (
                SELECT DISTINCT SEC_EJEC, ANO_EJE, NRO_ORDEN, TIPO_BIEN
                FROM   SIG_ORDEN_ITEM
                WHERE  GRUPO_BIEN=:g AND CLASE_BIEN=:c AND FAMILIA_BIEN=:f AND ITEM_BIEN=:it
            ) oi
                   ON oi.SEC_EJEC=oa.SEC_EJEC AND oi.ANO_EJE=oa.ANO_EJE
                  AND oi.NRO_ORDEN=oa.NRO_ORDEN AND oi.TIPO_BIEN=oa.TIPO_BIEN
            LEFT JOIN SIG_CERTIFICACION cab
                   ON cab.SEC_EJEC=oa.SEC_EJEC AND cab.ANO_EJE=oa.ANO_EJE
                  AND cab.NRO_CERTIFICA=oa.NRO_CERTIFICA
            LEFT JOIN SIG_CONTRATISTAS ct ON ct.PROVEEDOR=oa.PROVEEDOR
            WHERE  oa.SEC_EJEC=:sec AND oa.ANO_EJE=:ano AND oa.ESTADO<>'A'
              AND  oa.TIPO_BIEN=:tb
            ORDER BY oa.NRO_CERTIFICA, oa.NRO_ORDEN";
        $st = $this->db->prepare($sql);
        $st->bindValue(':sec', $secEjec,  PDO::PARAM_INT);
        $st->bindValue(':ano', $anioEjec, PDO::PARAM_INT);
        $st->bindValue(':tb',  $tipo);
        $st->bindValue(':g',  $g);  $st->bindValue(':c',  $c);
        $st->bindValue(':f',  $f);  $st->bindValue(':it', $it);
        // PDO_SQLSRV no reusa nombres → duplicados para los subqueries:
        $st->bindValue(':g2', $g);  $st->bindValue(':c2', $c);
        $st->bindValue(':f2', $f);  $st->bindValue(':it2', $it);
        $st->bindValue(':g3', $g);  $st->bindValue(':c3', $c);
        $st->bindValue(':f3', $f);  $st->bindValue(':it3', $it);
        $st->bindValue(':g4', $g);  $st->bindValue(':c4', $c);
        $st->bindValue(':f4', $f);  $st->bindValue(':it4', $it);
        $st->bindValue(':g5', $g);  $st->bindValue(':c5', $c);
        $st->bindValue(':f5', $f);  $st->bindValue(':it5', $it);
        $st->execute();
        return $st->fetchAll();
    }
}