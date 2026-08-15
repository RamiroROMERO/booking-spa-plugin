# SPEC 23 — Reporte y buscador de citas

> **Status:** Implementado
> **Depends on:** SPEC 01, SPEC 02, SPEC 03, SPEC 04
> **Date:** 2026-08-15
> **Objective:** Agregar una pantalla nueva en el admin ("Reporte de citas") que permita buscar y filtrar el historial de citas por cliente (registrado o invitado), servicio, staff, estado y rango de fechas, con exportación a CSV.

---

## Por qué esta spec existe

Hoy no existe ninguna forma de buscar una cita por cliente: el Calendario (SPEC04) solo navega por fecha, y "Saldos pendientes" (SPEC16) o "Nómina" (SPEC13) están acotados a un caso puntual (depósitos, comisiones). Al evaluar agregar una pantalla de "clientes" propia del plugin (sesión del 2026-08-15) se concluyó que sería redundante con WooCommerce para las citas pagadas online, e insuficiente para cubrir igual el caso de invitados en servicios sin pago (que nunca generan pedido de WooCommerce). La alternativa acordada es un reporte de citas con búsqueda de texto, no una entidad "cliente" nueva.

De paso, esta spec cierra un hueco detectado durante la sesión: ninguna pantalla del admin resuelve hoy el nombre/email real de un cliente registrado (usuario de WordPress) que reservó sin `guest_name` — siempre cae al texto genérico "Reserva" (`AppointmentCard.js`, `PendingBalancesPage.js`). Sin resolverlo, un buscador de texto no podría encontrar esas citas por el nombre real del cliente.

---

## Scope

**In:**

- Nueva página de admin "Reporte de citas" (mismo patrón de menú que Calendario/.../Saldos pendientes, SPEC04), como último ítem del menú.
- Filtros: rango de fechas Desde/Hasta (**obligatorios**, mismo criterio que `PayrollPage.js` — sin ambos, no se pide nada al backend), Staff (select, opcional), Servicio (select, opcional), Estado (select, opcional — ver más abajo), y un campo de texto libre ("Buscar cliente").
- El campo de texto compara, con coincidencia parcial (`LIKE %termino%`), contra `guest_name`, `guest_email` de la cita, y `display_name`/`user_email` de `wp_users` cuando la cita pertenece a un usuario registrado (`user_id`) — vía un nuevo `LEFT JOIN` en `get_items()`. Cierra el hueco descripto arriba: un cliente registrado ahora es encontrable por su nombre/email real.
- Cada cita en la respuesta de `GET /appointments` (SPEC03) suma dos campos nuevos, calculados con el mismo `LEFT JOIN`: `client_name` (`guest_name`, o `display_name` de `wp_users` si es un usuario registrado, o `null` si no hay ninguno) y `client_email` (`guest_email`, o `user_email` de `wp_users`). Estos campos solo se completan cuando la fila viene de `get_items()` (el listado) — `get_item()`/`get_mine()`/`update_item()` no cambian, no hacen el `JOIN`.
- El filtro Estado ofrece "Todos" + los 5 estados reales de una cita (`pending`, `confirmed`, `completed`, `no_show`, `cancelled`) — **no** ofrece `blocked`. Un nuevo parámetro `exclude_blocked=true` en `GET /appointments`, que esta pantalla siempre manda, excluye los bloqueos manuales (SPEC04) del resultado sin importar qué combinación de filtros se use — un bloqueo no tiene cliente ni servicio, no encaja en un reporte de citas.
- Nuevo parámetro `service_id` en `GET /appointments` (no existía; hoy solo hay `staff_id`), mismo patrón que ese filtro.
- Nuevo parámetro `search` en `GET /appointments` (texto libre, descripto arriba).
- Paginación real: la pantalla usa los headers `X-WP-Total`/`X-WP-TotalPages` que `GET /appointments` ya devuelve (y que hoy ningún consumidor usa) para mostrar controles "Anterior"/"Siguiente", en vez de pedir `per_page=100` de una sola vez como el resto del admin.
- Botón "Exportar CSV": trae **todas** las citas que matchean los filtros activos (no solo la página visible en pantalla), paginando internamente antes de armar el archivo — mismo patrón de generación client-side que ya usa `PayrollPage.js`.
- Tabla de solo lectura: cliente (`client_name`), servicio, staff, fecha/hora, estado. Sin acción de editar/abrir la cita desde la fila — para eso ya está el Calendario.
- Cada columna de servicio/staff se resuelve igual que en `PendingBalancesPage.js` (carga `GET /services` y `GET /staff` una vez al montar).

**Out of scope (para specs futuras):**

- Cualquier entidad "cliente" propia del plugin (tabla, perfil, historial agregado por cliente). Decisión explícita de esta sesión: sería redundante con WooCommerce para el caso pagado, e insuficiente igual para el caso de invitados sin pago — se descarta a favor de este reporte.
- Convertir un invitado en usuario registrado, o viceversa.
- Resolver `client_name`/`client_email` en otras pantallas ya existentes (`AppointmentModal.js`, `Calendar.js`/`MonthView.js`, `PendingBalancesPage.js`) — siguen mostrando lo mismo que hoy (fallback "Reserva" para usuarios registrados sin `guest_name`). Los campos nuevos quedan disponibles en la respuesta de `GET /appointments` para quien los quiera usar, pero actualizar esas pantallas es una spec aparte si hace falta.
- Poder abrir/editar una cita desde una fila del reporte.
- Filtros de pago/depósito/crédito (eso ya lo cubren Saldos pendientes y Nómina).
- Búsqueda por teléfono (`guest_phone`).
- Ordenar la tabla por una columna distinta de fecha (se mantiene `ORDER BY start_datetime ASC`, ya es el default del endpoint).

---

## Data model

No se crean tablas nuevas. Se extiende el endpoint existente `GET /booking-plugin/v1/appointments` (SPEC03) con parámetros de query nuevos y dos campos calculados en la respuesta:

```
GET /wp-json/booking-plugin/v1/appointments
    ?date_from=2026-08-01&date_to=2026-08-31
    &search=Juan
    &service_id=5
    &staff_id=1
    &status=confirmed
    &exclude_blocked=true
    &per_page=50&page=2
```

```js
// Item de la respuesta (SPEC03), con 2 campos nuevos:
{
  "id": 87,
  // ...resto de campos ya existentes (service_id, staff_id, user_id, guest_name, guest_email, status, start_datetime, ...)
  "client_name": "Juan Pérez",   // nuevo: guest_name, o display_name de wp_users si es un usuario registrado, o null
  "client_email": "juan@example.com"  // nuevo: guest_email, o user_email de wp_users
}
```

Convenciones:

- `search` compara contra 4 columnas con `LIKE %termino%` (coincidencia parcial, sin distinguir mayúsculas por el collation ya usado en el resto del plugin): `guest_name`, `guest_email` de `wp_booking_appointments`, y `display_name`, `user_email` de `wp_users` (vía `LEFT JOIN wp_users u ON u.ID = a.user_id`).
- `exclude_blocked=true` agrega `status != 'blocked'` al `WHERE`. Sin este parámetro, `GET /appointments` se comporta exactamente igual que hoy (Calendario sigue viendo bloqueos).
- `service_id` filtra por `service_id = %d`, mismo patrón que el `staff_id` ya existente.
- `client_name`/`client_email` solo se calculan en `get_items()` (el listado). Un `GET /appointments/{id}` individual, `GET /appointments/mine`, o la respuesta de un `PATCH /appointments/{id}` no incluyen estos campos (quedan `undefined`, no `null` — el `JOIN` no corre en esos métodos).
- Los headers `X-WP-Total`/`X-WP-TotalPages` (ya existentes desde SPEC03) no cambian de forma; esta spec es la primera en consumirlos desde el frontend del admin.

---

## Implementation plan

1. En `includes/rest/class-booking-rest-appointments-controller.php` (`get_items()`): cambiar el `SELECT * FROM {$table}` por un `LEFT JOIN` contra `{$wpdb->users}` sobre `user_id = ID`, y agregar los parámetros `search` (armando el `LIKE` con `$wpdb->esc_like()` sobre las 4 columnas), `service_id` (`service_id = %d`) y `exclude_blocked` (`status != 'blocked'`) al `WHERE` existente, combinables con los filtros ya presentes (`status`, `staff_id`, `date_from`, `date_to`, `has_pending_balance`, `has_deposit`). Prueba manual: `GET /appointments?search=...` encuentra tanto una cita de invitado como una de un usuario registrado por su nombre real; sin ninguno de los 3 parámetros nuevos, la respuesta es idéntica a la actual.
2. En el mismo archivo (`prepare_item()`): agregar `client_name`/`client_email` calculados a partir de las columnas del `LEFT JOIN` cuando estén presentes en la fila (`$row->wp_display_name ?? null`, etc.), sin romper las llamadas que no hacen el `JOIN` (`get_item()`, `get_mine()`, `update_item()`).
3. En `includes/class-booking-plugin-admin.php`: agregar `SLUG_APPOINTMENTS_REPORT`, su `add_submenu_page()` para "Reporte de citas" (último ítem del menú) y la entrada correspondiente en `$sections` (`section => 'report'`), siguiendo el mismo patrón que las páginas existentes.
4. Crear `assets/src/admin/pages/AppointmentsReportPage.js`: carga `staff`/`services` una vez al montar; filtros Desde/Hasta (obligatorios) + Staff + Servicio + Estado (patrón `PayrollPage.js`/`PendingBalancesPage.js`) + `TextControl` de búsqueda; sin Desde/Hasta, muestra el mismo mensaje que `PayrollPage.js` pidiendo el rango, sin pedir nada al backend. Con ambos, pide `GET /appointments?...&exclude_blocked=true&per_page=50&page=N`, siempre combinando los filtros activos.
5. En el mismo componente: controles "Anterior"/"Siguiente" leyendo `X-WP-Total`/`X-WP-TotalPages` de la respuesta de `apiFetch` (necesita `{ parse: false }` para acceder a los headers, o el helper que ya use el resto del admin si existe uno — si no, resolverlo con `response.headers.get(...)`).
6. En el mismo componente: botón "Exportar CSV" que, al hacer click, pagina internamente (`per_page` alto o recorriendo páginas) hasta traer todas las citas que matchean los filtros activos, arma el CSV client-side (mismo patrón que `PayrollPage.js::exportCsv`) con columnas Cliente/Servicio/Staff/Fecha/Estado, y dispara la descarga.
7. Editar `assets/src/admin/index.js`: registrar `report: AppointmentsReportPage` en `PAGES_BY_SECTION`.
8. Reusar `.booking-plugin-filters-grid` para los filtros (ajustando columnas si hace falta un quinto campo) y agregar estilos mínimos para los controles de paginación en `assets/src/admin/style.scss`, siguiendo el resto de las convenciones ya usadas (`gap`, gris de wp-admin).
9. `npm run build` y verificación manual end-to-end en WAMP: buscar por el nombre real de un usuario registrado y confirmar que aparece; buscar por nombre/email de un invitado; combinar búsqueda con Servicio/Staff/Estado; generar más de una página de resultados y confirmar que "Siguiente"/"Anterior" navegan bien; exportar CSV con más de una página de resultados y confirmar que el archivo trae todas las filas, no solo la visible; confirmar que un bloqueo manual nunca aparece en el reporte; confirmar que el Calendario y "Saldos pendientes" siguen funcionando exactamente igual que antes.

---

## Acceptance criteria

- [x] El campo de búsqueda encuentra citas de invitados por `guest_name`/`guest_email` con coincidencia parcial.
- [x] El campo de búsqueda encuentra citas de usuarios registrados por su `display_name`/`user_email` real de WordPress, aunque la cita no tenga `guest_name`.
- [x] Los filtros Servicio, Staff, Estado y el rango de fechas se combinan correctamente entre sí y con la búsqueda de texto.
- [x] El filtro Estado no ofrece la opción "Bloqueado"; ningún bloqueo manual aparece nunca en los resultados, sin importar qué filtros se elijan.
- [x] Sin elegir Desde y Hasta, la pantalla no pide nada al backend y muestra un mensaje pidiendo el rango.
- [x] Con más de `per_page` resultados, los controles "Anterior"/"Siguiente" navegan correctamente usando `X-WP-Total`/`X-WP-TotalPages`.
- [x] "Exportar CSV" genera un archivo con todas las citas que matchean los filtros activos, no solo la página visible en pantalla.
- [x] Cada fila muestra el nombre resuelto del cliente (`client_name`), servicio, staff, fecha/hora y estado; ninguna fila es clickeable ni editable.
- [x] `GET /appointments` sin `search`, `service_id` ni `exclude_blocked` se comporta exactamente igual que antes de esta spec (sin regresión en Calendario ni en "Saldos pendientes").
- [x] Aparece un nuevo ítem "Reporte de citas" al final del menú de admin del plugin.

---

## Decisions

- **Sí:** un reporte de citas con búsqueda de texto, en vez de una entidad "cliente" propia del plugin. Razón: decisión explícita del usuario en esta sesión — sería redundante con WooCommerce para citas pagadas online, e insuficiente igual para invitados de servicios sin pago (que nunca generan pedido de WooCommerce y por lo tanto no aparecen ahí).
- **Sí:** la búsqueda de texto resuelve también clientes registrados vía `LEFT JOIN wp_users`, no solo invitados. Razón: decisión explícita del usuario; sin esto, buscar a un cliente que reservó logueado no encontraría nada, lo que volvería inútil la mitad del propósito de esta pantalla.
- **No:** propagar `client_name`/`client_email` a otras pantallas existentes (`AppointmentModal.js`, Calendario, Saldos pendientes). Razón: mantiene el alcance de esta spec acotado al reporte nuevo; esas pantallas quedan con el mismo comportamiento de hoy, y los campos nuevos quedan disponibles para una spec futura si se decide actualizarlas.
- **Sí:** rango de fechas obligatorio antes de traer resultados, mismo criterio que Nómina. Razón: decisión explícita del usuario — a diferencia de Saldos pendientes (un subconjunto acotado por naturaleza), el historial completo de citas puede ser grande.
- **Sí:** solo lectura + Exportar CSV, sin poder editar una cita desde la fila. Razón: decisión explícita del usuario; para editar ya existe el Calendario, evita duplicar esa lógica en una segunda pantalla.
- **Sí:** paginación real con los headers `X-WP-Total`/`X-WP-TotalPages` que el endpoint ya devuelve, en vez de `per_page=100` fijo como el resto del admin. Razón: decisión explícita del usuario — un reporte que trunca resultados sin avisar es un error silencioso, más grave en una pantalla pensada justo para buscar/exportar.
- **Sí:** "Exportar CSV" trae todas las páginas que matchean los filtros, no solo la visible. Razón: decisión explícita del usuario, consistente con la decisión de paginación real — un CSV parcial sería igual de engañoso que un reporte truncado.
- **Sí:** nuevo parámetro `exclude_blocked=true` en vez de excluir `status = 'blocked'` siempre en `get_items()`. Razón: el Calendario depende de ver los bloqueos en el mismo endpoint; excluirlos incondicionalmente rompería esa pantalla. El parámetro es opt-in, sin cambiar el comportamiento default.
- **Sí:** "Reporte de citas" como nombre de pantalla (no "Buscador de citas"). Razón: decisión explícita del usuario; deja más claro que es de solo consulta/exportación, distinto del Calendario.
- **No:** filtros de pago/depósito/crédito en este reporte. Razón: ya cubiertos por Saldos pendientes (SPEC16) y Nómina (SPEC13); duplicarlos acá no agrega valor nuevo.
- **No:** búsqueda por `guest_phone`. Razón: fuera del pedido de esta sesión (solo nombre/email); se puede sumar después si hace falta.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| `search` usa `LIKE '%termino%'`, que no puede aprovechar un índice y hace un table scan sobre `wp_booking_appointments` (y el `JOIN` a `wp_users`). | Volumen esperado (citas de un negocio tipo spa/salón) es bajo comparado con un caso de miles de usuarios de WordPress; si se vuelve un cuello de botella real, se puede evaluar un índice o `FULLTEXT` en una spec futura. |
| Exportar CSV con muchísimas citas podría disparar varias páginas de `GET /appointments` seguidas antes de armar el archivo, con el navegador esperando. | Igual que el resto del admin (Nómina también arma el CSV client-side): el volumen esperado no justifica un endpoint de exportación server-side dedicado por ahora. |
| Un cliente registrado con `display_name` vacío (poco común pero posible en WordPress) no aportaría nada nuevo al buscador para esa cita puntual. | Cae al mismo comportamiento de hoy para ese caso (`client_name` termina en `null`, mismo fallback visual "Reserva" que ya usa el resto del admin) — no es una regresión. |

---

## What is **not** in this spec

- Una entidad "cliente" propia del plugin (tabla, perfil, historial agregado).
- Convertir invitados en usuarios registrados o viceversa.
- Actualizar `AppointmentModal.js`, el Calendario o "Saldos pendientes" para mostrar `client_name`/`client_email`.
- Editar una cita desde una fila del reporte.
- Filtros de pago/depósito/crédito, o búsqueda por teléfono.
- Ordenar la tabla por una columna distinta de fecha.

Cada uno de estos, si se implementa, va en su propia spec.
