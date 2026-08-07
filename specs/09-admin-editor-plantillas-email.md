# SPEC 09 — Admin SPA: Editor de Plantillas de Email

> **Status:** Aprovada
> **Depends on:** SPEC 04, SPEC 05, SPEC 08
> **Date:** 2026-08-07
> **Objective:** Construir la página "Notificaciones" del admin (submenú bajo "Reservas") donde el negocio edita el asunto y cuerpo con formato enriquecido de las 5 plantillas de correo de SPEC 08, con vista previa y envío de prueba, usando `@wordpress/rich-text` sobre la misma base de React del SPA.

---

## Por qué esta spec existe

SPEC 08 construyó el motor de envío y la API de plantillas (`GET`/`PUT /email-templates`), pero dejó el editor real para esta spec porque el requisito de texto enriquecido (negrita, enlaces) es un dominio de UI aparte. La alternativa clásica de WordPress para esto es `wp_editor()` (TinyMCE), pero integrarlo dentro del árbol de React del SPA reabriría el mismo riesgo de instancias duplicadas ya resuelto en SPEC 04 (`@wordpress/element` en todo el SPA, sin dependencias de React externas). En su lugar, esta spec usa `@wordpress/rich-text` + `@wordpress/format-library` — el mismo motor de edición que usa el editor de bloques de WordPress — que se integra de forma nativa sobre `@wordpress/element` sin ese conflicto.

---

## Scope

**In:**

- Submenú **Notificaciones** bajo "Reservas", junto a Calendario/Servicios/Staff/Configuración (`manage_options`).
- Lista de las 5 plantillas (confirmación cliente, recordatorio cliente, cancelación cliente, nueva reserva negocio, cancelación negocio) con su estado: "Personalizada" o "Predeterminada".
- Editor por plantilla: campo de asunto (texto plano) + cuerpo con editor de texto enriquecido (negrita, cursiva, enlace) construido con `@wordpress/rich-text` + `@wordpress/format-library`.
- Referencia de los placeholders disponibles para esa plantilla específica, mostrada como texto de ayuda junto al editor (sin botón de inserción automática).
- Vista previa: renderiza el asunto y cuerpo actuales (sin necesidad de guardar primero) con datos de ejemplo, en una caja con estilo de email.
- **Enviar correo de prueba**: envía el contenido en edición (guardado o no) a la cuenta del administrador actual, extendiendo la API de SPEC 08 con `POST /email-templates/test-send`.
- **Restaurar predeterminado** por plantilla (con confirmación), que descarta el override guardado y vuelve al texto definido en código (SPEC 08).
- Guardado (`PUT /email-templates`, SPEC 08) con indicador de éxito/error.

**Out of scope (para specs futuras):**

- Botón de inserción automática de placeholders en el cursor.
- Multi-idioma de plantillas.
- Adjuntos o imágenes embebidas en el cuerpo del correo.
- Historial de versiones de una plantilla (solo estado actual + default).
- Integración SMTP con proveedores externos (SPEC 08).

---

## Data model

No se crean tablas nuevas. Se extiende la API REST de SPEC 08 con una ruta adicional:

```
POST /wp-json/booking-plugin/v1/email-templates/test-send   (manage_options)
Body: { "template_key": "client_confirmation", "subject": "...", "body": "..." }
Response: { "sent": true } | WP_Error si wp_mail() falla
```

Envía el contenido recibido en el body (no necesariamente el guardado) con datos de ejemplo, al email de `wp_get_current_user()`. Reutiliza `Booking_Plugin_Notifications` (SPEC 08) para resolver placeholders y llamar a `wp_mail()`.

```js
// Estado de la página (assets/src/admin/pages/NotificationsPage.js)
{
  templates: {
    client_confirmation: { subject: '...', body: '...', isCustomized: true },
    client_reminder:     { subject: '...', body: '...', isCustomized: false },
    // ...
  },
  editingKey: 'client_confirmation' | null,
  previewVisible: false,
}
```

---

## Implementation plan

1. Extender `includes/rest/class-booking-rest-email-templates-controller.php` (SPEC 08) con `POST /email-templates/test-send`. Prueba manual: enviarlo vía Postman y confirmar que llega el correo con los datos de ejemplo.
2. Editar `includes/class-booking-plugin-admin.php` (SPEC 04/05) para registrar el submenú "Notificaciones".
3. Crear `assets/src/admin/pages/NotificationsPage.js`: lista las 5 plantillas con su estado personalizada/predeterminada.
4. Crear `assets/src/admin/components/RichTextBody.js`: campo de cuerpo sobre `@wordpress/rich-text`, con una barra de herramientas mínima (negrita, cursiva, enlace) vía `@wordpress/format-library`.
5. Crear `TemplateEditor.js`: asunto (texto plano) + `RichTextBody` + lista de placeholders disponibles para esa plantilla + botones Guardar / Restaurar predeterminado / Enviar prueba.
6. Crear `TemplatePreview.js`: renderiza asunto+cuerpo actuales con datos de ejemplo, en una caja con estilo de email (sin llamar a la API).
7. Crear el hook `useEmailTemplates.js` (`GET`/`PUT /email-templates`) y conectar "Enviar prueba" a `POST /email-templates/test-send`.
8. Prueba manual end-to-end: `npm run build`; editar la plantilla de confirmación aplicando negrita y un enlace, agregar manualmente un placeholder documentado, guardar; usar "Enviar prueba" y confirmar el formato en un correo real; crear una cita real (SPEC 06) y confirmar que el correo de confirmación usa el contenido personalizado, no el default; "Restaurar predeterminado" y confirmar que vuelve al texto original.

---

## Acceptance criteria

- [ ] El submenú "Notificaciones" aparece en "Reservas" junto a Calendario/Servicios/Staff/Configuración y requiere `manage_options`.
- [ ] Las 5 plantillas se listan con su estado correcto (Personalizada/Predeterminada).
- [ ] El editor de cuerpo permite aplicar negrita, cursiva y agregar un enlace; el HTML resultante se guarda tal como se editó.
- [ ] Cada plantilla muestra únicamente los placeholders relevantes para ese caso (ej. las plantillas de negocio incluyen datos de contacto del cliente; las del cliente no se autorreferencian).
- [ ] La vista previa refleja el asunto y cuerpo actuales en edición, incluso sin haber guardado todavía.
- [ ] "Enviar correo de prueba" hace llegar un correo real a la cuenta del administrador actual con el contenido en edición.
- [ ] "Restaurar predeterminado" descarta el override guardado y vuelve a mostrar el texto original de SPEC 08.
- [ ] Guardar una plantilla personalizada hace que la siguiente cita real creada reciba el correo con ese contenido, no el default (verificable contra los disparadores de SPEC 08).

---

## Decisions

- **Sí:** editor de cuerpo con `@wordpress/rich-text` + `@wordpress/format-library`, no `wp_editor()`/TinyMCE. Razón: se apoya en la misma base `@wordpress/element` que ya usa todo el SPA (SPEC 04), evitando el riesgo de instancias duplicadas de React ya identificado; es el mismo motor que usa el editor de bloques de WordPress, maduro y probado para este caso de uso.
- **Sí:** placeholders como referencia de texto (sin botón de inserción automática). Razón: mantiene el alcance acotado; escribir `{{client_name}}` a mano es una fricción aceptable para el MVP.
- **Sí:** "Enviar correo de prueba" incluido en esta spec, extendiendo la API de SPEC 08. Razón: cierra directamente el riesgo que SPEC 08 dejó documentado ("un placeholder mal escrito no se detecta hasta el primer envío real"); costo bajo porque reutiliza las funciones de envío ya construidas ahí.
- **Sí:** vista previa con datos de ejemplo (mock), no con una cita real de la base de datos. Razón: evita exponer accidentalmente datos personales de un cliente real durante la edición.
- **No:** botón de inserción automática de placeholders en el cursor. Razón: complejidad adicional de manejo de selección en `@wordpress/rich-text` sin beneficio suficiente para el MVP.
- **No:** multi-idioma, adjuntos, ni historial de versiones de plantillas. Razón: fuera de alcance, sin necesidad confirmada.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| `@wordpress/rich-text` es una API de más bajo nivel que un editor "todo incluido"; arma la barra de herramientas a mano. | Alcance acotado explícitamente a negrita/cursiva/enlace, sin abrir la puerta a un editor de formato libre tipo Gutenberg completo. |
| Un admin podría borrar por error un placeholder crítico (ej. `{{manage_url}}` en la cancelación del cliente) sin que el sistema lo bloquee. | Decisión consciente de no forzar placeholders obligatorios; "Enviar correo de prueba" ayuda a detectarlo antes de que afecte a un cliente real. |

---

## What is **not** in this spec

- Inserción automática de placeholders.
- Multi-idioma de plantillas.
- Adjuntos e imágenes embebidas en el cuerpo del correo.
- Historial de versiones de plantillas.
- Integración SMTP con proveedores externos (SPEC 08).

Cada uno de estos, si se implementa, va en su propia spec.
