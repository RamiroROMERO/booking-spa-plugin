---
title: "Booking Plugin — Resumen de funcionalidades"
tags: ["booking","reservas","wordpress","woocommerce","plugin"]
status: "Implementado"
---

# Booking Plugin — Resumen de funcionalidades

Plugin nativo de WordPress para gestión de reservas de servicios (turnos). WooCommerce es opcional para cobro online; el plugin funciona sin dependencias.

## Tabla de contenidos
- [Motor de reservas (backend)](#motor-de-reservas-backend)
- [Panel de administración (SPA)](#panel-de-administraci%C3%B3n-spa)
- [Widget público (shortcode)](#widget-p%C3%BAblico-shortcode)
- [Panel de autoservicio del cliente](#panel-de-autoservicio-del-cliente)
- [Notificaciones por e-mail](#notificaciones-por-e-mail)
- [Pagos online (WooCommerce) — opcional](#pagos-online-woocommerce--opcional)
- [Estado](#estado)
- [Shortcodes y ejemplos de uso](#shortcodes-y-ejemplos-de-uso)
- [Placeholders disponibles](#placeholders-disponibles)
- [Notas para administradores]

## Motor de reservas (backend)
- Catálogo de categorías y servicios: nombre, precio, duración, buffer entre citas.
- Gestión de staff: horarios semanales por profesional, excepciones (días libres/feriados), asignación a servicios.
- Horario comercial configurable por día.
- Motor de disponibilidad: combina horario del negocio + horario del staff + excepciones + buffer + citas ya tomadas para calcular slots reales.
- Prevención de condiciones de carrera: bloqueo atómico para evitar doble reserva del mismo slot.
- Reprogramación y cancelación de citas con ventanas mínimas configurables (anticipación / cancelación).
- Bloqueo manual de horarios desde el admin (vacaciones, mantenimiento).

## Panel de administración (SPA)
- Interfaz SPA en React (sobre @wordpress/element).
- Calendario visual con vistas día/semana/mes y detalle de cada cita.
- Gestión de categorías, servicios y staff desde UI.
- Configuración de reglas del negocio: antelación mínima, ventana de cancelación, intervalo de slots, e-mail de notificaciones.
- Editor de plantillas de e-mail con formato enriquecido, vista previa, envío de prueba y restauración al valor por defecto.

## Widget público (shortcode)
- Shortcode embebible en cualquier página.
- Flujo guiado: servicio → profesional → fecha/hora → datos del cliente → confirmación.
- Soporta reservas como invitado o usuario registrado.
- Código de acceso único por reserva para gestión sin cuenta.

## Panel de autoservicio del cliente
- Shortcode donde el cliente puede ver y gestionar sus citas (reprogramar/cancelar dentro de ventanas permitidas), con login o usando el código de acceso.

## Notificaciones por e-mail
- 5 plantillas automáticas: confirmación al cliente, recordatorio, cancelación al cliente, aviso de nueva reserva al negocio, aviso de cancelación al negocio.
- Recordatorio automático programado (cron) antes de la cita.
- Placeholders dinámicos (nombre, servicio, fecha, hora, etc.) documentados por plantilla.

## Pagos online (integración opcional con WooCommerce)
- Servicios pueden requerir pago online.
- Sincroniza un producto de WooCommerce (oculto) por cada servicio con pago.
- Al reservar, genera pedido y redirige al checkout de WooCommerce.
- Cita confirmada automáticamente al completarse el pago; cancelada si el pago se cancela/reembolsa.
- Limpieza automática: cancela reservas con pago pendiente vencido (configurable) para liberar slots.
- Si WooCommerce no está instalado, el plugin degrada funcionalidad sin errores (sin pagos online).

## Estado
Las 10 especificaciones están implementadas, probadas manualmente (incluyendo un ciclo de pago real con WooCommerce) y mergeadas a master.

## Shortcodes y ejemplos de uso
- Formulario público: [booking_form]
- Panel cliente: [booking_my_appointments]

(Personalizar atributos según la documentación interna: [booking_form service="masaje" staff="juan"]).

## Placeholders disponibles (ejemplos)
- {{client_name}}, {{service_name}}, {{date}}, {{time}}, {{staff_name}}, {{booking_code}}

## Notas para administradores
- Recomendado revisar la configuración de zonas horarias de WordPress.
- Configurar cron o sistema de tareas para recordatorios y barridos de pagos pendientes.
- Probar integración de WooCommerce en entorno de staging antes de producción.

---