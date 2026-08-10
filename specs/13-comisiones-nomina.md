# SPEC 13 — Comisiones (Nómina del Staff)

> **Status:** Aprovado
> **Depends on:** SPEC 01, SPEC 02, SPEC 03, SPEC 04, SPEC 05, SPEC 11
> **Date:** 2026-08-10
> **Objective:** Calcular automáticamente, cuando una cita pasa a `completed`, cuánto se le debe pagar de comisión al profesional que la atendió, y mostrarlo en un dashboard de "Nómina" filtrable por fecha y staff, con las sumas precalculadas en una vista de MySQL para que la consulta sea rápida.

---

## Por qué esta spec existe

WooCommerce ya cubre los reportes de venta del negocio (SPEC 10); esta spec se enfoca exclusivamente en cuánto se le debe pagar a cada profesional, un cálculo que no tiene relación directa con los reportes de WooCommerce porque depende del `staff_id` que atendió la cita, no del comprador. Depende de SPEC 11 porque el monto sobre el que se calcula la comisión debe incluir el precio de los add-ons de la cita.

---

## Scope

**In:**

- `commission_type` (`fixed` | `percentage`) y `commission_value` por **combinación staff + servicio**, en la tabla pivote `staff_services` (SPEC 01) — un mismo profesional puede tener comisiones distintas según el servicio.
- Tabla `payroll_logs` que registra, por cada cita marcada `completed`, el monto total de la cita (servicio + add-ons), el tipo/valor de comisión usado y el monto ganado.
- Cálculo automático: cuando `PATCH /appointments/{id}` cambia el `status` de una cita a `completed` (solo `manage_options`, ya definido en SPEC 03), se calcula y guarda la comisión, **una sola vez por cita**.
- Si el staff no tiene `commission_type`/`commission_value` configurados para ese servicio, no se genera `payroll_log` (se registra una advertencia en el log de PHP; no bloquea ni rompe el flujo de marcar la cita como completada).
- Vista de MySQL (`payroll_daily_summary`) que agrupa `payroll_logs` por `staff_id` y día, para que el dashboard consulte sumas ya calculadas en vez de recorrer `payroll_logs` completo en cada carga.
- Pantalla **"Nómina"** en el admin (submenú nuevo bajo Reservas, `manage_options`): filtro de rango de fechas libre (desde/hasta) + filtro opcional por staff, tabla con el total de comisión por staff en ese rango.

**Out of scope (para specs futuras):**

- Marcar una comisión como "pagada" — el dashboard es un reporte de solo lectura sobre lo generado en el rango filtrado, no un flujo de pagos.
- Reversión automática de un `payroll_log` si una cita `completed` se revierte a otro estado — el admin lo ajusta manualmente si fue un error.
- Exportar la nómina a PDF/Excel.
- Comisiones sobre paquetes vendidos (SPEC 12) en sí mismos — solo sobre citas individuales `completed`, sea cual sea su forma de pago.

---

## Data model

```sql
-- Migraciones aditivas, vía dbDelta + bump de BOOKING_PLUGIN_DB_VERSION

ALTER TABLE {$wpdb->prefix}booking_staff_services
  ADD COLUMN commission_type  VARCHAR(20) NULL,   -- 'fixed' | 'percentage' | NULL (no configurado)
  ADD COLUMN commission_value DECIMAL(10,2) NULL;

CREATE TABLE {$wpdb->prefix}booking_payroll_logs (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  appointment_id        BIGINT UNSIGNED NOT NULL,
  staff_id              BIGINT UNSIGNED NOT NULL,
  total_service_amount  DECIMAL(10,2) NOT NULL,  -- precio del servicio + suma de add-ons (SPEC 11), lista de precios
  commission_type       VARCHAR(20) NOT NULL,    -- snapshot del tipo usado en el cálculo
  commission_value      DECIMAL(10,2) NOT NULL,  -- snapshot del valor usado en el cálculo
  commission_earned     DECIMAL(10,2) NOT NULL,
  created_at            DATETIME NOT NULL,
  UNIQUE KEY appointment_id (appointment_id),
  KEY staff_id (staff_id),
  KEY created_at (created_at)
);
```

```sql
-- Vista (creada/reemplazada vía $wpdb->query(), no vía dbDelta — dbDelta no soporta CREATE VIEW)
CREATE OR REPLACE VIEW {$wpdb->prefix}booking_payroll_daily_summary AS
SELECT
  staff_id,
  DATE(created_at)            AS day,
  COUNT(*)                    AS appointments_count,
  SUM(total_service_amount)   AS total_service_amount,
  SUM(commission_earned)      AS total_commission
FROM {$wpdb->prefix}booking_payroll_logs
GROUP BY staff_id, DATE(created_at);
```

```
GET /wp-json/booking-plugin/v1/payroll?date_from=&date_to=&staff_id=   (manage_options)
  -> SELECT staff_id, SUM(total_commission) AS total_commission, SUM(appointments_count) AS appointments_count
     FROM {$wpdb->prefix}booking_payroll_daily_summary
     WHERE day BETWEEN date_from AND date_to [AND staff_id = ?]
     GROUP BY staff_id
  -> el controlador hace JOIN con booking_staff para devolver también el nombre del profesional.
```

Convención: una sola vista de grano diario (`payroll_daily_summary`) cubre tanto reportes diarios como cualquier rango arbitrario (incluida una "semana"): la API suma sus filas dentro del rango pedido con un `GROUP BY staff_id` simple, evitando mantener una segunda vista casi idéntica solo para agregados semanales.

---

## Implementation plan

1. Editar `includes/class-booking-plugin-db-schema.php`: agregar las columnas de comisión a `staff_services`, la tabla `payroll_logs`, y un método `maybe_create_views()` que ejecute el `CREATE OR REPLACE VIEW` (fuera de `dbDelta()`, ya que esta no soporta vistas) en cada bump de `BOOKING_PLUGIN_DB_VERSION`. Subir `BOOKING_PLUGIN_DB_VERSION`.
2. Crear `includes/class-booking-plugin-payroll.php`: método `log_commission_for_appointment( $appointment_id )` — calcula `total_service_amount` (precio del servicio + suma de `appointment_addons` de SPEC 11, precio de lista, sin importar si se pagó con crédito de SPEC 12), busca `commission_type`/`commission_value` en `staff_services` para ese `staff_id`+`service_id`; si no están configurados, escribe una advertencia en el log de PHP y no inserta nada; si están, calcula `commission_earned` (`fixed` → `commission_value`; `percentage` → `total_service_amount * commission_value / 100`) e inserta en `payroll_logs`.
3. Editar `includes/rest/class-booking-rest-appointments-controller.php` (SPEC 03): en `PATCH /appointments/{id}`, cuando el `status` nuevo es `completed` y el anterior no lo era, llamar a `log_commission_for_appointment()` después de confirmar el cambio (la `UNIQUE KEY appointment_id` de `payroll_logs` evita duplicados si el hook se dispara dos veces).
4. Editar `includes/rest/class-booking-rest-staff-controller.php` (SPEC 02) para aceptar y devolver `commission_type`/`commission_value` dentro de cada entrada de `staff_services` en `POST`/`PUT /staff`.
5. Crear `includes/rest/class-booking-rest-payroll-controller.php`: `GET /payroll` descrito arriba, consultando `payroll_daily_summary`. Registrar en `includes/class-booking-plugin-rest.php` y `booking-plugin.php`.
6. Editar `assets/src/admin/pages/StaffPage.js`/`StaffFormModal.js` (SPEC 05): por cada servicio asignado al staff, agregar campos `commission_type` (select fijo/porcentaje) + `commission_value`.
7. Crear `assets/src/admin/pages/PayrollPage.js`: filtros de fecha desde/hasta (obligatorios) + selector opcional de staff, tabla con nombre del staff, cantidad de citas y comisión total del rango (`GET /payroll`). Registrar el submenú "Nómina" en `includes/class-booking-plugin-admin.php`.
8. Prueba manual end-to-end: configurar `commission_type=percentage` (20%) para un staff en un servicio con add-ons (SPEC 11); reservar y marcar esa cita como `completed`; confirmar que `payroll_logs` guarda el monto correcto (servicio + add-ons) y que `PayrollPage.js` lo muestra al filtrar por esa fecha y ese staff; marcar `completed` una cita de un staff+servicio sin comisión configurada y confirmar que no aparece en la nómina ni rompe el flujo; volver a hacer `PATCH` a `completed` sobre la misma cita ya completada y confirmar que no se duplica el registro.

---

## Acceptance criteria

- [ ] `StaffFormModal.js` permite definir `commission_type`/`commission_value` por cada servicio asignado a un staff, y se guarda correctamente en `staff_services`.
- [ ] Marcar una cita como `completed` (solo posible con `manage_options`, SPEC 03) crea un `payroll_log` con el monto correcto (precio del servicio + add-ons de SPEC 11), usando el `commission_type`/`commission_value` configurado para ese staff+servicio.
- [ ] Una cita `completed` de un staff+servicio sin comisión configurada no genera `payroll_log` y no produce ningún error visible en la UI.
- [ ] Marcar la misma cita como `completed` más de una vez no duplica su `payroll_log`.
- [ ] Revertir el `status` de una cita `completed` a otro estado no elimina ni modifica su `payroll_log` ya generado.
- [ ] `GET /payroll?date_from=&date_to=` devuelve el total de comisión agrupado por staff dentro de ese rango, consultando la vista `payroll_daily_summary` (no `payroll_logs` directo).
- [ ] El filtro opcional `staff_id` en `GET /payroll` limita el resultado a un solo profesional.
- [ ] `PayrollPage.js` requiere `manage_options` y muestra los totales correctos al filtrar por fecha y, opcionalmente, por staff.
- [ ] Una cita pagada con crédito de paquete (SPEC 12) genera comisión igual que una pagada normalmente, calculada sobre el precio de lista del servicio.

---

## Decisions

- **Sí:** `commission_type`/`commission_value` por combinación staff+servicio (en `staff_services`), no un valor único por profesional. Razón: decisión explícita del usuario; cubre negocios donde el mismo profesional cobra distinto porcentaje/fijo según el servicio.
- **Sí:** si no hay comisión configurada para ese staff+servicio, se omite silenciosamente (con advertencia en el log de PHP), no se asume `0` ni se bloquea marcar la cita como completada. Razón: evita registros de `payroll_log` engañosos con monto `0`, que un admin podría confundir con "no le corresponde comisión" en vez de "falta configurarla".
- **Sí:** el cálculo ocurre una única vez, al momento en que el `status` pasa a `completed`; no hay reversión automática si se revierte el estado después. Razón: decisión explícita del usuario; revertir un estado `completed` no está contemplado en ninguna spec anterior, y automatizar la reversión abriría la pregunta de qué hacer si esa nómina ya se revisó.
- **Sí:** una cita pagada con crédito de paquete (SPEC 12) genera comisión sobre el precio de lista del servicio, igual que una pagada normalmente. Razón: decisión explícita del usuario; el profesional atendió el servicio igual, y el ingreso del paquete ya se contabilizó cuando se vendió por WooCommerce.
- **Sí:** una sola vista de grano diario (`payroll_daily_summary`), agregada por la API para cualquier rango pedido, en vez de dos vistas (diaria y semanal) como sugiere literalmente el documento. Razón: un rango de fechas libre (pedido explícitamente por el usuario para el dashboard) no encaja en una vista fija de "semana calendario"; una sola vista de grano diario resuelve diario, semanal o cualquier rango con el mismo `SELECT ... GROUP BY` simple que pedía el documento, sin duplicar lógica de agregación.
- **Sí:** la vista se crea con `$wpdb->query()` en el hook de migración, no vía `dbDelta()`. Razón: `dbDelta()` solo reconoce sentencias `CREATE TABLE`; `CREATE VIEW` requiere ejecución directa.
- **No:** marcar comisiones como "pagadas". Razón: decisión explícita del usuario; el dashboard es un reporte de solo lectura sobre un rango de fechas, no un flujo de pagos.
- **No:** exportar a PDF/Excel. Razón: sin necesidad confirmada; fuera de alcance del documento base.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| `dbDelta()` no gestiona `CREATE VIEW`; una migración mal ejecutada podría dejar la vista desactualizada tras un cambio de esquema. | La creación/reemplazo de la vista (`CREATE OR REPLACE VIEW`) se ejecuta explícitamente en cada bump de `BOOKING_PLUGIN_DB_VERSION`, no solo en la instalación inicial. |
| Un admin podría interpretar la ausencia de `payroll_log` (por falta de configuración) como "el sistema no cobró bien" en vez de "falta configurar la comisión". | La advertencia queda en el log de PHP; `StaffFormModal.js` deja el campo de comisión visiblemente vacío en vez de con un `0` por defecto, dando una pista visual de que falta definirlo. |
| Marcar `completed` dos veces en llamadas concurrentes podría intentar insertar dos `payroll_logs` para la misma cita. | `UNIQUE KEY appointment_id` en `payroll_logs` rechaza el segundo `INSERT`; el código lo trata como no-op, no como error. |

---

## What is **not** in this spec

- Marcar comisiones como pagadas.
- Reversión automática de comisiones al revertir el estado de una cita.
- Exportar la nómina a PDF/Excel.
- Comisiones sobre la venta de paquetes en sí (SPEC 12) — solo sobre citas `completed`.

Cada uno de estos, si se implementa, va en su propia spec.
