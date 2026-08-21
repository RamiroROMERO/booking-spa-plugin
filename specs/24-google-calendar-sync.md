# SPEC 24 — Sincronización de citas con Google Calendar

> **Status:** Implementado
> **Depends on:** SPEC 01, SPEC 02, SPEC 03, SPEC 04
> **Date:** 2026-08-21
> **Objective:** Sincronizar automáticamente hacia un único Google Calendar del negocio, en un solo sentido, las citas confirmadas/pendientes y los bloqueos manuales creados en el plugin.

---

## Por qué esta spec existe

Hoy la única forma de ver las citas es entrando al Calendario del admin (SPEC 04). El negocio quiere que esas citas aparezcan también en el Google Calendar que ya usa el día a día, sin tener que revisar dos sistemas. Se descartó una integración bidireccional (traer eventos de Google como bloqueos de disponibilidad) y una cuenta por staff: por ahora una sola cuenta de Google conectada a nivel negocio, sincronizada en un solo sentido (plugin → Google), es suficiente y evita la complejidad de manejar múltiples tokens OAuth y el riesgo de que un evento externo bloquee un hueco por error.

El proyecto no usa Composer (ver `CLAUDE.md`), así que no hay SDK oficial de Google disponible. Toda la integración se implementa a mano contra la Calendar API v3 (REST) y el endpoint de OAuth2 de Google usando `wp_remote_post`/`wp_remote_get`, igual que cualquier otra llamada HTTP saliente del plugin.

---

## Scope

**In:**

- Nueva sección "Google Calendar" dentro de la pantalla Configuración (`SettingsPage.js`): campos para pegar `Client ID`/`Client Secret` de una app OAuth2 ya creada por el administrador en Google Cloud Console, y un botón "Conectar cuenta de Google" que arranca el flujo de autorización (Authorization Code, `access_type=offline` para obtener `refresh_token`).
- Tras autorizar, un selector lista los calendarios de esa cuenta (`GET /users/me/calendarList` de la Calendar API) y el admin elige a cuál se sincronizan las citas; se guarda su `calendar_id`.
- Al confirmar el calendario elegido se dispara un backfill: todas las citas futuras (`start_datetime >= ahora`) en estado `confirmed`, `pending` o `blocked` sin `google_event_id` se sincronizan.
- Toda cita nueva o modificada (crear, reprogramar, cancelar, bloqueo manual vía `BlockModal.js`) intenta sincronizarse de inmediato en el mismo request que la creó/modificó.
- Si la sincronización inmediata falla (red, rate limit, token vencido), la cita queda marcada como pendiente y un cron de respaldo (mismo patrón que `Booking_Plugin_Reminder_Cron`) la reintenta en su siguiente corrida.
- Reglas de mapeo evento↔cita:
  - Crear cita (`confirmed`/`pending`) o bloqueo (`blocked`) → crea el evento, guarda `google_event_id`.
  - Reprogramar (cambia `start_datetime`/`end_datetime`) sobre una cita ya sincronizada → actualiza el evento existente.
  - Cancelar (`status = cancelled`) sobre una cita ya sincronizada → borra el evento y limpia `google_event_id`.
  - `completed`/`no_show` → no toca el evento (ya ocurrió).
- Contenido del evento: título `"{Servicio} - {Cliente}"`, resolviendo el cliente con la misma lógica que `client_name` de SPEC 23 (`guest_name`, o `display_name` de `wp_users`, o "Reserva"); para un bloqueo, título "Bloqueado" (+ `notes` si tiene). Descripción con staff asignado, estado y notas. Fecha/hora con la zona horaria del sitio (`wp_timezone_string()`, mismo criterio que el resto del plugin). Sin agregar al cliente como `attendee` del evento — no se dispara ninguna invitación de Google aparte de los emails que ya manda el plugin (SPEC 07/08).
- Si el token de la cuenta conectada deja de ser válido (revocado, `invalid_grant`), la cuenta pasa a estado `error`: se detiene el reintento de citas individuales y la pantalla de Configuración muestra un aviso "Reconectar cuenta de Google" con el último error.
- Botón "Desconectar": borra las credenciales guardadas, la cuenta vuelve a `disconnected` y deja de sincronizar citas nuevas/modificadas hacia adelante. No borra los eventos ya creados en Google Calendar.

**Out of scope (para specs futuras):**

- Sincronización bidireccional: leer eventos del Google Calendar conectado para bloquear disponibilidad en el plugin.
- Un calendario por staff, o más de una cuenta de Google conectada a la vez.
- Agregar al cliente como `attendee` del evento (invitación de Google Calendar aparte del email que ya manda el plugin).
- Registrar/crear la app OAuth2 en Google Cloud Console — el administrador ya trae su propio `Client ID`/`Client Secret`; esta spec solo los consume.
- Sincronizar citas pasadas (`start_datetime` anterior a ahora) al conectar la cuenta — el backfill es solo hacia adelante.
- Reintentos con backoff exponencial o límite configurable de intentos — el cron reintenta en cada corrida mientras la cuenta esté `connected`, sin límite.
- Reflejar en el plugin una edición manual hecha directamente en Google Calendar sobre un evento ya sincronizado (ver bidireccional, arriba).

---

## Data model

No se crean tablas nuevas. Se extiende `wp_booking_appointments` (SPEC 01) con 3 columnas:

```sql
-- includes/class-booking-plugin-db-schema.php, CREATE TABLE {$prefix}booking_appointments
google_event_id    VARCHAR(255) NULL,
google_sync_status VARCHAR(20) NOT NULL DEFAULT 'not_synced', -- not_synced|pending|synced|failed
google_synced_at   DATETIME NULL,
```

Nueva opción en `wp_options`, `booking_plugin_google_calendar` (mismo patrón que `Booking_Plugin_Settings`):

```php
array(
  'client_id'        => '',
  'client_secret'    => '',
  'access_token'     => '',
  'refresh_token'    => '',
  'token_expires_at' => '',            // datetime UTC
  'calendar_id'      => '',
  'calendar_summary' => '',            // nombre visible del calendario, para la UI
  'account_email'    => '',            // email de la cuenta conectada, para la UI
  'status'           => 'disconnected', // disconnected|connected|error
  'last_error'       => '',
)
```

Convenciones:

- `google_sync_status` de una cita: `not_synced` (default, o cuenta desconectada), `synced` (tiene `google_event_id` al día), `pending` (falló y espera el cron de respaldo), `failed` (agotó reintentos por una causa no de autenticación — ver Risks). El cron de respaldo procesa `pending` y `failed` por igual mientras la cuenta esté `connected`.
- El estado de la cuenta (`booking_plugin_google_calendar.status`) es independiente del `google_sync_status` de cada cita: si la cuenta pasa a `error`, no se reintenta ninguna cita individual hasta reconectar.
- Los tokens se guardan en texto plano en `wp_options`, mismo nivel de exposición que otras credenciales ya guardadas por el plugin (ej. configuración de WooCommerce) — no hay librería de cifrado disponible sin Composer.

---

## Implementation plan

1. En `includes/class-booking-plugin-db-schema.php`, agregar `google_event_id`, `google_sync_status`, `google_synced_at` al `CREATE TABLE` de `booking_appointments`; bump `BOOKING_PLUGIN_DB_VERSION` a `1.11.0` en `booking-plugin.php`. Prueba manual: recargar el admin de WP (dispara la re-sincronización de esquema en `Booking_Plugin::init()`) y confirmar con `DESCRIBE wp_booking_appointments` que las 3 columnas nuevas existen.
2. Crear `includes/class-booking-plugin-google-calendar.php` (`Booking_Plugin_Google_Calendar`): `get_settings()`/`update_settings()` (mismo patrón que `Booking_Plugin_Settings`, opción `booking_plugin_google_calendar`), `get_auth_url()` (arma la URL de autorización con `client_id`, `redirect_uri`, `scope=https://www.googleapis.com/auth/calendar`, `access_type=offline`, `prompt=consent`), `exchange_code( $code )` y `refresh_access_token()` (ambos `POST` a `https://oauth2.googleapis.com/token` vía `wp_remote_post`), `list_calendars()` (`GET /users/me/calendarList`). Sin SDK externo. Prueba manual: con WP-CLI (`wp eval`), invocar `get_auth_url()` y confirmar que arma una URL de Google válida con los parámetros esperados.
3. Crear `includes/class-booking-plugin-google-calendar-sync.php` (`Booking_Plugin_Google_Calendar_Sync`) con `sync_appointment( $row )`: si la cuenta no está `connected`, no hace nada; si no, resuelve `client_name` (misma lógica de SPEC 23), arma el payload del evento (título, descripción, `start`/`end` en `wp_timezone_string()`), y según el caso hace `POST` (crear), `PATCH` (ya tiene `google_event_id`) o `DELETE` (`status = cancelled`) contra `/calendars/{calendar_id}/events` de la Calendar API, refrescando el token si está vencido; actualiza `google_event_id`/`google_sync_status`/`google_synced_at` en la fila. Un 401/`invalid_grant` de Google marca la cuenta como `error` con `last_error`. Prueba manual: con una cuenta ya conectada (paso 6), invocar `sync_appointment()` a mano sobre una cita existente y confirmar que el evento aparece en Google Calendar.
4. En `includes/rest/class-booking-rest-appointments-controller.php`, tras un `create_item()`/`update_item()` exitoso (incluye la creación de bloqueos, que ya pasa por `create_item()` con `status = 'blocked'`), llamar `Booking_Plugin_Google_Calendar_Sync::sync_appointment( $row )`. Prueba manual: crear una cita nueva y confirmar que el evento aparece en el Google Calendar conectado en el mismo request.
5. Crear `includes/class-booking-plugin-google-calendar-sync-cron.php` (`Booking_Plugin_Google_Calendar_Sync_Cron`), mismo patrón que `Booking_Plugin_Reminder_Cron` (`register()`, `register_schedule()`, `schedule()`/`unschedule()`): en cada corrida, si la cuenta está `connected`, busca citas con `google_sync_status IN ('pending', 'failed')` y reintenta `sync_appointment()`. Prueba manual: forzar una fila a `google_sync_status = 'pending'`, disparar el hook a mano (WP-CLI), confirmar que pasa a `synced`.
6. Crear `includes/rest/class-booking-rest-google-calendar-controller.php` (`Booking_Rest_Google_Calendar_Controller`, `rest_base = 'google-calendar'`, todas las rutas con `permissions_check` de `manage_options`, mismo patrón que `Booking_Rest_Settings_Controller`): `GET /google-calendar` (estado, cuenta/calendario conectados, `last_error`), `POST /google-calendar/credentials` (guarda `client_id`/`client_secret`), `GET /google-calendar/auth-url`, `GET /google-calendar/oauth-callback` (recibe `code`, intercambia el token, guarda `access_token`/`refresh_token`, `status = connected`), `GET /google-calendar/calendars`, `POST /google-calendar/calendar` (guarda `calendar_id` y dispara el backfill del paso 7), `POST /google-calendar/disconnect` (borra tokens, `status = disconnected`, no toca eventos ya creados). Prueba manual: completar el flujo con una cuenta de prueba (auth-url → autorizar en Google → oauth-callback → calendars → calendar) y confirmar que `GET /google-calendar` devuelve `status: connected` con el calendario elegido.
7. En el método que guarda `calendar_id` (paso 6): recorrer las citas con `start_datetime >= NOW()` y `status IN ('confirmed', 'pending', 'blocked')` sin `google_event_id`, y llamar `sync_appointment()` sobre cada una. Prueba manual: con citas futuras ya existentes antes de conectar, conectar la cuenta y confirmar que todas aparecen en Google Calendar tras elegir el calendario.
8. Registrar lo nuevo en `booking-plugin.php` (`require_once` de los 3 archivos PHP nuevos, sumar `Booking_Rest_Google_Calendar_Controller` a la lista de `Booking_Plugin_Rest::register_routes()`, `add_filter( 'cron_schedules', ... )` del cron nuevo) y en `includes/class-booking-plugin.php` / `class-booking-plugin-activator.php` / `class-booking-plugin-deactivator.php` (`register()`/`schedule()`/`unschedule()` del cron nuevo, mismo patrón que `Booking_Plugin_Reminder_Cron`).
9. En `assets/src/admin/pages/SettingsPage.js`, agregar la sección "Google Calendar": campos `client_id`/`client_secret` + botón "Conectar cuenta de Google" (redirige a `auth-url`); al volver del `oauth-callback`, selector de calendario (`GET /google-calendar/calendars`) + botón "Guardar calendario"; conectado, muestra cuenta/calendario activos + botón "Desconectar"; si `status = error`, muestra el aviso "Reconectar cuenta de Google" con `last_error`.
10. `npm run build` y verificación manual end-to-end en WAMP: conectar una cuenta de Google real, elegir un calendario y confirmar que el backfill trae las citas futuras existentes; crear una cita nueva y confirmar que aparece de inmediato; reprogramarla y confirmar que el evento se mueve; cancelarla y confirmar que el evento se borra; crear un bloqueo manual y confirmar que también sincroniza; marcar una cita como `completed` y confirmar que el evento no cambia; revocar el acceso desde la cuenta de Google y confirmar que la próxima sincronización marca la cuenta en `error` con el aviso de reconexión en Configuración; reconectar y confirmar que las citas `pending`/`failed` se sincronizan en la siguiente corrida del cron.

---

## Acceptance criteria

- [ ] El admin puede conectar una cuenta de Google desde Configuración pegando `Client ID`/`Client Secret` y autorizando vía OAuth2.
- [ ] Tras conectar, el admin puede elegir a cuál de los calendarios de esa cuenta se sincronizan las citas.
- [ ] Al elegir el calendario, todas las citas futuras (`confirmed`/`pending`/`blocked`) ya existentes se sincronizan automáticamente (backfill), sin duplicar eventos en corridas posteriores.
- [ ] Crear una cita `confirmed` o `pending` genera un evento en el Google Calendar conectado en el mismo request.
- [ ] Crear un bloqueo manual (`status = blocked`) también genera un evento, titulado "Bloqueado".
- [ ] Reprogramar una cita ya sincronizada actualiza fecha/hora del evento existente, sin crear uno nuevo.
- [ ] Cancelar una cita ya sincronizada borra su evento de Google Calendar.
- [ ] Marcar una cita como `completed` o `no_show` no modifica su evento en Google Calendar.
- [ ] Si la sincronización inmediata falla, la cita queda pendiente y el cron de respaldo la reintenta en su siguiente corrida.
- [ ] Si el token de la cuenta conectada deja de ser válido, Configuración muestra un aviso pidiendo reconectar, y no se reintenta la sincronización de citas individuales hasta reconectar.
- [ ] Desconectar la cuenta detiene la sincronización de citas nuevas/modificadas, sin borrar los eventos que ya existían en Google Calendar.
- [ ] Ningún evento sincronizado agrega al cliente como asistente/invitado.
- [ ] La disponibilidad del plugin (SPEC 03) no se ve afectada por ningún evento del Google Calendar conectado (sincronización de un solo sentido).

---

## Decisions

- **Sí:** una sola cuenta de Google conectada a nivel negocio, no una por staff. Razón: decisión explícita del usuario — más simple de implementar/mantener, sin necesidad de guardar tokens OAuth por staff.
- **Sí:** sincronización en un solo sentido (plugin → Google), sin leer eventos de Google para afectar disponibilidad. Razón: decisión explícita del usuario; bidireccional es bastante más grande y se deja para una spec futura si hace falta.
- **Sí:** el admin elige de una lista de calendarios de la cuenta conectada, en vez de usar siempre el calendario `primary`. Razón: decisión explícita del usuario — deja usar un calendario dedicado al negocio en vez del personal del admin.
- **Sí:** sincronización inmediata (en el mismo request de crear/modificar) con un cron de respaldo para reintentar fallas. Razón: decisión explícita del usuario — buena experiencia (el evento aparece al instante) sin perder citas si la API de Google falla momentáneamente.
- **Sí:** incluir los bloqueos manuales (`status = blocked`) en la sincronización, no solo citas de clientes. Razón: decisión explícita del usuario — un bloqueo también ocupa tiempo real del negocio y debería verse reflejado en el Google Calendar.
- **Sí:** backfill de citas futuras ya existentes al conectar la cuenta por primera vez. Razón: decisión explícita del usuario — evita que el admin tenga que recrear a mano las citas que ya tenía cargadas antes de conectar Google.
- **No:** backfill de citas pasadas. Razón: decisión explícita del usuario — solo importa lo que todavía puede pasar, no el historial.
- **Sí:** reprogramar actualiza el evento existente y cancelar lo borra; `completed`/`no_show` no tocan el evento. Razón: decisión explícita del usuario — un evento de una cita que ya pasó no necesita mantenerse sincronizado activamente.
- **No:** agregar al cliente como `attendee` del evento de Google. Razón: decisión explícita del usuario — evita una segunda notificación fuera del sistema de emails que ya tiene el plugin (SPEC 07/08).
- **Sí:** `Client ID`/`Client Secret` los trae el propio administrador desde su Google Cloud Console, pegados en Configuración. Razón: el plugin no gestiona una app OAuth propia; consistente con "sin Composer" — no hay una capa intermedia (proxy OAuth) que mantener.
- **No:** cifrar los tokens en `wp_options`. Razón: no hay librería de cifrado disponible sin Composer; se acepta el mismo nivel de exposición que el resto de credenciales que ya guarda el plugin.
- **Sí:** el cron de respaldo reintenta sin límite de intentos mientras la cuenta esté `connected`, y se detiene por completo (con aviso) si el problema es de autenticación de la cuenta. Razón: decisión explícita del usuario — evita spamear reintentos contra un token roto, pero no penaliza fallas transitorias de red con un límite arbitrario.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| El backfill inicial podría hacer muchas llamadas seguidas a la Calendar API si hay muchas citas futuras cargadas, contra el rate limit de Google. | Se ejecuta de forma secuencial, no en paralelo; el volumen esperado (negocio tipo spa/salón, mismo supuesto que SPEC 23) está lejos del límite de la API. Si se vuelve un problema real, se puede paginar el backfill en una spec futura. |
| Tokens guardados en texto plano en `wp_options`. | Mismo nivel de exposición que otras credenciales ya guardadas por el plugin (ej. configuración de WooCommerce); no hay librería de cifrado disponible sin Composer. Un acceso no autorizado a la base de datos ya compromete otras credenciales igual de sensibles. |
| En un entorno local (WAMP, sin HTTPS público) el `redirect_uri` del OAuth callback puede complicar las pruebas manuales. | Google permite `http://localhost` (o `http://127.0.0.1`) como redirect URI autorizado para pruebas con clientes tipo "Web application"; en producción el sitio ya corre sobre HTTPS. |
| Una falla no relacionada con el token (ej. el calendario elegido se borró desde Google, 404) haría que el cron reintente indefinidamente sin nunca poder sincronizar esa cita. | Un 404 sobre el `calendar_id` también marca la cuenta como `error` (igual que un 401), mostrando el mismo aviso de reconexión/re-selección de calendario en Configuración, en vez de reintentar para siempre. |

---

## What is **not** in this spec

- Sincronización bidireccional (leer eventos de Google Calendar como bloqueos de disponibilidad).
- Un calendario por staff o múltiples cuentas de Google conectadas.
- Invitar al cliente como asistente del evento de Google Calendar.
- Crear/registrar la app OAuth2 en Google Cloud Console — el administrador la trae ya creada.
- Backfill de citas pasadas.
- Reintentos con backoff exponencial o límite configurable.
- Reflejar en el plugin una edición manual hecha directamente sobre un evento en Google Calendar.

Cada uno de estos, si se implementa, va en su propia spec.
