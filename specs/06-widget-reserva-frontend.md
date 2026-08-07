# SPEC 06 — Widget de Reserva (Frontend)

> **Status:** Aprovada
> **Depends on:** SPEC 01, SPEC 02, SPEC 03
> **Date:** 2026-08-07
> **Objective:** Construir el widget público de reserva (shortcode `[booking_widget]`) con un wizard de 5 pasos (Servicio → Profesional opcional → Fecha/Hora → Datos personales → Confirmación), filtros por categoría y detección automática de la zona horaria del cliente, consumiendo la API pública de SPEC 02 y SPEC 03.

---

## Por qué esta spec existe

El documento base pide un "Shortcode o bloque nativo de Gutenberg que inyecte tu aplicación frontend para que el cliente haga la reserva sin recargar la página", con un wizard concreto (Servicio → Profesional → Fecha y Hora → Datos Personales → Pago/Confirmación) y detección de zona horaria del cliente. Esta spec es la primera pieza que un visitante del sitio realmente ve y usa; todo lo construido en SPEC 01-05 existe para que este widget tenga datos con los que trabajar.

---

## Scope

**In:**

- Shortcode `[booking_widget]` que inyecta el wizard en cualquier página/entrada del sitio, sin recargar la página entre pasos.
- Wizard de 5 pasos con navegación "Atrás" (resetea las selecciones dependientes de un paso anterior si se cambia): **Servicio** (con filtro visual por categoría) → **Profesional** (opcional, incluye "cualquier profesional disponible") → **Fecha y Hora** (calendario visual de mes + lista de horarios) → **Datos personales** (formulario de invitado, u omitido/resumido si el visitante ya tiene sesión WP) → **Confirmación**.
- Calendario visual de mes construido a medida (consistente con la decisión de SPEC 04 de no usar librerías de pago), resaltando qué días tienen al menos un hueco disponible.
- Detección automática de la zona horaria del navegador del cliente (`Intl.DateTimeFormat().resolvedOptions().timeZone`), usada como parámetro en las llamadas a disponibilidad y para mostrar las horas en la zona del cliente.
- Aislamiento visual del widget frente al tema del sitio vía prefijo de clases CSS único.
- Creación de la cita (`POST /appointments`, SPEC 03) al confirmar, con manejo explícito del caso de colisión (`409`: el horario se ocupó entre que se mostró y se confirmó).
- Pantalla de éxito con el resumen de la cita y el `access_token` recibido, visible/copiable para que el cliente pueda gestionar su cita más adelante (SPEC 07).
- Nueva ruta ligera `GET /availability/days` que extiende el controlador de disponibilidad de SPEC 03, para pintar el calendario visual sin pedir 30 días sueltos por red.

**Out of scope (para specs futuras):**

- Bloque nativo de Gutenberg (se construye solo el shortcode; un bloque queda como posible spec futura si hace falta).
- Envío real de correos de confirmación (SPEC 08) — la pantalla de éxito es la única confirmación por ahora.
- Cualquier paso o UI de pago real — el documento base dice "Pago/Confirmación", pero la estrategia de pago (WooCommerce vs SDK directo) sigue sin definirse (ver SPEC 09); esta spec crea la cita con `status='pending'` sin cobrar nada.
- Panel de cliente para ver/cancelar/reprogramar reservas pasadas (SPEC 07) — el widget solo cubre la creación de una reserva nueva.
- Rate limiting o protección anti-spam sobre `POST /appointments` (endpoint público, hereda el riesgo ya señalado en SPEC 02/03).
- Bloque configurable de atributos (colores, textos) — el widget usa un único estilo visual por ahora.

---

## Data model

No se crean tablas nuevas. Se extiende la API de SPEC 03 con una ruta adicional de solo lectura:

```
GET /wp-json/booking-plugin/v1/availability/days   (público)
  ?service_id=&staff_id=&month=YYYY-MM&timezone=

Response:
{
  "service_id": 12,
  "month": "2026-08",
  "available_dates": ["2026-08-20", "2026-08-21", "2026-08-24"]
}
```

Internamente reutiliza `Booking_Plugin_Availability::get_available_slots()` día por día dentro del mismo request del servidor, con salida anticipada en cuanto encuentra un slot libre ese día — el límite de "un día por llamada" de SPEC 03 aplica a las llamadas de red desde el cliente (evita que el widget dispare 30 requests HTTP al cargar el calendario), no al cómputo interno del servidor.

```js
// Estado del wizard (assets/src/frontend/App.js)
{
  step: 'service',   // 'service' | 'staff' | 'datetime' | 'personal' | 'confirmation' | 'success'
  timezone: 'America/Mexico_City',   // detectada del navegador
  selection: {
    category_id: null,
    service: null,        // objeto completo del servicio elegido
    staff_id: null,        // null = "cualquier profesional disponible"
    date: null,             // 'YYYY-MM-DD'
    slot: null,             // { start_datetime, end_datetime } en UTC
    personalData: { name: '', email: '', phone: '', notes: '' }, // vacío si hay sesión WP
  },
  currentUser: null,   // { name, email } si is_user_logged_in(), si no null
}
```

---

## Implementation plan

1. Extender `includes/rest/class-booking-rest-availability-controller.php` (SPEC 03) con la ruta `GET /availability/days`. Prueba manual: pedirla para un mes con citas de prueba y confirmar que solo devuelve los días con hueco libre real.
2. Agregar el entry `frontend` a `webpack.config.js` (creado en SPEC 04), apuntando a `assets/src/frontend/index.js`, salida `assets/build/frontend.js`/`frontend.css`.
3. Crear `includes/class-booking-plugin-shortcode.php`: registra `[booking_widget]`, detecta su uso en la página (`has_shortcode`) para encolar el bundle solo cuando hace falta, localiza la URL base de la API y los datos del usuario actual (`is_user_logged_in()`, nombre, email), y renderiza el contenedor con un id único por instancia.
4. Crear `assets/src/frontend/index.js` + `App.js`: monta el wizard con `@wordpress/element`, maneja el estado de los 5 pasos y la detección de zona horaria del navegador.
5. Crear `ServiceStep.js`: filtro de categorías (`GET /categories`) + grilla de servicios (`GET /services`, filtrable por categoría en el cliente).
6. Crear `StaffStep.js`: lista de staff que puede realizar el servicio elegido (`GET /staff?service_id=`), con la tarjeta "Cualquier profesional disponible" como primera opción.
7. Crear `MonthCalendar.js` (`GET /availability/days`) y `TimeSlotList.js` (`GET /availability`, SPEC 03) dentro de `DateTimeStep.js`, mostrando las horas convertidas a la zona horaria detectada del cliente.
8. Crear `PersonalDataStep.js` (formulario de invitado, u omitido con resumen si `currentUser` no es `null`) y `ConfirmationStep.js` + `SuccessScreen.js`: al confirmar, `POST /appointments`; si responde `409`, muestra el error y vuelve al paso de fecha/hora con los horarios refrescados; si responde `201`, muestra el resumen final y el `access_token`.
9. Prueba manual end-to-end: insertar `[booking_widget]` en una página del sitio, completar el flujo completo como invitado, confirmar que la cita creada aparece en el calendario admin (SPEC 04); abrir dos pestañas, llevar ambas hasta el mismo slot y confirmar en las dos casi al mismo tiempo, verificando que una tiene éxito y la otra muestra el error de colisión y se recupera mostrando horarios actualizados.

---

## Acceptance criteria

- [ ] `[booking_widget]` insertado en cualquier página/entrada muestra el wizard sin errores de consola ni de PHP.
- [ ] El paso Servicio permite filtrar por categoría y elegir un servicio; el precio y duración mostrados coinciden con los datos de SPEC 02.
- [ ] El paso Profesional muestra solo el staff vinculado a ese servicio (`staff_services`, SPEC 01/02), más la opción "cualquier profesional disponible".
- [ ] El calendario visual resalta únicamente los días con al menos un hueco libre real para el servicio/profesional elegidos.
- [ ] Las horas mostradas en la lista de horarios corresponden a la zona horaria detectada del navegador, no a UTC crudo.
- [ ] Usar el botón "Atrás" para cambiar el servicio elegido limpia la selección de profesional, fecha y hora ya hechas.
- [ ] Con una sesión WP activa, el paso de Datos personales no pide nombre/email/teléfono y muestra un resumen con los datos de la cuenta.
- [ ] Sin sesión, el paso de Datos personales exige nombre, email y teléfono antes de avanzar.
- [ ] Confirmar la reserva crea una cita visible en `GET /appointments` (SPEC 03) con `status='pending'`.
- [ ] La pantalla de éxito muestra un `access_token` visible/copiable.
- [ ] Si el slot elegido se ocupa entre la selección y la confirmación (`409`), el widget muestra un mensaje claro y vuelve al paso de fecha/hora con los horarios de ese día recargados, sin perder el servicio/profesional ya elegidos.
- [ ] El CSS del tema activo del sitio no rompe visualmente el widget en una instalación de WordPress con el tema por defecto (Twenty Twenty-Four o similar).

---

## Decisions

- **Sí:** shortcode `[booking_widget]` en vez de bloque de Gutenberg. Razón: funciona en cualquier editor/page builder sin depender del editor de bloques; menor superficie para esta spec.
- **Sí:** prefijo de clases CSS para aislamiento, sin Shadow DOM. Razón: simple, sin dependencias nuevas, y evita la fricción de integrar `@wordpress/components` dentro de un shadow root.
- **Sí:** navegación "Atrás" con reset en cascada de selecciones dependientes. Razón: mejor experiencia de reserva; evita que un error en un paso obligue a reiniciar todo el wizard.
- **Sí:** calendario visual de mes construido a medida, sin librería externa. Razón: consistente con la decisión de SPEC 04; mejor conversión en un widget cara al público que un `<input type="date">` nativo.
- **Sí:** nueva ruta `GET /availability/days` que extiende SPEC 03, con cómputo interno día-por-día y salida anticipada. Razón: resuelve el resaltado de disponibilidad del calendario sin violar la decisión de SPEC 03 de un día por llamada de red — el límite era sobre el número de requests HTTP del cliente, no sobre el trabajo interno del servidor.
- **Sí:** si el visitante ya tiene sesión WP activa, se omite el formulario de datos personales y se usa su nombre/email de cuenta. Razón: evita pedir datos que WordPress ya tiene, reduce fricción para usuarios registrados.
- **No:** paso o UI de pago real en esta spec. Razón: la estrategia de pago (WooCommerce vs SDK directo) sigue sin decidirse (SPEC 09); se crea la cita en `pending` sin cobrar, consistente con "Aún no lo sé" definido al planear el roadmap.
- **No:** bloque de Gutenberg en esta spec. Razón: el shortcode cubre el caso de uso principal con menos esfuerzo; un bloque queda disponible como spec futura si se necesita.
- **No:** envío de correo de confirmación. Razón: `wp_mail()` y las plantillas son responsabilidad de SPEC 08; la pantalla de éxito es la única confirmación mientras tanto.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| `POST /appointments` es un endpoint público sin autenticación (decisión de SPEC 03), lo que lo expone a reservas automatizadas/spam. | Riesgo heredado y ya documentado en SPEC 02/03 como fuera de alcance; una spec futura de "protección anti-spam" (honeypot, rate limiting) puede cubrirlo sin tocar este widget. |
| Un tema con selectores CSS muy globales (ej. `button { ... }` sin especificidad) puede romper visualmente el widget pese al prefijo de clases. | Se documenta como limitación conocida del enfoque de "prefijo de clases" elegido; el paso 9 del plan incluye probar contra el tema por defecto de WordPress como caso base. |
| La detección de zona horaria del navegador puede fallar o no estar disponible en navegadores muy antiguos. | Si `Intl.DateTimeFormat` no está disponible, se usa la zona horaria del sitio (mismo fallback que SPEC 04 usa en el admin) en vez de bloquear el widget. |

---

## What is **not** in this spec

- Bloque de Gutenberg.
- Pago real y su UI (SPEC 09).
- Envío de correos de confirmación (SPEC 08).
- Panel de cliente para gestionar reservas pasadas (SPEC 07).
- Rate limiting / protección anti-spam.

Cada uno de estos, si se implementa, va en su propia spec.
