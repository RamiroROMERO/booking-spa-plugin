# SPEC 05 — Admin SPA: Catálogo (Servicios/Staff) y Configuración

> **Status:** Aprovada
> **Depends on:** SPEC 01, SPEC 02, SPEC 03, SPEC 04
> **Date:** 2026-08-07
> **Objective:** Construir en el admin de WP (submenús "Servicios", "Staff" y "Configuración" bajo "Reservas", reutilizando la infraestructura del SPA de SPEC 04) los formularios CRUD de categorías, servicios, staff —con su horario semanal y excepciones— y la configuración general del negocio, consumiendo la API de SPEC 02 y SPEC 03.

---

## Por qué esta spec existe

El documento base pide "Gestión de Servicios" (precio, duración, buffer) y "Gestión de Personal" (horarios, días libres, pausas, servicios que puede realizar cada profesional) como parte de la administración del negocio. SPEC 02 y SPEC 03 ya construyeron la API para todo esto; falta la interfaz que un administrador realmente use para cargarlo, sin tocar código ni la base de datos directamente.

---

## Scope

**In:**

- Tres submenús nuevos de WP bajo "Reservas" (creado en SPEC 04): **Servicios**, **Staff**, **Configuración**. El submenú "Calendario" de SPEC 04 pasa a tener también una entrada explícita en el mismo menú.
- **Página Servicios:** gestor compacto de categorías (listar, crear, editar, borrar) integrado en la misma página, y CRUD completo de servicios (nombre, categoría, precio, duración, buffer, descripción, activar/desactivar).
- **Página Staff:** CRUD completo de staff (nombre, email, teléfono, activar/desactivar), selector de qué servicios puede realizar, editor de horario semanal (grilla de 7 filas) y editor de excepciones (días libres/horario especial por fecha).
- **Página Configuración:** editor del horario del negocio (`business-hours`, misma grilla de 7 filas que el horario de staff) y formulario de las ventanas de tiempo (`settings`: antelación mínima, máximo de días a futuro, ventana mínima de cancelación, intervalo de slots).
- Reutilización del bundle `admin.js`/`webpack.config.js` ya creado en SPEC 04: las páginas nuevas se resuelven dentro del mismo entry, sin bundles adicionales.
- Manejo visible de errores de la API (validación 400, conflicto 409 al borrar una categoría con servicios asociados, etc.), no solo mensajes genéricos.

**Out of scope (para specs futuras):**

- Calendario y bloqueo manual de horas (ya cubiertos en SPEC 04).
- Widget de reserva frontend (SPEC 06), panel de cliente (SPEC 07), notificaciones (SPEC 08), pagos (SPEC 09).
- Subida de imágenes/fotos de servicios o staff (no existe ese campo en el modelo de datos).
- Roles o capabilities personalizados — sigue usándose `manage_options` (decisión de SPEC 02).
- Historial de cambios/auditoría sobre servicios o staff.

---

## Data model

No se crean tablas ni endpoints nuevos — esta spec consume exclusivamente los endpoints ya definidos en SPEC 02 (`/categories`, `/services`, `/staff`) y SPEC 03 (`/business-hours`, `/settings`).

```js
// Estado de la página Staff al editar un registro (assets/src/admin/pages/StaffPage.js)
{
  id: 5,
  name: "Ana Pérez",
  email: "ana@example.com",
  phone: "+52 555 000 0000",
  status: "active",
  service_ids: [1, 4, 7],
  schedules: [
    { day_of_week: 1, enabled: true, start_time: "09:00", end_time: "18:00", break_start: "13:00", break_end: "14:00" },
    // ... una fila por cada día 0-6, "enabled: false" si ese día no trabaja
  ],
  exceptions: [
    { exception_date: "2026-12-25", is_day_off: true, start_time: null, end_time: null, reason: "Feriado" }
  ]
}
```

Convención: el componente `WeeklyScheduleEditor` (7 filas fijas, día/toggle/hora inicio/hora fin/pausa opcional) se reutiliza tanto para el horario semanal de un staff como para el horario del negocio en Configuración — misma forma de datos, distinto endpoint de guardado (`PUT /staff/{id}` vs `PUT /business-hours`).

---

## Implementation plan

1. Editar `includes/class-booking-plugin-admin.php` (SPEC 04) para registrar los submenús "Calendario" (explícito), "Servicios", "Staff" y "Configuración" con `add_submenu_page`, pasando a cada uno la sección correspondiente vía `wp_localize_script`. Prueba manual: los 4 ítems aparecen en el menú "Reservas" y cada uno carga una página en blanco sin errores de PHP.
2. Editar `assets/src/admin/index.js` para leer la sección localizada y montar el componente de página correspondiente (`CalendarPage` ya existente, o los 3 nuevos).
3. Crear `assets/src/admin/pages/ServicesPage.js` + `CategoriesManager.js` (listar/crear/editar/borrar categorías) + `ServiceFormModal.js` (crear/editar servicio, con activar/desactivar). Prueba manual: crear una categoría, crear un servicio dentro de ella, confirmar que aparece en el listado.
4. Crear `assets/src/admin/components/WeeklyScheduleEditor.js` (grilla de 7 filas reutilizable) y `assets/src/admin/components/ExceptionsEditor.js` (selector de fecha + tipo, lista de tarjetas removibles).
5. Crear `assets/src/admin/pages/StaffPage.js` + `StaffFormModal.js`: nombre/email/teléfono/status, selector de `service_ids`, y los dos editores del paso 4 embebidos. Prueba manual: crear un staff, asignarle 2 servicios, definir su horario y una excepción, guardar, reabrir el formulario y confirmar que todo se recargó igual a como se guardó.
6. Crear `assets/src/admin/pages/SettingsPage.js`: `WeeklyScheduleEditor` apuntando a `business-hours`, y un formulario simple para los 4 campos de `/settings`.
7. Manejo de errores compartido (`assets/src/admin/utils/apiError.js`): traduce respuestas `400`/`409` de la API en mensajes legibles mostrados en la UI (ej. "No se puede borrar: hay servicios en esta categoría").
8. Prueba manual end-to-end: `npm run build`; recorrer las 4 páginas nuevas; provocar un 409 intentando borrar una categoría con servicios y confirmar que el mensaje es claro; cambiar `min_cancellation_hours` en Configuración y confirmar (vía `GET /settings` en devtools o Postman) que el nuevo valor quedó guardado.

---

## Acceptance criteria

- [ ] El menú "Reservas" muestra 4 entradas: Calendario, Servicios, Staff, Configuración.
- [ ] Reservas > Servicios lista los servicios existentes con nombre, categoría, precio, duración y estado.
- [ ] Crear una categoría nueva desde Reservas > Servicios la deja disponible de inmediato en el selector de categoría del formulario de servicio, sin recargar la página.
- [ ] Intentar borrar una categoría con servicios asociados muestra el error de la API de forma legible, no un mensaje genérico ni una pantalla en blanco.
- [ ] Crear un servicio con `duration_minutes` vacío o `0` muestra un error de validación antes o al llamar a la API.
- [ ] "Desactivar" un servicio activo lo saca del listado por defecto (solo activos) pero sigue visible con el filtro "mostrar inactivos".
- [ ] Reservas > Staff permite crear un staff con nombre/email/teléfono, marcar qué servicios puede realizar, definir su horario semanal completo y agregar al menos una excepción, guardando todo en un solo submit.
- [ ] Editar el horario semanal de un staff, guardar, y volver a abrir su formulario muestra los datos recién guardados (no los anteriores).
- [ ] Reservas > Configuración permite editar el horario del negocio (7 días) y guardar sin errores.
- [ ] Cambiar `min_cancellation_hours` (u otra ventana) en Reservas > Configuración y guardarlo se refleja en una llamada posterior a `GET /settings`.
- [ ] Las 3 páginas nuevas requieren `manage_options` para ser visibles en el menú de WP.

---

## Decisions

- **Sí:** submenús clásicos de WP (`add_submenu_page`) para Calendario/Servicios/Staff/Configuración, con recarga completa del admin entre ellos. Razón: decisión explícita del usuario sobre el patrón de navegación, priorizando familiaridad sobre un router SPA sin recargas.
- **Sí:** categorías se gestionan dentro de la página Servicios, sin submenú propio. Razón: es una entidad pequeña y estrechamente ligada a servicios; un submenú dedicado sería desproporcionado para una lista de nombres y orden.
- **Sí:** las 4 páginas comparten el mismo bundle `admin.js` de SPEC 04, seleccionando la sección a mostrar vía datos localizados por PHP. Razón: evita duplicar configuración de build por cada submenú.
- **Sí:** `WeeklyScheduleEditor` reutilizado entre el horario de staff y el horario del negocio. Razón: misma estructura de 7 días, misma UX de grilla; solo cambia el endpoint de guardado.
- **Sí:** grilla de 7 filas fijas para horarios semanales, y selector de fecha + tipo para excepciones. Razón: decisiones explícitas del usuario sobre la forma de estos formularios.
- **No:** pestañas internas sin recarga entre secciones. Razón: el usuario prefirió el patrón clásico de submenús de WP.
- **No:** campo de texto libre para cargar excepciones. Razón: riesgo de errores de formato sin validación en el momento.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| `PUT /staff/{id}` reemplaza por completo `schedules`/`exceptions`/`service_ids` (decisión de SPEC 02); si el formulario guarda antes de que el `GET` inicial haya cargado esos datos, se sobrescriben con un payload vacío. | El botón "Guardar" del formulario de staff permanece deshabilitado hasta que el `GET` inicial de ese staff se resuelve con éxito. |
| Al navegar entre submenús clásicos de WP se pierde cualquier cambio sin guardar en el formulario abierto, sin forma directa de interceptar esa navegación entre páginas de admin distintas. | Se muestra una advertencia visual (ej. asterisco/badge "cambios sin guardar") mientras el formulario tiene ediciones pendientes; se documenta como limitación conocida del patrón de submenús elegido, no como algo resuelto. |

---

## What is **not** in this spec

- Calendario y bloqueo manual de horas (SPEC 04).
- Widget de reserva frontend (SPEC 06), panel de cliente (SPEC 07), notificaciones (SPEC 08), pagos (SPEC 09).
- Subida de imágenes/fotos, roles personalizados, historial de auditoría.

Cada uno de estos, si se implementa, va en su propia spec.
