# SPEC 07 — Panel de Cliente

> **Status:** Implementado
> **Depends on:** SPEC 01, SPEC 02, SPEC 03, SPEC 06
> **Date:** 2026-08-07
> **Objective:** Construir el panel de cliente (shortcode `[booking_client_panel]`) donde un usuario registrado ve, cancela y reprograma sus citas (próximas e historial), y donde un invitado con su `access_token` gestiona puntualmente la cita que reservó, reutilizando los componentes de fecha/hora de SPEC 06.

---

## Por qué esta spec existe

El documento base pide una "vista donde los usuarios registrados puedan ver, cancelar o reprogramar sus próximas citas". Pero SPEC 03/06 ya construyeron un mecanismo paralelo de autogestión por `access_token` para invitados que, hasta ahora, no tiene ninguna pantalla donde usarse — solo se muestra una vez en la confirmación de SPEC 06. Esta spec cierra ambos casos con una misma página.

---

## Scope

**In:**

- Shortcode `[booking_client_panel]`, mismo patrón de inyección que SPEC 06.
- **Modo usuario registrado:** si hay sesión WP activa, lista de citas propias en dos pestañas — **Próximas** (`pending`/`confirmed` futuras, con acciones Cancelar y Reprogramar) e **Historial** (citas pasadas o en estado `completed`/`no_show`/`cancelled`, solo lectura).
- **Modo invitado:** si la URL trae `?token=&appointment=`, vista puntual de esa cita (sin requerir sesión WP) con las mismas acciones de Cancelar y Reprogramar.
- **Sin sesión y sin token:** formulario de login embebido (`wp_login_form()`), sin salir de la página.
- Cancelar cita: confirmación + `PATCH /appointments/{id}` con `status=cancelled` (SPEC 03), respetando `min_cancellation_hours` (SPEC 03/05).
- Reprogramar cita: selector de fecha/hora reutilizando `MonthCalendar`/`TimeSlotList` de SPEC 06 (mismo servicio y staff, solo cambia `start_datetime`), vía `PATCH /appointments/{id}`.
- Nueva ruta `GET /appointments/mine` que extiende el controlador de citas de SPEC 03: devuelve únicamente las citas del usuario autenticado actual (pasadas y futuras), sin los filtros de alcance admin de `GET /appointments`.
- Mensajes de error claros para los casos ya definidos en SPEC 03: `409` por ventana de cancelación insuficiente, `403` por token inválido/vencido.

**Out of scope (para specs futuras):**

- Bloque de Gutenberg para este panel.
- Integración con "Mi cuenta" de WooCommerce (sigue sin decidirse la estrategia de pago, SPEC 09).
- Cambiar el servicio o el profesional de una cita al reprogramar (solo cambia fecha/hora, igual que el `PATCH` de SPEC 03).
- Envío de notificaciones por email cuando el cliente cancela o reprograma (SPEC 08).
- Edición de datos personales del cliente (nombre/email/teléfono) desde este panel.

---

## Data model

No se crean tablas nuevas. Se extiende la API de SPEC 03 con una ruta adicional:

```
GET /wp-json/booking-plugin/v1/appointments/mine   (autenticado, cualquier usuario logueado)

Response: array de citas donde user_id = usuario actual, sin límite de fecha,
misma forma que las citas de GET /appointments (SPEC 03).
```

Convención de acceso invitado (ya definida en SPEC 03, reutilizada sin cambios): `GET /appointments/{id}?token=...` y `PATCH /appointments/{id}?token=...`.

```js
// Estado del panel (assets/src/frontend/client-panel/ClientPanelApp.js)
{
  mode: 'guest' | 'member' | 'login',   // resuelto en PHP y pasado vía datos localizados
  guestContext: { appointmentId: 87, token: '9f3a...' } | null,
  tab: 'upcoming' | 'history',           // solo aplica en mode: 'member'
  appointments: [],
  reschedulingId: null,                  // id de la cita en proceso de reprogramación, o null
}
```

---

## Implementation plan

1. Extender `includes/rest/class-booking-rest-appointments-controller.php` (SPEC 03) con `GET /appointments/mine`. Prueba manual: con dos usuarios de prueba, confirmar que cada uno solo ve sus propias citas.
2. Crear `includes/class-booking-plugin-client-panel-shortcode.php`: registra `[booking_client_panel]`; en PHP decide el modo — `token`+`appointment` en la URL → `guest`; si no, `is_user_logged_in()` → `member`; si no, `login`. Para `login`, renderiza `wp_login_form()` directamente sin encolar el bundle de React. Para `guest`/`member`, encola `assets/build/frontend.js` (SPEC 06) y localiza el modo + contexto.
3. Editar `assets/src/frontend/index.js` (SPEC 06) para leer el modo localizado y montar `BookingWidget` (ya existente) o `ClientPanelApp` (nuevo) según corresponda.
4. Crear `assets/src/frontend/client-panel/ClientPanelApp.js`: en `mode: 'guest'` renderiza `GuestAppointmentView`; en `mode: 'member'` renderiza `MyAppointmentsList` con las pestañas Próximas/Historial (`GET /appointments/mine`).
5. Crear `AppointmentListItem.js` con las acciones Cancelar (confirmación + `PATCH status=cancelled`) y Reprogramar (abre el selector de fecha/hora reutilizado de SPEC 06, luego `PATCH` con el nuevo `start_datetime`).
6. Crear `GuestAppointmentView.js`: misma UI de detalle y acciones que un ítem de la lista, pero resolviendo la cita vía `GET /appointments/{id}?token=`.
7. Manejo de errores compartido: `409` por ventana de cancelación insuficiente → mensaje "No se puede modificar, faltan menos de X horas para la cita"; `403` por token inválido → "Este enlace ya no es válido".
8. Prueba manual end-to-end: crear una cita como invitado (SPEC 06), abrir el enlace con su token en el panel y cancelarla; crear otra cita con un usuario WP logueado, verla en "Próximas", reprogramarla a otro horario y confirmar que se refleja en el calendario admin (SPEC 04); intentar cancelar una cita a menos de `min_cancellation_hours` y confirmar que se bloquea con el mensaje claro.

---

## Acceptance criteria

- [x] `[booking_client_panel]` sin sesión y sin token en la URL muestra el formulario de login embebido, no un error ni una lista vacía.
- [x] `GET /appointments/mine` nunca devuelve citas de un usuario distinto al autenticado.
- [x] La pestaña "Próximas" muestra únicamente citas futuras con `status` `pending`/`confirmed`, cada una con botones Cancelar y Reprogramar.
- [x] La pestaña "Historial" muestra citas pasadas o con `status` `completed`/`no_show`/`cancelled`, sin acciones (solo lectura).
- [x] Cancelar una cita desde el panel cambia su `status` a `cancelled`; al refrescar, la cita aparece en "Historial" y ya no en "Próximas".
- [x] Reprogramar una cita reutiliza el calendario/lista de horarios de SPEC 06, mantiene el mismo servicio y staff, y actualiza `start_datetime` al confirmar.
- [x] Un enlace con `?token=&appointment=` válido muestra esa cita puntual con sus acciones, sin requerir sesión WP.
- [x] Un enlace con token inválido o de otra cita muestra un mensaje de error claro, sin exponer ningún dato de la cita.
- [x] Intentar cancelar o reprogramar dentro de la ventana `min_cancellation_hours` responde con el mensaje claro definido arriba, no un error genérico.

---

## Decisions

- **Sí:** la misma página cubre usuarios registrados (lista) e invitados con token (vista puntual). Razón: sin esto, el `access_token` generado desde SPEC 03/06 nunca tendría una interfaz donde usarse.
- **Sí:** shortcode `[booking_client_panel]`, mismo patrón que SPEC 06. Razón: consistencia; no depende de una integración con WooCommerce que sigue sin decidirse.
- **Sí:** formulario de login embebido (`wp_login_form()`) en vez de redirect a `wp-login.php`. Razón: decisión explícita del usuario; el cliente no sale del flujo del panel.
- **Sí:** pestañas Próximas/Historial. Razón: decisión explícita del usuario; útil para que el cliente vea qué servicios usó antes.
- **Sí:** nuevo endpoint `GET /appointments/mine`, separado del `GET /appointments` admin-only de SPEC 03. Razón: semántica distinta (siempre auto-limitado al usuario actual, sin los filtros cross-cliente de un admin); evita mezclar dos modos de autorización en el mismo endpoint.
- **Sí:** reprogramar reutiliza `MonthCalendar`/`TimeSlotList` ya construidos en SPEC 06, dentro del mismo bundle `frontend.js`. Razón: evita duplicar la lógica de selección de fecha/hora en dos bundles distintos.
- **No:** cambiar el servicio o el profesional al reprogramar. Razón: el `PATCH /appointments/{id}` de SPEC 03 solo revalida `start_datetime`; cambiar de servicio equivaldría a cancelar y crear una cita nueva, fuera del alcance de "reprogramar".
- **No:** integración con "Mi cuenta" de WooCommerce. Razón: la estrategia de pago sigue sin decidirse (SPEC 09); este panel se mantiene independiente de esa decisión futura.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| Un `access_token` filtrado o compartido por error permite a cualquiera con el enlace cancelar/reprogramar esa cita puntual. | Riesgo ya aceptado desde SPEC 03 como el mecanismo de autogestión de invitados; el token no expone ningún otro dato del negocio más allá de esa cita. |
| Mezclar el modo "widget de reserva" y "panel de cliente" en el mismo bundle `frontend.js` aumenta su tamaño en páginas que solo necesitan uno de los dos. | Aceptable para el MVP; si el tamaño del bundle se vuelve un problema real, se puede separar en dos entries de `webpack.config.js` más adelante sin cambiar ninguna API. |

---

## What is **not** in this spec

- Bloque de Gutenberg para el panel de cliente.
- Integración con "Mi cuenta" de WooCommerce (SPEC 09 si aplica).
- Cambiar servicio/profesional de una cita al reprogramar.
- Notificaciones por email de cambios hechos desde el panel (SPEC 08).
- Edición de datos personales del cliente.

Cada uno de estos, si se implementa, va en su propia spec.
