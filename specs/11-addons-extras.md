# SPEC 11 — Add-ons (Extras y Upselling)

> **Status:** Implementado
> **Depends on:** SPEC 01, SPEC 02, SPEC 03, SPEC 04, SPEC 05, SPEC 06, SPEC 10
> **Date:** 2026-08-10
> **Objective:** Permitir que cada servicio tenga extras opcionales (add-ons) con precio y tiempo adicional, que el cliente seleccione en el wizard de reserva, que el motor de disponibilidad los sume a la duración de la cita, y que su costo se refleje en el pedido de WooCommerce cuando el servicio requiere pago online.

---

## Por qué esta spec existe

El documento base (`booking-plugin-v2.txt`) agrupa Add-ons, Paquetes y Comisiones en un solo texto, pero son tres dominios independientes con su propio modelo de datos, API y UI — se dividen en SPEC 11, 12 y 13. Esta (SPEC 11) es la primera porque SPEC 13 (comisiones) necesita que el monto total de una cita ya incluya sus add-ons.

---

## Scope

**In:**

- Tabla `service_addons`: extras ligados a un `service_id` específico, con nombre, precio y minutos extra.
- CRUD de add-ons (alta, edición, desactivación) desde el formulario de servicio en el admin (SPEC 05), con borrado lógico (`status active/inactive`), igual que `services`/`staff`.
- Paso nuevo en el wizard de reserva (SPEC 06), **justo después de elegir el servicio y antes de elegir profesional**, con tarjetas de checkbox (nombre, precio, tiempo extra) usando el mismo CSS plano del resto del widget. El paso se salta automáticamente si el servicio no tiene add-ons activos.
- Selección múltiple de add-ons por reserva, sin límite.
- El motor de disponibilidad (`GET /availability`, SPEC 03) suma los minutos extra de los add-ons seleccionados a la duración del servicio al calcular huecos.
- `POST /appointments` (SPEC 03) acepta `addon_ids`, revalida disponibilidad con la duración total dentro de la misma transacción ya existente, y guarda una copia (snapshot) de nombre/precio/minutos de cada add-on en `appointment_addons` — no una referencia viva a `service_addons`.
- Edición de los add-ons de una cita ya creada, **solo desde el admin** (modal de cita, SPEC 04), y **solo si la cita todavía no tiene `wc_order_id`** (SPEC 10) y su `status` es `pending` o `confirmed`. La edición revalida disponibilidad con la nueva duración.
- Aplica a cualquier servicio, tenga o no `requires_payment=true` (SPEC 10); en servicios sin pago online el precio con add-ons es solo informativo.
- Cuando el servicio requiere pago online: el pedido de WooCommerce generado (SPEC 10) usa una única línea con precio = precio del servicio + suma de add-ons, y guarda el nombre de cada add-on como metadata del ítem del pedido para que salga en el recibo.
- Precio y duración total (servicio + add-ons) visibles en el paso de confirmación y en la pantalla de éxito del widget (SPEC 06).

**Out of scope (para specs futuras):**

- Editar add-ons de una cita desde el panel de autoservicio del cliente (SPEC 07) — solo admin.
- Editar add-ons de una cita que ya tiene `wc_order_id`; el admin cancela y crea una cita nueva si hace falta cambiar el precio ya facturado.
- Límite de cantidad de add-ons seleccionables por reserva.
- Add-ons compartidos/reutilizables entre varios servicios (catálogo global) — cada add-on pertenece a un único `service_id`.
- Tailwind CSS u otro framework de estilos nuevo — se usa CSS plano, consistente con CLAUDE.md y el resto del widget.
- Líneas de pedido de WooCommerce separadas por add-on — se usa una única línea con el precio total.

---

## Data model

```sql
-- Migraciones aditivas, vía dbDelta + bump de BOOKING_PLUGIN_DB_VERSION

CREATE TABLE {$wpdb->prefix}booking_service_addons (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_id          BIGINT UNSIGNED NOT NULL,
  name                VARCHAR(191) NOT NULL,
  price               DECIMAL(10,2) NOT NULL DEFAULT 0,
  extra_time_minutes  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status              VARCHAR(20) NOT NULL DEFAULT 'active', -- 'active' | 'inactive'
  created_at          DATETIME NOT NULL,
  updated_at          DATETIME NOT NULL,
  KEY service_id (service_id),
  KEY status (status)
);

CREATE TABLE {$wpdb->prefix}booking_appointment_addons (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  appointment_id      BIGINT UNSIGNED NOT NULL,
  addon_id            BIGINT UNSIGNED NOT NULL,
  name                VARCHAR(191) NOT NULL,        -- snapshot al momento de la reserva
  price               DECIMAL(10,2) NOT NULL,       -- snapshot
  extra_time_minutes  SMALLINT UNSIGNED NOT NULL,   -- snapshot
  UNIQUE KEY appointment_addon (appointment_id, addon_id),
  KEY appointment_id (appointment_id),
  KEY addon_id (addon_id)
);
```

```
GET    /wp-json/booking-plugin/v1/services/{service_id}/addons   (público: solo status=active; manage_options: ?status=all)
POST   /wp-json/booking-plugin/v1/services/{service_id}/addons   (manage_options)
PUT    /wp-json/booking-plugin/v1/addons/{id}                    (manage_options)
DELETE /wp-json/booking-plugin/v1/addons/{id}                    (manage_options — soft-delete: status='inactive')

GET /wp-json/booking-plugin/v1/availability?service_id=&date=&addon_ids=3,7
  -> suma extra_time_minutes de los addon_ids indicados (deben pertenecer a service_id y estar active; si no, 400) a la duración del servicio.

POST /appointments  { ..., "addon_ids": [3, 7] }
  -> valida pertenencia+activo, calcula duración total, revalida el hueco en la misma transacción de SPEC 03,
     inserta las filas snapshot en appointment_addons.

PATCH /appointments/{id}  { "addon_ids": [3] }   (manage_options; solo si status in pending|confirmed y wc_order_id IS NULL)
  -> reemplaza las filas de appointment_addons, recalcula duración y revalida disponibilidad excluyendo la propia cita.
```

Convención: `appointment_addons` guarda una copia de `name`/`price`/`extra_time_minutes` en vez de solo el `addon_id`, para que el historial de una cita (y el cálculo de comisiones de SPEC 13) no cambie si el add-on se edita o desactiva después.

---

## Implementation plan

1. Editar `includes/class-booking-plugin-db-schema.php` para agregar `booking_service_addons` y `booking_appointment_addons`; subir `BOOKING_PLUGIN_DB_VERSION`.
2. Crear `includes/rest/class-booking-rest-addons-controller.php` con las 4 rutas de add-ons descritas arriba (validación `price >= 0`, `extra_time_minutes >= 0`, soft-delete en `DELETE`). Registrarlo en `includes/class-booking-plugin-rest.php` y `booking-plugin.php`.
3. Editar `includes/class-booking-plugin-availability.php`: `get_available_slots()` acepta `$addon_ids = []` opcional, valida que pertenezcan a `service_id` y estén activos, y suma sus `extra_time_minutes` a la duración usada para buscar huecos.
4. Editar `includes/rest/class-booking-rest-availability-controller.php` para leer `addon_ids` de la query string y pasarlo a `Booking_Plugin_Availability`.
5. Editar `includes/rest/class-booking-rest-appointments-controller.php`: `POST /appointments` acepta `addon_ids`, revalida con la duración total dentro de la transacción existente (SPEC 03) e inserta las filas snapshot en `appointment_addons`; `PATCH /appointments/{id}` acepta `addon_ids` para editar (solo `manage_options`, solo `pending`/`confirmed` sin `wc_order_id`), revalidando disponibilidad excluyendo la cita propia.
6. Editar `includes/class-booking-plugin-woocommerce.php` (SPEC 10): `create_order_for_appointment()` suma el precio de los `appointment_addons` a la línea única del pedido y agrega cada add-on como metadata del ítem (nombre + precio).
7. Editar `assets/src/admin/components/ServiceFormModal.js` (SPEC 05): agregar `AddonsEditor.js` — lista de add-ons del servicio con alta/edición/desactivación inline contra los endpoints de add-ons.
8. Editar `assets/src/admin/components/AppointmentModal.js` (SPEC 04): mostrar los add-ons de la cita; si `wc_order_id` es `null` y el `status` es `pending`/`confirmed`, permitir editarlos contra el `PATCH` extendido.
9. Crear `assets/src/frontend/AddonsStep.js` (`GET /services/{id}/addons`, tarjetas con checkbox, CSS plano); editar `App.js` para insertar el paso entre `ServiceStep` y `StaffStep`, guardar `selectedAddonIds` en el estado del wizard, y saltar el paso automáticamente si no hay add-ons activos.
10. Editar `assets/src/frontend/ConfirmationStep.js` y `SuccessScreen.js` (SPEC 06): mostrar los add-ons seleccionados, el precio total (servicio + add-ons) y la duración total.
11. Prueba manual end-to-end: crear un add-on desde el admin en un servicio existente; reservarlo desde el widget seleccionando 2 add-ons y confirmar que los horarios ofrecidos (`GET /availability`) respetan la duración extendida; completar la reserva y confirmar el precio/duración total mostrados; si el servicio requiere pago (SPEC 10), pagar y confirmar que el pedido de WooCommerce tiene el precio con add-ons y sus nombres como metadata del ítem; editar los add-ons de esa cita desde el admin (antes de que tenga `wc_order_id`) y confirmar que la disponibilidad se revalida correctamente.

---

## Acceptance criteria

- [x] Crear un add-on desde `ServiceFormModal.js` lo persiste en `service_addons` ligado al `service_id` correcto.
- [x] Desactivar un add-on (`DELETE`) lo saca de la lista pública (`GET /services/{id}/addons` sin `manage_options`) pero no borra las filas de `appointment_addons` de citas pasadas que lo usaron.
- [x] El widget muestra el paso de add-ons entre la elección de servicio y la de profesional, y lo salta automáticamente si el servicio no tiene add-ons activos.
- [x] Seleccionar add-ons en el widget hace que `GET /availability` devuelva huecos que respetan la duración del servicio + minutos extra de los add-ons elegidos.
- [x] `POST /appointments` con `addon_ids` crea la cita, revalida el hueco con la duración total, y guarda en `appointment_addons` una copia de nombre/precio/minutos (no solo el id).
- [x] Editar el nombre o precio de un add-on después de una reserva no altera los valores ya guardados en `appointment_addons` de citas anteriores.
- [x] En un servicio con `requires_payment=true` (SPEC 10), reservar con add-ons genera un pedido de WooCommerce con una única línea cuyo precio es servicio + add-ons, y los nombres de los add-ons aparecen como metadata del ítem.
- [x] El modal de cita en el admin (SPEC 04) permite editar los add-ons de una cita `pending`/`confirmed` sin `wc_order_id`, revalidando disponibilidad; no ofrece esa opción si la cita ya tiene `wc_order_id`.
- [x] El panel de autoservicio del cliente (SPEC 07) no ofrece ninguna opción para editar add-ons.
- [x] `npm run build` genera `assets/build/frontend.js`/`admin.js` sin errores tras los cambios.

---

## Decisions

- **Sí:** cada add-on pertenece a un único `service_id` (no un catálogo global reutilizable). Razón: así lo describe el documento base y evita una tabla pivote adicional sin caso de uso confirmado.
- **Sí:** selección múltiple de add-ons por reserva, sin límite. Razón: coincide con la UI de "tarjetas con checkbox" que pide el documento.
- **Sí:** borrado lógico (`status`) en `service_addons`, igual que `services`/`staff`. Razón: consistencia con el patrón ya establecido en SPEC 01; evita romper referencias visuales en el admin a add-ons usados en citas pasadas.
- **Sí:** `appointment_addons` guarda una copia (snapshot) de nombre/precio/minutos, no solo el `addon_id`. Razón: el historial de una cita (y el cálculo de comisiones de SPEC 13, que depende del monto total de la cita) no debe cambiar si el add-on se edita o desactiva después.
- **Sí:** CRUD de add-ons integrado en `ServiceFormModal.js`, sin pantalla propia en el menú. Razón: decisión explícita del usuario; el add-on pertenece conceptualmente al servicio que se está editando.
- **Sí:** editar add-ons de una cita ya creada solo desde el admin, nunca desde el panel de autoservicio del cliente (SPEC 07). Razón: decisión explícita del usuario; evita abrir una nueva superficie de autogestión no descrita en el documento base.
- **Sí:** no se permite editar add-ons si la cita ya tiene `wc_order_id`. Razón: decisión explícita del usuario; evita la complejidad de recalcular o reembolsar un pedido de WooCommerce ya generado — el admin cancela y crea una cita nueva si hace falta.
- **Sí:** los add-ons aplican a cualquier servicio, tenga o no pago online. Razón: decisión explícita del usuario; cubre negocios que cobran en persona pero igual quieren registrar/mostrar extras.
- **Sí:** una única línea en el pedido de WooCommerce (precio total) con los add-ons como metadata del ítem, no líneas separadas por add-on. Razón: así lo pide el documento base ("el precio dinámico del producto oculto ahora será precio_base + suma_de_addons"); líneas separadas requerirían un producto WC por add-on sin beneficio confirmado.
- **Sí:** CSS plano para el paso de add-ons del widget, no Tailwind. Razón: decisión explícita del usuario; CLAUDE.md fija `@wordpress/scripts` sin frameworks CSS adicionales, y el resto del widget (SPEC 06) ya usa ese patrón.
- **Sí:** el paso de add-ons va inmediatamente después de elegir el servicio, antes de elegir profesional y horario. Razón: decisión explícita del usuario; la duración total ya está definida antes de buscar disponibilidad, evitando recalcular una elección de horario ya hecha.
- **No:** límite de cantidad de add-ons por reserva. Razón: sin necesidad confirmada; el documento no lo pide.
- **No:** catálogo de add-ons compartido entre servicios. Razón: fuera de alcance; cada add-on nace ligado a un servicio.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| Sumar minutos extra de add-ons puede hacer que una cita ya no quepa en el hueco elegido (choque con la siguiente cita del staff). | `POST /appointments` y `PATCH /appointments/{id}` revalidan disponibilidad con la duración total dentro de la misma transacción `SELECT ... FOR UPDATE` de SPEC 03, igual que ya se hace para la duración base. |
| Un add-on desactivado o editado después de una reserva podría alterar retroactivamente el monto de citas pasadas si se leyera en vivo desde `service_addons`. | `appointment_addons` guarda una copia (snapshot) de nombre/precio/minutos en el momento de la reserva; nunca se recalcula desde `service_addons`. |
| Editar add-ons de una cita con pedido de WooCommerce ya generado dejaría el monto cobrado desincronizado del monto real de la cita. | Bloqueado explícitamente: no se permite editar add-ons si `wc_order_id` no es `null`. |

---

## What is **not** in this spec

- Edición de add-ons desde el panel de autoservicio del cliente (SPEC 07).
- Edición de add-ons de una cita que ya tiene `wc_order_id`.
- Límite de cantidad de add-ons por reserva.
- Catálogo de add-ons compartido entre varios servicios.
- Tailwind CSS u otro framework de estilos nuevo.
- Líneas de pedido de WooCommerce separadas por add-on.

Cada uno de estos, si se implementa, va en su propia spec.
