<div align="center">
  <h1>📅 Booking Plugin para WordPress</h1>
  <p><b>Gestión nativa de reservas de servicios y turnos, sin dependencias externas obligatorias.</b></p>
  
  <img src="https://img.shields.io/badge/WordPress-21759B?style=for-the-badge&logo=wordpress&logoColor=white" />
  <img src="https://img.shields.io/badge/React-20232A?style=for-the-badge&logo=react&logoColor=61DAFB" />
  <img src="https://img.shields.io/badge/WooCommerce-96588A?style=for-the-badge&logo=woocommerce&logoColor=white" />
  <img src="https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white" />
</div>

---

## 📖 Resumen de Funcionalidades
Este plugin ofrece un motor completo de reservas (**10 specs implementadas**) diseñado para gestionar citas, personal y pagos directamente desde WordPress. **WooCommerce es completamente opcional** y solo se requiere si deseas procesar cobros online.

---

## ✨ Características Principales

### ⚙️ 1. Motor de Reservas (Backend)
- **Catálogo de Servicios:** Gestión de categorías y servicios (nombre, precio, duración, *buffer* entre citas).
- **Gestión de Staff:** Configuración de horario semanal por profesional, excepciones (días libres/feriados) y asignación a servicios.
- **Horarios Flexibles:** Horario comercial del negocio, configurable día por día.
- **Disponibilidad Inteligente:** Calcula *slots* reales cruzando el horario del negocio + staff + excepciones + buffer + citas ya tomadas.
- **Prevención de Colisiones:** Evita condiciones de carrera (dos clientes no pueden tomar el mismo horario a la vez).
- **Control Total:** Reprogramación y cancelación de citas con ventanas mínimas configurables (tiempo de antelación y cancelación).
- **Bloqueos Manuales:** Bloqueo de horarios desde el admin (ej. vacaciones, mantenimiento de infraestructura).

### 💻 2. Panel de Administración (SPA en React)
Construido sobre `@wordpress/element` para una experiencia rápida, moderna y sin recargas de página.
- **Calendario Visual:** Vista por día, semana y mes con el detalle enriquecido de cada cita.
- **Gestión Integral:** Administración fluida de categorías, servicios y staff desde la UI.
- **Reglas de Negocio:** Configuración centralizada del horario comercial, antelación mínima, ventana de cancelación, intervalo de slots y configuración de correos.
- **Editor de Plantillas de Email:** Editor WYSIWYG (negrita, cursiva, enlaces), vista previa, envío de prueba y opción para restaurar a valores predeterminados.

### 📅 3. Widget de Reserva Público (Frontend)
- **Fácil Integración:** Shortcode embebible en cualquier página o entrada.
- **Flujo Guiado Paso a Paso:** 
  > `Servicio` ➔ `Profesional` ➔ `Fecha/Hora` ➔ `Datos del Cliente` ➔ `Confirmación`
- **Accesibilidad Universal:** Soporta reservas tanto como invitado o como usuario autenticado (logueado).
- **Código de Acceso Único:** Generación de un PIN o código único por reserva para que el usuario pueda gestionarla posteriormente sin necesidad de crear una cuenta.

### 👤 4. Panel de Autoservicio del Cliente
- **Portal Personal:** Shortcode dedicado donde el cliente puede ver y administrar sus propias citas.
- **Gestión Autónoma:** Permite reprogramar o cancelar citas respetando estrictamente las ventanas permitidas.
- **Acceso Dual:** Ingreso mediante sesión de WordPress o utilizando el código de acceso único.

### ✉️ 5. Notificaciones por Email
- **5 Plantillas Automáticas:**
  1. Confirmación al cliente.
  2. Recordatorio al cliente.
  3. Cancelación al cliente.
  4. Aviso de nueva reserva al negocio.
  5. Aviso de cancelación al negocio.
- **Recordatorios Automatizados:** Sistema automático programado (CRON) que notifica antes de la cita.
- **Variables Dinámicas:** Soporte documentado para *placeholders* (nombre, servicio, fecha, hora, etc.) en cada plantilla.

### 💳 6. Pagos Online (Integración WooCommerce)
*Módulo completamente opcional. Si WooCommerce no está instalado, el plugin sigue funcionando con degradación consciente y sin generar errores.*
- **Servicios Monetizados:** Opción para marcar cualquier servicio como "Requiere pago online".
- **Sincronización Transparente:** Crea y enlaza automáticamente un producto de WooCommerce (oculto del catálogo general) por cada servicio de pago.
- **Checkout Estándar:** Al reservar, genera un pedido y redirige al checkout de WooCommerce (aprovechando todas las pasarelas que el negocio ya tenga: tarjeta, PayPal, transferencia, Stripe, etc.).
- **Estado Sincronizado:**
  - La cita se **confirma** automáticamente cuando el pago se completa.
  - La cita se **cancela** automáticamente (liberando el horario para otros) si el pago se cancela o reembolsa.
- **Limpieza Automática (Anti-abandono):** Barrido automático configurable que cancela reservas con pago pendiente vencido, evitando que horarios queden bloqueados por usuarios que no completaron el pago.

---
<div align="center">
  <i>Desarrollado para potenciar negocios basados en servicios sobre WordPress.</i>
</div>
