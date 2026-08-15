---
title: "Booking Plugin — Resumen de funcionalidades"
tags: ["booking","reservas","wordpress","woocommerce","plugin"]
status: "Implementado"
---

# Booking Plugin — Resumen de funcionalidades

Plugin nativo de WordPress para gestión de reservas de servicios (turnos), pensado para spas/salones. WooCommerce es opcional (solo se necesita para cobro online); el resto del plugin funciona sin dependencias. Disponible en español e inglés según el idioma del sitio.

## Tabla de contenidos
- [Motor de reservas (backend)](#motor-de-reservas-backend)
- [Panel de administración (SPA)](#panel-de-administraci%C3%B3n-spa)
- [Widget público de reserva (shortcode)](#widget-p%C3%BAblico-de-reserva-shortcode)
- [Panel de autoservicio del cliente (shortcode)](#panel-de-autoservicio-del-cliente-shortcode)
- [Notificaciones por e-mail](#notificaciones-por-e-mail)
- [Pagos online (WooCommerce) — opcional](#pagos-online-woocommerce--opcional)
- [Paquetes y créditos prepagados](#paquetes-y-cr%C3%A9ditos-prepagados)
- [Comisiones y nómina del staff](#comisiones-y-n%C3%B3mina-del-staff)
- [Personalización visual del widget](#personalizaci%C3%B3n-visual-del-widget)
- [Internacionalización](#internacionalizaci%C3%B3n)
- [Estado](#estado)
- [Shortcodes](#shortcodes)
- [Placeholders disponibles en los e-mails](#placeholders-disponibles-en-los-e-mails)
- [Notas para administradores](#notas-para-administradores)

## Motor de reservas (backend)
- Catálogo de categorías y servicios: nombre, precio, duración, imagen (Media Library), extras opcionales (add-ons).
- Gestión de staff: horarios semanales por profesional, excepciones (días libres/feriados), asignación a servicios.
- Horario comercial configurable por día.
- Motor de disponibilidad: combina horario del negocio + horario del staff + excepciones + duración del servicio (+ add-ons elegidos) + citas ya tomadas para calcular slots reales.
- Prevención de condiciones de carrera: bloqueo atómico para evitar doble reserva del mismo slot.
- Reprogramación y cancelación de citas con ventanas mínimas configurables (anticipación / cancelación).
- Bloqueo manual de horarios desde el admin (vacaciones, mantenimiento).

## Panel de administración (SPA)
- Interfaz SPA en React (sobre `@wordpress/element`), bajo el menú "Reservas" del admin de WordPress.
- **Calendario**: vistas día/semana/mes por staff, detalle de cada cita, cambio de estado, bloqueo manual de horarios.
- **Servicios**: catálogo con categorías, precio, duración, imagen, extras (add-ons), pago online y depósito configurables por servicio.
- **Staff**: horario semanal y excepciones por profesional.
- **Configuración**: horario del negocio, antelación mínima, máximo de días a futuro, ventana mínima de cancelación, intervalo de slots, ventana de pago, e-mail de notificaciones del negocio, reembolso automático al cancelar, y los 4 colores personalizables del widget (ver [Personalización visual del widget](#personalizaci%C3%B3n-visual-del-widget)).
- **Notificaciones**: editor de las 5 plantillas de e-mail con formato enriquecido, vista previa, envío de prueba y restauración al valor por defecto.
- **Paquetes**: búsqueda de clientes y asignación manual de créditos de paquetes prepagados.
- **Nómina**: comisiones del staff por citas completadas, filtro por fecha/profesional, marcar como pagadas y exportar a CSV.
- **Saldos pendientes**: listado único de citas con depósito pagado y saldo por cobrar, para marcarlas como cobradas sin abrir cada cita.

## Widget público de reserva (shortcode)
- Shortcode `[booking_widget]`, embebible en cualquier página.
- Wizard guiado de 6 pasos: Servicio → Extras (add-ons, si el servicio tiene) → Profesional (opcional) → Fecha/hora → Datos del cliente → Confirmación.
- Filtro de servicios por categoría, tarjetas con imagen del servicio (o placeholder genérico).
- Detección automática de la zona horaria del cliente.
- Soporta reservas como invitado o como usuario registrado.
- Si el cliente tiene créditos de un paquete aplicables al servicio elegido, puede gastarlos en vez de pagar.
- Si el servicio requiere pago online, redirige al checkout de WooCommerce (pago completo o solo el depósito configurado, según el servicio).
- Código de acceso único por reserva para gestionarla después sin necesidad de cuenta.
- Colores del widget (acento, hover, borde, texto secundario) heredados de la configuración del admin, sin tocar CSS.

## Panel de autoservicio del cliente (shortcode)
- Shortcode `[booking_client_panel]`.
- Un usuario registrado ve y gestiona sus citas (próximas e historial): reprogramar o cancelar dentro de las ventanas permitidas.
- Un invitado puede gestionar puntualmente la cita que reservó usando el enlace con su código de acceso (`access_token`), sin necesidad de cuenta.
- Mismos colores personalizables que el widget de reserva.

## Notificaciones por e-mail
- 5 plantillas automáticas: confirmación al cliente, recordatorio, cancelación al cliente, aviso de nueva reserva al negocio, aviso de cancelación al negocio.
- Recordatorio automático programado (WP-Cron) antes de la cita.
- Placeholders dinámicos por plantilla (ver [Placeholders disponibles](#placeholders-disponibles-en-los-e-mails)).
- Editor con formato enriquecido, vista previa y envío de prueba desde el admin.

## Pagos online (WooCommerce) — opcional
- Cada servicio puede marcarse como "requiere pago online" y, opcionalmente, configurar un **porcentaje de depósito** (pago parcial: solo se cobra esa parte al reservar, el resto queda como saldo pendiente).
- Sincroniza un producto de WooCommerce (oculto) por cada servicio con pago.
- Al reservar, genera un pedido y redirige al checkout estándar de WooCommerce.
- La cita se confirma automáticamente al completarse el pago; se cancela si el pago se cancela.
- **Reembolso automático** (activable/desactivable desde Configuración): al cancelar una cita con pago o depósito ya cobrado, se intenta reembolsar automáticamente en la pasarela de pago original.
- **Saldos pendientes**: pantalla del admin para cobrar y marcar como saldadas las citas con depósito.
- Limpieza automática: cancela reservas con pago pendiente vencido (ventana configurable) para liberar el slot.
- Si WooCommerce no está instalado o activo, el plugin degrada sin errores (sin pagos online ni depósitos).

## Paquetes y créditos prepagados
- Un cliente puede comprar un paquete de sesiones prepagadas vía WooCommerce, o el admin puede asignárselo manualmente desde "Paquetes".
- El wizard de reserva le ofrece al cliente gastar una sesión de su saldo en vez de pagar, confirmando la cita directamente sin pasar por el checkout.

## Comisiones y nómina del staff
- Al completarse una cita, se calcula automáticamente la comisión del profesional que la atendió.
- Dashboard "Nómina": totales precalculados (vista de MySQL), filtrables por fecha y staff.
- Marcar (o desmarcar) comisiones como pagadas, y exportar el listado filtrado a CSV.

## Personalización visual del widget
- El widget y el panel de cliente exponen 5 custom properties CSS (`--booking-accent`, `--booking-accent-hover`, `--booking-border-color`, `--booking-border-radius`, `--booking-text-muted`) para re-brandear vía CSS externo (Customizer, child theme) sin tocar el plugin.
- Desde **Configuración → Colores del widget**, el admin puede elegir 4 de esos colores (acento, hover, borde, texto secundario) con un selector visual, sin escribir CSS, con botón "Restablecer" por color. Aplica por igual al widget de reserva y al panel de cliente.
- El radio de borde y los colores semánticos de estado (pendiente/confirmada/cancelada/error) no son configurables desde el admin — siguen accesibles solo vía CSS externo.

## Internacionalización
- Plugin completamente traducible; catálogo en inglés (`en_US`) incluido además del español (idioma base de los strings).
- Cubre tanto el backend PHP como los textos del JS (admin y frontend), vía `wp_set_script_translations`.
- Se adapta automáticamente al idioma configurado en el sitio de WordPress.

## Estado
Las 21 especificaciones están implementadas, probadas manualmente (incluyendo ciclos de pago real con WooCommerce, paquetes de créditos, comisiones y reembolsos) y mergeadas a `main`.

## Shortcodes
- Widget de reserva: `[booking_widget]`
- Panel de autoservicio del cliente: `[booking_client_panel]`

Ninguno de los dos shortcodes toma atributos — el comportamiento se configura desde el admin (Reservas → Configuración, Servicios, Staff).

## Placeholders disponibles en los e-mails
`{{business_name}}`, `{{client_name}}`, `{{client_email}}`, `{{client_phone}}`, `{{service_name}}`, `{{staff_name}}`, `{{date}}`, `{{time}}`, `{{manage_url}}`

## Notas para administradores
- Recomendado revisar la configuración de zona horaria de WordPress (Ajustes → General) antes de publicar el widget.
- El recordatorio automático y el barrido de pagos pendientes vencidos dependen de que WP-Cron esté corriendo con regularidad en el sitio (o de un cron de sistema real, si WP-Cron está desactivado).
- Probar la integración de WooCommerce (pagos, depósitos, reembolsos) en un entorno de staging antes de producción.
- El texto secundario y el borde del widget usan colores con contraste pensado para fondo claro — si se cambia el color de acento desde Configuración, verificar que siga siendo legible como color de botón/texto.

---
