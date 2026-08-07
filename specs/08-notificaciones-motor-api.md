# SPEC 08 — Notificaciones: Motor de Envío y API de Plantillas

> **Status:** Implementado
> **Depends on:** SPEC 01, SPEC 03, SPEC 04
> **Date:** 2026-08-07
> **Objective:** Construir el motor de notificaciones por email (confirmación inmediata, recordatorio 24h vía barrido de WP-Cron, y avisos de cancelación) para cliente y negocio, disparado por action hooks sobre los cambios de estado de citas de SPEC 03/04, con plantillas almacenadas y expuestas vía API para que SPEC 09 las edite.

---

## Por qué esta spec existe

El documento base pide "Envío de correos de confirmación, recordatorios 24h antes (para reducir el ausentismo) y avisos de cancelación" conectando `wp_mail()` a los cambios de estado de la cita. Esta spec construye ese motor y la API de plantillas; el editor visual para que el dueño del negocio las redacte sin tocar código queda en SPEC 09, siguiendo el mismo patrón API→UI usado en el resto del proyecto (SPEC 02→04/05, SPEC 03→04/06/07).

---

## Scope

**In:**

- Correo de **confirmación** al cliente, enviado inmediatamente al crear la cita (`POST /appointments`, SPEC 03), sin esperar a que un admin la confirme.
- Correo de **nueva reserva** al negocio (dirección de notificaciones configurable), en el mismo momento.
- Correo de **recordatorio** al cliente, ~24h antes de la cita, vía un barrido periódico de WP-Cron.
- Correo de **cancelación** al cliente y al negocio, cuando una cita pasa a `status='cancelled'` (sin importar quién la canceló: admin, cliente logueado o invitado con token).
- Exclusión explícita de bloqueos manuales (`status='blocked'`, SPEC 04) de cualquier disparador de email.
- 5 plantillas administrables (confirmación cliente, recordatorio cliente, cancelación cliente, nueva reserva negocio, cancelación negocio), con placeholders (`{{client_name}}`, `{{service_name}}`, `{{staff_name}}`, `{{date}}`, `{{time}}`, `{{business_name}}`, `{{manage_url}}`), almacenadas en `wp_options` con fallback a defaults definidos en código.
- Endpoints `GET`/`PUT /email-templates` (`manage_options`) para leer/guardar esas plantillas.
- Dirección de notificaciones del negocio (`notification_email`) como campo nuevo dentro de la opción de configuración ya creada en SPEC 03 (`booking_plugin_settings`), con default `get_option('admin_email')`.
- Migración aditiva: columna `reminder_sent_at` en `wp_booking_appointments`, para no reenviar el recordatorio dos veces.
- Disparadores implementados como WordPress action hooks (`booking_plugin_appointment_created`, `booking_plugin_appointment_cancelled`) sobre el controlador de citas de SPEC 03/04, desacoplando el envío de correos de la lógica de creación/cancelación.
- Manejo de fallos de `wp_mail()`: se registra en el log de errores, nunca bloquea la respuesta HTTP de la operación que lo disparó.

**Out of scope (para specs futuras):**

- Editor visual de plantillas en el admin (rich text) — SPEC 09.
- Integración con proveedores SMTP transaccionales (SendGrid, Mailgun, etc.) — se asume `wp_mail()` funcional o un plugin SMTP ya configurado aparte en el hosting.
- Notificaciones por SMS o push.
- Recordatorios en intervalos configurables distintos a 24h fijas.
- Notificación al staff asignado (solo cliente y negocio por ahora).

---

## Data model

```sql
-- Migración aditiva sobre wp_booking_appointments (SPEC 01), vía dbDelta + bump de BOOKING_PLUGIN_DB_VERSION
ALTER TABLE {$wpdb->prefix}booking_appointments
  ADD COLUMN reminder_sent_at DATETIME NULL;
```

```js
// Opción wp_options: booking_plugin_email_templates (array asociativo serializado)
{
  "client_confirmation": { "subject": "...", "body": "..." },
  "client_reminder":     { "subject": "...", "body": "..." },
  "client_cancellation": { "subject": "...", "body": "..." },
  "admin_new_booking":   { "subject": "...", "body": "..." },
  "admin_cancellation":  { "subject": "...", "body": "..." }
}
// Si una clave no existe todavía, se usa el default definido en
// includes/emails/class-booking-plugin-email-templates.php

// booking_plugin_settings (SPEC 03) se extiende con:
{
  ...campos existentes de SPEC 03,
  "notification_email": "admin@example.com"   // default: get_option('admin_email')
}
```

### Rutas

```
GET  /wp-json/booking-plugin/v1/email-templates   (manage_options)
PUT  /wp-json/booking-plugin/v1/email-templates   (manage_options)  reemplazo completo de las 5 plantillas
```

### Hooks disparadores

```php
// Disparado dentro de POST /appointments tras un INSERT exitoso con status != 'blocked'
do_action( 'booking_plugin_appointment_created', $appointment );

// Disparado dentro de PATCH /appointments/{id} cuando el nuevo status es 'cancelled'
// y el status anterior no era ya 'cancelled' (evita disparos duplicados)
do_action( 'booking_plugin_appointment_cancelled', $appointment );
```

Convención: los placeholders se resuelven con los datos de la cita, su servicio y su staff en el momento del envío; el cuerpo guardado se procesa con `wpautop()` antes de enviarse como HTML (`Content-Type: text/html` en `wp_mail()`).

---

## Implementation plan

1. Editar `includes/class-booking-plugin-db-schema.php` para agregar `reminder_sent_at` a `wp_booking_appointments`, y subir `BOOKING_PLUGIN_DB_VERSION`. Prueba manual: confirmar la columna tras recargar el admin.
2. Crear `includes/emails/class-booking-plugin-email-templates.php`: defaults de las 5 plantillas y `get_template( $key )` / `get_all_templates()` (override de `wp_options` o default).
3. Crear `includes/rest/class-booking-rest-email-templates-controller.php` con `GET`/`PUT /email-templates`.
4. Editar `includes/class-booking-plugin-settings.php` (SPEC 03) para incluir `notification_email` con su default.
5. Crear `includes/class-booking-plugin-notifications.php`: resuelve placeholders y expone `send_client_confirmation()`, `send_client_reminder()`, `send_client_cancellation()`, `send_admin_new_booking()`, `send_admin_cancellation()`, todos sobre `wp_mail()`, registrando en el log si falla sin lanzar excepción.
6. Editar `includes/rest/class-booking-rest-appointments-controller.php` (SPEC 03/04) para disparar `booking_plugin_appointment_created` y `booking_plugin_appointment_cancelled` en los puntos correspondientes (excluyendo `status='blocked'`), y registrar los listeners de `Booking_Plugin_Notifications` sobre esos hooks desde `includes/class-booking-plugin.php`. Prueba manual: crear y cancelar una cita de prueba, confirmar (con un capturador de correos local) que llegan los 4 correos esperados (2 en creación, 2 en cancelación).
7. Crear `includes/class-booking-plugin-reminder-cron.php`: registra el evento recurrente `booking_plugin_reminder_sweep` (cada 30 min) en la activación (extiende `Booking_Plugin_Activator` de SPEC 01) y lo desprograma en la desactivación (extiende `Booking_Plugin_Deactivator`); el callback busca citas con `start_datetime` entre ahora+23h y ahora+25h, `status` en (`pending`, `confirmed`) y `reminder_sent_at IS NULL`, envía el recordatorio y marca `reminder_sent_at`.
8. Prueba manual end-to-end: adelantar manualmente el `start_datetime` de una cita de prueba a ~24h desde ahora, disparar el barrido manualmente (`do_action('booking_plugin_reminder_sweep')`), confirmar el envío del recordatorio y que `reminder_sent_at` queda seteado; correr el barrido de nuevo y confirmar que no se reenvía.

---

## Acceptance criteria

- [ ] Crear una cita dispara un correo de confirmación al cliente y uno de nueva reserva a `notification_email`.
- [ ] Cancelar una cita (por cualquier vía: admin, cliente logueado, invitado con token) dispara un correo de cancelación al cliente y uno a `notification_email`.
- [ ] Un bloqueo manual (`status='blocked'`) no dispara ningún correo.
- [ ] El barrido de recordatorio solo envía el correo a citas dentro de la ventana de ~24h configurada y con `reminder_sent_at IS NULL`.
- [ ] Ejecutar el barrido dos veces seguidas no envía el recordatorio dos veces para la misma cita.
- [ ] `GET /email-templates` devuelve las 5 plantillas con sus valores por defecto si nunca se guardó una versión personalizada.
- [ ] `PUT /email-templates` guarda una plantilla personalizada y una llamada posterior a `GET /email-templates` la refleja.
- [ ] Los placeholders se reemplazan correctamente por los datos reales de la cita en el correo enviado.
- [ ] Forzar un fallo de `wp_mail()` (ej. con un filtro de prueba) no impide que `POST /appointments` o el `PATCH` de cancelación respondan exitosamente.
- [ ] Desactivar el plugin desprograma el evento de WP-Cron del recordatorio (verificable con `wp_next_scheduled('booking_plugin_reminder_sweep')`).

---

## Decisions

- **Sí:** dividir Notificaciones en motor+API (esta spec) y editor visual (SPEC 09). Razón: el editor de texto enriquecido decidido por el usuario amplía el alcance lo suficiente como para seguir el mismo patrón API→UI ya usado en todo el proyecto.
- **Sí:** correo de confirmación inmediato al crear la cita, no al pasar a `confirmed`. Razón: decisión explícita del usuario; patrón estándar de sistemas de reservas online (confirma que la solicitud llegó, no que el negocio ya la aprobó).
- **Sí:** el negocio recibe notificación de nueva reserva y de cancelación. Razón: decisión explícita del usuario; evita que el negocio dependa de revisar el calendario constantemente.
- **Sí:** barrido periódico de WP-Cron (cada 30 min) en vez de eventos únicos programados por cita. Razón: decisión explícita del usuario; patrón estándar en plugins WP, evita la complejidad de programar/desprogramar miles de eventos individuales al cancelar/reprogramar.
- **Sí:** plantillas en `wp_options` con fallback a defaults en PHP, expuestas vía REST desde esta spec aunque el editor llegue en SPEC 09. Razón: separa la capa de datos/API de la capa de UI, consistente con el resto del proyecto.
- **Sí:** disparadores como action hooks (`booking_plugin_appointment_created`/`_cancelled`) en vez de llamadas directas desde los controladores REST. Razón: desacopla SPEC 03/04 (que no necesitan saber de emails) de esta spec.
- **Sí:** los bloqueos manuales quedan explícitamente excluidos de todo disparador. Razón: no son citas de cliente, no tiene sentido notificar nada sobre ellos.
- **Sí:** fallo de `wp_mail()` se loggea pero nunca bloquea la respuesta HTTP de la operación. Razón: un problema de configuración de correo del hosting no debe impedir que el negocio siga operando.
- **No:** editor de plantillas en el admin en esta spec. Razón: movido a SPEC 09 por el alcance del editor de texto enriquecido.
- **No:** integración SMTP con proveedores externos. Razón: fuera de alcance; se asume `wp_mail()` funcional o un plugin SMTP externo ya configurado en el hosting.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| `wp_mail()` en muchos hostings compartidos termina en spam o falla silenciosamente sin un plugin SMTP configurado aparte. | Cada fallo se registra en el log de errores para diagnóstico; se documenta como limitación conocida de `wp_mail()`, fuera del alcance de esta spec resolverla con una integración SMTP propia. |
| El barrido de recordatorio depende de que WP-Cron se dispare, lo cual está ligado a las visitas al sitio en la configuración por defecto de WordPress; en sitios de bajo tráfico el recordatorio podría enviarse con retraso. | Documentado como limitación conocida de WP-Cron; una instalación real en producción debería configurar un cron de servidor apuntando a `wp-cron.php`, fuera del alcance de este plugin. |
| Editar el texto de una plantilla sin previsualización (hasta que exista SPEC 09) podría dejar un placeholder mal escrito sin detectarlo hasta el primer envío real. | `PUT /email-templates` valida que el cuerpo no quede vacío; la validación de placeholders válidos y la previsualización quedan para el editor de SPEC 09. |

---

## What is **not** in this spec

- Editor visual de plantillas en el admin (SPEC 09).
- Integración con proveedores SMTP externos.
- Notificaciones por SMS o push.
- Recordatorios en intervalos configurables distintos a 24h.
- Notificación al staff asignado.

Cada uno de estos, si se implementa, va en su propia spec.
