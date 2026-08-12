# SPEC 15 — Pagos Parciales (Depósito) y Saldo Pendiente

> **Status:** Implementado
> **Depends on:** SPEC 01, SPEC 02, SPEC 03, SPEC 04, SPEC 05, SPEC 06, SPEC 10, SPEC 11
> **Date:** 2026-08-12
> **Objective:** Permitir que un servicio con pago online configure un porcentaje de depósito, de modo que la reserva solo cobre esa parte al momento de reservar y el saldo restante quede pendiente hasta que el admin lo marque como cobrado.

---

## Por qué esta spec existe

SPEC 10 dejó explícitamente fuera de alcance "depósitos o pagos parciales — solo el precio completo del servicio". Esta spec cierra ese hueco: extiende la integración de WooCommerce de SPEC 10 para que un servicio pueda cobrar solo una parte del precio al reservar (depósito), dejando el resto como saldo pendiente que el admin gestiona manualmente desde el calendario.

---

## Scope

**In:**

- Nuevo toggle `requires_deposit` y campo `deposit_percentage` por servicio (SPEC 02), solo disponible si el servicio ya tiene `requires_payment=true` (SPEC 10).
- Al reservar un servicio con `requires_deposit=true`, el pedido de WooCommerce generado (SPEC 10) es solo por el monto del depósito, no por el precio total.
- El monto total sobre el que se calcula el depósito y el saldo incluye el precio del servicio más los add-ons de la cita (SPEC 11), igual criterio que ya usa SPEC 13 para comisiones.
- `deposit_amount` y `balance_due` quedan guardados como snapshot en la cita al momento de crearla (`POST /appointments`), sin recalcularse después.
- El barrido de `payment_window_hours` (SPEC 10) sigue aplicando igual sobre el pedido del depósito: si no se paga a tiempo, la cita se autocancela — sin cambios de código, porque ya opera sobre `wc_order_id` sin importar el monto del pedido.
- El admin puede marcar el saldo pendiente como cobrado desde el modal de detalle de cita existente (`AppointmentModal.js`, SPEC 04), en cualquier momento después de que el depósito esté pagado — no hace falta esperar a que la cita esté en `completed`.
- El widget de reserva (SPEC 06 / SPEC 10) muestra "Pagar depósito" con el monto y porcentaje, y un texto indicando el saldo pendiente que quedará después del pago.

**Out of scope (para specs futuras):**

- Pantalla dedicada de "saldos pendientes" (listado agregado de todas las citas con saldo sin cobrar) — por ahora el saldo se ve y se gestiona cita por cita en `AppointmentModal.js`.
- Reembolso automático del depósito al cancelar una cita — igual que SPEC 10, el reembolso es una acción manual del admin en WooCommerce.
- Cambios en el cálculo de comisiones de SPEC 13 — sigue usando el monto total de la cita (servicio + add-ons) al marcarla `completed`, sin importar cuánto del saldo esté cobrado.
- Segundo pedido de WooCommerce generado automáticamente para cobrar el saldo online — el cobro del saldo es manual (efectivo, transferencia, POS en persona), no un checkout adicional.
- El cliente eligiendo cuánto pagar — el porcentaje de depósito es fijo por servicio, configurado por el admin.
- Depósitos para servicios sin `requires_payment=true` (sin WooCommerce activo) — igual restricción que SPEC 10.

---

## Data model

```sql
-- Migraciones aditivas, vía dbDelta + bump de BOOKING_PLUGIN_DB_VERSION
ALTER TABLE {$wpdb->prefix}booking_services
  ADD COLUMN requires_deposit    TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN deposit_percentage  DECIMAL(5,2) NULL; -- ej. 30.00 = 30%. NULL si requires_deposit=0

ALTER TABLE {$wpdb->prefix}booking_appointments
  ADD COLUMN deposit_amount        DECIMAL(10,2) NULL, -- snapshot del monto cobrado como depósito; NULL = esta cita no usa depósito
  ADD COLUMN balance_due           DECIMAL(10,2) NULL, -- snapshot: (precio del servicio + add-ons) - deposit_amount
  ADD COLUMN balance_collected_at  DATETIME NULL;       -- NULL = saldo pendiente; timestamp = cuándo el admin lo marcó cobrado
```

`wc_order_id` (ya existente desde SPEC 10) se reutiliza tal cual: cuando la cita usa depósito, ese pedido de WooCommerce es por `deposit_amount`, no por el total. No se crea una columna ni un pedido separado para el saldo.

```js
// POST/PUT /services (SPEC 02) — se agregan dos campos al payload existente
{
  ...campos existentes (incluye requires_payment de SPEC 10),
  "requires_deposit": true,
  "deposit_percentage": 30.00   // obligatorio y entre 1-99 si requires_deposit=true; requiere requires_payment=true (400 si no)
}

// Respuesta de POST /appointments (SPEC 03) cuando el servicio requiere depósito:
{
  "id": 87,
  "status": "pending",
  // ...campos existentes de SPEC 10 (incluye checkout_url, ahora apuntando al pedido del depósito),
  "deposit_amount": 60.00,
  "balance_due": 140.00
}

// PATCH /appointments/{id} (manage_options) — nueva acción para registrar el cobro del saldo
{ "balance_collected": true }
// -> solo válido si balance_due IS NOT NULL y balance_collected_at IS NULL; setea balance_collected_at = NOW()
```

Convención: `deposit_amount` y `balance_due` se calculan una única vez, dentro de la misma transacción de `POST /appointments` que ya crea el pedido de WooCommerce (SPEC 10) y las filas de `appointment_addons` (SPEC 11): `total = price_del_servicio + suma(appointment_addons.price)`, `deposit_amount = round(total * deposit_percentage / 100, 2)`, `balance_due = total - deposit_amount`. Al igual que con `appointment_addons`, este snapshot nunca se recalcula después, aunque cambie el precio del servicio o del add-on.

---

## Implementation plan

1. Editar `includes/class-booking-plugin-db-schema.php`: agregar `requires_deposit`/`deposit_percentage` a `booking_services` y `deposit_amount`/`balance_due`/`balance_collected_at` a `booking_appointments`. Subir `BOOKING_PLUGIN_DB_VERSION`. Prueba manual: reactivar/recargar el admin y confirmar en `SHOW COLUMNS` que las columnas existen sin romper filas ya existentes.
2. Editar `includes/rest/class-booking-rest-services-controller.php` (SPEC 02): aceptar/devolver `requires_deposit`/`deposit_percentage`; validar que `requires_deposit=true` solo sea posible si `requires_payment=true` (400 si no) y que `deposit_percentage` esté presente y entre 1-99 cuando `requires_deposit=true`.
3. Editar `includes/class-booking-plugin-woocommerce.php` (SPEC 10): `create_order_for_appointment()` calcula `total` (precio del servicio + add-ons, ya usado por SPEC 11), y si el servicio tiene `requires_deposit=true`, crea el pedido de WooCommerce por `deposit_amount` en vez del total, devolviendo también `deposit_amount`/`balance_due` para que el controlador los persista.
4. Editar `includes/rest/class-booking-rest-appointments-controller.php` (SPEC 03/10): en `POST /appointments`, cuando el servicio requiere depósito, guardar `deposit_amount`/`balance_due` junto con `wc_order_id`; en `PATCH /appointments/{id}`, aceptar `balance_collected: true` (`manage_options`, solo si `balance_due IS NOT NULL` y `balance_collected_at IS NULL`) y setear `balance_collected_at = NOW()`.
5. Editar `assets/src/admin/pages/ServiceFormModal.js` (SPEC 05): toggle "Requiere depósito" (habilitado solo si "Requiere pago online" ya está activo) + campo numérico de porcentaje (1-99).
6. Editar `assets/src/admin/components/AppointmentModal.js` (SPEC 04): cuando la cita tiene `deposit_amount`, mostrar "Depósito pagado: $X" y "Saldo pendiente: $Y" con botón "Marcar saldo como cobrado"; tras marcarlo, mostrar la fecha de `balance_collected_at` en vez del botón.
7. Editar `ConfirmationStep.js`/`SuccessScreen.js` (SPEC 06/10): cuando la respuesta de `POST /appointments` incluye `deposit_amount`, cambiar el botón "Pagar ahora" por "Pagar depósito ($deposit_amount — deposit_percentage%)" y mostrar un texto con el `balance_due` restante.
8. Prueba manual end-to-end: marcar un servicio como "Requiere pago online" + "Requiere depósito" (30%), reservarlo desde el widget, confirmar que el pedido de WooCommerce generado es solo por el 30% del total (servicio + add-ons si tiene) y que la pantalla de éxito muestra el monto del depósito y el saldo restante; pagar el depósito y confirmar que la cita pasa a `confirmed`; desde el calendario admin, abrir la cita y marcar el saldo como cobrado, confirmando que el modal deja de mostrar el botón y pasa a mostrar la fecha de cobro; crear una reserva con depósito y no pagarla, adelantar `payment_window_hours` y correr el barrido de SPEC 10, confirmando que la cita se autocancela igual que con pago completo.

---

## Acceptance criteria

- [ ] `booking_services` tiene las columnas `requires_deposit`/`deposit_percentage` tras la migración, sin afectar las filas ya existentes (quedan con `requires_deposit=0`).
- [ ] `booking_appointments` tiene las columnas `deposit_amount`/`balance_due`/`balance_collected_at` tras la migración.
- [ ] Activar `requires_deposit=true` en un servicio con `requires_payment=false` devuelve `400`.
- [ ] `deposit_percentage` es obligatorio y debe estar entre 1 y 99 cuando `requires_deposit=true`; la API rechaza valores fuera de rango.
- [ ] Reservar un servicio con `requires_deposit=true` genera un pedido de WooCommerce solo por `deposit_amount` (calculado sobre precio del servicio + add-ons), no por el total.
- [ ] La respuesta de `POST /appointments` incluye `deposit_amount` y `balance_due` cuando el servicio requiere depósito.
- [ ] El widget de reserva muestra "Pagar depósito" con el monto y porcentaje correspondiente, y un texto indicando el saldo pendiente.
- [ ] El modal de detalle de cita en el admin (SPEC 04) muestra el depósito pagado y el saldo pendiente cuando la cita tiene `deposit_amount`.
- [ ] El admin puede marcar el saldo como cobrado desde el modal de cita en cualquier momento después de pagado el depósito, sin necesidad de que la cita esté en `completed`.
- [ ] Marcar el saldo como cobrado registra `balance_collected_at`; el modal deja de mostrar el botón y muestra la fecha de cobro en su lugar.
- [ ] Intentar marcar el saldo como cobrado dos veces (o en una cita sin `balance_due`) devuelve un error, no crea un segundo registro.
- [ ] Un servicio con `requires_payment=true` pero `requires_deposit=false` sigue funcionando exactamente como en SPEC 10 (cobro completo, sin campos de depósito en la respuesta).
- [ ] El barrido de `payment_window_hours` (SPEC 10) sigue cancelando automáticamente citas con el depósito impago vencido, sin cambios de código en el barrido.

---

## Decisions

- **Sí:** `requires_deposit`/`deposit_percentage` configurable por servicio, no un porcentaje global ni elegido por el cliente. Razón: decisión explícita del usuario; consistente con cómo `requires_payment` (SPEC 10) ya es por servicio.
- **Sí:** `requires_deposit=true` solo es válido si el servicio ya tiene `requires_payment=true`. Razón: un depósito es un caso particular de "requiere pago online"; sin WooCommerce/pago habilitado no hay nada que cobrar.
- **Sí:** el cobro del saldo es manual (el admin lo marca cobrado), sin generar un segundo pedido de WooCommerce automático. Razón: decisión explícita del usuario; el saldo suele cobrarse en persona (efectivo, transferencia, POS) al momento del servicio, no por un checkout adicional online.
- **Sí:** el saldo puede marcarse como cobrado en cualquier momento después de pagado el depósito, sin esperar a que la cita esté `completed`. Razón: decisión explícita del usuario; el negocio puede cobrar el saldo antes de que termine la cita.
- **Sí:** `deposit_amount`/`balance_due` son snapshot calculados una sola vez en `POST /appointments`, nunca recalculados después. Razón: mismo criterio que `appointment_addons` (SPEC 11) — el historial de una cita no debe cambiar si el precio del servicio o de un add-on se edita después.
- **Sí:** el monto base para calcular depósito y saldo incluye add-ons (SPEC 11), no solo el precio base del servicio. Razón: decisión explícita del usuario; mismo criterio que SPEC 13 ya usa para comisiones (`total_service_amount`).
- **Sí:** el barrido de `payment_window_hours` (SPEC 10) aplica igual al depósito, sin cambios de código. Razón: decisión explícita del usuario; el barrido ya opera sobre `wc_order_id` sin importar el monto del pedido, así que una cita con depósito impago se autocancela con el mismo mecanismo.
- **No:** reembolso automático del depósito al cancelar una cita. Razón: consistente con la política ya definida en SPEC 10 — el reembolso, si corresponde, es una acción manual del admin en WooCommerce.
- **No:** cambios en el cálculo de comisiones de SPEC 13. Razón: decisión explícita del usuario; el payroll sigue usando el monto total de la cita al marcarla `completed`, sin importar cuánto del saldo esté cobrado.
- **No:** pantalla dedicada de "saldos pendientes". Razón: decisión explícita del usuario; alcanza con verlo y gestionarlo cita por cita desde el modal ya existente de SPEC 04. Puede ser una spec futura si hace falta un listado agregado.
- **No:** el cliente elige cuánto pagar. Razón: fuera de alcance pedido; el porcentaje de depósito lo fija el admin por servicio.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| El admin olvida marcar el saldo como cobrado y queda pendiente indefinidamente sin que nadie lo note. | Queda visible en el modal de cada cita (SPEC 04); un listado agregado de saldos pendientes queda como spec futura si se vuelve un problema recurrente. |
| Marcar el saldo como cobrado dos veces, o en una cita sin depósito, generaría un estado inconsistente. | La validación de `PATCH /appointments/{id}` exige `balance_due IS NOT NULL` y `balance_collected_at IS NULL` antes de aceptar `balance_collected: true`. |
| Si WooCommerce se desactiva después de tener servicios con `requires_deposit=true`, esas reservas dejarían de generar pedido de depósito silenciosamente. | Mismo tratamiento que SPEC 10: se degrada a comportamiento sin pago con advertencia en el log; la página de Configuración ya muestra el estado de WooCommerce. |

---

## What is **not** in this spec

- Pantalla dedicada de "saldos pendientes" (listado agregado).
- Reembolso automático del depósito.
- Cambios en el cálculo de comisiones/nómina (SPEC 13).
- Segundo pedido de WooCommerce automático para cobrar el saldo online.
- El cliente eligiendo cuánto pagar al reservar.

Cada uno de estos, si se implementa, va en su propia spec.
