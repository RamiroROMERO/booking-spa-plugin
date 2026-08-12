# SPEC 16 — Pantalla de Saldos Pendientes

> **Status:** Implementado
> **Depends on:** SPEC 01, SPEC 02, SPEC 03, SPEC 04, SPEC 15
> **Date:** 2026-08-12
> **Objective:** Agregar una pantalla nueva en el admin que liste, en un solo lugar, todas las citas con depósito pagado y saldo pendiente de cobro, permitiendo marcarlas como cobradas sin abrir cada cita individualmente.

---

## Por qué esta spec existe

SPEC 15 dejó explícitamente fuera de alcance una "pantalla dedicada de saldos pendientes", indicando que alcanzaba con verlo y gestionarlo cita por cita desde `AppointmentModal.js`, y que podía ser una spec futura si hacía falta un listado agregado. Esta spec cierra ese hueco.

---

## Scope

**In:**

- Nueva página de admin "Saldos pendientes" (mismo patrón de menú que Calendario/Servicios/Staff/Configuración/Notificaciones/Paquetes/Nómina, SPEC 04).
- Nuevo query param `has_pending_balance` en `GET /appointments` (SPEC 03): cuando es `true`, filtra `balance_due IS NOT NULL AND balance_collected_at IS NULL`. Sin este parámetro, el endpoint se comporta exactamente igual que hoy.
- Filtros en la pantalla: rango de fechas (Desde/Hasta, opcionales) y Staff (select, opcional) — mismo patrón que `PayrollPage.js` (SPEC 13). Sin filtros aplicados, la pantalla trae todas las citas con saldo pendiente, sin exigir un rango de fechas.
- Toggle "Mostrar cobradas" (apagado por defecto, mismo patrón que "Mostrar inactivos" en `ServicesPage.js`): cuando está activo, la pantalla omite el filtro `has_pending_balance` y en su lugar trae toda cita con `balance_due IS NOT NULL` sin importar si ya fue cobrada.
- Orden por `start_datetime ASC` (más antigua primero) — ya es el orden por defecto del endpoint, sin cambios de código.
- Cada fila de la tabla muestra: Cliente (`guest_name`, o "Reserva" si es un cliente registrado sin nombre de invitado — mismo criterio que ya usa `AppointmentCard.js`), Servicio (resuelto contra `GET /services`), Staff (resuelto contra `GET /staff`), Fecha y hora de la cita, Depósito pagado (`deposit_amount`) y Saldo pendiente (`balance_due`).
- Botón "Marcar cobrado" por fila (visible solo si `balance_collected_at` es `null`) que llama al mismo `PATCH /appointments/{id}` con `{ "balance_collected": true }` que ya usa `AppointmentModal.js` (SPEC 15), y recarga el listado al confirmar.
- Nuevo ítem en el menú de admin del plugin, sección `balances`.

**Out of scope (para specs futuras):**

- Exportar el listado a PDF/Excel (la Nómina, SPEC 13, tampoco lo tiene).
- Notificar al admin (email o aviso en el dashboard) cuando aparece un saldo nuevo o queda vencido hace mucho tiempo.
- Un indicador de "atraso"/antigüedad más allá del orden por fecha (colores, alertas, etc.).
- Cobrar el saldo online desde esta pantalla — sigue siendo manual (mismo criterio de SPEC 15, sin segundo pedido de WooCommerce).
- Deshacer "marcar cobrado" si el admin se equivoca.
- Búsqueda por nombre/email del cliente.
- Resolver el nombre real de clientes registrados (con `user_id`) que reservaron sin `guest_name` — sigue mostrando el mismo fallback genérico que ya usa el resto del admin.

---

## Data model

Esta spec no agrega tablas ni columnas nuevas: reutiliza `deposit_amount`, `balance_due` y `balance_collected_at` en `booking_appointments`, agregadas en SPEC 15.

Único cambio de forma es un parámetro de query nuevo sobre el endpoint ya existente:

```
GET /booking-plugin/v1/appointments?has_pending_balance=true&date_from=...&date_to=...&staff_id=...&per_page=100
```

La respuesta usa el mismo shape de item que el endpoint ya devuelve hoy (sin cambios): incluye `deposit_amount`, `balance_due` y `balance_collected_at` por cada cita.

---

## Implementation plan

1. Editar `includes/rest/class-booking-rest-appointments-controller.php` (`get_items`): agregar el parámetro `has_pending_balance`; cuando es truthy, agregar `balance_due IS NOT NULL AND balance_collected_at IS NULL` a la cláusula `WHERE` existente (junto a `status`/`staff_id`/`date_from`/`date_to`, que se pueden combinar). Prueba manual: `GET /appointments?has_pending_balance=true` devuelve solo citas con saldo pendiente; sin el parámetro, el resultado no cambia respecto al comportamiento actual.
2. Editar `includes/class-booking-plugin-admin.php`: agregar `SLUG_BALANCES`, su `add_submenu_page()` para "Saldos pendientes" y la entrada correspondiente en el array `$sections` (`section => 'balances'`), siguiendo el mismo patrón que las páginas existentes. Prueba manual: el nuevo ítem aparece en el menú del plugin y carga una página en blanco con la sección correcta (`window.BookingPluginAdmin.section === 'balances'`).
3. Crear `assets/src/admin/pages/PendingBalancesPage.js`: carga `staff` (`GET /staff`) y `services` (`GET /services`) una vez al montar para resolver nombres; filtros Desde/Hasta/Staff (patrón `PayrollPage.js`) + `ToggleControl` "Mostrar cobradas" (patrón `ServicesPage.js`); tabla (`booking-plugin-table`) con las columnas descriptas en Scope. Sin filtros, pide `GET /appointments?has_pending_balance=true&per_page=100` al montar.
4. En el mismo componente, agregar el botón "Marcar cobrado" por fila: `PATCH /appointments/{id}` con `{ balance_collected: true }`, y recargar el listado al resolver.
5. Editar `assets/src/admin/index.js`: registrar `balances: PendingBalancesPage` en `PAGES_BY_SECTION`.
6. Agregar estilos en `assets/src/admin/style.scss` para los filtros de la nueva página, generalizando el patrón grid ya usado en `.booking-plugin-payroll__filters`.
7. Prueba manual end-to-end: crear/usar un servicio con depósito (SPEC 15), reservarlo generando saldo pendiente, entrar a "Saldos pendientes" sin aplicar filtros y confirmar que la cita aparece con los montos correctos; marcarla como cobrada desde la fila y confirmar que desaparece de la vista por defecto; activar "Mostrar cobradas" y confirmar que reaparece con la fecha de cobro; probar que los filtros de fecha y staff acotan el listado correctamente.

---

## Acceptance criteria

- [ ] `GET /appointments?has_pending_balance=true` devuelve únicamente citas con `balance_due` no nulo y `balance_collected_at` nulo.
- [ ] Sin el parámetro `has_pending_balance`, el endpoint se comporta exactamente igual que antes de esta spec.
- [ ] Aparece un nuevo ítem "Saldos pendientes" en el menú de admin del plugin.
- [ ] Al entrar a la pantalla sin aplicar filtros, se listan todas las citas con saldo pendiente de cobro, sin necesidad de elegir un rango de fechas.
- [ ] Cada fila muestra cliente, servicio, staff, fecha/hora, depósito pagado y saldo pendiente.
- [ ] El botón "Marcar cobrado" de una fila marca `balance_collected_at` (mismo `PATCH` que SPEC 15) y la fila desaparece de la vista por defecto.
- [ ] El toggle "Mostrar cobradas" revela también las citas con `balance_collected_at` ya seteado, y esas filas no muestran el botón "Marcar cobrado".
- [ ] Los filtros de fecha (Desde/Hasta) y Staff acotan correctamente el listado, combinándose con el filtro de saldo pendiente.
- [ ] Una cita sin depósito (servicio sin `requires_deposit`) nunca aparece en este listado.
- [ ] El modal de detalle de cita (`AppointmentModal.js`, SPEC 04/15) sigue funcionando exactamente igual, sin cambios de comportamiento.

---

## Decisions

- **Sí:** página nueva dedicada en el menú de admin, no una sección dentro de Nómina. Razón: decisión explícita del usuario; "saldos pendientes" es un concepto propio (cobro de depósitos), distinto de comisiones de staff.
- **Sí:** reutilizar `GET /appointments` con un nuevo filtro `has_pending_balance`, en vez de un endpoint dedicado. Razón: decisión explícita del usuario; evita duplicar paginación, permisos y filtros ya construidos en SPEC 03.
- **Sí:** sin filtro de fecha aplicado, la pantalla trae todo lo pendiente (no arranca vacía como Nómina). Razón: decisión explícita del usuario; el universo de citas con saldo pendiente ya está acotado por naturaleza, a diferencia del historial completo de citas que sí justifica exigir un rango en Nómina.
- **Sí:** orden por fecha de cita ascendente (más antigua primero), reusando el `ORDER BY start_datetime ASC` que ya tiene el endpoint. Razón: decisión explícita del usuario; las citas más viejas sin cobrar son las más urgentes de resolver.
- **Sí:** acción "Marcar cobrado" directa desde la fila de la tabla, sin pasar por el modal de la cita. Razón: decisión explícita del usuario; es el beneficio principal de esta pantalla frente a revisar cita por cita.
- **Sí:** toggle "Mostrar cobradas" en vez de un filtro de estado explícito. Razón: mismo patrón ya usado en `ServicesPage.js` ("Mostrar inactivos"), consistente con el resto del admin.
- **Sí:** para citas de clientes registrados sin `guest_name`, mostrar el mismo fallback genérico ("Reserva") que ya usa `AppointmentCard.js`. Razón: el plugin no resuelve hoy el nombre de un usuario de WordPress logueado en ningún listado de citas; resolverlo es un cambio más amplio que excede el pedido puntual de esta spec.
- **No:** exportar a PDF/Excel. Razón: fuera de alcance pedido; mismo estado que la Nómina (SPEC 13).
- **No:** deshacer "marcar cobrado". Razón: fuera de alcance pedido; si se necesita, requiere una acción explícita a diseñar en una spec futura.
- **No:** búsqueda por nombre/email del cliente. Razón: decisión explícita del usuario; se descartó a favor de fecha + staff únicamente.
- **No:** cobro del saldo online desde esta pantalla. Razón: consistente con la decisión ya tomada en SPEC 15 de que el cobro del saldo es siempre manual.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| Un cliente registrado sin `guest_name` es indistinguible de otro en la lista (ambos muestran "Reserva"). | Mismo comportamiento ya existente en el resto del admin (`AppointmentCard.js`); no es una regresión de esta spec. Resolver nombres de usuario de WP queda para una spec futura si se vuelve un problema real. |
| El filtro `has_pending_balance=true` sin índice específico en `balance_due`/`balance_collected_at` podría ser lento si `booking_appointments` crece mucho. | El volumen esperado de citas con depósito es bajo comparado con el total de citas; se puede agregar un índice compuesto en una spec futura si se vuelve un cuello de botella real. |

---

## What is **not** in this spec

- Exportar el listado a PDF/Excel.
- Deshacer "marcar cobrado".
- Búsqueda por nombre/email del cliente.
- Cobro del saldo online (segundo pedido de WooCommerce) desde esta pantalla.
- Resolver el nombre real de clientes registrados sin `guest_name`.

Cada uno de estos, si se implementa, va en su propia spec.
