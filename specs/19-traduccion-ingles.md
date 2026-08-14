# SPEC 19 — Traducción del Plugin a Inglés

> **Status:** Implementado
> **Depends on:** SPEC 01, SPEC 04, SPEC 05, SPEC 06, SPEC 07, SPEC 09
> **Date:** 2026-08-14
> **Objective:** Completar la internacionalización del plugin (cerrar gaps de strings sin envolver, activar la carga de traducciones en el JS, y generar el catálogo real en inglés) para que el plugin se use completamente en inglés o español según el idioma del sitio WordPress.

---

## Por qué esta spec existe

Al revisar el código antes de escribir esta spec se encontró que el plugin **ya tiene buena parte de la infraestructura de i18n hecha**: 178 usos de `__()`/`_e()` en PHP y 447 usos de `__()` de `@wordpress/i18n` en JS, todos con el text domain `booking-plugin`, y `load_plugin_textdomain()` ya registrado en `class-booking-plugin.php`. El texto fuente de esos strings ya está en **español** (ej. `__( 'Configuración', 'booking-plugin' )`), así que el español ya funciona hoy "gratis", sin ningún archivo de traducción.

Lo que falta no es envolver todo desde cero, sino tres cosas concretas: (1) auditar y cerrar los gaps de strings que puedan haber quedado sin envolver, (2) registrar `wp_set_script_translations()` — hoy ausente en los tres puntos donde se hace `wp_enqueue_script()`, por lo que el JS no puede cargar ninguna traducción aunque ya use `__()` — y (3) generar el catálogo real en **inglés** (no en español, que es el idioma fuente) con archivos `.po`/`.mo`/`.json` en una carpeta `languages/` que hoy no existe. No hay WP-CLI ni `msgfmt` instalados localmente, así que el plan usa `wp-cli.phar` de forma puntual sobre el `php.exe` de WAMP ya usado en las pruebas e2e del proyecto.

---

## Scope

**In:**

- Auditoría de strings de UI fija (labels, títulos, botones, mensajes de error, placeholders) en `includes/` y `assets/src/` que falten envolver en `__()`/`_e()`/`esc_html__()` (PHP) o `__()` de `@wordpress/i18n` (JS) con el text domain `booking-plugin`; envolver los que falten.
- La UI fija del editor de plantillas de email (SPEC 09) entra en la auditoría (botones, labels del editor) — **no** el contenido de las plantillas en sí (asunto/cuerpo), que ya es texto libre editado por el admin.
- Carpeta `languages/` nueva en la raíz del plugin.
- `languages/booking-plugin.pot` generado combinando extracción de PHP y JS, vía `wp i18n make-pot` (WP-CLI).
- `languages/booking-plugin-en_US.po` con traducción real al inglés de cada string (no placeholders vacíos), compilado a `languages/booking-plugin-en_US.mo`.
- JSON de traducción por script JS (`languages/booking-plugin-en_US-{hash}.json`) vía `wp i18n make-json`, para los bundles `admin.js` y `frontend.js`.
- `wp_set_script_translations( 'booking-plugin-admin', 'booking-plugin', BOOKING_PLUGIN_DIR . 'languages' )` agregado junto al `wp_enqueue_script()` existente en `includes/class-booking-plugin-admin.php`.
- `wp_set_script_translations( 'booking-plugin-frontend', 'booking-plugin', BOOKING_PLUGIN_DIR . 'languages' )` agregado junto a los dos `wp_enqueue_script()` existentes del handle `booking-plugin-frontend` (`includes/class-booking-plugin-shortcode.php` e `includes/class-booking-plugin-client-panel-shortcode.php`).
- El idioma se decide automáticamente por el locale del sitio WordPress (`get_locale()`), sin selector propio del plugin: si el sitio está en inglés (`en_US` o cualquier otro `en_*`), se carga la traducción; en español (o cualquier locale sin traducción) se usa el texto fuente en español ya presente en el código, sin necesitar ningún archivo adicional.
- Verificación manual end-to-end en WAMP: cambiar el idioma del sitio a inglés y confirmar que admin, widget público, panel de cliente, y el asunto/textos fijos del sistema en las notificaciones por email se muestran en inglés; volver a español y confirmar que no cambia nada respecto al comportamiento actual.

**Out of scope (para specs futuras):**

- Traducir el contenido de las plantillas de email editables por el admin (SPEC 09) — sigue siendo texto libre en el idioma que el admin escriba.
- Selector de idioma propio del plugin, independiente del locale del sitio.
- Idiomas adicionales a español/inglés (ej. portugués) — el mecanismo queda listo para agregar otro `.po`/`.mo` después de la misma forma.
- Traducción de datos ingresados por el usuario (nombres de servicios, staff, categorías, notas de citas) — no es texto de UI del plugin, es contenido de negocio del sitio.
- Integración con `translate.wordpress.org`/GlotPress — el plugin no está en el repositorio oficial de WordPress.
- Instalar WP-CLI de forma permanente en el entorno o como parte del build — se usa `wp-cli.phar` puntualmente solo para generar los archivos de esta spec.

---

## Data model

Esta spec no agrega tablas ni columnas nuevas. Agrega archivos nuevos en el propio plugin (no en la base de datos):

```
languages/
  booking-plugin.pot                          # catálogo completo (PHP + JS), fuente de verdad
  booking-plugin-en_US.po                      # traducción al inglés (editable, versionado en el repo)
  booking-plugin-en_US.mo                      # compilado, usado por load_plugin_textdomain() (PHP)
  booking-plugin-en_US-{hash}.json             # uno por script (admin.js, frontend.js), usado por wp_set_script_translations() (JS)
```

Convención: el `{hash}` de cada JSON es el que genera `wp i18n make-json` a partir de la referencia de origen del string en el `.po` (`assets/build/admin.js`, `assets/build/frontend.js`) — es el nombre exacto que WordPress busca en runtime a partir del handle del script; si no coincide, la traducción JS falla en silencio (ver Riesgos).

---

## Implementation plan

1. Auditoría de `includes/` y `assets/src/`: buscar strings de UI fija (labels, títulos, botones, mensajes de error/confirmación, placeholders, `alert`/`confirm` de JS) que no estén envueltos en `__()`/`_e()`/`esc_html__()` (PHP) o `__()` de `@wordpress/i18n` (JS) con el text domain `booking-plugin`, y envolverlos. El texto fuente sigue en español; el plugin queda funcionalmente idéntico a hoy en este paso.
2. Descargar `wp-cli.phar` a una carpeta de herramientas local no versionada (ej. `tools/`, agregada a `.gitignore`) y confirmar que corre con el `php.exe` de WAMP.
3. Correr `wp i18n make-pot . languages/booking-plugin.pot --domain=booking-plugin` (excluyendo `node_modules/`, `assets/src/`, `specs/`) para generar el catálogo completo a partir de `includes/` y `assets/build/` (JS ya compilado).
4. Crear `languages/booking-plugin-en_US.po` a partir del `.pot`, traduciendo cada `msgid` (español) a su `msgstr` en inglés real.
5. Compilar: `wp i18n make-mo languages/` (genera el `.mo`) y `wp i18n make-json languages/booking-plugin-en_US.po --no-purge` (genera el/los JSON por script).
6. Editar `includes/class-booking-plugin-admin.php`, `includes/class-booking-plugin-shortcode.php` e `includes/class-booking-plugin-client-panel-shortcode.php`: agregar `wp_set_script_translations()` inmediatamente después de cada `wp_enqueue_script()` existente, apuntando a `BOOKING_PLUGIN_DIR . 'languages'`.
7. Prueba manual end-to-end en WAMP: cambiar Ajustes → General → Idioma del sitio a English (United States) e instalar el paquete de idioma de WordPress si lo pide; verificar en inglés el panel de admin completo (calendario, servicios, staff, configuración, nómina, saldos pendientes, paquetes), el widget público (`booking_widget`), el panel de cliente (`booking_client_panel`) y el asunto/textos fijos de una notificación por email de prueba; volver el idioma del sitio a español y confirmar que el comportamiento es idéntico al actual (sin archivo adicional necesario).

---

## Acceptance criteria

- [x] `languages/booking-plugin.pot` existe y contiene strings extraídos tanto de PHP como de JS.
- [x] `languages/booking-plugin-en_US.po` y `.mo` existen con traducciones reales al inglés (no vacías) para cada string de UI fija.
- [x] Existen los JSON de traducción JS correspondientes a `admin.js` y `frontend.js` en `languages/`.
- [x] `wp_set_script_translations()` está registrado para los handles `booking-plugin-admin` y `booking-plugin-frontend`.
- [x] Con el sitio WordPress en inglés, el panel de admin completo se muestra en inglés.
- [x] Con el sitio en inglés, el widget público de reserva se muestra en inglés.
- [x] Con el sitio en inglés, el panel de autoservicio del cliente se muestra en inglés.
- [x] Con el sitio en inglés, el asunto y los textos fijos del sistema en las notificaciones por email se muestran en inglés (no el contenido editable de las plantillas, SPEC 09).
- [x] Con el sitio en español (o cualquier locale sin traducción cargada), el plugin se comporta igual que hoy — texto fuente en español, sin ningún archivo adicional necesario.
- [x] No queda ningún string de UI fija (label, botón, mensaje de error, título) sin envolver con el text domain `booking-plugin`, salvo el contenido editable de plantillas de email (SPEC 09) y datos ingresados por el usuario.

---

## Decisions

- **Sí:** el idioma sigue el locale del sitio WordPress (`get_locale()`), sin selector propio del plugin. Razón: decisión explícita del usuario; es el patrón estándar de i18n de WordPress y no duplica lógica de idioma ni agrega un ajuste nuevo.
- **Sí:** el español sigue siendo el texto fuente del código — no requiere archivo de traducción propio. El trabajo real de esta spec es generar el catálogo en **inglés**. Razón: se detectó al revisar el código que `__()`/`_e()` ya se usan con strings en español; confirmado con el usuario antes de escribir el resto de la spec.
- **Sí:** se usa `wp-cli.phar` de forma puntual (no instalado permanentemente) para generar y compilar los archivos de traducción, corriendo sobre el `php.exe` de WAMP ya usado para las pruebas e2e del proyecto. Razón: no hay WP-CLI ni `msgfmt` instalados localmente; `wp-cli.phar` es un único archivo, no requiere instalación, y es la herramienta estándar de WordPress para esto.
- **Sí:** se traduce también la UI fija del editor de plantillas de email (SPEC 09), pero no el contenido de las plantillas en sí. Razón: decisión explícita del usuario — el contenido de las plantillas ya es texto libre editado por el admin, no strings de código.
- **No:** selector de idioma independiente del sitio. Razón: fuera del alcance pedido; agregaría un ajuste nuevo y lógica de idioma duplicada sin necesidad real.
- **No:** traducir a idiomas más allá de español/inglés. Razón: fuera del pedido original; el mecanismo (`languages/` + text domain) queda listo para agregar otro `.po`/`.mo` más adelante de la misma forma.
- **No:** traducir datos ingresados por el usuario. Razón: no es texto de UI del plugin, es contenido de negocio propio de cada sitio.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| Sin WP-CLI instalado localmente, el flujo de generación de `.pot`/`.po`/`.mo`/`.json` depende de bajar `wp-cli.phar` puntualmente. | Se usa el mismo `php.exe` de WAMP ya validado en el proyecto; el `.phar` no queda como dependencia permanente del repo ni del build. |
| El nombre de archivo del JSON de traducción JS debe coincidir exactamente con el hash que WordPress espera a partir del handle del script, o la traducción JS falla en silencio (el admin/widget se ve en español aunque el sitio esté en inglés). | El paso 7 verifica visualmente en el navegador, no solo que los archivos existan — si el JS no traduce, se detecta ahí antes de cerrar la spec. |
| La auditoría de gaps (paso 1) puede no ser 100% exhaustiva dado el tamaño del plugin (18 specs de superficie). | Se prioriza cubrir toda la UI visible durante la prueba manual del paso 7; algún string suelto que aparezca después queda como fix menor, no bloquea el cierre de esta spec. |

---

## What is **not** in this spec

- Traducción del contenido de las plantillas de email (SPEC 09).
- Selector de idioma propio del plugin, independiente del sitio.
- Idiomas adicionales a español/inglés.
- Traducción de datos ingresados por el usuario.
- Integración con GlotPress/`translate.wordpress.org`.

Cada uno de estos, si se implementa, va en su propia spec.
