# SPEC 14 — Imagen por Servicio

> **Status:** Aprovado
> **Depends on:** SPEC 01, SPEC 02, SPEC 05, SPEC 06
> **Date:** 2026-08-12
> **Objective:** Permitir asignar una imagen del Media Library de WordPress a cada servicio, mostrarla recortada en cuadro (1:1, vía CSS) tanto en el listado de Reservas > Servicios del admin como en las tarjetas de servicio del widget de reserva, con un placeholder genérico cuando el servicio no tiene imagen.

---

## Por qué esta spec existe

SPEC 02 y SPEC 05 dejaron explícitamente fuera de alcance la "subida de imágenes/fotos de servicios o staff", porque el modelo de datos de SPEC 01 no tenía ese campo. Esta spec cierra ese hueco puntual, solo para **servicios** (no para staff ni categorías), agregando el campo al modelo, extendiendo la API de SPEC 02 y las dos superficies donde ya se listan servicios: el admin (SPEC 05) y el widget público (SPEC 06).

---

## Scope

**In:**

- Nueva columna `image_id` (attachment ID del Media Library de WP, nullable) en `booking_services`, migración aditiva vía `dbDelta()` + bump de `BOOKING_PLUGIN_DB_VERSION` (mismo patrón que SPEC 13).
- Selector de imagen en `ServiceFormModal.js` (SPEC 05) usando el uploader nativo de WordPress (`wp.media()`), con opción de quitar la imagen ya asignada.
- `GET`/`POST`/`PUT /services` (SPEC 02) acepta y devuelve `image_id`; las respuestas `GET` incluyen además `image_url` (calculado desde `image_id` vía `wp_get_attachment_image_url()`, `null` si no hay imagen).
- Miniatura cuadrada (recorte visual vía CSS `object-fit: cover`, sin recorte real del archivo) en el listado de Reservas > Servicios del admin y en las tarjetas de `ServiceStep.js` del widget (SPEC 06).
- Placeholder genérico (ícono/color sólido resuelto con CSS, sin ningún asset de imagen nuevo) donde `image_url` sea `null`, en ambas superficies.
- Servicios ya existentes sin imagen siguen funcionando sin cambios: el campo es opcional y no retroactivo.

**Out of scope (para specs futuras):**

- Imágenes para staff o categorías — esta spec cubre únicamente servicios.
- Editor de recorte interactivo al subir la imagen — se usa `object-fit: cover` sobre el archivo tal cual se sube, sin generar un archivo recortado nuevo.
- Múltiples imágenes o galería por servicio — un solo `image_id` por servicio.
- Redimensionado/optimización adicional al que WordPress ya genera automáticamente al subir al Media Library (`thumbnail`/`medium`/`large`).
- Validación de tipo o tamaño de archivo más allá de la que el Media Library de WP ya aplica por defecto.

---

## Data model

```sql
-- Migración aditiva, vía dbDelta + bump de BOOKING_PLUGIN_DB_VERSION
ALTER TABLE {$wpdb->prefix}booking_services
  ADD COLUMN image_id BIGINT UNSIGNED NULL; -- attachment ID de wp_posts, NULL = sin imagen
```

```js
// POST/PUT /services (SPEC 02) — se agrega image_id al payload existente
{
  "category_id": 3,
  "name": "Corte de cabello",
  "price": 25.00,
  "duration_minutes": 45,
  "buffer_minutes": 10,
  "description": "...",
  "image_id": 142   // nullable; attachment ID ya existente en el Media Library, o null para quitar la imagen
}

// GET /services y GET /services/{id} — respuesta agrega dos campos derivados de image_id
{
  "id": 1,
  "name": "Corte de cabello",
  // ...resto de campos ya existentes en SPEC 02...
  "image_id": 142,
  "image_url": "https://sitio.com/wp-content/uploads/2026/08/corte.jpg" // null si image_id es null
}
```

Convención: `image_id` es la fuente de verdad guardada en la tabla; `image_url` es un campo calculado en cada respuesta `GET`, nunca persistido — evita que la URL quede obsoleta si el archivo del Media Library se reemplaza o se mueve.

---

## Implementation plan

1. Editar `includes/class-booking-plugin-db-schema.php`: agregar la columna `image_id` a `booking_services`. Subir `BOOKING_PLUGIN_DB_VERSION`. Prueba manual: reactivar/recargar el admin y confirmar en `SHOW COLUMNS` que la columna existe sin romper filas ya existentes (quedan con `image_id NULL`).
2. Editar `includes/rest/class-booking-rest-services-controller.php` (SPEC 02): aceptar `image_id` (entero o `null`) en `POST`/`PUT /services`; en todas las respuestas `GET`, agregar `image_url` calculado con `wp_get_attachment_image_url( $image_id, 'medium' )` (`null` si `image_id` es `null` o el attachment ya no existe).
3. Editar `includes/class-booking-plugin-admin.php` (SPEC 04/05): encolar `wp_enqueue_media()` específicamente en la pantalla de Reservas > Servicios, para que `wp.media()` esté disponible sin cargarlo en el resto del admin.
4. Editar `assets/src/admin/pages/ServiceFormModal.js` (SPEC 05): botón "Seleccionar imagen" que abre `wp.media()` en modo selección única de imágenes, vista previa cuadrada de la imagen elegida, y botón "Quitar imagen" que pone `image_id` en `null`.
5. Editar `assets/src/admin/pages/ServicesPage.js` (SPEC 05): columna de miniatura cuadrada (`object-fit: cover`) por fila, usando `image_url`; placeholder genérico vía CSS cuando sea `null`.
6. Editar `assets/src/frontend/ServiceStep.js` (SPEC 06): agregar la imagen (recorte cuadrado, mismo tratamiento CSS que el admin) en la parte superior de cada tarjeta de servicio de la grilla; mismo placeholder genérico cuando `image_url` sea `null`.
7. Prueba manual end-to-end: en Reservas > Servicios, editar un servicio existente y asignarle una imagen desde el Media Library; confirmar que la miniatura aparece recortada en cuadro en el listado del admin; abrir el widget `[booking_widget]` y confirmar que la misma imagen aparece recortada en cuadro en la tarjeta de ese servicio; quitar la imagen desde el admin y confirmar que ambas superficies vuelven a mostrar el placeholder; confirmar que un servicio que nunca tuvo imagen asignada muestra el placeholder sin errores de consola ni de PHP.

---

## Acceptance criteria

- [ ] `booking_services` tiene la columna `image_id` (nullable) tras la migración, sin afectar las filas ya existentes.
- [ ] `POST`/`PUT /services` acepta `image_id` (entero o `null`) y lo guarda correctamente.
- [ ] `GET /services` y `GET /services/{id}` devuelven `image_url` calculado a partir de `image_id`, `null` cuando no hay imagen asignada.
- [ ] Desde Reservas > Servicios se puede asignar una imagen a un servicio usando el selector nativo del Media Library de WordPress (`wp.media()`), sin salir del formulario.
- [ ] Existe una acción explícita para quitar la imagen ya asignada a un servicio (deja `image_id` en `null`).
- [ ] El listado de Reservas > Servicios muestra la miniatura de cada servicio recortada en cuadro (1:1); los servicios sin imagen muestran el placeholder genérico.
- [ ] El widget de reserva (`ServiceStep.js`) muestra la misma imagen recortada en cuadro en la tarjeta de cada servicio; los servicios sin imagen muestran el placeholder genérico.
- [ ] Un servicio creado antes de esta spec (sin `image_id`) sigue funcionando sin errores en el admin ni en el widget, mostrando el placeholder.
- [ ] Quitar la imagen de un servicio se refleja de inmediato (tras guardar) tanto en el listado admin como en el widget.

---

## Decisions

- **Sí:** guardar `image_id` (attachment ID del Media Library) en vez de una URL externa de texto libre. Razón: decisión explícita del usuario; reutiliza el manejo nativo de subida, tamaños y permisos de WordPress en vez de reinventar validación de URLs.
- **Sí:** campo opcional, no retroactivo. Razón: decisión explícita del usuario; evita bloquear servicios ya creados que no tendrán imagen de inmediato.
- **Sí:** se muestra tanto en el listado admin de Servicios como en el widget de reserva. Razón: decisión explícita del usuario; ambas superficies listan servicios y se benefician de la misma miniatura.
- **Sí:** recorte cuadrado (1:1) resuelto con CSS (`object-fit: cover`) en vez de un editor de recorte al subir. Razón: decisión explícita del usuario; logra tarjetas uniformes sin agregar una librería de recorte de imágenes al bundle del admin, consistente con la restricción del proyecto de no introducir configuración de build adicional a `wp-scripts`.
- **Sí:** `image_url` es un campo calculado en cada respuesta, no una columna persistida. Razón: evita que quede una URL obsoleta si el archivo del Media Library se reemplaza; `wp_get_attachment_image_url()` siempre resuelve la ubicación actual.
- **Sí:** placeholder genérico vía CSS (ícono/color sólido), sin asset de imagen nuevo. Razón: decisión explícita del usuario; no requiere diseñar ni empaquetar un archivo de imagen placeholder.
- **No:** imágenes para staff o categorías en esta spec. Razón: el usuario pidió explícitamente solo servicios; agregarlo a staff/categorías es una spec futura independiente si se necesita.
- **No:** editor de recorte interactivo al subir. Razón: decisión explícita del usuario a favor del recorte visual con CSS, que cubre el caso de uso sin dependencias nuevas.
- **No:** galería de múltiples imágenes por servicio. Razón: fuera de alcance pedido; un `image_id` por servicio es suficiente para el caso de uso actual.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| `object-fit: cover` recorta la imagen según el contenedor sin ningún control del punto focal; una imagen con el elemento importante fuera del centro (ej. una cara en la esquina) puede quedar mal recortada en la tarjeta cuadrada. | Se documenta como limitación conocida del enfoque elegido (recorte visual, sin editor interactivo); el admin debe elegir o encuadrar imágenes que funcionen razonablemente bien en un recorte centrado. |
| `wp_enqueue_media()` cargado de más (en pantallas de admin donde no hace falta) puede sumar peso innecesario al admin de WP. | Se encola únicamente en la pantalla de Reservas > Servicios (paso 3 del plan), no de forma global. |
| Un `image_id` puede quedar "huérfano" si el archivo se borra directamente del Media Library fuera del flujo del plugin. | `wp_get_attachment_image_url()` devuelve `false`/vacío en ese caso; el controlador lo normaliza a `image_url: null`, y ambas superficies ya manejan `null` mostrando el placeholder. |

---

## What is **not** in this spec

- Imágenes para staff o categorías.
- Editor de recorte interactivo al subir la imagen.
- Galería de múltiples imágenes por servicio.
- Redimensionado/optimización más allá de los tamaños estándar que genera WordPress al subir.

Cada uno de estos, si se implementa, va en su propia spec.
