# SPEC 10 — Integración de Pagos (WooCommerce)

> **Status:** Aprovada
> **Depends on:** SPEC 01, SPEC 02, SPEC 03, SPEC 04, SPEC 05, SPEC 06, SPEC 08
> **Date:** 2026-08-07
> **Objective:** Integrar el cobro online con WooCommerce (dependencia opcional): sincronizar un producto por servicio marcado como "requiere pago", generar un pedido en "En espera" al reservar, redirigir al checkout estándar de WooCommerce, y sincronizar automáticamente el estado de la cita con el estado del pago.

---

## Por qué esta spec existe

El documento base deja dos caminos para pagos: integrar WooCommerce (pedido en "Espera" por cada cita) o SDKs de Stripe/PayPal directos. Esta decisión quedó abierta desde el planeamiento inicial del proyecto (SPEC 01) y recién ahora se resuelve: WooCommerce, como dependencia **opcional** — el plugin ya es completamente funcional sin ella (SPEC 01-09), y esta spec solo *habilita* cobro online cuando el sitio la tiene instalada y activa.

---

## Scope

**In:**

- Detección de WooCommerce activo (`class_exists('WooCommerce')`) como interruptor central de toda esta funcionalidad.
- Campo `requires_payment` por servicio (SPEC 02): solo los servicios marcados generan cobro; el resto sigue funcionando exactamente como en SPEC 01-09.
- Producto de WooCommerce sincronizado por servicio (oculto del catálogo), creado/actualizado/despublicado junto con el ciclo de vida del servicio (SPEC 02: crear, editar precio/nombre, desactivar).
- Al crear una cita de un servicio con `requires_payment=true` (`POST /appointments`, SPEC 03): se crea un pedido de WooCommerce en estado `on-hold` vinculado a esa cita, y la respuesta incluye una URL de pago (`checkout_url`).
- Redirección al checkout estándar de WooCommerce (página de pago del pedido) — no un checkout embebido a medida.
- Sincronización automática de estado: pedido pagado → cita `confirmed`; pedido cancelado/reembolsado → cita `cancelled` (libera el horario).
- Barrido de WP-Cron (mismo patrón que el recordatorio de SPEC 08) que cancela automáticamente citas con pago pendiente vencido (`payment_window_hours`, nuevo campo en `booking_plugin_settings`, default 2 horas), liberando el horario para otros clientes.
- Botón "Pagar ahora" en la pantalla de éxito del widget (SPEC 06) cuando la reserva requiere pago.
- Estado de pago visible (y enlace al pedido) en el modal de detalle de una cita en el calendario admin (SPEC 04).
- Indicador de estado de WooCommerce (activo/no instalado) en la página de Configuración (SPEC 05).

**Out of scope (para specs futuras):**

- Reembolsos automáticos al cancelar una cita desde el plugin — el reembolso queda como acción manual del admin en WooCommerce.
- Checkout embebido dentro del wizard de reserva.
- Integración directa con SDKs de Stripe/PayPal (alternativa no elegida).
- Depósitos o pagos parciales — solo el precio completo del servicio.
- Multi-moneda — se usa la moneda configurada en WooCommerce tal cual.
- Notificación de emails específica de pagos más allá de lo que WooCommerce ya envía por defecto (las plantillas de SPEC 08/09 no se modifican).

---

## Data model

```sql
-- Migraciones aditivas, vía dbDelta + bump de BOOKING_PLUGIN_DB_VERSION
ALTER TABLE {$wpdb->prefix}booking_services
  ADD COLUMN requires_payment TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN wc_product_id BIGINT UNSIGNED NULL;

ALTER TABLE {$wpdb->prefix}booking_appointments
  ADD COLUMN wc_order_id BIGINT UNSIGNED NULL;
```

```js
// booking_plugin_settings (SPEC 03/08) se extiende con:
{
  ...campos existentes,
  "payment_window_hours": 2   // tiempo máximo sin pagar antes de auto-cancelar la reserva
}

// Respuesta de POST /appointments (SPEC 03) cuando el servicio requiere pago:
{
  "id": 87,
  "status": "pending",
  // ...campos existentes,
  "checkout_url": "https://sitio.com/checkout/order-pay/87/?pay_for_order=true&key=wc_order_xxx"
}
```

Convención: el pedido de WooCommerce se crea directamente vía `wc_create_order()` (sin pasar por el carrito del sitio), con una única línea correspondiente al `wc_product_id` del servicio, cantidad 1, y el precio vigente del servicio en el momento de la reserva. Se redirige a `$order->get_checkout_payment_url()` — sigue usando todas las pasarelas configuradas en WooCommerce, sin interferir con cualquier otro contenido que el cliente tenga en su carrito.

---

## Implementation plan

1. Editar `includes/class-booking-plugin-db-schema.php` para las dos migraciones aditivas de arriba; subir `BOOKING_PLUGIN_DB_VERSION`.
2. Crear `includes/class-booking-plugin-woocommerce.php`: `is_active()`, `sync_product_for_service( $service )` (crea/actualiza un `WC_Product` oculto del catálogo, guarda `wc_product_id`), `create_order_for_appointment( $appointment )` (crea el pedido `on-hold`, guarda `wc_order_id`, devuelve la URL de pago).
3. Editar `includes/rest/class-booking-rest-services-controller.php` (SPEC 02) para aceptar/devolver `requires_payment`, y llamar a `sync_product_for_service()` en create/update (y despublicar el producto cuando el servicio pasa a `inactive`).
4. Editar `includes/rest/class-booking-rest-appointments-controller.php` (SPEC 03/04): tras crear una cita con `requires_payment=true` y WooCommerce activo, llamar a `create_order_for_appointment()` e incluir `checkout_url` en la respuesta.
5. Registrar el hook `woocommerce_order_status_changed` en `includes/class-booking-plugin-woocommerce.php`: pedido pagado (`$order->is_paid()`) → `PATCH` interno de la cita a `confirmed`; pedido `cancelled`/`refunded` → cita a `cancelled`.
6. Extender `includes/class-booking-plugin-settings.php` (SPEC 03/08) con `payment_window_hours`.
7. Crear `includes/class-booking-plugin-payment-sweep-cron.php`: evento recurrente de WP-Cron (mismo patrón que SPEC 08) que busca citas `pending` con `wc_order_id` no pagado hace más de `payment_window_hours`, **revalida el estado real del pedido en WooCommerce antes de actuar**, y cancela la cita si el pedido sigue genuinamente impago.
8. Editar `assets/src/admin/pages/ServicesPage.js`/`ServiceFormModal.js` (SPEC 05): toggle "Requiere pago online"; y `SettingsPage.js`: campo `payment_window_hours` + indicador de estado de WooCommerce (activo/no instalado).
9. Editar `assets/src/admin/components/AppointmentModal.js` (SPEC 04): mostrar estado de pago y enlace al pedido de WooCommerce cuando `wc_order_id` está presente.
10. Editar `ConfirmationStep.js`/`SuccessScreen.js` (SPEC 06): botón "Pagar ahora" hacia `checkout_url` cuando la respuesta de `POST /appointments` lo incluye.
11. Prueba manual end-to-end: con WooCommerce instalado y una pasarela de prueba activa, marcar un servicio como "Requiere pago online", reservarlo desde el widget, pagar en el checkout de WooCommerce y confirmar que la cita pasa a `confirmed`; cancelar/reembolsar el pedido desde WooCommerce y confirmar que la cita pasa a `cancelled` y el horario vuelve a `GET /availability`; crear una reserva sin pagarla, adelantar el reloj (o el valor de `payment_window_hours`) y correr el barrido, confirmando que la cita se auto-cancela.

---

## Acceptance criteria

- [ ] Con WooCommerce inactivo, todo el flujo de reservas de SPEC 01-09 sigue funcionando sin errores ni cambios visibles.
- [ ] Marcar un servicio como "Requiere pago online" (con WooCommerce activo) crea/sincroniza un producto WC oculto del catálogo, con el mismo nombre y precio del servicio.
- [ ] Editar el precio de un servicio actualiza el precio del producto WC sincronizado.
- [ ] Reservar un servicio con `requires_payment=true` genera un pedido de WooCommerce `on-hold` vinculado a la cita, y la respuesta de `POST /appointments` incluye `checkout_url`.
- [ ] Reservar un servicio con `requires_payment=false` no genera ningún pedido de WooCommerce.
- [ ] Completar el pago del pedido cambia el `status` de la cita de `pending` a `confirmed` automáticamente.
- [ ] Cancelar o reembolsar el pedido desde WooCommerce cambia el `status` de la cita a `cancelled` automáticamente, liberando el horario en `GET /availability`.
- [ ] Una reserva con pago pendiente por más de `payment_window_hours` (y cuyo pedido sigue impago al revalidarlo) se cancela automáticamente vía el barrido, liberando el horario.
- [ ] El widget de reserva (SPEC 06) muestra el botón "Pagar ahora" hacia `checkout_url` cuando el servicio requiere pago, y no lo muestra cuando no lo requiere.
- [ ] El modal de detalle de una cita en el calendario admin (SPEC 04) muestra el estado de pago y un enlace al pedido cuando corresponde.

---

## Decisions

- **Sí:** WooCommerce como dependencia opcional. Razón: decisión explícita del usuario; el plugin ya funciona completo sin ella desde SPEC 01-09.
- **Sí:** producto WC sincronizado por servicio (no uno genérico compartido). Razón: decisión explícita del usuario; permite reportes de WooCommerce desglosados por servicio.
- **Sí:** pedido creado directamente vía `wc_create_order()` (sin pasar por el carrito) y redirección a `get_checkout_payment_url()`, en vez de agregar al carrito y redirigir a `/checkout/`. Razón: evita interferir con cualquier otro contenido que el cliente ya tenga en su carrito de WooCommerce, manteniendo el espíritu de "reutilizar el checkout estándar" que pidió el usuario.
- **Sí:** `requires_payment` configurable por servicio. Razón: decisión explícita del usuario; cubre negocios mixtos (servicios gratuitos o de pago en persona junto a servicios con cobro online).
- **Sí:** el estado de la cita se sincroniza automáticamente con el estado del pedido (pagado→`confirmed`, cancelado/reembolsado→`cancelled`). Razón: evita que un admin tenga que confirmar manualmente cada cita ya pagada.
- **Sí:** barrido de WP-Cron que auto-cancela reservas con pago vencido. Razón: sin esto, una reserva abandonada sin pagar bloquearía ese horario indefinidamente para otros clientes — es un problema de correctitud, no solo una mejora cosmética.
- **No:** reembolso automático al cancelar la cita desde el plugin. Razón: la política de reembolso es una decisión de negocio que no corresponde asumir; queda como acción manual del admin en WooCommerce.
- **No:** checkout embebido dentro del wizard. Razón: decisión explícita del usuario; reutilizar el checkout de WooCommerce evita reconstruir selección de pasarela y validación de tarjeta.
- **No:** depósitos/pagos parciales ni multi-moneda. Razón: fuera de alcance del MVP de pagos; se cobra el precio completo en la moneda configurada en WooCommerce.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| Si WooCommerce se desactiva después de tener servicios con `requires_payment=true`, esas reservas dejarían de generar pedido silenciosamente. | Se trata como `requires_payment=false` con una advertencia en el log (degradación consciente); la página de Configuración muestra el estado de WooCommerce para que el admin lo note. |
| Sincronizar un producto WC por servicio agrega productos "ocultos" que un admin desprevenido podría encontrar confuso en otras pantallas de WooCommerce. | Los productos se crean con visibilidad de catálogo oculta y un prefijo claro en el nombre interno (ej. "[Reserva] Corte de cabello"). |
| El barrido de cancelación por pago vencido podría cancelar una reserva cuyo pago sí se completó pero cuyo hook de WooCommerce falló en sincronizarse a tiempo. | El barrido revalida el estado real del pedido directamente en WooCommerce antes de cancelar, no confía solo en el reloj. |

---

## What is **not** in this spec

- Reembolsos automáticos.
- Checkout embebido/a medida.
- Integración directa con SDKs de Stripe/PayPal.
- Depósitos o pagos parciales.
- Multi-moneda.

Cada uno de estos, si se implementa, va en su propia spec.
