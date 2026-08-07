# SPEC 01 — Core y Arquitectura de Datos

> **Status:** Aprovada
> **Depends on:** —
> **Date:** 2026-08-07
> **Objective:** Sentar la base ejecutable del plugin (hooks de activación/desactivación) y el modelo de datos relacional (tablas personalizadas) para categorías, servicios, staff, horarios y citas, sobre el que se construirán todas las specs siguientes.

---

## Por qué esta spec existe

El documento base (`base-plugin.txt`) recomienda explícitamente usar tablas MySQL personalizadas en vez de Custom Post Types + postmeta, porque el sistema es relacional (Cita → Cliente, Servicio, Staff) y postmeta se vuelve un cuello de botella. Esta spec traduce esa recomendación en un diagrama de base de datos concreto y en el andamiaje PHP mínimo para crearlo, sin todavía exponer ninguna API ni UI.

---

## Scope

**In:**

- Registro de hooks de activación/desactivación en `booking-plugin.php` sobre el scaffold ya existente.
- Creación de 8 tablas personalizadas vía `dbDelta()`: categorías de servicios, servicios, staff, pivote staff-servicios, horarios recurrentes de staff, excepciones de staff (días libres/horario especial), horario del negocio, y citas.
- Registro y control de versión de esquema (`booking_plugin_db_version` en `wp_options`) para permitir migraciones futuras sin reactivar el plugin manualmente.
- Desactivación no destructiva: desactivar el plugin no borra tablas ni datos.
- Estructura de carpetas de `includes/` que servirá de base para las specs siguientes (activator, deactivator, schema).

**Out of scope (para specs futuras):**

- Cualquier endpoint REST (SPEC 02 y SPEC 03).
- CRUD real de servicios/staff vía interfaz (SPEC 02).
- Algoritmo de cálculo de disponibilidad (SPEC 03).
- UI de administración (SPEC 04) y widget de reserva frontend (SPEC 05).
- Panel de cliente (SPEC 06), notificaciones (SPEC 07), pagos (SPEC 08).
- Multi-sucursal (`location_id`): el diseño deja la puerta abierta (tabla `business_hours` aislada y sin acoplar) pero no se implementa ahora.
- Soporte de activación de red en WordPress Multisite: se asume instalación single-site.
- Tabla de clientes independiente: los registrados usan `wp_users`, los invitados quedan como campos sueltos en `appointments`.

---

## Data model

Todas las tablas usan el prefijo `{$wpdb->prefix}booking_*` (ej. `wp_booking_appointments`) y `charset_collate` de `$wpdb`. Los timestamps de citas y horarios se guardan en **UTC**; la conversión a la zona horaria del sitio o del cliente ocurre en capas superiores (SPEC 03+).

```sql
-- Categorías de servicios (filtros visuales del frontend)
CREATE TABLE {$wpdb->prefix}booking_service_categories (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(191) NOT NULL,
  slug        VARCHAR(191) NOT NULL,
  sort_order  INT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL,
  UNIQUE KEY slug (slug)
);

-- Servicios
CREATE TABLE {$wpdb->prefix}booking_services (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id       BIGINT UNSIGNED NULL,
  name              VARCHAR(191) NOT NULL,
  slug              VARCHAR(191) NOT NULL,
  description       TEXT NULL,
  price             DECIMAL(10,2) NOT NULL DEFAULT 0,
  duration_minutes  SMALLINT UNSIGNED NOT NULL,
  buffer_minutes    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status            VARCHAR(20) NOT NULL DEFAULT 'active', -- 'active' | 'inactive'
  created_at        DATETIME NOT NULL,
  updated_at        DATETIME NOT NULL,
  UNIQUE KEY slug (slug),
  KEY category_id (category_id),
  KEY status (status)
);

-- Staff
CREATE TABLE {$wpdb->prefix}booking_staff (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NULL, -- FK logico a wp_users.ID si el staff tiene login WP
  name        VARCHAR(191) NOT NULL,
  email       VARCHAR(191) NOT NULL,
  phone       VARCHAR(30) NULL,
  status      VARCHAR(20) NOT NULL DEFAULT 'active', -- 'active' | 'inactive'
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,
  KEY user_id (user_id),
  KEY status (status)
);

-- Pivote: qué servicios puede realizar cada staff
CREATE TABLE {$wpdb->prefix}booking_staff_services (
  staff_id    BIGINT UNSIGNED NOT NULL,
  service_id  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (staff_id, service_id)
);

-- Horario semanal recurrente del staff (incluye pausa de comida)
CREATE TABLE {$wpdb->prefix}booking_staff_schedules (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  staff_id     BIGINT UNSIGNED NOT NULL,
  day_of_week  TINYINT UNSIGNED NOT NULL, -- 0=domingo .. 6=sabado
  start_time   TIME NOT NULL,
  end_time     TIME NOT NULL,
  break_start  TIME NULL,
  break_end    TIME NULL,
  KEY staff_id_day (staff_id, day_of_week)
);

-- Excepciones puntuales del staff (dias libres u horario especial en una fecha concreta)
CREATE TABLE {$wpdb->prefix}booking_staff_exceptions (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  staff_id         BIGINT UNSIGNED NOT NULL,
  exception_date   DATE NOT NULL,
  is_day_off       TINYINT(1) NOT NULL DEFAULT 1,
  start_time       TIME NULL,
  end_time         TIME NULL,
  reason           VARCHAR(191) NULL,
  UNIQUE KEY staff_date (staff_id, exception_date)
);

-- Horario general del negocio (una sola ubicacion por ahora)
CREATE TABLE {$wpdb->prefix}booking_business_hours (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  day_of_week TINYINT UNSIGNED NOT NULL, -- 0=domingo .. 6=sabado
  open_time   TIME NULL,  -- NULL = cerrado ese dia
  close_time  TIME NULL,
  UNIQUE KEY day_of_week (day_of_week)
);

-- Citas
CREATE TABLE {$wpdb->prefix}booking_appointments (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_id      BIGINT UNSIGNED NOT NULL,
  staff_id        BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT UNSIGNED NULL,   -- NULL = reserva de invitado
  guest_name      VARCHAR(191) NULL,
  guest_email     VARCHAR(191) NULL,
  guest_phone     VARCHAR(30) NULL,
  start_datetime  DATETIME NOT NULL,      -- UTC
  end_datetime    DATETIME NOT NULL,      -- UTC
  status          VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending|confirmed|completed|no_show|cancelled
  notes           TEXT NULL,
  created_at      DATETIME NOT NULL,
  updated_at      DATETIME NOT NULL,
  KEY staff_start (staff_id, start_datetime),
  KEY service_id (service_id),
  KEY user_id (user_id),
  KEY status (status)
);
```

Convenciones:

- Todas las tablas usan `id BIGINT UNSIGNED AUTO_INCREMENT` salvo el pivote `staff_services` (clave primaria compuesta).
- No se usan `FOREIGN KEY` reales: `dbDelta()` no las gestiona de forma confiable. La integridad referencial se mantiene a nivel de aplicación (PHP), apoyada en los índices declarados arriba.
- `status` en `services`/`staff` es borrado lógico (`active`/`inactive`); nunca se hace `DELETE` físico desde el plugin.
- `wp_options` guarda `booking_plugin_db_version` (string, ej. `'1.0.0'`) para detectar cuándo re-ejecutar la creación/actualización de tablas.

---

## Implementation plan

1. Crear `includes/class-booking-plugin-db-schema.php` con una clase `Booking_Plugin_DB_Schema` y un método estático `get_sql()` que devuelve un array de sentencias `CREATE TABLE` (formato compatible con `dbDelta()`) para las 8 tablas anteriores, usando `$wpdb->prefix` y `$wpdb->get_charset_collate()`.
2. Crear `includes/class-booking-plugin-activator.php` con `Booking_Plugin_Activator::activate()`: incluye `wp-admin/includes/upgrade.php`, ejecuta `dbDelta()` sobre cada sentencia de `get_sql()`, y guarda `update_option( 'booking_plugin_db_version', BOOKING_PLUGIN_DB_VERSION )`.
3. Crear `includes/class-booking-plugin-deactivator.php` con `Booking_Plugin_Deactivator::deactivate()`: solo `flush_rewrite_rules()`, sin tocar tablas ni datos.
4. Editar `booking-plugin.php`: definir la constante `BOOKING_PLUGIN_DB_VERSION`, hacer `require_once` de las 3 clases nuevas, y registrar `register_activation_hook()` / `register_deactivation_hook()` apuntando a los métodos estáticos. Prueba manual: activar el plugin en un WP local y confirmar en phpMyAdmin/`wp db query "SHOW TABLES LIKE '%booking_%'"` que existen las 8 tablas.
5. Editar `includes/class-booking-plugin.php`: en `init()`, comparar la opción `booking_plugin_db_version` contra `BOOKING_PLUGIN_DB_VERSION`; si difieren, volver a ejecutar `Booking_Plugin_Activator::activate()`. Esto permite que una futura spec cambie el esquema sin exigir desactivar/reactivar el plugin. Prueba manual: cambiar manualmente el valor de la opción en la BD, recargar el admin de WP, confirmar que el esquema se re-sincroniza sin errores.
6. Verificación final: desactivar y reactivar el plugin repetidamente sin que `dbDelta()` genere errores ni tablas duplicadas (debe ser idempotente).

---

## Acceptance criteria

- [ ] Al activar el plugin en WordPress, existen las 8 tablas `wp_booking_*` descritas en el modelo de datos, con las columnas e índices especificados.
- [ ] La opción `booking_plugin_db_version` en `wp_options` queda establecida tras la activación.
- [ ] Al desactivar el plugin, las 8 tablas y sus datos siguen existiendo en la base de datos.
- [ ] Reactivar el plugin varias veces seguidas no produce errores de SQL ni tablas/columnas duplicadas.
- [ ] No existe `uninstall.php` en el plugin (comportamiento por defecto de WordPress conserva los datos al desinstalar).
- [ ] El plugin se activa sin errores fatales ni warnings de PHP en un WP 5.8+/PHP 7.4+ limpio, sin necesidad de ningún endpoint REST ni pantalla de administración todavía.

---

## Decisions

- **Sí:** 8 tablas personalizadas en vez de CPTs + postmeta. Razón: sistema relacional (cita↔servicio↔staff), evita cuellos de botella en `wp_postmeta` (justificación del propio documento base).
- **Sí:** borrado lógico (`status`) en `services` y `staff` en vez de `DELETE` físico. Razón: preserva el historial de citas pasadas aunque el servicio o el profesional ya no esté activo.
- **Sí:** horarios de staff en tablas relacionales separadas (`staff_schedules` + `staff_exceptions`) en vez de JSON en una columna. Razón: el algoritmo de disponibilidad (SPEC 03) necesita filtrar por SQL directamente.
- **Sí:** fechas y horas de citas en UTC. Razón: evita bugs de huso horario con clientes en zonas distintas a la del negocio (detección de zona horaria mencionada en el documento base).
- **Sí:** `business_hours` sin `location_id` por ahora, pero como tabla propia y desacoplada. Razón: cubre el caso de una sola ubicación hoy, y añadir `location_id` más adelante es una migración aditiva, no destructiva.
- **Sí:** reservas de invitado con campos `guest_*` directos en `appointments`, sin tabla `clients` separada. Razón: evita duplicar datos de contacto; los usuarios registrados ya viven en `wp_users`.
- **No:** `FOREIGN KEY` constraints reales. Razón: `dbDelta()` no las crea ni actualiza de forma confiable; se usan índices + validación en PHP en su lugar.
- **No:** `uninstall.php`. Razón: al no crearlo, WordPress no ejecuta ninguna limpieza al desinstalar, cumpliendo "conservar datos" sin código adicional que mantener.
- **No:** soporte de activación de red en Multisite. Razón: fuera de alcance actual; activación estándar por sitio es suficiente.
- **No:** múltiples ubicaciones (`location_id`) todavía. Razón: no hay caso de uso confirmado hoy; el diseño ya deja la puerta abierta sin coste.

---

## Risks

| Riesgo | Mitigación |
| --- | --- |
| Sin `FOREIGN KEY`, un `DELETE` manual directo en la base de datos (fuera del plugin) puede dejar filas huérfanas en `appointments`, `staff_services`, `staff_schedules`, etc. | El plugin nunca hace `DELETE` físico de `services`/`staff` (usa `status`); se documenta que el borrado manual en BD es responsabilidad del operador. |
| Cambios de esquema en specs futuras (nuevas columnas/tablas) podrían romper instalaciones ya activadas. | `booking_plugin_db_version` + re-ejecución idempotente de `dbDelta()` en `init()` permiten migrar el esquema sin reinstalar. |
| Zonas horarias con horario de verano pueden generar horas ambiguas si se guardan en hora local. | Se decidió UTC en base de datos; la conversión a hora local ocurre en la capa de presentación (specs posteriores). |

---

## What is **not** in this spec

- Endpoints REST de ningún tipo (SPEC 02, SPEC 03).
- CRUD de servicios/staff vía interfaz (SPEC 02).
- Algoritmo de disponibilidad y creación de citas (SPEC 03).
- Panel de administración SPA (SPEC 04) y widget de reserva frontend (SPEC 05).
- Panel de cliente (SPEC 06), notificaciones por email (SPEC 07), integración de pagos (SPEC 08).
- Multi-sucursal y soporte de red Multisite.

Cada uno de estos, si se implementa, va en su propia spec.
