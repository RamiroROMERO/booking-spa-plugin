# SPEC 20 — Estilos personalizables del widget (branding por sitio)

> **Status:** Implementado
> **Depends on:** SPEC 06, SPEC 07
> **Date:** 2026-08-14
> **Objective:** Permitir que cada sitio donde se instala el plugin adapte los colores y la forma del widget de reserva y del panel de cliente a su propio branding, sin tener que sobrescribir clases CSS una por una.

---

## Por qué esta spec existe

El widget de reserva (`assets/src/frontend/style.scss`, usado tanto por `[booking_widget]` como por `[booking_client_panel]`) tiene todos sus colores hardcodeados en hex: el azul `#2271b1` de wp-admin como color de acento (pasos activos, categorías activas, día seleccionado del calendario, botón primario, links, tabs activos), grises `#dcdcde`/`#646970` para bordes y texto secundario, y `border-radius: 4px` repetido en ~15 lugares. Lo único que hereda del tema del sitio es `font-family: inherit`.

El panel de administración (`@wordpress/components`) queda fuera de esto: está pensado para verse como el resto de wp-admin y no necesita re-brandearse.

Este plugin se instala en sitios de spas/salones con identidades de marca propias, y hoy la única forma de cambiar el color del widget es escribir CSS custom sobrescribiendo cada selector `.booking-plugin-widget__*` / `.booking-plugin-client-panel__*` a mano (documentado como lista de referencia en una sesión anterior, pero no es una solución de código).

---

## Scope

**In:**

- Declarar un set acotado de CSS custom properties en los selectores raíz `.booking-plugin-widget` y `.booking-plugin-client-panel`, con los valores actuales como default (fallback):
  - `--booking-accent` (default `#2271b1`) — color de acento/interactivo: pasos activos, categoría activa, hover de tarjetas, día seleccionado del calendario, botón primario, tabs activos, links.
  - `--booking-accent-hover` (default `#135e96`) — estado hover del botón primario.
  - `--booking-border-color` (default `#dcdcde`) — color de borde general (tarjetas, inputs, divisores).
  - `--booking-border-radius` (default `4px`) — redondeo de esquinas (tarjetas, inputs, botones, notice).
  - `--booking-text-muted` (default `#646970`) — texto secundario/meta.
- Reemplazar cada uso hardcodeado de esos 5 valores en `assets/src/frontend/style.scss` por `var(--booking-*)`.
- Rebuild de assets y verificación visual en WAMP: el widget y el panel de cliente deben verse **idénticos** a como están hoy (mismos valores, solo parametrizados).
- Prueba real de override: redefinir `--booking-accent` y `--booking-border-radius` vía CSS externo (Customizer → CSS adicional, o un mu-plugin de prueba) y confirmar visualmente que el widget cambia sin tocar el código del plugin.
- Comentario de documentación al inicio de `style.scss` listando las 5 variables, su selector y su valor default, para que quien re-brandee un sitio sepa qué declarar.

**Out of scope (para specs futuras):**

- Selector de color en el admin del plugin (`SettingsPage.js`) para elegir el acento desde la UI sin escribir CSS. Se deja anotado como posible spec futura.
- Colores semánticos de estado: error (`#b32d2e`), notice/pendiente (`#fcf0e0`/`#7a5b00`), confirmada (`#edfaef`/`#00450c`), cancelada/no-show (`#fcf0f1`/`#8a2424`). Quedan hardcodeados — cambiarlos por variables abre la puerta a combinaciones de contraste rotas si un sitio las pisa sin querer, y no es lo que se pidió.
- Cambios de layout, spacing, tipografía o tamaños — solo color de acento, color de borde, radio de borde y texto secundario.
- Estilos del panel de administración — sigue el esquema de wp-admin vía `@wordpress/components`, sin cambios.
- Fondo blanco (`#fff`) de tarjetas/inputs — no se parametriza en esta spec (asume que la mayoría de sitios usan fondo claro; si hace falta soporte de fondo oscuro es una spec aparte, más grande).

---

## Data model

Esta spec no agrega tablas, columnas ni opciones nuevas. Es un cambio puramente de CSS (`assets/src/frontend/style.scss`), sin tocar PHP, JS de comportamiento, ni la API REST.

---

## Implementation plan

1. Auditar `assets/src/frontend/style.scss` completo y listar cada aparición de los 5 valores a parametrizar (`#2271b1`, `#135e96`, `#dcdcde`, `4px` como border-radius, `#646970`).
2. Declarar las 5 custom properties, con los valores actuales como default, en los selectores raíz `.booking-plugin-widget` y `.booking-plugin-client-panel` (declaración duplicada en ambos, ya que son selectores hermanos, no anidados).
3. Reemplazar cada aparición auditada en el Paso 1 por `var(--booking-accent)`, `var(--booking-accent-hover)`, `var(--booking-border-color)`, `var(--booking-border-radius)` o `var(--booking-text-muted)` según corresponda.
4. Agregar un comentario al inicio del archivo documentando las 5 variables (nombre, selector donde están declaradas, valor default, qué controla).
5. `npm run build` y comparación visual en WAMP (widget en `reservar-test`, panel de cliente) contra capturas previas — debe verse exactamente igual, sin ningún override declarado.
6. Prueba real de override: en WAMP, agregar temporalmente un CSS (vía mu-plugin de prueba o Customizer) que redefina `--booking-accent: <color de prueba>` y `--booking-border-radius: <valor de prueba>` sobre `.booking-plugin-widget`, confirmar visualmente el cambio, y quitar el CSS de prueba al terminar.

---

## Acceptance criteria

- [x] Las 5 custom properties (`--booking-accent`, `--booking-accent-hover`, `--booking-border-color`, `--booking-border-radius`, `--booking-text-muted`) están declaradas con los valores actuales como default en `.booking-plugin-widget` y en `.booking-plugin-client-panel`.
- [x] Ningún selector de `assets/src/frontend/style.scss` usa los valores hex/px hardcodeados originales para esos 5 conceptos — todos usan `var(--booking-*)`.
- [x] El widget de reserva y el panel de cliente se ven visualmente idénticos a como estaban antes de esta spec, sin ningún override declarado.
- [x] Al redefinir `--booking-accent` y `--booking-border-radius` desde un CSS externo apuntando a `.booking-plugin-widget` (o `.booking-plugin-client-panel`), el color de acento y el redondeo cambian en todo el widget/panel sin tocar el código del plugin.
- [x] El archivo tiene un comentario de cabecera documentando las 5 variables disponibles.
- [x] El panel de administración no tiene ningún cambio (sigue usando el esquema de wp-admin).
- [x] Los colores semánticos de estado (error, notice, confirmada, pendiente, cancelada) no cambian — siguen hardcodeados como antes.

---

## Decisions

- **Sí:** alcance limitado a variables CSS con fallback, sin selector de color en el admin. Razón: decisión explícita del usuario en esta sesión — mejora incremental, de bajo riesgo, que no rompe nada existente.
- **Sí:** el set de variables cubre branding "estructural" (acento, acento-hover, borde, radio de borde, texto secundario) y no cada color individual del archivo. Razón: mantener la superficie de personalización simple y fácil de documentar; cubre el caso real (cambiar el azul de WordPress por el color de marca del spa) sin explotar el alcance.
- **No:** colores semánticos de estado configurables. Razón: son indicadores convencionales (rojo=error, verde=confirmado, ámbar=pendiente); hacerlos configurables agrega riesgo de contraste roto sin necesidad real planteada por el usuario.
- **No:** selector de color en el admin. Razón: fuera del pedido de esta sesión; queda anotado como posible spec futura si se necesita una UI sin tocar CSS.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| Reescribir ~30 selectores a `var(--booking-*)` puede introducir un desvío visual accidental si algún valor se transcribe mal. | Paso 5 del plan: comparación visual explícita en WAMP antes de cerrar la spec, no solo revisar el diff de código. |
| `.booking-plugin-widget` y `.booking-plugin-client-panel` son selectores hermanos (no anidados) — un sitio que solo sobrescribe uno de los dos no afecta al otro. | Documentado explícitamente en el comentario de cabecera del Paso 4: hay que declarar el override en ambos selectores (o en un ancestro común como `body`) si se quiere un solo punto de personalización. |

---

## What is **not** in this spec

- Selector de color en el admin del plugin.
- Colores semánticos de estado configurables (error, notice, confirmada, pendiente, cancelada).
- Cambios de layout, spacing o tipografía.
- Estilos del panel de administración.
- Soporte de fondo oscuro / fondo configurable.

Cada uno de estos, si se implementa, va en su propia spec.
