# SPEC 18 — Reembolsos Automáticos al Cancelar

> **Status:** Implementado
> **Depends on:** SPEC 01, SPEC 03, SPEC 04, SPEC 05, SPEC 07, SPEC 10, SPEC 15
> **Date:** 2026-08-13
> **Objective:** Cuando se cancela una cita que ya tiene un pago (o depósito) cobrado vía WooCommerce, disparar automáticamente el reembolso real en la pasarela de pago original — en vez de dejarlo siempre como una acción manual del admin en WooCommerce — con la posibilidad de activar/desactivar este comportamiento desde Configuración.

---

## Por qué esta spec existe

SPEC 10 dejó explícitamente fuera de alcance "reembolsos automáticos al cancelar una cita desde el plugin — el reembolso queda como acción manual del admin en WooCommerce", y SPEC 15 reafirmó lo mismo para el depósito. Esta spec cierra ese hueco: reutiliza el hook `booking_plugin_appointment_cancelled` (ya disparado uniformemente por la cancelación del admin desde el calendario, la autogestión del cliente en su panel, y el barrido de pagos vencidos) para intentar el reembolso real en WooCommerce cuando corresponde.

---

## Scope

**In:**

- Nuevo ajuste `auto_refund_enabled` (booleano, default `false`) en `booking_plugin_settings` (SPEC 03/08/10), configurable desde la página de Configuración (SPEC 05), junto al resto de ajustes de pago (`payment_window_hours`).
- Con `auto_refund_enabled=true` y WooCommerce activo: al cancelarse una cita que tiene `wc_order_id` (pago completo de SPEC 10 o depósito de SPEC 15) con un pedido pagado y con saldo pendiente de reembolso (`get_remaining_refund_amount() > 0`), se dispara un reembolso real vía `wc_create_refund()` con `refund_payment => true`, reutilizando la pasarela de pago original si la soporta.
- El disparador es el hook ya existente `booking_plugin_appointment_cancelled` — se enciende para **cualquier** cancelación (admin desde el calendario SPEC 04, cliente desde su panel/autogestión SPEC 07 ya protegido por `min_cancellation_hours`), sin distinguir el origen, porque el hook no lo distingue hoy y ninguna de las dos vías tiene motivo para tratarse distinto.
- Si el pedido nunca se pagó, ya está totalmente reembolsado, o la cita no tiene `wc_order_id`, no se intenta ningún reembolso (evita reembolsos duplicados o sobre pedidos sin dinero cobrado). Esto también hace que el barrido de pagos vencidos (SPEC 10, que solo cancela pedidos impagos) sea un no-op para este mecanismo sin necesidad de código especial.
- Si `auto_refund_enabled=false` (default) o WooCommerce está inactivo, el comportamiento es exactamente el de hoy: reembolso 100% manual del admin en WooCommerce.
- Si la pasarela no soporta reembolsos automáticos vía API (ej. transferencia bancaria manual) o el intento falla (error de gateway, fondos, etc.), la cancelación de la cita se completa igual con normalidad — el reembolso es *best-effort*; el resultado (éxito o fallo) queda registrado como nota nativa en el pedido de WooCommerce.
- El modal de detalle de cita (`AppointmentModal.js`, SPEC 04) muestra el monto reembolsado del pedido cuando existe (`refunded_amount`, leído en vivo del pedido de WooCommerce, mismo patrón que ya usa `payment_status`/`wc_order_edit_url` — sin nueva columna en `booking_appointments`).

**Out of scope (para specs futuras):**

- Reembolsar el saldo cobrado manualmente en efectivo/transferencia fuera de WooCommerce (SPEC 15) — solo se reembolsa lo que WooCommerce efectivamente procesó (el monto del pedido).
- Política de cancelación con penalidad o reembolso parcial (ej. "solo 50% si cancela con menos de 24hs") — siempre se reembolsa el 100% del saldo pendiente de reembolso del pedido, sin descuentos.
- Reintentos automáticos si el reembolso falla — queda como acción manual del admin en WooCommerce, igual que hoy.
- Notificación de email específica de "reembolso" más allá del email nativo que WooCommerce ya envía al reembolsar un pedido (mismo criterio que SPEC 10 con los emails de pago).
- Reembolsar créditos de paquetes (SPEC 12) — esta spec es solo sobre pedidos de WooCommerce vinculados a citas (`wc_order_id`), no sobre créditos.
- Deshacer o cancelar un reembolso ya disparado.

---

## Data model

```js
// booking_plugin_settings (SPEC 03/08/10) se extiende con:
{
  ...campos existentes,
  "auto_refund_enabled": false   // default false; true = intenta reembolso real vía WooCommerce al cancelar una cita con pago/depósito ya cobrado
}
```

Sin migraciones de tabla nuevas. El pedido de WooCommerce (vía `wc_order_id`, ya existente desde SPEC 10) es la única fuente de verdad del estado de reembolso — no se agrega columna de reembolso en `booking_appointments`, igual criterio que ya usa `payment_status` (calculado en vivo en `prepare_item()`, no persistido).

```
GET /appointments, GET /appointments/{id}  (ya existente, SPEC 03/04)
  -> cuando la cita tiene wc_order_id y WooCommerce está activo, además de
     "payment_status"/"wc_order_edit_url" (ya existentes), agrega:
     "refunded_amount": 60.00   // $order->get_total_refunded(); 0 si no hay reembolsos
```

Convención: el reembolso se dispara desde `handle_appointment_cancelled_for_refund()`, enganchado a `booking_plugin_appointment_cancelled` en `Booking_Plugin_WooCommerce::register_hooks()`. Guardas, en orden: (1) `auto_refund_enabled` debe estar activo y WooCommerce activo; (2) la cita debe tener `wc_order_id`; (3) el pedido debe existir y tener `get_remaining_refund_amount() > 0` (cubre pedidos nunca pagados, ya reembolsados totalmente, o inexistentes). El monto reembolsado es siempre `get_remaining_refund_amount()` del pedido (no un monto fijo), lo que hace la operación naturalmente idempotente si el hook se disparara dos veces. La razón del reembolso queda fija: `"Cita cancelada #{appointment_id}"`.

---

## Implementation plan

1. Editar `includes/class-booking-plugin-settings.php`: agregar `auto_refund_enabled` a `get_defaults()` (`false`) y sanitizarlo como booleano en `update_settings()`.
2. Editar `includes/class-booking-plugin-woocommerce.php`: agregar `handle_appointment_cancelled_for_refund( $appointment )`, enganchado con `add_action( 'booking_plugin_appointment_cancelled', ... )` dentro de `register_hooks()`. Implementa las guardas descritas en "Convención" y llama a `wc_create_refund( array( 'order_id' => ..., 'amount' => ..., 'reason' => ..., 'refund_payment' => true ) )`; si devuelve `WP_Error`, solo `error_log()` (no relanzar ni bloquear).
3. En el mismo archivo, extender el bloque de `prepare_item()` en `includes/rest/class-booking-rest-appointments-controller.php` (junto a `payment_status`/`wc_order_edit_url`, SPEC 10) para agregar `refunded_amount` a partir de `$order->get_total_refunded()`.
4. Editar `assets/src/admin/pages/SettingsPage.js` (SPEC 05): agregar toggle "Reembolso automático al cancelar" cerca de `payment_window_hours`; deshabilitado con nota si WooCommerce no está activo (mismo patrón que el indicador de estado de WooCommerce ya existente).
5. Editar `assets/src/admin/AppointmentModal.js` (SPEC 04): cuando `refunded_amount > 0`, mostrar "Reembolsado: $X" junto al resto del estado de pago ya existente (línea ~178-181).
6. Prueba manual end-to-end con WAMP + pasarela de prueba: activar "Reembolso automático al cancelar"; reservar y pagar un servicio con pago completo (SPEC 10), cancelarla desde el calendario admin y confirmar en WooCommerce que el pedido queda reembolsado automáticamente y que el modal de la cita muestra `refunded_amount`; repetir con un servicio con depósito (SPEC 15) y confirmar que solo se reembolsa el monto del depósito, no el total; cancelar una cita cuyo pedido nunca se pagó y confirmar que no se genera ningún reembolso; desactivar el toggle y confirmar que cancelar ya no dispara ningún reembolso; cancelar una cita desde el panel de cliente (SPEC 07, dentro de la ventana permitida) y confirmar que también dispara el reembolso igual que la cancelación del admin.

---

## Acceptance criteria

- [ ] `booking_plugin_settings` acepta y persiste `auto_refund_enabled`, con default `false` para instalaciones existentes (no se activa solo por actualizar el plugin).
- [ ] Con `auto_refund_enabled=false` (o WooCommerce inactivo), cancelar una cita con pago cobrado no dispara ningún reembolso — comportamiento idéntico al actual.
- [ ] Con `auto_refund_enabled=true`, cancelar una cita con pago completo (SPEC 10) ya cobrado dispara un reembolso real por el 100% del pedido vía la pasarela original.
- [ ] Con `auto_refund_enabled=true`, cancelar una cita con depósito (SPEC 15) ya cobrado reembolsa únicamente el monto del depósito (lo que efectivamente cobró el pedido de WooCommerce), no el precio total del servicio.
- [ ] Cancelar una cita cuyo pedido nunca se pagó (`pending`/`failed`) no genera ningún intento de reembolso.
- [ ] Cancelar una cita cuyo pedido ya está totalmente reembolsado (ej. reembolso manual previo del admin) no genera un segundo reembolso.
- [ ] Si la pasarela no soporta reembolso automático o el intento falla, la cita se cancela igual con normalidad (el fallo no bloquea la cancelación).
- [ ] El reembolso se dispara tanto si cancela el admin desde el calendario (SPEC 04) como si cancela el cliente desde su panel/autogestión (SPEC 07).
- [ ] `GET /appointments`/`GET /appointments/{id}` incluye `refunded_amount` cuando la cita tiene `wc_order_id` y WooCommerce está activo.
- [ ] El modal de detalle de cita (SPEC 04) muestra el monto reembolsado cuando `refunded_amount > 0`.
- [ ] La página de Configuración (SPEC 05) muestra el toggle "Reembolso automático al cancelar", deshabilitado con una nota clara cuando WooCommerce no está activo.

---

## Decisions

- **Sí:** reembolso real vía la API de la pasarela (`wc_create_refund` con `refund_payment => true`), no solo un registro contable. Razón: decisión explícita del usuario — el valor de esta spec es evitar que el admin tenga que ir a reembolsar a mano cada vez.
- **Sí:** el reembolso automático es opt-in vía un toggle en Configuración, con default `false`. Razón: mover dinero real automáticamente es una decisión de negocio; una instalación existente no debe empezar a reembolsar solo por actualizar el plugin. Sigue el mismo patrón de "apagado por defecto" que otros toggles del proyecto (`requires_payment`, `requires_deposit`).
- **Sí:** se dispara tanto en cancelación del admin como en autogestión del cliente. Razón: decisión explícita del usuario; además, ambas vías ya comparten el mismo hook `booking_plugin_appointment_cancelled` y la autogestión del cliente ya está acotada por `min_cancellation_hours` (SPEC 07), así que no hace falta lógica extra para distinguir el origen.
- **Sí:** el monto reembolsado es siempre `get_remaining_refund_amount()` del pedido, no un monto fijo calculado aparte. Razón: hace la operación idempotente (un segundo disparo del hook no duplica el reembolso) y respeta automáticamente el caso de depósito (SPEC 15), donde el pedido ya es solo por ese monto.
- **Sí:** un reembolso fallido (pasarela no soportada, error de gateway) nunca bloquea la cancelación de la cita. Razón: consistente con el resto del proyecto — un problema de la capa de pagos no debe impedir una operación de negocio ya decidida (mismo criterio que el barrido de pagos vencidos de SPEC 10, que revalida pero nunca bloquea el flujo).
- **No:** reembolsar saldo cobrado en efectivo/transferencia manual (SPEC 15). Razón: ese dinero nunca pasó por WooCommerce; no hay ninguna API que el plugin pueda usar para devolverlo automáticamente.
- **No:** política de reembolso parcial o con penalidad. Razón: fuera de alcance pedido; siempre se reembolsa el 100% de lo efectivamente pagado vía el pedido.
- **No:** reintentos automáticos de un reembolso fallido. Razón: consistente con la política general del proyecto (SPEC 10) de dejar los casos de fallo de pago como acción manual del admin.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| Activar el reembolso automático sin darse cuenta de que mueve dinero real podría sorprender a un admin. | Default `false`; el toggle en Configuración incluye una nota explícita de que reembolsa dinero real vía la pasarela. |
| Un reembolso disparado dos veces (ej. el hook se ejecuta por error en dos rutas de cancelación distintas casi simultáneas) podría intentar reembolsar de más. | El monto usado es siempre `get_remaining_refund_amount()` en el momento del intento — si ya no queda nada por reembolsar, la guarda de "`> 0`" evita el segundo intento. |
| Una pasarela sin soporte de reembolso automático (ej. transferencia bancaria manual) deja al admin sin saber que el reembolso "falló silenciosamente". | El resultado (éxito o error) queda como nota nativa en el pedido de WooCommerce, y el modal de la cita sigue mostrando el estado real (`payment_status`, `refunded_amount`) en vivo desde el pedido. |

---

## What is **not** in this spec

- Reembolso de saldo cobrado manualmente fuera de WooCommerce.
- Política de reembolso parcial/con penalidad por cancelación tardía.
- Reintentos automáticos de reembolsos fallidos.
- Notificación de email específica de reembolso.
- Reembolso de créditos de paquetes (SPEC 12).

Cada uno de estos, si se implementa, va en su propia spec.
