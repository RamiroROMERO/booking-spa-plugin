# SPEC 17 — Pago de Comisiones y Exportación de Nómina

> **Status:** Implementado
> **Depends on:** SPEC 01, SPEC 02, SPEC 04, SPEC 13
> **Date:** 2026-08-12
> **Objective:** Permitir que el admin marque (y desmarque) como pagadas las comisiones de un staff dentro del rango de fechas filtrado en la pantalla de Nómina, y exportar a CSV el listado agregado que se está viendo.

---

## Por qué esta spec existe

SPEC 13 dejó explícitamente fuera de alcance "marcar una comisión como pagada" (el dashboard era un reporte de solo lectura) y "exportar la nómina a PDF/Excel". Esta spec cierra ambos huecos sobre la misma pantalla de Nómina ya existente.

---

## Scope

**In:**

- Nueva columna `paid_at` (`DATETIME NULL`) en `booking_payroll_logs` (SPEC 13): `NULL` = comisión pendiente de pago; timestamp = cuándo se marcó pagada.
- Vista `payroll_daily_summary` (SPEC 13) extendida para sumar también `paid_count` (cantidad de `payroll_logs` con `paid_at` no nulo) por staff+día, junto a lo que ya suma (`appointments_count`, `total_commission`).
- `GET /payroll` (SPEC 13) devuelve además `paid_count` por staff, agregado sobre el rango filtrado igual que ya hace con `appointments_count`/`total_commission`.
- Dos acciones nuevas, ambas `manage_options`, que operan en bloque sobre staff + rango de fechas filtrado (no comisión por comisión):
  - `PATCH /payroll/mark-paid` — marca como pagadas todas las comisiones pendientes de ese staff dentro del rango.
  - `PATCH /payroll/unmark-paid` — revierte a pendientes todas las comisiones ya pagadas de ese staff dentro del rango.
- En `PayrollPage.js`: columna nueva "Pagado" con formato `X/Y pagadas` (`paid_count`/`appointments_count`) por fila de staff.
- Botón "Marcar pagada" por fila, visible/habilitado cuando `paid_count < appointments_count` (hay al menos una comisión pendiente en el rango).
- Botón "Desmarcar pagada" por fila, visible/habilitado cuando `paid_count > 0` (hay al menos una comisión ya pagada en el rango).
- Botón "Exportar CSV" en la pantalla, generado en el navegador (sin endpoint nuevo) a partir de las filas visibles en ese momento (staff, cantidad de citas, comisión total, pagado X/Y), respetando los filtros de fecha/staff aplicados.

**Out of scope (para specs futuras):**

- Marcar/desmarcar una comisión individual (cita por cita) — sigue siendo por staff+rango; no hay en esta spec un listado línea por línea de `payroll_logs`.
- Exportar en formato XLSX real (binario de Excel) — el CSV generado se abre bien en Excel/Sheets, pero no es un `.xlsx` nativo.
- Notificar al staff cuando se le marca una comisión como pagada.
- Cambios al cálculo de comisiones en sí (SPEC 13) — esta spec solo agrega estado de pago sobre lo ya calculado.
- Filtrar el dashboard por estado de pago (por ejemplo, "mostrar solo staff con pagos pendientes") — el filtro sigue siendo fecha + staff, igual que hoy.

---

## Data model

```sql
-- Migración aditiva, vía dbDelta + bump de BOOKING_PLUGIN_DB_VERSION
ALTER TABLE {$wpdb->prefix}booking_payroll_logs
  ADD COLUMN paid_at DATETIME NULL; -- NULL = pendiente de pago; timestamp = cuándo se marcó pagada

-- Vista reemplazada (ya existía desde SPEC 13; se le agrega paid_count)
CREATE OR REPLACE VIEW {$wpdb->prefix}booking_payroll_daily_summary AS
SELECT
  staff_id,
  DATE(created_at)            AS day,
  COUNT(*)                    AS appointments_count,
  SUM(total_service_amount)   AS total_service_amount,
  SUM(commission_earned)      AS total_commission,
  SUM(CASE WHEN paid_at IS NOT NULL THEN 1 ELSE 0 END) AS paid_count
FROM {$wpdb->prefix}booking_payroll_logs
GROUP BY staff_id, DATE(created_at);
```

```
GET /payroll?date_from=&date_to=&staff_id=   (manage_options, ya existente desde SPEC 13)
  -> agrega "paid_count" a cada fila de staff (SUM igual que appointments_count/total_commission)

PATCH /payroll/mark-paid    { staff_id, date_from, date_to }   (manage_options)
  -> UPDATE booking_payroll_logs SET paid_at = NOW()
     WHERE staff_id = ? AND DATE(created_at) BETWEEN ? AND ? AND paid_at IS NULL

PATCH /payroll/unmark-paid  { staff_id, date_from, date_to }   (manage_options)
  -> UPDATE booking_payroll_logs SET paid_at = NULL
     WHERE staff_id = ? AND DATE(created_at) BETWEEN ? AND ? AND paid_at IS NOT NULL
```

Convención: el rango usado para marcar/desmarcar pagada es sobre `DATE(created_at)` de `payroll_logs` — el mismo campo que ya usa `GET /payroll` para filtrar (fecha en que la cita se marcó `completed`, no la fecha de la cita en sí; criterio ya establecido en SPEC 13). Como `created_at` se fija en el momento del `INSERT` y nunca se modifica, un rango de fechas ya cerrado (con `date_to` en el pasado) no puede recibir comisiones nuevas más adelante — solo un rango que incluya el día de hoy puede seguir sumando comisiones después de haberlo marcado pagado.

---

## Implementation plan

1. Editar `includes/class-booking-plugin-db-schema.php`: agregar `paid_at DATETIME NULL` a `booking_payroll_logs` y actualizar `maybe_create_views()` para que el `CREATE OR REPLACE VIEW payroll_daily_summary` incluya `paid_count`. Subir `BOOKING_PLUGIN_DB_VERSION`. Prueba manual: reactivar/recargar el admin y confirmar en `SHOW COLUMNS`/`SHOW CREATE VIEW` que ambos cambios se aplicaron sin romper filas ni vista existentes.
2. Editar `includes/rest/class-booking-rest-payroll-controller.php` (SPEC 13): en `GET /payroll`, sumar también `paid_count` por staff en la respuesta, mismo criterio que `appointments_count`/`total_commission`.
3. En el mismo archivo, agregar las rutas `PATCH /payroll/mark-paid` y `PATCH /payroll/unmark-paid` (`manage_options`), validando `staff_id`/`date_from`/`date_to` requeridos, ejecutando el `UPDATE` correspondiente y devolviendo `paid_count`/`appointments_count` actualizados para ese staff+rango. Registrar las rutas en `register_routes()`.
4. Editar `assets/src/admin/pages/PayrollPage.js`: agregar columna "Pagado" (`X/Y pagadas`); botones "Marcar pagada"/"Desmarcar pagada" por fila (visibles/habilitados según `paid_count` vs `appointments_count`) que llaman a los endpoints nuevos y recargan el listado; botón "Exportar CSV" que arma un archivo a partir de las filas visibles (`Blob` + descarga en el navegador, sin request al servidor).
5. Prueba manual end-to-end: completar 2 citas para el mismo staff dentro de un rango con comisión configurada, confirmar que la fila muestra `0/2`; marcar pagada y confirmar `2/2` con el cambio de botones disponibles (Marcar deshabilitado/oculto, Desmarcar habilitado); completar una tercera cita del mismo staff dentro del mismo rango y confirmar que pasa a `2/3` con "Marcar pagada" disponible de nuevo; desmarcar pagada y confirmar que vuelve a `0/3`; exportar CSV y confirmar que el archivo descargado tiene las columnas y valores esperados para el rango filtrado.

---

## Acceptance criteria

- [x] `booking_payroll_logs` tiene la columna `paid_at` tras la migración, sin afectar filas existentes (quedan con `paid_at = NULL`).
- [x] La vista `payroll_daily_summary` incluye `paid_count` además de los campos que ya tenía.
- [x] `GET /payroll` devuelve `paid_count` por staff, correctamente sumado sobre el rango filtrado.
- [x] `PATCH /payroll/mark-paid` marca como pagadas únicamente las comisiones pendientes de ese staff dentro del rango indicado, sin tocar comisiones ya pagadas ni de otro staff/rango.
- [x] Ejecutar `mark-paid` dos veces seguidas sobre el mismo staff+rango es idempotente (no falla, y no cambia nada la segunda vez si ya no queda ninguna pendiente).
- [x] `PATCH /payroll/unmark-paid` revierte a pendiente únicamente las comisiones pagadas de ese staff dentro del rango indicado.
- [x] `PayrollPage.js` muestra "X/Y pagadas" por fila, coincidiendo con `paid_count`/`appointments_count`.
- [x] El botón "Marcar pagada" está visible/habilitado solo si hay al menos una comisión pendiente en la fila (`paid_count < appointments_count`).
- [x] El botón "Desmarcar pagada" está visible/habilitado solo si hay al menos una comisión pagada en la fila (`paid_count > 0`).
- [x] Una comisión nueva (de una cita completada después de un "Marcar pagada" previo) que cae dentro del mismo rango filtrado aparece como pendiente, sin afectar las que ya estaban pagadas.
- [x] "Exportar CSV" descarga un archivo con las mismas filas y columnas (staff, cantidad de citas, comisión total, pagado X/Y) que se ven en pantalla en ese momento, respetando los filtros aplicados.
- [x] Ambos endpoints nuevos (`mark-paid`/`unmark-paid`) requieren `manage_options`, igual que el resto de la API de nómina.

---

## Decisions

- **Sí:** granularidad por staff + rango de fechas filtrado, no por comisión individual. Razón: decisión explícita del usuario; el dashboard actual ya opera a ese nivel (una fila = staff + rango), y bajar a nivel de cita individual requeriría rediseñar la pantalla para mostrar un listado línea por línea.
- **Sí:** se puede desmarcar una comisión ya pagada. Razón: decisión explícita del usuario para esta spec puntual (a diferencia del precedente de SPEC 15 con "saldo cobrado", que no permite deshacer).
- **Sí:** el estado mixto (parcialmente pagado) se muestra como contador "X/Y pagadas", con ambos botones (Marcar/Desmarcar) disponibles según corresponda, en vez de un simple Sí/No/Parcial. Razón: decisión explícita del usuario; es más preciso y permite completar el pago de lo que falta sin afectar lo ya pagado.
- **Sí:** "Marcar pagada"/"Desmarcar pagada" son operaciones en bloque (`UPDATE ... WHERE ... AND paid_at IS NULL/NOT NULL`), idempotentes, en vez de exigir que el admin elija comisiones puntuales. Razón: consistente con la granularidad por staff+rango ya decidida; evita un segundo viaje de ida y vuelta si se ejecuta dos veces.
- **Sí:** exportar es un CSV generado en el navegador (sin endpoint nuevo), con las mismas filas agregadas que ya se ven en pantalla. Razón: decisión explícita del usuario; evita construir un generador de archivos en PHP para un caso simple que ya tiene los datos cargados en el cliente.
- **No:** exportar el detalle línea por línea de cada comisión individual. Razón: decisión explícita del usuario; el export refleja lo que se ve en pantalla (agregado por staff), no un detalle nuevo que no existe hoy en la UI.
- **No:** exportar en formato XLSX real. Razón: fuera de alcance pedido; un CSV ya se abre correctamente en Excel/Sheets.
- **No:** notificar al staff cuando se le marca una comisión como pagada. Razón: fuera de alcance pedido; el dashboard sigue siendo una herramienta interna del admin.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| Un admin marca pagado un rango que incluye el día de hoy, y más tarde ese mismo día se completan más citas de ese staff dentro del rango — esas comisiones nuevas quedan sin pagar sin que sea obvio a simple vista. | El contador "X/Y pagadas" lo refleja de inmediato (deja de decir "todo pagado" apenas aparece una comisión nueva pendiente), y el botón "Marcar pagada" vuelve a estar disponible para cubrir la diferencia. |
| Marcar/desmarcar pagada desde dos pestañas del admin en simultáneo podría generar una condición de carrera sobre las mismas filas. | El `UPDATE` es una operación atómica de MySQL con condición `WHERE paid_at IS NULL/NOT NULL`; ejecutarlo dos veces en paralelo no duplica ni corrompe el estado, en el peor caso una de las dos queda como no-op. |

---

## What is **not** in this spec

- Marcar/desmarcar una comisión individual (cita por cita).
- Exportar el detalle línea por línea de cada comisión.
- Exportar en formato XLSX real.
- Notificar al staff cuando se le marca una comisión como pagada.
- Cambios al cálculo de comisiones en sí (SPEC 13).

Cada uno de estos, si se implementa, va en su propia spec.
