# SPEC 22 — Creación manual de reservas desde el admin

> **Status:** Implementado
> **Depends on:** SPEC 01, SPEC 02, SPEC 03, SPEC 04, SPEC 10, SPEC 11, SPEC 12, SPEC 15
> **Date:** 2026-08-15
> **Objective:** Permitir que el administrador cree una cita real (servicio + extras + staff + cliente) desde el Calendario del admin, para reservas coordinadas por teléfono o mostrador, sin pasar por el widget público.

---

## Por qué esta spec existe

Hoy el Calendario del admin (SPEC 04) solo permite "Bloquear horario" (`BlockModal.js`), que reserva un hueco sin cliente ni servicio asociado, y `AppointmentModal.js` es puramente de edición: reprogramar, cambiar estado o cancelar una cita que ya existe. No existe ningún camino para que el administrador cargue una reserva nueva a nombre de un cliente. Esta spec cierra ese hueco reutilizando el motor de disponibilidad y creación de citas ya construido en SPEC 03, en vez de duplicar su lógica en el admin.

---

## Scope

**In:**

- Botón "Nueva cita" en la barra de herramientas de `CalendarPage.js`, junto a "Bloquear horario" (SPEC 04), que abre un modal nuevo. Sin precarga de fecha/hora/staff — el admin completa todo el formulario desde cero.
- El formulario cubre el mismo alcance que el wizard del widget público (SPEC 06/11): Servicio → Extras (add-ons, si el servicio tiene) → Profesional (opcional, "cualquier disponible" por defecto) → Fecha/hora, reutilizando los mismos endpoints (`GET /services`, `GET /services/{id}/addons`, `GET /staff?service_id=`, `GET /availability`).
- Identificación del cliente: buscar entre usuarios registrados del sitio (`GET /wp/v2/users?search=`, mismo patrón que `UserCreditsTab.js` en Paquetes/SPEC 12) o cargar datos de invitado (nombre, email, teléfono) si no tiene cuenta — igual flexibilidad que el motor de reservas público.
- Si el cliente elegido es un usuario registrado con créditos de un paquete aplicables al servicio (SPEC 12), el admin puede gastar uno en vez de generar un pedido de pago — mismo mecanismo que ya ofrece el widget.
- La disponibilidad se valida siempre con el mismo motor que usa el widget público (`Booking_Plugin_Availability` + el bloqueo `SELECT ... FOR UPDATE` de `POST /appointments`, SPEC 03) — el admin no puede forzar un horario ocupado o fuera de horario.
- La cita creada por este camino queda en estado `confirmed` de entrada (salvo que quede `cancelled`/`409` por falta de disponibilidad), sin pasar por `pending`, sin importar si el servicio requiere pago online o depósito.
- Si el servicio requiere pago online o depósito (SPEC 10/15) y no se usó un crédito, igual se genera el pedido de WooCommerce (mismo mecanismo que hoy, `maybe_create_payment_order`) para dejar constancia y poder cobrarlo, pero el modal **no** redirige a ningún checkout — el pago se gestiona directamente en WooCommerce (o, si el servicio tiene depósito, también puede cobrarse luego desde "Saldos pendientes", SPEC 16, sin cambios adicionales porque reutiliza el mismo `deposit_amount`/`balance_due`).
- Extensión de `POST /appointments` (SPEC 03): nuevo parámetro `is_manual_booking` (boolean), solo utilizable por un usuario con `manage_options`. Cuando es `true`, exige exactamente uno de `client_user_id` (entero, usuario de WP existente) o `guest_name`+`guest_email` (+ `guest_phone` opcional), ignora la sesión del propio admin como cliente de la cita, y fuerza el estado inicial a `confirmed`.
- Extensión de `GET /users/{id}/credits` (SPEC 12): nuevo parámetro opcional `service_id`. Cuando está presente, aplica el mismo filtro de aplicabilidad (`JOIN` con `booking_package_services`) e incluye `credit_cost` que ya usa `GET /credits/mine`, en vez de devolver todos los créditos del usuario sin filtrar.
- La cita creada aparece en el Calendario y se abre con el mismo `AppointmentModal.js` que cualquier otra cita (reprogramar, cancelar, cambiar estado), sin cambios en ese componente.

**Out of scope (para specs futuras):**

- Forzar un horario ocupado o fuera de las reglas de disponibilidad (overbooking manual / walk-in sin hueco libre). El admin siempre reserva sobre un slot realmente libre, igual que un cliente público.
- Redirigir o generar un link de checkout de WooCommerce para que el admin se lo pase al cliente. El cobro de pagos/depósitos generados por una reserva manual se gestiona directamente en WooCommerce (o en "Saldos pendientes" para depósitos), no desde este modal.
- Un buscador o vista dedicada de "clientes" del plugin — se reutiliza tal cual el buscador de usuarios de WordPress (`/wp/v2/users`) ya usado en Paquetes.
- Crear un usuario de WordPress nuevo desde este modal para un invitado sin cuenta. Si el cliente no tiene cuenta, la cita queda como invitado (`guest_name`/`guest_email`), igual que una reserva pública.
- Notificar de forma distinta (plantilla de email separada) a una cita creada manualmente. Dispara los mismos correos automáticos que cualquier cita nueva (SPEC 07/08), sin distinción de origen.
- Precargar fecha/hora/staff al abrir el modal desde un click en un hueco vacío del calendario. Se abre siempre en blanco desde el botón "Nueva cita".
- Editar/reprogramar con este modal — eso ya lo cubre `AppointmentModal.js` una vez creada la cita.

---

## Data model

No se crean tablas nuevas. Se reutiliza `wp_booking_appointments` (SPEC 01) y el resto del modelo existente sin cambios de esquema.

Cambios de forma sobre endpoints ya existentes:

```
POST /wp-json/booking-plugin/v1/appointments
{
  "service_id": 12,
  "staff_id": null,
  "start_datetime": "2026-08-20T14:00:00Z",
  "is_manual_booking": true,        // nuevo — solo con manage_options
  "client_user_id": 8,              // nuevo — cliente registrado, O bien:
  "guest_name": "María López",      // (ya existía) usable ahora estando logueado como admin
  "guest_email": "maria@example.com",
  "guest_phone": "+52 555 111 2222",
  "addon_ids": [3, 5],
  "use_credit_id": 21,
  "notes": "Reservó por teléfono"
}
```

```
GET /wp-json/booking-plugin/v1/users/8/credits?service_id=12
// misma forma de item que ya devuelve GET /credits/mine (incluye credit_cost),
// filtrado a los créditos aplicables a ese servicio, en vez de todos los del usuario.
```

Convenciones:

- `is_manual_booking: true` sin `manage_options` responde `403`, igual que cualquier otro chequeo de permisos de este controlador.
- `is_manual_booking: true` sin exactamente uno de `client_user_id` / `guest_name`+`guest_email` responde `400`.
- Con `is_manual_booking: true`, el `status` inicial de la cita insertada es siempre `confirmed` (no `pending`), independientemente de si el servicio requiere pago/depósito o si se usó `use_credit_id`.
- El resto de la validación (disponibilidad, colisión, add-ons válidos, crédito con saldo suficiente) es exactamente la misma que ya usa `POST /appointments` — no se relaja ninguna regla existente.

---

## Implementation plan

1. En `includes/rest/class-booking-rest-appointments-controller.php` (`create_item()`), reestructurar la resolución de `user_id`/`guest_*`: si `is_manual_booking` es `true`, verificar `manage_options` (si no, `403`); exigir exactamente uno de `client_user_id` (validando que el usuario exista con `get_userdata()`) o `guest_name`+`guest_email` válidos; en cualquier otro caso, mantener el comportamiento actual (`is_user_logged_in()` → sesión propia, si no → invitado). Prueba manual: `POST /appointments` con `is_manual_booking: true` y `client_user_id` de un usuario real crea la cita con ese `user_id`, no con el del admin logueado.
2. En el mismo archivo, propagar el `$user_id` resuelto (en vez de `get_current_user_id()`) a la llamada de `validate_credit()`, y en `attempt_booking()` forzar `status = 'confirmed'` cuando `is_manual_booking` es `true` (además del caso ya existente de `$credit`). Prueba manual: una cita manual sin crédito ni pago queda `confirmed` de una; con `use_credit_id` de un crédito del `client_user_id` indicado, se consume el crédito correctamente.
3. En `includes/rest/class-booking-rest-user-credits-controller.php` (`get_items()`), agregar soporte al parámetro opcional `service_id`: si viene, usar la misma consulta con `JOIN booking_package_services` y `credit_cost`/`remaining_sessions > 0` que ya tiene `get_mine()`, parametrizada por el `wp_user_id` de la ruta en vez de `get_current_user_id()`. Prueba manual: `GET /users/8/credits?service_id=12` devuelve solo los créditos de ese usuario aplicables a ese servicio, con `credit_cost`; sin el parámetro, el endpoint se comporta igual que hoy.
4. Crear `assets/src/admin/NewAppointmentModal.js`: modal con los pasos Servicio (`GET /services`) → Extras si aplica (`GET /services/{id}/addons`) → Profesional opcional (`GET /staff?service_id=`) → Fecha/hora (`GET /availability?service_id=&date=&staff_id=&timezone=`, mismo cálculo de slots que ya usa `DateTimeStep.js`/`Calendar.js`) → Cliente (buscador `GET /wp/v2/users?search=` con opción de cambiar a "invitado" y cargar nombre/email/teléfono) → si hay créditos aplicables (`GET /users/{id}/credits?service_id=`), opción de usarlos → Notas → enviar `POST /appointments` con `is_manual_booking: true`. Estilo `@wordpress/components` (`Modal`, `Button`, `SelectControl`, `TextControl`), consistente con `BlockModal.js`/`AppointmentModal.js`.
5. En `assets/src/admin/pages/CalendarPage.js`, agregar el botón "Nueva cita" junto a "Bloquear horario" y el estado `isNewAppointmentOpen`; al guardar con éxito, cerrar el modal y llamar `loadAppointments()` (mismo patrón que `BlockModal`).
6. `npm run build` y verificación manual end-to-end en WAMP: crear una cita manual para un usuario registrado con un servicio sin pago (queda `confirmed`, visible en el Calendario y en `AppointmentModal.js`); crear una para un invitado con un servicio que requiere depósito (queda `confirmed`, genera pedido de WooCommerce, y aparece en "Saldos pendientes" con el depósito correspondiente); crear una usando un crédito de paquete del cliente (queda `confirmed`, descuenta el crédito, sin pedido de WooCommerce); intentar crear una en un horario ya ocupado y confirmar que el modal muestra el mismo error de disponibilidad que el widget público.

---

## Acceptance criteria

- [x] Aparece un botón "Nueva cita" en el Calendario del admin, junto a "Bloquear horario".
- [x] El modal permite elegir servicio, extras (si el servicio tiene), profesional (o "cualquier disponible") y un slot de fecha/hora realmente libre, con las mismas reglas de disponibilidad que el widget público.
- [x] El modal permite identificar al cliente buscando un usuario registrado del sitio, o cargando nombre/email/teléfono como invitado.
- [x] `POST /appointments` con `is_manual_booking: true` sin `manage_options` responde `403`.
- [x] `POST /appointments` con `is_manual_booking: true` sin `client_user_id` ni datos de invitado completos responde `400`.
- [x] Una cita creada por este camino queda en estado `confirmed` de inmediato, tenga o no el servicio pago/depósito requerido.
- [x] Si el servicio requiere pago o depósito y no se usó un crédito, se genera el pedido de WooCommerce correspondiente, pero el modal no redirige a ningún checkout.
- [x] Una cita manual de un servicio con depósito aparece correctamente en "Saldos pendientes" (SPEC 16), igual que una reserva pública.
- [x] Si el cliente elegido tiene créditos de un paquete aplicables al servicio, el admin puede usarlos en vez de generar un pedido de pago, y el crédito se descuenta.
- [x] Intentar reservar un horario ya ocupado o fuera de disponibilidad responde con el mismo error (`409`) que ya usa el motor de reservas, sin crear la cita.
- [x] La cita creada se ve y se gestiona igual que cualquier otra desde `AppointmentModal.js` (reprogramar, cancelar, cambiar estado), sin cambios en ese componente.
- [x] `GET /users/{id}/credits` sin el parámetro `service_id` se comporta exactamente igual que antes de esta spec.

---

## Decisions

- **Sí:** reutilizar `POST /appointments` (SPEC 03) con un parámetro nuevo (`is_manual_booking`) en vez de crear un endpoint aparte. Razón: evita duplicar la validación de disponibilidad, la transacción de colisión y la creación del pedido de WooCommerce ya construidas; consistente con el principio de SPEC 03 de que toda lógica de negocio vive en la API.
- **Sí:** la disponibilidad se valida siempre igual que en el widget público, sin atajo para forzar un horario ocupado. Razón: decisión explícita del usuario; evita dobles reservas por error del admin.
- **Sí:** la cita manual queda `confirmed` de entrada, incluso si el servicio requiere pago/depósito. Razón: decisión explícita del usuario — el admin ya coordinó la reserva con el cliente (teléfono/mostrador), no tiene sentido un estado `pending` pensado para autogestión pública.
- **Sí:** el pedido de WooCommerce se sigue generando cuando corresponde, pero sin redirigir a checkout; el cobro se gestiona directo en WooCommerce. Razón: decisión explícita del usuario — el pago ocurre en persona cuando el cliente llega a la cita, no en el momento de cargarla.
- **Sí:** las citas manuales con depósito aparecen en "Saldos pendientes" (SPEC 16) sin cambios adicionales. Razón: reutiliza `deposit_amount`/`balance_due`, que ya se completan igual sin importar el origen de la cita — no hay necesidad de tocar esa pantalla.
- **Sí:** el admin puede usar un crédito de paquete del cliente al crear la cita manual, igual que el widget público. Razón: decisión explícita del usuario; evita que una reserva telefónica de un cliente con paquete prepago tenga que gestionarse aparte desde "Paquetes".
- **Sí:** identificación de cliente por búsqueda de usuario de WP (`/wp/v2/users?search=`) o datos de invitado, replicando exactamente la flexibilidad del motor público. Razón: decisión explícita del usuario; reutiliza un patrón de búsqueda que ya existe en Paquetes (SPEC 12), sin construir un sistema de "clientes" propio del plugin.
- **Sí:** el formulario cubre el mismo alcance que el widget (servicio, extras, staff opcional, fecha/hora). Razón: decisión explícita del usuario — el admin no debería tener menos capacidad de reservar que un cliente por su cuenta.
- **No:** overbooking o forzar horarios fuera de disponibilidad. Razón: descartado explícitamente por el usuario a favor de validar siempre disponibilidad real.
- **No:** generar/mostrar un link de checkout para que el admin se lo envíe al cliente. Razón: el pedido de WooCommerce se gestiona directo ahí, no como un paso adicional de este modal.
- **No:** crear un usuario de WordPress nuevo desde este modal. Razón: fuera del pedido de esta sesión; un invitado sin cuenta se maneja igual que en una reserva pública.
- **No:** precargar fecha/hora/staff al hacer click en un hueco vacío del calendario. Razón: decisión explícita del usuario — solo el botón "Nueva cita", modal siempre en blanco.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| Un admin sin querer envía `is_manual_booking` con ambos `client_user_id` y datos de invitado, o con ninguno. | El backend exige exactamente uno de los dos y responde `400` en cualquier otro caso — no se crea una cita con datos ambiguos. |
| Una cita manual con pago/depósito requerido queda `confirmed` con el pedido de WooCommerce sin pagar, y nadie hace seguimiento del cobro. | Las citas con depósito ya aparecen en "Saldos pendientes" (SPEC 16); las de pago completo sin depósito dependen de que el admin las revise directamente en el listado de pedidos de WooCommerce, igual que decidió el usuario para esta spec. |
| El sweep de pagos vencidos (`Booking_Plugin_Payment_Sweep_Cron`, SPEC 10) podría cancelar una cita manual sin pagar. | No aplica: ese cron solo actúa sobre citas con `status = 'pending'`, y las citas manuales quedan `confirmed` desde el inicio — nunca entran en ese filtro. |

---

## What is **not** in this spec

- Overbooking o forzar horarios fuera de disponibilidad.
- Link de checkout de WooCommerce generado para enviar al cliente.
- Vista/buscador de "clientes" propio del plugin, distinto del buscador de usuarios de WordPress.
- Creación de un usuario de WordPress nuevo desde el modal.
- Precarga de fecha/hora/staff al hacer click en un hueco del calendario.

Cada uno de estos, si se implementa, va en su propia spec.
