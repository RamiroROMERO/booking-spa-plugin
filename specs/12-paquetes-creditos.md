# SPEC 12 — Paquetes (Sesiones Prepagadas / Créditos)

> **Status:** Aprovado
> **Depends on:** SPEC 01, SPEC 02, SPEC 03, SPEC 04, SPEC 05, SPEC 06, SPEC 07, SPEC 10, SPEC 11
> **Date:** 2026-08-10
> **Objective:** Permitir que un cliente compre un paquete de sesiones prepagadas (vía WooCommerce, o asignado manualmente desde el admin), y que el wizard de reserva le ofrezca gastar una sesión de su saldo en vez de pagar, confirmando la cita directamente sin pasar por el checkout.

---

## Por qué esta spec existe

El documento base describe "paquetes" de forma simple (`service_id` único), pero al preguntar quedó claro que el caso real del negocio es un paquete que cubre **varios servicios con distinto costo en sesiones cada uno** (ej. "Bono Spa" = 1 sesión de Masaje o 2 sesiones de Facial). Eso agrega una tabla pivote (`package_services`) y una entidad `packages` propia del plugin, que no estaba en el documento original pero es necesaria para que el "gasto" de créditos sea correcto. También se decidió permitir otorgar créditos manualmente desde el admin, para que el módulo no dependa 100% de WooCommerce (mismo espíritu de degradación consciente que SPEC 10).

---

## Scope

**In:**

- Tabla `packages`: definidas desde el plugin (nombre, cantidad total de sesiones, precio informativo), no productos creados a mano en WooCommerce.
- Un paquete puede cubrir **varios servicios**, cada uno con su propio costo en créditos (`credit_cost`) vía tabla pivote `package_services`.
- CRUD de paquetes desde un submenú nuevo **"Paquetes"** en el admin (`manage_options`), con dos pestañas: definición de paquetes (nombre, sesiones totales, servicios incluidos + costo en créditos) y créditos de clientes (buscar un usuario, ver su saldo, otorgar crédito manual con nota).
- Sincronización con WooCommerce (si está activo, mismo patrón que SPEC 10): cada paquete activo tiene un producto WC oculto del catálogo; al completarse el pedido (`woocommerce_order_status_completed`) se inserta un registro de crédito para el comprador.
- Si WooCommerce no está instalado/activo, el módulo sigue funcional vía **otorgamiento manual** de créditos desde el admin (sin producto ni pedido).
- En el wizard de reserva (SPEC 06), si el usuario está logueado y tiene saldo (`remaining_sessions > 0`) aplicable al servicio elegido, se le ofrece un aviso ("Tienes N sesiones disponibles de [paquete]. ¿Usar una ahora?").
- Bypass de pago: si el cliente elige usar su crédito, `POST /appointments` confirma la cita directamente (`status = confirmed`) sin generar pedido de WooCommerce ni ofrecer `checkout_url`, y descuenta del saldo el `credit_cost` correspondiente a ese servicio dentro del paquete.
- Cancelar una cita pagada con crédito, dentro de las ventanas permitidas (SPEC 03/07), devuelve exactamente los créditos consumidos al saldo del cliente. Reprogramarla no toca el saldo.
- Trazabilidad: cada cita pagada con crédito guarda una referencia al crédito usado y a la cantidad exacta consumida.

**Out of scope (para specs futuras):**

- Combinar add-ons (SPEC 11) con una reserva pagada con crédito — al usar crédito, el wizard no ofrece el paso de add-ons.
- Vencimiento/expiración de paquetes o de créditos.
- Transferir créditos entre usuarios.
- Reembolso en dinero de créditos no usados.
- Fraccionar un crédito entre dos citas parciales — el `credit_cost` de un servicio dentro de un paquete siempre se consume completo o no se consume.
- Reportes de ventas de paquetes (eso lo cubre WooCommerce, como ya aclara el documento base).

---

## Data model

```sql
-- Migraciones aditivas, vía dbDelta + bump de BOOKING_PLUGIN_DB_VERSION

CREATE TABLE {$wpdb->prefix}booking_packages (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(191) NOT NULL,
  total_sessions  SMALLINT UNSIGNED NOT NULL,
  price           DECIMAL(10,2) NOT NULL DEFAULT 0,  -- informativo; precio real vive en WooCommerce si está sincronizado
  wc_product_id   BIGINT UNSIGNED NULL,
  status          VARCHAR(20) NOT NULL DEFAULT 'active', -- 'active' | 'inactive'
  created_at      DATETIME NOT NULL,
  updated_at      DATETIME NOT NULL,
  KEY status (status)
);

CREATE TABLE {$wpdb->prefix}booking_package_services (
  package_id   BIGINT UNSIGNED NOT NULL,
  service_id   BIGINT UNSIGNED NOT NULL,
  credit_cost  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (package_id, service_id)
);

CREATE TABLE {$wpdb->prefix}booking_user_credits (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wp_user_id          BIGINT UNSIGNED NOT NULL,
  package_id          BIGINT UNSIGNED NOT NULL,
  total_sessions      SMALLINT UNSIGNED NOT NULL,
  remaining_sessions  SMALLINT UNSIGNED NOT NULL,
  source              VARCHAR(20) NOT NULL DEFAULT 'woocommerce', -- 'woocommerce' | 'manual'
  woo_order_id        BIGINT UNSIGNED NULL,
  granted_by          BIGINT UNSIGNED NULL, -- wp_user_id del admin, solo si source='manual'
  note                VARCHAR(191) NULL,
  created_at          DATETIME NOT NULL,
  KEY wp_user_id (wp_user_id),
  KEY package_id (package_id)
);

ALTER TABLE {$wpdb->prefix}booking_appointments
  ADD COLUMN paid_with_credit_id BIGINT UNSIGNED NULL,
  ADD COLUMN credits_consumed    SMALLINT UNSIGNED NULL;
```

```
GET/POST   /wp-json/booking-plugin/v1/packages           (manage_options; POST incluye services:[{service_id, credit_cost}])
PUT/DELETE /wp-json/booking-plugin/v1/packages/{id}       (manage_options; PUT reemplaza por completo la lista de services, igual patrón que staff_services de SPEC 02; DELETE = soft-delete)

GET  /wp-json/booking-plugin/v1/users/{wp_user_id}/credits   (manage_options — lista de créditos del usuario, cualquier source)
POST /wp-json/booking-plugin/v1/users/{wp_user_id}/credits   (manage_options — otorgar manual: { package_id, note })
  -> crea user_credits con source='manual', total_sessions/remaining_sessions = packages.total_sessions, granted_by = usuario admin actual.

GET /wp-json/booking-plugin/v1/credits/mine?service_id=   (autenticado, cualquier usuario logueado)
  -> créditos propios con remaining_sessions > 0 aplicables a ese service_id (join package_services), con el credit_cost que le costaría.

POST /appointments  { ..., "use_credit_id": 15 }
  -> valida que el crédito pertenezca al usuario autenticado, que el service_id esté en su package_services,
     y que remaining_sessions >= credit_cost. Si es válido: crea la cita con status='confirmed' directamente
     (sin pedido WooCommerce, sin checkout_url), resta credit_cost de remaining_sessions, y guarda
     paid_with_credit_id + credits_consumed en la cita — todo dentro de la misma transacción de SPEC 03.

PATCH /appointments/{id}  { "status": "cancelled" }
  -> si la cita tiene paid_with_credit_id, además de la lógica ya existente de SPEC 03/07 (ventanas mínimas),
     devuelve credits_consumed a remaining_sessions del crédito referenciado.
```

Convención: `use_credit_id` es mutuamente excluyente con el flujo de pago de SPEC 10 — si el servicio tiene `requires_payment=true` pero el cliente elige `use_credit_id`, se ignora el pago online y se confirma directamente. Un servicio con add-ons (SPEC 11) no puede reservarse con `use_credit_id` si trae `addon_ids`; el widget nunca ofrece esa combinación.

---

## Implementation plan

1. Editar `includes/class-booking-plugin-db-schema.php` para las tres tablas nuevas y las dos columnas en `booking_appointments`; subir `BOOKING_PLUGIN_DB_VERSION`.
2. Crear `includes/rest/class-booking-rest-packages-controller.php`: `GET`/`POST /packages`, `PUT`/`DELETE /packages/{id}` (reemplazo completo de `package_services` en `POST`/`PUT`, soft-delete en `DELETE`). Registrar en `includes/class-booking-plugin-rest.php` y `booking-plugin.php`.
3. Crear `includes/rest/class-booking-rest-user-credits-controller.php`: `GET`/`POST /users/{wp_user_id}/credits` (manage_options) y `GET /credits/mine` (autenticado, usa `get_current_user_id()`).
4. Editar `includes/class-booking-plugin-woocommerce.php` (SPEC 10): agregar `sync_product_for_package()` (mismo patrón que servicios) llamado desde el controlador de paquetes; y el handler de `woocommerce_order_status_completed` que, cuando el pedido incluye un `wc_product_id` de un paquete, inserta la fila en `user_credits` (`source='woocommerce'`, `woo_order_id`).
5. Editar `includes/rest/class-booking-rest-appointments-controller.php` (SPEC 03): `POST /appointments` acepta `use_credit_id`, valida saldo y pertenencia, confirma la cita directamente (`status=confirmed`) sin generar pedido WC, y guarda `paid_with_credit_id`/`credits_consumed` en la misma transacción. `PATCH /appointments/{id}` devuelve `credits_consumed` al crédito referenciado cuando la cita pasa a `cancelled`.
6. Crear `assets/src/admin/pages/PackagesPage.js` con dos pestañas: `PackageFormModal.js` (CRUD de paquetes: nombre, sesiones totales, precio, servicios incluidos + costo en créditos cada uno) y `UserCreditsTab.js` (buscador de usuario WP, lista de sus créditos, formulario "Otorgar crédito manual" con selección de paquete + nota). Registrar el submenú "Paquetes" en `includes/class-booking-plugin-admin.php` (SPEC 04).
7. Crear `assets/src/frontend/hooks/useMyCredits.js` (`GET /credits/mine?service_id=`) y editar `App.js`/`ServiceStep.js` (SPEC 06): tras elegir servicio, si el usuario está logueado y tiene saldo aplicable, muestra el aviso "Tienes N sesiones disponibles. ¿Usar una ahora?"; si acepta, el wizard salta `AddonsStep` (SPEC 11) y `StaffStep`/`DateTimeStep`/`ConfirmationStep` avanzan sin mostrar precio ni checkout.
8. Editar `ConfirmationStep.js`/`SuccessScreen.js` (SPEC 06): cuando se reserva con crédito, mostrar "Se usó 1 sesión de tu paquete [nombre] — te quedan N" en vez de precio/checkout_url.
9. Editar `assets/src/admin/components/AppointmentModal.js` (SPEC 04): mostrar "Pagada con crédito ([paquete])" cuando `paid_with_credit_id` no es `null`.
10. Prueba manual end-to-end: crear un paquete con 2 servicios (costos en créditos distintos) sincronizado a WooCommerce; comprarlo con un usuario de prueba, completar el pedido y confirmar que aparece en `GET /credits/mine`; reservar con crédito uno de los servicios del paquete desde el widget y confirmar que la cita queda `confirmed` sin checkout y el saldo baja el `credit_cost` correcto; cancelarla dentro de la ventana permitida y confirmar que el saldo se restituye; otorgar un crédito manual desde el admin a un usuario sin pasar por WooCommerce y repetir la reserva con ese crédito.

---

## Acceptance criteria

- [ ] Crear un paquete con varios servicios y costos en créditos distintos lo persiste correctamente en `packages`/`package_services`.
- [ ] Con WooCommerce activo, un paquete activo tiene un producto WC sincronizado (oculto del catálogo), igual patrón que SPEC 10 con servicios.
- [ ] Completar un pedido de WooCommerce de un producto de paquete inserta una fila en `user_credits` con `remaining_sessions = total_sessions` del paquete, asociada al comprador.
- [ ] `GET /credits/mine?service_id=` solo devuelve créditos del usuario autenticado actual, aplicables a ese servicio, nunca de otro usuario.
- [ ] El wizard muestra el aviso de saldo disponible solo a usuarios logueados con `remaining_sessions > 0` aplicable al servicio elegido.
- [ ] Reservar usando `use_credit_id` confirma la cita directamente (`status=confirmed`), no genera pedido de WooCommerce, y descuenta exactamente el `credit_cost` de ese servicio dentro del paquete.
- [ ] Al usar crédito, el wizard no ofrece el paso de add-ons (SPEC 11).
- [ ] Cancelar una cita pagada con crédito, dentro de la ventana mínima permitida, devuelve `credits_consumed` a `remaining_sessions` del crédito original.
- [ ] Reprogramar una cita pagada con crédito no modifica ningún saldo.
- [ ] Con WooCommerce inactivo, "Otorgar crédito manual" desde el admin sigue funcionando y el resto del plugin (SPEC 01-11) no se ve afectado.
- [ ] El modal de cita en el admin (SPEC 04) indica cuando una cita fue pagada con crédito y con qué paquete.

---

## Decisions

- **Sí:** un paquete puede cubrir varios servicios, cada uno con su propio `credit_cost`. Razón: decisión explícita del usuario; el ejemplo real del negocio (bono multi-servicio) no encajaba en el modelo simplificado de "un `service_id`" del documento original.
- **Sí:** los paquetes se definen en una tabla propia del plugin (`packages`) y se sincronizan a un producto WC, no al revés. Razón: decisión explícita del usuario; mismo patrón ya validado en SPEC 10 con servicios, permite editar sesiones/servicios incluidos desde el plugin sin tocar WooCommerce directamente.
- **Sí:** otorgamiento manual de créditos desde el admin, independiente de WooCommerce. Razón: decisión explícita del usuario; evita que todo el módulo quede inutilizable en negocios sin WooCommerce, siguiendo el mismo espíritu de degradación consciente de SPEC 10.
- **Sí:** cancelar una cita pagada con crédito (dentro de la ventana permitida) devuelve el crédito consumido. Razón: decisión explícita del usuario; consistente con las reglas de cancelación ya definidas en SPEC 03/07 — no castiga al cliente por cancelar a tiempo.
- **Sí:** reprogramar no toca el saldo. Razón: es la misma sesión, solo cambia de horario.
- **Sí:** la cita guarda `paid_with_credit_id` + `credits_consumed` (no se recalcula el costo al cancelar). Razón: decisión explícita del usuario; si el admin edita después el `credit_cost` de ese servicio dentro del paquete, una devolución recalculada sería incorrecta.
- **No:** combinar add-ons (SPEC 11) con una reserva pagada con crédito. Razón: decisión explícita del usuario; evita el flujo mixto de cobrar el excedente de un add-on fuera de WooCommerce cuando el servicio base ya se paga con crédito.
- **No:** vencimiento de paquetes/créditos, transferencia entre usuarios, ni reembolso en dinero de créditos no usados. Razón: sin necesidad confirmada; el documento no lo pide.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| Condición de carrera: dos reservas simultáneas del mismo crédito podrían dejar `remaining_sessions` en negativo. | La validación y el descuento de `remaining_sessions` ocurren dentro de la misma transacción `SELECT ... FOR UPDATE` que ya usa `POST /appointments` (SPEC 03) para el hueco de disponibilidad. |
| Un admin desactiva un paquete (`status=inactive`) que un cliente ya compró; el crédito existente queda "huérfano" de un paquete no listado. | `package_services` y el `credit_cost` se siguen leyendo por `package_id` sin importar el `status` del paquete; solo se oculta de la oferta de compra, nunca invalida créditos ya otorgados. |
| Si WooCommerce se desactiva después de tener paquetes sincronizados, las compras nuevas de esos paquetes dejan de generar créditos silenciosamente. | Mismo tratamiento que SPEC 10: se trata como degradación consciente (log de advertencia); el otorgamiento manual sigue disponible como vía alterna. |

---

## What is **not** in this spec

- Combinar add-ons con reservas pagadas con crédito.
- Vencimiento/expiración de paquetes o créditos.
- Transferencia de créditos entre usuarios.
- Reembolso en dinero de créditos no usados.
- Fraccionamiento parcial de un `credit_cost`.
- Reportes de ventas de paquetes (los cubre WooCommerce).

Cada uno de estos, si se implementa, va en su propia spec.
