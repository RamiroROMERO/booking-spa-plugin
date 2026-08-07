# SPEC 03 — API REST: Disponibilidad y Reservas

> **Status:** Implementado
> **Depends on:** SPEC 01, SPEC 02
> **Date:** 2026-08-07
> **Objective:** Exponer vía API REST (`booking-plugin/v1`) el motor de reservas completo —disponibilidad, creación y gestión de citas con protección real contra colisiones y autogestión de invitados por token— sobre el modelo de datos de SPEC 01 y los servicios/staff de SPEC 02.

---

## Por qué esta spec existe

El documento base pide explícitamente "el algoritmo que calcula los espacios de tiempo disponibles cruzando el horario del local, el horario del profesional, la duración del servicio y el tiempo de buffer", y como caso de prueba de borde "simular colisiones de reservas (dos personas intentando reservar la misma hora exacta)". Esta spec es el núcleo de negocio del plugin: sin ella, SPEC 04 (admin), SPEC 05 (widget) y SPEC 06 (panel de cliente) no tienen nada real que consumir.

---

## Scope

**In:**

- `GET /availability`: algoritmo de cálculo de huecos libres, cruzando `business_hours` (SPEC 01), `staff_schedules`/`staff_exceptions` (SPEC 01), duración y `buffer_minutes` del servicio (SPEC 01/02), y citas ya existentes.
- `POST /appointments`: creación de citas (invitado o usuario registrado) con re-validación de disponibilidad y protección real contra colisiones concurrentes.
- `GET /appointments` (listado, admin) y `GET /appointments/{id}` (admin, dueño autenticado, o invitado con token).
- `PATCH /appointments/{id}`: transiciones de estado (confirmar, completar, no-show, cancelar) y reprogramación (cambio de `start_datetime` con revalidación), con permisos distintos según quién hace la petición.
- `GET`/`PUT /business-hours`: gestión del horario general del negocio (tabla ya creada en SPEC 01, sin endpoint hasta ahora).
- `GET`/`PUT /settings`: ventanas de tiempo configurables del motor de reservas (antelación mínima, máximo de días a futuro, ventana mínima de cancelación/reprogramación, intervalo de slots).
- Autogestión de citas por parte de invitados mediante un `access_token` único generado en la creación (sin necesitar cuenta WordPress).
- Mecanismo de concurrencia con transacción SQL (`SELECT ... FOR UPDATE`) para eliminar la condición de carrera en reservas simultáneas.
- Migración aditiva sobre el esquema de SPEC 01: columna `access_token` en `wp_booking_appointments`.

**Out of scope (para specs futuras):**

- Envío real de correos (confirmación, recordatorio 24h, cancelación) — SPEC 07. Esta spec deja el `access_token` disponible en la respuesta de `POST /appointments`, pero no dispara `wp_mail()`.
- Cualquier UI: panel admin (SPEC 04), widget de reserva (SPEC 05), panel de cliente (SPEC 06).
- Integración de pagos (SPEC 08).
- Multi-sucursal (`location_id` en `business_hours`) — sigue fuera de alcance, consistente con SPEC 01.
- Políticas de reembolso o penalización económica por cancelación tardía (encaja en SPEC 08 si llega a existir).

---

## Data model

No se crean tablas nuevas. Se modifica el esquema de SPEC 01 con una migración aditiva y se agrega una opción nueva en `wp_options`.

```sql
-- Migración aditiva sobre wp_booking_appointments (SPEC 01), vía dbDelta + bump de BOOKING_PLUGIN_DB_VERSION
ALTER TABLE {$wpdb->prefix}booking_appointments
  ADD COLUMN access_token VARCHAR(64) NULL,
  ADD UNIQUE KEY access_token (access_token);
```

```js
// Opción wp_options: booking_plugin_settings (array asociativo serializado)
{
  "min_lead_time_hours": 1,      // antelación mínima para poder reservar
  "max_advance_days": 60,        // máximo de días a futuro reservables
  "min_cancellation_hours": 2,   // ventana mínima para cancelar/reprogramar sin ser admin
  "slot_interval_minutes": 15    // cada cuánto se generan los huecos candidatos de GET /availability
}
```

### Rutas

```
GET    /wp-json/booking-plugin/v1/availability   (público)  ?service_id=&date=YYYY-MM-DD&staff_id=&timezone=
POST   /wp-json/booking-plugin/v1/appointments    (público)  crea una cita (invitado o autenticado)
GET    /wp-json/booking-plugin/v1/appointments     (manage_options)  ?date_from=&date_to=&staff_id=&status=
GET    /wp-json/booking-plugin/v1/appointments/{id} (manage_options | dueño autenticado | ?token=)
PATCH  /wp-json/booking-plugin/v1/appointments/{id} (manage_options | dueño autenticado | ?token=)

GET    /wp-json/booking-plugin/v1/business-hours   (manage_options)
PUT    /wp-json/booking-plugin/v1/business-hours   (manage_options)  reemplazo completo de las 7 filas

GET    /wp-json/booking-plugin/v1/settings         (manage_options)
PUT    /wp-json/booking-plugin/v1/settings         (manage_options)
```

### Forma de los payloads

```js
// GET /availability response
{
  "service_id": 12,
  "date": "2026-08-20",
  "slots": [
    { "staff_id": 5, "start_datetime": "2026-08-20T14:00:00Z", "end_datetime": "2026-08-20T14:45:00Z" },
    { "staff_id": 5, "start_datetime": "2026-08-20T14:15:00Z", "end_datetime": "2026-08-20T15:00:00Z" }
  ]
}

// POST /appointments
{
  "service_id": 12,
  "staff_id": null,           // opcional: si se omite, se auto-asigna "primero disponible"
  "start_datetime": "2026-08-20T14:00:00Z",
  "guest_name": "María López",  // requerido si no hay sesión WP
  "guest_email": "maria@example.com",
  "guest_phone": "+52 555 111 2222",
  "notes": "Alergia a ciertos productos"
}

// POST /appointments response (201)
{
  "id": 87,
  "status": "pending",
  "start_datetime": "2026-08-20T14:00:00Z",
  "end_datetime": "2026-08-20T14:45:00Z",
  "staff_id": 5,
  "access_token": "9f3a1c7e2b8d4f6a0e1c5b7d9a2f4e6c"
}

// PATCH /appointments/{id}
{ "status": "cancelled" }
// o
{ "start_datetime": "2026-08-21T16:00:00Z" }   // reprogramar (revalida disponibilidad)
```

Convenciones:

- Todas las horas viajan en UTC ISO 8601 (`...Z`), consistente con la decisión de SPEC 01. El parámetro opcional `timezone` en `GET /availability` solo afecta qué día calendario se busca (límites del día), no el formato de salida.
- `GET /appointments/{id}` y `PATCH /appointments/{id}` resuelven permisos en este orden: `manage_options` → sesión WP con `user_id` igual al dueño de la cita → parámetro `?token=` igual al `access_token` guardado. Si ninguno aplica, `403`.
- `status=confirmed|completed|no_show` solo puede establecerlo `manage_options`. El dueño (usuario o token) solo puede establecer `status=cancelled` o cambiar `start_datetime` (reprogramar).

---

## Implementation plan

1. Editar `includes/class-booking-plugin-db-schema.php` para agregar la columna `access_token` a `wp_booking_appointments`, y subir `BOOKING_PLUGIN_DB_VERSION` en `booking-plugin.php` para disparar la migración automática ya prevista en SPEC 01. Prueba manual: recargar el admin de WP y confirmar que la columna existe.
2. Crear `includes/class-booking-plugin-settings.php`: `get_settings()`/`update_settings()` sobre la opción `booking_plugin_settings`, aplicando los defaults (`1`/`60`/`2`/`15`) si la opción no existe todavía.
3. Crear `includes/rest/class-booking-rest-settings-controller.php` (`GET`/`PUT /settings`) y `includes/rest/class-booking-rest-business-hours-controller.php` (`GET`/`PUT /business-hours`, reemplazo completo de las 7 filas), ambos `manage_options`.
4. Crear `includes/class-booking-plugin-availability.php`: clase con el método `get_available_slots( $service_id, $date, $timezone, $staff_id = null )` que implementa el cruce de `business_hours` + `staff_schedules`/`staff_exceptions` + citas existentes (considerando `buffer_minutes`) + ventanas de `settings`, generando slots cada `slot_interval_minutes`.
5. Crear `includes/rest/class-booking-rest-availability-controller.php` (`GET /availability`) que delega en `Booking_Plugin_Availability`.
6. Crear `includes/rest/class-booking-rest-appointments-controller.php` con `POST /appointments` (transacción SQL `START TRANSACTION` + `SELECT ... FOR UPDATE` sobre las citas del staff en el rango afectado, revalidación con `Booking_Plugin_Availability`, `INSERT` + generación de `access_token` + `COMMIT`), `GET /appointments` (listado admin con filtros), `GET /appointments/{id}` y `PATCH /appointments/{id}` (con la lógica de permisos y la ventana `min_cancellation_hours` descrita arriba).
7. Editar `includes/class-booking-plugin-rest.php` y `booking-plugin.php` para registrar los 4 controladores nuevos.
8. Prueba manual end-to-end: configurar `business_hours` y el horario de un staff vía los endpoints de SPEC 02/03; pedir `GET /availability` y confirmar los slots esperados; disparar dos `POST /appointments` casi simultáneos al mismo `service_id`+`staff_id`+`start_datetime` (dos pestañas o un script) y confirmar que solo uno responde `201` y el otro `409`; cancelar la cita ganadora vía `PATCH` con el `access_token` recibido y confirmar que el slot vuelve a aparecer en `GET /availability`.

---

## Acceptance criteria

- [x] `GET /availability` no devuelve slots fuera del horario de `business_hours` para el día de la semana consultado.
- [x] `GET /availability` no devuelve slots fuera del horario del staff, durante su `break_start`/`break_end`, ni en fechas marcadas `is_day_off` en `staff_exceptions`.
- [x] `GET /availability` no devuelve un slot que se superponga con una cita existente no cancelada de ese staff, incluyendo el `buffer_minutes` posterior de esa cita.
- [x] `GET /availability` no devuelve slots con antelación menor a `min_lead_time_hours` ni más allá de `max_advance_days` (valores leídos de `/settings`).
- [x] `POST /appointments` sin `staff_id` asigna automáticamente el primer staff (por id) que puede realizar el servicio y tiene el slot libre.
- [x] Dos `POST /appointments` concurrentes para el mismo `service_id`+`staff_id`+`start_datetime` exacto: exactamente uno responde `201` y el otro `409 Conflict`.
- [x] La respuesta de `POST /appointments` incluye `access_token`, capaz de autenticar `GET`/`PATCH` sobre esa cita sin sesión WP.
- [x] `PATCH /appointments/{id}?token=...` con el token correcto permite cancelar o reprogramar la cita de un invitado; con un token incorrecto responde `403`.
- [x] `PATCH /appointments/{id}` para cancelar/reprogramar responde `409` si faltan menos de `min_cancellation_hours` (desde `/settings`) para la cita, salvo que la petición venga de un usuario `manage_options`.
- [x] `PATCH /appointments/{id}` con `status=confirmed|completed|no_show` solo lo puede hacer un usuario con `manage_options`; el dueño o el token de invitado reciben `403` si lo intentan.
- [x] `GET /appointments` (listado) requiere `manage_options` y admite filtros `date_from`, `date_to`, `staff_id`, `status`.
- [x] `PUT /business-hours` reemplaza correctamente las 7 filas de `wp_booking_business_hours`.
- [x] `PUT /settings` persiste los valores en `booking_plugin_settings`, y una llamada posterior a `GET /availability` o `PATCH` de cancelación refleja los nuevos valores inmediatamente (sin necesidad de reactivar el plugin).

---

## Decisions

- **Sí:** el motor completo (disponibilidad + creación + transiciones de estado + reprogramación) vive en esta spec. Razón: acordado explícitamente para que SPEC 04/05/06 sean consumidoras puras de la API, sin lógica de negocio nueva en la UI.
- **Sí:** reprogramar es un `PATCH` que revalida disponibilidad y actualiza la misma fila. Razón: conserva `id` e historial de la cita en vez de generar una cancelación + una cita nueva desconectada.
- **Sí:** invitados se autogestionan con `access_token` generado en la creación (también para usuarios registrados, por uniformidad del `permission_callback`). Razón: la ventana de reserva pide soportar "Panel de cliente" incluso para quien no se registra.
- **Sí:** transacción SQL con `SELECT ... FOR UPDATE` para las colisiones. Razón: es un caso de prueba explícito del documento base; una validación optimista (check-then-insert) deja una ventana de milisegundos donde dos requests simultáneos podrían colar una colisión real.
- **Sí:** auto-asignación "primero disponible" cuando no se especifica `staff_id`. Razón: determinístico, fácil de razonar y testear frente a alternativas (aleatorio, menor carga) que añaden variabilidad sin necesidad confirmada.
- **Sí:** slots candidatos generados cada 15 minutos (`slot_interval_minutes`, configurable). Razón: estándar en sistemas de citas de spas/clínicas; balance entre precisión y cantidad de opciones mostradas.
- **Sí:** las 4 ventanas de tiempo (`min_lead_time_hours`, `max_advance_days`, `min_cancellation_hours`, `slot_interval_minutes`) viven juntas en una sola opción configurable `booking_plugin_settings`, en vez de mezclar constantes hardcodeadas con una sola de ellas expuesta. Razón: el usuario pidió explícitamente que la ventana de cancelación fuera configurable desde la configuración general; agruparlas es el mismo costo de implementación y evita tener dos mecanismos de configuración distintos en el plugin.
- **Sí:** columna `access_token` se agrega a `wp_booking_appointments` vía migración aditiva (bump de `BOOKING_PLUGIN_DB_VERSION`). Razón: usa el mecanismo de versionado de esquema que SPEC 01 diseñó explícitamente para este tipo de caso.
- **No:** envío de correos en esta spec. Razón: `wp_mail()` y las plantillas son responsabilidad de SPEC 07; esta spec solo deja el estado y el `access_token` listos.
- **No:** políticas de penalización económica por cancelación tardía. Razón: pertenece a la lógica de pagos, fuera de alcance hasta SPEC 08.
- **No:** multi-sucursal. Razón: consistente con SPEC 01/02, sigue sin caso de uso confirmado.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| La transacción con `SELECT ... FOR UPDATE` puede generar deadlocks bajo alta concurrencia si el orden de bloqueo no es consistente. | Se bloquea siempre por `staff_id` (las citas de ese staff en el rango afectado) en el mismo orden en cada request, evitando bloqueos cruzados entre transacciones. |
| El algoritmo de disponibilidad es costoso si se piden rangos de fechas amplios con muchos staff candidatos. | `GET /availability` acepta un único `date` por llamada (no rango), apoyado en el índice `staff_start (staff_id, start_datetime)` ya creado en SPEC 01. |
| Cambiar `/settings` (ej. reducir `max_advance_days`) no afecta citas ya creadas fuera de la nueva ventana. | Comportamiento esperado y documentado: solo bloquea nuevas reservas, no cancela nada retroactivamente. |

---

## What is **not** in this spec

- Envío de correos electrónicos (SPEC 07).
- Panel de administración SPA (SPEC 04), widget de reserva frontend (SPEC 05), panel de cliente (SPEC 06).
- Integración de pagos (SPEC 08).
- Multi-sucursal (`location_id`).
- Políticas de penalización o reembolso por cancelación tardía.

Cada uno de estos, si se implementa, va en su propia spec.
