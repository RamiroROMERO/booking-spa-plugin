# SPEC 21 — Selector de colores personalizables desde el admin

> **Status:** Implementado
> **Depends on:** SPEC 05, SPEC 20
> **Date:** 2026-08-14
> **Objective:** Permitir que el administrador del sitio elija desde la página de configuración del plugin los 4 colores personalizables del widget (acento, hover del acento, borde y texto secundario), sin escribir CSS, con opción de restablecer cada uno a su valor por defecto.

---

## Por qué esta spec existe

SPEC 20 introdujo 5 custom properties CSS (`--booking-accent`, `--booking-accent-hover`, `--booking-border-color`, `--booking-border-radius`, `--booking-text-muted`) en `assets/src/frontend/style.scss`, con los valores actuales como default. Esa spec dejó explícitamente fuera de alcance un "selector de color en el admin del plugin (`SettingsPage.js`) para elegir el acento desde la UI sin escribir CSS", anotado como posible spec futura.

Hoy, la única forma de re-brandear el widget es que alguien con conocimientos de CSS escriba un override externo (Customizer → CSS adicional, o un child theme) apuntando a `.booking-plugin-widget` / `.booking-plugin-client-panel`. El plugin se instala en sitios de spas/salones con identidad de marca propia, y su dueño típico no sabe escribir CSS. Esta spec cierra ese hueco: expone los colores como controles del admin de WordPress, iguales a cualquier otro campo de `SettingsPage.js`.

---

## Scope

**In:**

- 4 `ColorPicker` de `@wordpress/components` en `SettingsPage.js` (una sección nueva), uno por cada variable de color de SPEC 20:
  - Color principal → `--booking-accent`.
  - Color hover → `--booking-accent-hover`.
  - Color de borde → `--booking-border-color`.
  - Color de texto secundario → `--booking-text-muted`.
- Un botón "Restablecer" por color, que limpia el valor en el estado local del formulario (vuelve a "sin override"). La persistencia real ocurre al presionar "Guardar configuración", igual que el resto de los campos de esa sección — no hay autosave para estos 4 campos en particular.
- 4 claves nuevas en la opción existente `booking_plugin_settings` (no se crea una option nueva): `widget_accent_color`, `widget_accent_hover_color`, `widget_border_color`, `widget_text_muted_color`. Default `''` (string vacío = sin override, se usa el default de SPEC 20).
- Sanitización server-side con `sanitize_hex_color()` en `Booking_Plugin_Settings::update_settings()`: valor vacío se acepta y persiste (mecanismo de "Restablecer"); valor no vacío inválido se descarta silenciosamente y se conserva el valor guardado anterior — mismo patrón que ya usa `notification_email` en ese método.
- `GET`/`PUT /booking-plugin/v1/settings` exponen las 4 claves nuevas sin cambios en `class-booking-rest-settings-controller.php` (ya hace passthrough genérico de lo que devuelve `Booking_Plugin_Settings`).
- Los shortcodes `[booking_widget]` y `[booking_client_panel]` localizan estos 4 valores al frontend vía un nuevo objeto `BookingPluginFrontend.branding` (`accent`, `accentHover`, `borderColor`, `textMuted` en camelCase), incluyendo solo las claves que tengan un valor no vacío guardado.
- El componente raíz de cada uno de esos shortcodes (`App.js` → `.booking-plugin-widget`; `ClientPanelApp.js` → `.booking-plugin-client-panel`) aplica esos valores como custom properties inline (`style={{ '--booking-accent': ... }}`), lo que sobrescribe el default declarado en `style.scss` solo para las variables efectivamente configuradas.
- El mismo set de 4 colores aplica por igual al widget de reserva y al panel de cliente — no hay un color distinto por componente.
- `npm run build` y verificación visual en WAMP: elegir los 4 colores desde el admin, guardar, confirmar el cambio en el widget y en el panel de cliente; restablecer uno o más y confirmar que vuelve a los defaults de SPEC 20 tras guardar.

**Out of scope (para specs futuras):**

- Selector para `--booking-border-radius`. No es un color — el pedido de esta sesión fue específicamente "selector de colores". El radio de borde sigue siendo configurable solo vía CSS externo (ya cubierto por SPEC 20).
- Colores semánticos de estado configurables (error, notice/pendiente, confirmada, cancelada/no-show). Misma razón que en SPEC 20: son indicadores convencionales; hacerlos configurables agrega riesgo de contraste roto sin necesidad planteada.
- Un concepto de marca "primario/secundario" distinto de las 4 variables ya definidas por SPEC 20 (por ejemplo, un color de fondo propio para el botón secundario en vez de compartir `--booking-border-color`). Si hace falta a futuro, es una spec aparte que además tocaría `style.scss`.
- Soporte de canal alfa/transparencia en los colores — el `ColorPicker` se configura sin alfa, siempre devuelve hex sólido.
- Vista previa en vivo del widget embebida dentro de `SettingsPage.js`. El admin verifica el resultado visitando la página real del sitio donde está el shortcode, igual que la verificación de SPEC 20.
- Paletas predefinidas o temas prearmados — cada color se elige individualmente, sin presets.
- Validación de contraste (accesibilidad) entre los colores elegidos.

---

## Data model

No se crean tablas nuevas. Se extiende la opción existente `booking_plugin_settings` (`includes/class-booking-plugin-settings.php`):

- **Nuevas claves en `get_defaults()`:** `widget_accent_color`, `widget_accent_hover_color`, `widget_border_color`, `widget_text_muted_color`, todas con default `''`.
- **`update_settings()`:** sanitiza cada una de las 4 con `sanitize_hex_color()`. Si el resultado es un hex válido (incluyendo `''`), se persiste. Si el valor entrante no es `''` y no es un hex válido, se descarta y se conserva el valor previamente guardado (no se rompe la config existente por un valor mal formado).
- **REST:** sin cambios de ruta. `GET`/`PUT /booking-plugin/v1/settings` ya hacen passthrough genérico de cualquier clave que devuelva `Booking_Plugin_Settings::get_settings()` / `::update_settings()`.
- **Nuevo objeto localizado `BookingPluginFrontend.branding`** (en ambos shortcodes, vía `wp_localize_script`), con las 4 claves en camelCase (`accent`, `accentHover`, `borderColor`, `textMuted`), incluyendo solo las que tengan valor no vacío guardado.

---

## Implementation plan

1. Extender `Booking_Plugin_Settings::get_defaults()` y `::update_settings()` con las 4 claves nuevas y su sanitización vía `sanitize_hex_color()`, con fallback al valor previamente guardado si el nuevo valor es inválido y no vacío.
2. Verificar manualmente en WAMP (vía `GET`/`PUT /booking-plugin/v1/settings` autenticado) que las 4 claves nuevas viajan ida y vuelta correctamente, incluyendo el caso de restablecer a `''`.
3. En `includes/class-booking-plugin-shortcode.php` (el shortcode del widget no llama hoy a `Booking_Plugin_Settings::get_settings()`) y en `includes/class-booking-plugin-client-panel-shortcode.php` (que ya la llama para `minCancellationHours`), agregar la clave `branding` al array pasado a `wp_localize_script( 'booking-plugin-frontend', 'BookingPluginFrontend', ... )`, con las 4 claves en camelCase, incluyendo solo las no vacías.
4. En `assets/src/frontend/App.js`, calcular un objeto de estilo inline a partir de `window.BookingPluginFrontend.branding` y aplicarlo en el `<div className="booking-plugin-widget">` raíz.
5. En `assets/src/frontend/client-panel/ClientPanelApp.js`, aplicar el mismo cálculo de estilo inline en los `<div className="booking-plugin-client-panel">` raíz (tanto en el estado de carga/error como en el estado normal).
6. En `assets/src/admin/pages/SettingsPage.js`, agregar una sección nueva ("Colores del widget") con 4 `ColorPicker` de `@wordpress/components` (uno por variable) y un botón "Restablecer" por color que limpia el valor en el estado local del formulario. Se guarda junto con el resto de "Guardar configuración".
7. `npm run build` y verificación visual en WAMP: elegir los 4 colores desde el admin, guardar, confirmar que el widget de reserva (`[booking_widget]`) y el panel de cliente (`[booking_client_panel]`) reflejan esos colores; restablecer uno o más colores, guardar, y confirmar que cada uno vuelve al default de SPEC 20.

---

## Acceptance criteria

- [ ] `booking_plugin_settings` expone 4 claves nuevas (`widget_accent_color`, `widget_accent_hover_color`, `widget_border_color`, `widget_text_muted_color`) con default `''`.
- [ ] `PUT /booking-plugin/v1/settings` acepta las 4 claves nuevas, las sanitiza con `sanitize_hex_color()`, y descarta silenciosamente valores no vacíos inválidos (mantiene el valor anterior).
- [ ] `SettingsPage.js` muestra 4 `ColorPicker` (uno por variable) con un botón "Restablecer" cada uno, integrados al flujo existente de "Guardar configuración".
- [ ] Al guardar colores desde el admin y visitar una página con `[booking_widget]`, el widget refleja esos 4 colores (acento, hover, borde, texto secundario) sin tocar CSS.
- [ ] Lo mismo aplica a `[booking_client_panel]`, con el mismo set de colores que el widget.
- [ ] Al restablecer un color y guardar, el elemento correspondiente vuelve al valor default definido en `style.scss` por SPEC 20.
- [ ] Los colores semánticos de estado y `--booking-border-radius` no son configurables desde este selector — siguen igual que en SPEC 20.
- [ ] El resto del panel de administración (fuera de la nueva sección de colores) no cambia visualmente.

---

## Decisions

- **Sí:** 4 variables de color (acento, hover, borde, texto secundario) — no un concepto nuevo de "primario/secundario" distinto de las variables ya definidas por SPEC 20. Razón: aclaración explícita del usuario en esta sesión — "primary/secondary" se refería a las variables ya existentes, no a un sistema de marca de 2 colores que requeriría tocar `style.scss` otra vez.
- **Sí:** mismo set de colores para el widget de reserva y el panel de cliente, sin selector separado por componente. Razón: decisión explícita del usuario; coincide con cómo SPEC 20 declaró las variables (branding de sitio, no por componente).
- **Sí:** `ColorPicker` de `@wordpress/components` en vez de `<input type="color">` nativo. Razón: decisión explícita del usuario; consistencia visual con el resto de wp-admin.
- **Sí:** botón "Restablecer" explícito por color, que limpia el override guardado. Razón: decisión explícita del usuario; más claro para un usuario no técnico que vaciar un `ColorPicker` sin una acción dedicada para eso.
- **Sí:** "Restablecer" solo limpia el estado local del formulario — la persistencia sigue ocurriendo al presionar "Guardar configuración", igual que cualquier otro campo de esa sección. Razón: consistencia con el patrón de guardado por lote que ya tiene `SettingsPage.js`; evita un segundo mecanismo de guardado (autosave parcial) solo para estos 4 campos.
- **No:** selector de `--booking-border-radius` desde el admin. Razón: no es un color — el pedido de esta sesión fue específicamente "selector de colores"; el radio de borde sigue accesible solo vía CSS externo, ya cubierto por SPEC 20.
- **No:** colores semánticos de estado configurables. Razón: hereda la misma justificación de SPEC 20 — son indicadores convencionales; hacerlos configurables agrega riesgo de contraste roto sin necesidad planteada.
- **No:** soporte de canal alfa/transparencia. Razón: evita combinaciones rotas cuando alguna de estas variables se usa como color de texto o fondo sólido en el CSS existente.
- **No:** vista previa en vivo embebida en el admin. Razón: fuera del pedido de esta sesión; el admin verifica visitando la página real del sitio, igual que la verificación de SPEC 20.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| Un admin sin criterio de diseño elige un color de acento con bajo contraste sobre fondo blanco (ej. amarillo claro), volviendo poco legible texto o botones que usan `--booking-accent` como color de texto o fondo. | No se valida contraste automáticamente (fuera de alcance). El botón "Restablecer" por color permite deshacer fácilmente sin tocar CSS ni perder el resto de la configuración guardada. |
| `class-booking-plugin-shortcode.php` (widget) no llama hoy a `Booking_Plugin_Settings::get_settings()` — agregar esa llamada en `maybe_enqueue_assets()` (que ya corre en cada carga de página con el shortcode) suma una lectura de `wp_options` por request. | `get_option()` usa el object cache de WordPress; el panel de cliente ya hace esta misma llamada en su propio shortcode sin impacto documentado. |

---

## What is **not** in this spec

- Selector de `--booking-border-radius`.
- Colores semánticos de estado configurables.
- Un color "secundario" nuevo distinto de las 4 variables ya definidas por SPEC 20.
- Canal alfa/transparencia en los colores.
- Vista previa en vivo dentro del admin.
- Paletas predefinidas / temas prearmados.
- Validación de contraste/accesibilidad.

Cada uno de estos, si se implementa, va en su propia spec.
