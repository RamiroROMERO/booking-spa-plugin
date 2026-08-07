# SPEC 02 — API REST: Servicios y Staff

> **Status:** Aprovada
> **Depends on:** SPEC 01
> **Date:** 2026-08-07
> **Objective:** Exponer una API REST (`booking-plugin/v1`) con lectura pública y escritura protegida por `manage_options` para gestionar categorías, servicios y staff —incluyendo sus servicios permitidos, horarios y excepciones— sobre el modelo de datos de SPEC 01.

---

## Por qué esta spec existe

El documento base pide que el backend de PHP exponga los datos a través de la WP REST API para que interfaces modernas (el panel admin en SPA de SPEC 04, el widget de reserva de SPEC 05) los consuman. Esta spec cubre las rutas de gestión (CRUD) de las entidades "de catálogo" — categorías, servicios y staff — dejando fuera, deliberadamente, todo lo relacionado con el cálculo de disponibilidad y la creación de citas (SPEC 03), que tiene reglas de negocio propias.

---

## Scope

**In:**

- Rutas REST bajo el namespace `booking-plugin/v1` para **categorías**, **servicios** y **staff**.
- CRUD completo (`GET` lista, `GET` por id, `POST`, `PUT`, `DELETE`) para las tres entidades.
- Gestión del vínculo staff↔servicios (`staff_services`) como campo anidado `service_ids` en el payload de staff.
- Gestión de horarios semanales (`staff_schedules`) y excepciones (`staff_exceptions`) como campos anidados `schedules` y `exceptions` en el payload de staff.
- Lectura (`GET`) pública sin autenticación; escritura (`POST`/`PUT`/`DELETE`) restringida a usuarios con capability `manage_options`.
- Ocultamiento de campos sensibles de staff (`email`, `phone`) en respuestas no autenticadas.
- Autogeneración de slugs (categorías y servicios) a partir de `name` vía `sanitize_title()`.
- Paginación estándar de WP REST (`page`, `per_page`, headers `X-WP-Total` / `X-WP-TotalPages`) en los listados.
- Validaciones de negocio: `price >= 0`, `duration_minutes > 0`, `buffer_minutes >= 0`; bloqueo de borrado de una categoría con servicios asociados.

**Out of scope (para specs futuras):**

- Endpoint de `business_hours` (horario del negocio) — se cubre en SPEC 03, junto al algoritmo de disponibilidad que realmente lo consume.
- Endpoints públicos de disponibilidad y creación de citas (SPEC 03).
- UI de administración que consuma esta API (SPEC 04) y widget de reserva frontend (SPEC 05).
- Capability personalizada tipo `manage_booking_plugin` (roles intermedios como "recepcionista") — se usa `manage_options` por ahora.
- Subida de imágenes/fotos de staff o servicios (no hay campo `photo` en el modelo de SPEC 01).
- Rate limiting o throttling de los endpoints públicos de lectura.

---

## Data model

Esta spec no crea tablas nuevas — reutiliza el esquema de SPEC 01 (`booking_service_categories`, `booking_services`, `booking_staff`, `booking_staff_services`, `booking_staff_schedules`, `booking_staff_exceptions`). Define los **contratos REST** (rutas y forma de request/response) sobre ese esquema.

### Rutas

```
GET    /wp-json/booking-plugin/v1/categories            (público)
GET    /wp-json/booking-plugin/v1/categories/{id}        (público)
POST   /wp-json/booking-plugin/v1/categories             (manage_options)
PUT    /wp-json/booking-plugin/v1/categories/{id}        (manage_options)
DELETE /wp-json/booking-plugin/v1/categories/{id}        (manage_options)

GET    /wp-json/booking-plugin/v1/services                (público, ?category_id=&status=&page=&per_page=)
GET    /wp-json/booking-plugin/v1/services/{id}            (público)
POST   /wp-json/booking-plugin/v1/services                 (manage_options)
PUT    /wp-json/booking-plugin/v1/services/{id}            (manage_options)
DELETE /wp-json/booking-plugin/v1/services/{id}            (manage_options)  -- soft delete

GET    /wp-json/booking-plugin/v1/staff                    (público, ?service_id=&status=&page=&per_page=)
GET    /wp-json/booking-plugin/v1/staff/{id}                (público)
POST   /wp-json/booking-plugin/v1/staff                     (manage_options)
PUT    /wp-json/booking-plugin/v1/staff/{id}                (manage_options)
DELETE /wp-json/booking-plugin/v1/staff/{id}                (manage_options)  -- soft delete
```

### Forma de los payloads

```js
// POST/PUT /services
{
  "category_id": 3,          // nullable
  "name": "Corte de cabello",
  "price": 25.00,
  "duration_minutes": 45,    // > 0, requerido
  "buffer_minutes": 10,      // >= 0, default 0
  "description": "..."       // nullable
  // "slug" no se envía: se autogenera desde "name"
}

// POST/PUT /staff
{
  "name": "Ana Pérez",
  "email": "ana@example.com",
  "phone": "+52 555 000 0000",
  "service_ids": [1, 4, 7],   // reemplaza por completo booking_staff_services para este staff
  "schedules": [               // reemplaza por completo booking_staff_schedules para este staff
    { "day_of_week": 1, "start_time": "09:00", "end_time": "18:00", "break_start": "13:00", "break_end": "14:00" }
  ],
  "exceptions": [               // reemplaza por completo booking_staff_exceptions para este staff
    { "exception_date": "2026-12-25", "is_day_off": true, "reason": "Feriado" }
  ]
}
```

Respuesta de `GET /staff/{id}` **sin autenticar** (lectura pública): omite `email` y `phone`.

```js
{
  "id": 5,
  "name": "Ana Pérez",
  "status": "active",
  "service_ids": [1, 4, 7]
  // sin email, sin phone, sin schedules/exceptions (información operativa interna)
}
```

Respuesta de `GET /staff/{id}` **autenticada con `manage_options`**: incluye todos los campos, `email`, `phone`, `schedules` y `exceptions` completos.

Convenciones:

- Errores usan `WP_Error` con códigos HTTP estándar (`400` validación, `401`/`403` permisos, `404` no encontrado, `409` conflicto).
- Los listados públicos (`GET` sin sesión admin) filtran implícitamente `status = 'active'`; una petición autenticada con `manage_options` puede pedir cualquier `status` vía el parámetro `?status=`.

---

## Implementation plan

1. Crear `includes/rest/class-booking-rest-categories-controller.php`: rutas `GET`/`POST`/`PUT`/`DELETE` de `/categories`, con slug autogenerado, validación de `name` único y bloqueo (`409`) del `DELETE` si existen servicios (de cualquier `status`) con ese `category_id`.
2. Crear `includes/rest/class-booking-rest-services-controller.php`: rutas de `/services`, validación numérica (`price >= 0`, `duration_minutes > 0`, `buffer_minutes >= 0`), filtros `category_id`/`status` en el listado, y `DELETE` como soft-delete (`UPDATE status = 'inactive'`).
3. Crear `includes/rest/class-booking-rest-staff-controller.php`: rutas de `/staff`, incluyendo el reemplazo completo de `staff_services`, `staff_schedules` y `staff_exceptions` en `POST`/`PUT`, ocultamiento de `email`/`phone` en respuestas no autenticadas, filtro `service_id` en el listado, y `DELETE` como soft-delete.
4. Crear `includes/class-booking-plugin-rest.php`: instancia los 3 controladores y llama a `register_routes()` de cada uno.
5. Editar `booking-plugin.php` para hacer `require_once` de los 4 archivos nuevos, y editar `includes/class-booking-plugin.php` para enganchar `add_action( 'rest_api_init', ... )` que dispare el registro de rutas. Prueba manual: `GET /wp-json/booking-plugin/v1/services` sin sesión responde `200` con lista vacía (o `[]` paginado).
6. Prueba manual end-to-end: crear una categoría, un servicio dentro de ella, un staff con ese servicio y un horario semanal vía `POST`; confirmar que `GET` público los devuelve sin campos sensibles de staff, que `DELETE /services/{id}` lo saca del listado público pero la fila sigue en `wp_booking_services` con `status='inactive'`, y que `DELETE /categories/{id}` con el servicio aún asociado responde `409`.

---

## Acceptance criteria

- [ ] `GET /wp-json/booking-plugin/v1/categories` sin autenticación responde `200` (lectura pública funciona sin login).
- [ ] `POST /wp-json/booking-plugin/v1/services` sin sesión admin responde `401` o `403` (escritura protegida).
- [ ] `POST /services` con `duration_minutes = 0` responde `400` con un mensaje de validación.
- [ ] Crear un servicio sin enviar `slug` genera automáticamente uno único vía `sanitize_title( name )`.
- [ ] `DELETE /services/{id}` deja la fila en `wp_booking_services` con `status = 'inactive'` (no la borra); deja de aparecer en `GET /services` público pero sigue apareciendo en `GET /services?status=inactive` autenticado.
- [ ] `PUT /staff/{id}` con `service_ids: [1,4]` dos veces seguidas (la segunda con `[1]`) deja exactamente una fila en `wp_booking_staff_services` para ese staff (reemplazo completo, no acumulación).
- [ ] `PUT /staff/{id}` con `schedules`/`exceptions` reemplaza por completo las filas previas en `wp_booking_staff_schedules`/`wp_booking_staff_exceptions` de ese staff.
- [ ] `DELETE /categories/{id}` responde `409` si existe al menos un servicio (activo o inactivo) con ese `category_id`.
- [ ] `DELETE /categories/{id}` sin servicios asociados borra físicamente la fila de `wp_booking_service_categories`.
- [ ] `GET /staff/{id}` sin autenticación no incluye `email` ni `phone` en la respuesta; la misma petición autenticada con `manage_options` sí los incluye.

---

## Decisions

- **Sí:** namespace `booking-plugin/v1`. Razón: coincide con el text domain del plugin definido en `CLAUDE.md`.
- **Sí:** lectura (`GET`) pública, escritura protegida con `manage_options`. Razón: el widget de reserva de SPEC 05 necesita leer servicios/staff/categorías sin sesión; separar lectura de escritura evita duplicar endpoints de solo-lectura más adelante.
- **Sí:** `GET` público oculta `email`/`phone` de staff. Razón: son datos de contacto interno del negocio, no necesarios para que un cliente elija profesional en el wizard de reserva.
- **Sí:** `DELETE` hace soft-delete (`UPDATE status`) en `services` y `staff`. Razón: consistente con la decisión de SPEC 01 de no hacer `DELETE` físico sobre esas tablas.
- **Sí:** las categorías sí permiten `DELETE` físico real. Razón: SPEC 01 no les dio campo `status` (no se previó necesidad de "categoría inactiva"); nada más referencia una categoría salvo `services.category_id`, y ese caso ya queda bloqueado con `409` si hay servicios asociados, así que un `DELETE` físico en una categoría vacía es seguro.
- **Sí:** slugs autogenerados con `sanitize_title()` + sufijo incremental en colisión (`-2`, `-3`, ...). Razón: evita que el cliente tenga que pensar en URLs válidas; consistente con el comportamiento nativo de WordPress para posts/términos.
- **Sí:** paginación estándar de WP REST (`page`/`per_page`, headers `X-WP-Total`/`X-WP-TotalPages`). Razón: convención nativa de `wp-json`, evita reinventar el formato y escala si el negocio crece a cientos de servicios/staff.
- **Sí:** horarios y excepciones de staff se gestionan anidados en el payload de `/staff`, con reemplazo completo en cada guardado (no PATCH incremental). Razón: coincide con un formulario de "editar horario de este profesional" de una sola pantalla (SPEC 04); evita el problema de sincronizar altas/bajas parciales desde el cliente.
- **No:** endpoint dedicado `/staff/{id}/services/{service_id}` para el vínculo staff-servicio. Razón: el campo anidado `service_ids` cubre el caso de uso con una sola llamada.
- **No:** capability personalizada `manage_booking_plugin`. Razón: fuera de alcance del MVP; se puede introducir en una spec futura si se necesitan roles intermedios (ej. "recepcionista" sin acceso admin completo).
- **No:** endpoint de `business_hours` en esta spec. Razón: es configuración que consume directamente el algoritmo de disponibilidad; tiene más sentido junto a SPEC 03.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| El reemplazo completo de `schedules`/`exceptions` en cada `PUT /staff/{id}` puede borrar datos por accidente si el cliente (admin SPA) no carga primero el estado completo antes de editar. | `GET /staff/{id}` autenticado siempre devuelve `schedules` y `exceptions` completos, para que el formulario de edición parta de ahí y nunca envíe un payload parcial. |
| `GET` público expone todo el contenido de `description` de un servicio sin autenticación. | Documentado como comportamiento esperado: la descripción de servicio es contenido pensado para mostrarse en el widget público de reserva (SPEC 05). |
| Colisiones de slug muy frecuentes (mismo `name` repetido muchas veces) podrían generar sufijos incrementales largos. | Se limita a un número razonable de intentos (100) antes de hacer fallback a `slug-{timestamp}`. |

---

## What is **not** in this spec

- Endpoint de `business_hours` (SPEC 03).
- Algoritmo de disponibilidad y creación/edición de citas (SPEC 03).
- Panel de administración SPA (SPEC 04) y widget de reserva frontend (SPEC 05).
- Panel de cliente (SPEC 06), notificaciones por email (SPEC 07), integración de pagos (SPEC 08).
- Capabilities/roles personalizados más allá de `manage_options`.

Cada uno de estos, si se implementa, va en su propia spec.
