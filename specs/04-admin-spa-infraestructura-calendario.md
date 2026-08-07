# SPEC 04 — Admin SPA: Infraestructura y Calendario

> **Status:** Aprovada
> **Depends on:** SPEC 01, SPEC 02, SPEC 03
> **Date:** 2026-08-07
> **Objective:** Construir el SPA de administración (montado en un menú del admin de WP con React vía `@wordpress/element`) con un calendario interactivo día/semana/mes por staff, gestión de estados de citas y bloqueo manual de horarios, consumiendo la API de SPEC 03.

---

## Por qué esta spec existe

El documento base pide "montar una Single Page Application usando React" en el admin de WP y un "Calendario Centralizado: una vista interactiva (diaria, semanal, mensual) para que los recepcionistas vean la ocupación de todo el equipo, bloqueen horas manualmente y gestionen el estado de las citas". Esta spec cubre exactamente eso — la infraestructura del SPA y el calendario — dejando los formularios de gestión de catálogo (servicios/staff/configuración) para SPEC 05, porque son dominios de UI independientes que se pueden construir e implementar por separado.

---

## Scope

**In:**

- Registro de un menú de nivel superior "Reservas" en el admin de WP (`manage_options`) que monta el contenedor del SPA.
- Build de assets con `wp-scripts` (`webpack.config.js` propio que extiende la configuración por defecto para definir el entry `admin`), usando `@wordpress/element` como base de React en todo el SPA — sin `react`/`react-dom` como dependencia npm separada.
- `@wordpress/components` + CSS propio para los controles de UI (botones, selects, modales).
- Autenticación de las llamadas REST desde el SPA vía nonce (`wp_localize_script` + `@wordpress/api-fetch`), consumiendo los endpoints de SPEC 02 y SPEC 03.
- Calendario con vistas **día**, **semana** y **mes**, navegación anterior/siguiente.
- Vistas día/semana: una columna por staff activo, con selector para mostrar/ocultar columnas específicas.
- Vista mes: celdas por día (sin columnas por staff); clic en un día navega a la vista día de esa fecha.
- Conversión de horas UTC (como las devuelve la API) a la zona horaria del sitio WP en toda la UI.
- Gestión de estado de una cita: clic en una cita abre un modal con sus datos y botones para Confirmar / Completar / marcar No asistió / Cancelar (`PATCH /appointments/{id}`), y reprogramar cambiando fecha/hora en el mismo modal.
- Bloqueo manual de horas: botón "Bloquear horario" + modal (staff, fecha, hora inicio, hora fin, motivo) que crea una cita con `status='blocked'`; y "Desbloquear" sobre un bloqueo existente (`status='cancelled'`).
- Migración aditiva sobre SPEC 01/03: `service_id` pasa a admitir `NULL` en `wp_booking_appointments`, y `'blocked'` se suma como valor válido de `status`.
- Nuevo endpoint `POST /appointments/block` (`manage_options`) sobre el controlador de citas de SPEC 03.

**Out of scope (para specs futuras):**

- Formularios CRUD de categorías, servicios, staff (perfil, horarios, excepciones) y configuración general/`business-hours` — SPEC 05.
- Widget de reserva frontend (SPEC 06), panel de cliente (SPEC 07), notificaciones (SPEC 08), pagos (SPEC 09).
- Arrastrar-y-soltar para reprogramar o crear bloqueos directamente sobre la grilla.
- Actualizaciones en tiempo real (polling o websockets) — se recarga al cambiar de vista/fecha o con un botón de refrescar manual.
- Reportes, métricas de ocupación o exportación de datos.
- Librerías de calendario de terceros (FullCalendar u otras) — la grilla se construye a medida en React.

---

## Data model

No se crean tablas nuevas. Se extiende el esquema de `wp_booking_appointments` (SPEC 01) con una migración aditiva:

```sql
-- Migración aditiva sobre wp_booking_appointments, vía dbDelta + bump de BOOKING_PLUGIN_DB_VERSION
ALTER TABLE {$wpdb->prefix}booking_appointments
  MODIFY COLUMN service_id BIGINT UNSIGNED NULL;
-- status sigue siendo VARCHAR(20) sin restricción a nivel de BD (SPEC 01);
-- 'blocked' se suma a los valores válidos controlados en PHP junto a
-- pending | confirmed | completed | no_show | cancelled.
```

Una fila de bloqueo manual se representa así (sin `access_token`, sin datos de cliente):

```js
{
  "id": 142,
  "service_id": null,
  "staff_id": 5,
  "user_id": null,
  "guest_name": null,
  "start_datetime": "2026-08-20T18:00:00Z",
  "end_datetime": "2026-08-20T19:00:00Z",
  "status": "blocked",
  "notes": "Mantenimiento de equipo"   // se reutiliza el campo notes como "motivo" del bloqueo
}
```

### Ruta nueva

```
POST /wp-json/booking-plugin/v1/appointments/block   (manage_options)
Body: { "staff_id": 5, "start_datetime": "...", "end_datetime": "...", "notes": "Motivo" }
```

Reutiliza la misma transacción `SELECT ... FOR UPDATE` de SPEC 03 para validar que el rango no se superponga con una cita no cancelada existente de ese staff, antes de insertar.

### Estado del SPA (cliente)

```js
// Estado principal del calendario en assets/src/admin/App.js
{
  view: 'week',              // 'day' | 'week' | 'month'
  referenceDate: '2026-08-20',
  visibleStaffIds: [5, 7],   // filtro de columnas visibles
  appointments: [/* respuesta de GET /appointments para el rango visible */],
}
```

---

## Implementation plan

1. Editar `includes/class-booking-plugin-db-schema.php` para permitir `service_id NULL` en `wp_booking_appointments`, y subir `BOOKING_PLUGIN_DB_VERSION` en `booking-plugin.php` para disparar la migración automática. Prueba manual: recargar el admin y confirmar que la columna acepta `NULL`.
2. Extender `includes/rest/class-booking-rest-appointments-controller.php` (SPEC 03): agregar `'blocked'` a los valores válidos de `status`, y la ruta `POST /appointments/block` que crea la fila reutilizando la transacción de colisión existente. Prueba manual: `POST /appointments/block` vía Postman/cURL y confirmar que ese rango desaparece de `GET /availability`.
3. Crear `webpack.config.js` en la raíz del proyecto, extendiendo `@wordpress/scripts/config/webpack.config`, con un entry `admin` apuntando a `assets/src/admin/index.js` y salida a `assets/build/admin.js`.
4. Crear `includes/class-booking-plugin-admin.php`: registra el menú "Reservas" (`add_menu_page`), renderiza `<div id="booking-plugin-admin-root">`, encola `assets/build/admin.js`/`admin.css` con las dependencias de `admin.asset.php`, y pasa vía `wp_localize_script` el nonce REST, la URL base de la API y la zona horaria del sitio (`get_option('timezone_string')`, con fallback a `gmt_offset`).
5. Crear `assets/src/admin/index.js` y `App.js`: monta el SPA con `@wordpress/element`, maneja el estado de vista/fecha/filtro de staff, y hace el fetch inicial de staff activo (`GET /staff`) y citas del rango visible (`GET /appointments`).
6. Crear los componentes de la grilla de calendario (`Calendar.js`, `CalendarColumn.js`, `AppointmentCard.js`, `MonthView.js`): pintan las columnas por staff en día/semana (con conversión UTC→zona horaria del sitio) y las celdas por día en mes.
7. Crear `AppointmentModal.js`: al hacer clic en una cita, muestra sus datos y botones de transición de estado + reprogramar, todo contra `PATCH /appointments/{id}`.
8. Crear `BlockModal.js`: formulario de bloqueo (staff/fecha/hora inicio/hora fin/motivo) contra `POST /appointments/block`, y acción de desbloqueo (`PATCH { status: 'cancelled' }`) sobre bloqueos existentes.
9. Prueba manual end-to-end: `npm run build`; navegar día/semana/mes en el admin; filtrar por staff; cambiar el estado de una cita de prueba y confirmar que persiste al recargar; bloquear un horario y confirmar que deja de aparecer en `GET /availability` (SPEC 03); desbloquearlo y confirmar que vuelve a aparecer.

---

## Acceptance criteria

- [ ] El menú "Reservas" aparece en el admin de WP para usuarios con `manage_options` y no es visible para roles sin ese capability.
- [ ] El calendario ofrece vistas día, semana y mes, con navegación anterior/siguiente funcional en las tres.
- [ ] Las vistas día/semana muestran una columna por staff activo, con un selector que permite mostrar/ocultar columnas específicas.
- [ ] Una cita creada con un `start_datetime` UTC conocido se muestra en la hora correcta según la zona horaria configurada en Ajustes > General de WordPress.
- [ ] Clic en una cita abre un modal que permite cambiar su `status` (Confirmada/Completada/No asistió/Cancelada) y el cambio persiste tras recargar la página.
- [ ] El mismo modal permite reprogramar la cita (nuevo `start_datetime`) vía `PATCH`.
- [ ] "Bloquear horario" crea una fila en `wp_booking_appointments` con `status='blocked'` y `service_id NULL`, y ese rango deja de aparecer en `GET /availability` para ese staff.
- [ ] Un bloqueo existente puede desbloquearse (`status` pasa a `cancelled`) y el horario vuelve a aparecer en `GET /availability`.
- [ ] `npm run build` genera `assets/build/admin.js`, `admin.css` (si aplica) y `admin.asset.php` sin errores.
- [ ] Una llamada REST de escritura sin el nonce válido responde `401`/`403` (verificable removiendo el nonce en devtools).

---

## Decisions

- **Sí:** dividir el panel admin original en dos specs (esta = infraestructura + calendario; SPEC 05 = formularios de catálogo). Razón: reduce el riesgo de una spec demasiado grande y permite implementar/revisar cada parte por separado.
- **Sí:** el bloqueo manual reutiliza `wp_booking_appointments` con `status='blocked'` y `service_id` nullable, en vez de una tabla nueva. Razón: el algoritmo de disponibilidad de SPEC 03 ya trata como "ocupada" cualquier cita no cancelada, así que un bloqueo se resta automáticamente sin tocar ese algoritmo.
- **Sí:** grilla de calendario construida a mano en React, sin librería externa tipo FullCalendar. Razón: la vista "una columna por staff" que pide el documento base requiere la edición de pago de las librerías más conocidas; se evita ese costo de licencia.
- **Sí:** `@wordpress/element` como única base de React en todo el SPA, sin `react`/`react-dom` como dependencia npm aparte. Razón: `@wordpress/components` depende de `@wordpress/element`, que en el navegador usa la instancia de React que WordPress ya carga (`wp-element`); tener una segunda copia de React vía npm generaría dos instancias coexistiendo en la misma página, con riesgo real de romper Context/hooks entre componentes propios y de `@wordpress/components`.
- **Sí:** `@wordpress/components` + CSS propio para la UI, sin Tailwind ni Material UI. Razón: cero configuración de build adicional, consistente con "no introducir tooling extra" de `CLAUDE.md`, y mantiene el look nativo del admin de WP.
- **Sí:** bloqueo vía botón + modal de formulario, no arrastrar-y-soltar sobre la grilla. Razón: MVP con menor superficie de bugs de interacción; arrastrar-y-soltar se puede añadir después como mejora incremental.
- **Sí:** vista mes muestra celdas por día (no columnas por staff); solo día/semana muestran la grilla completa de staff. Razón: una grilla de columnas por staff en una vista mensual sería ilegible por el espacio disponible.
- **Sí:** zona horaria del sitio WP (no la del navegador) para mostrar horas en el admin. Razón: el negocio opera en su propia zona horaria sin importar dónde esté físicamente el recepcionista.
- **No:** actualizaciones en tiempo real (polling/websockets). Razón: fuera de alcance del MVP; refrescar al cambiar de vista/fecha es suficiente.
- **No:** reprogramar citas arrastrando en la grilla. Razón: se cubre con el modal de gestión de la cita, reutilizando el mismo `PATCH` que las demás transiciones de estado.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| Ampliar `service_id` a `NULL`-able es una migración segura sobre datos existentes, pero algún `JOIN` de SPEC 02/03 podría asumir que `service_id` siempre está presente. | Revisar los controladores de SPEC 03 que hacen `JOIN` con `services` y usar `LEFT JOIN` donde corresponda antes de dar por cerrado el paso 2 del plan. |
| Construir la grilla del calendario a mano (sin librería) implica más superficie de bugs visuales (solapamientos, cálculo de posiciones) que usar una probada. | Las pruebas manuales del paso 9 incluyen explícitamente el caso "dos citas del mismo staff que casi se tocan en el tiempo". |
| Si en el futuro se instala alguna librería de terceros que traiga `react`/`react-dom` como dependencia transitiva, se reintroduce el riesgo de dos instancias de React. | Documentado en Decisions: cualquier librería nueva para el admin debe evaluarse contra `@wordpress/element` antes de instalarse. |

---

## What is **not** in this spec

- Formularios CRUD de categorías, servicios, staff y configuración general (SPEC 05).
- Widget de reserva frontend (SPEC 06), panel de cliente (SPEC 07), notificaciones (SPEC 08), pagos (SPEC 09).
- Arrastrar-y-soltar para reprogramar o bloquear horarios.
- Actualizaciones en tiempo real, reportes y métricas de ocupación.

Cada uno de estos, si se implementa, va en su propia spec.
