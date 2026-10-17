# BarberHub: especificación de requisitos de software

Documento organizado según la estructura de ISO/IEC/IEEE 29148:2018 para una especificación de requisitos de software. Es la fuente de verdad funcional del sistema, junto con `business-rules.md`.

## 0. Control del documento

| Campo | Valor |
| --- | --- |
| Documento | `context/requirements.md` |
| Versión | 1.6 |
| Fecha | 16 de octubre de 2026 |
| Estado | Para aprobación |
| Documentos relacionados | `context/business-rules.md` (reglas de negocio), `docs/` (arquitectura, base de datos, modelo de datos y decisiones técnicas) |
| Alcance de la versión | 53 requisitos funcionales, 28 requisitos no funcionales, 18 restricciones de diseño y 5 requisitos legales |

Reglas de mantenimiento: los requisitos y las reglas viven en este archivo y en `business-rules.md`; los issues los citan por su identificador; si algo cambia, se corrige primero el archivo. Los identificadores no se reutilizan.

## 1. Introducción

### 1.1 Propósito

Este documento especifica qué debe hacer y cómo debe comportarse BarberHub, de modo que quien lo desarrolla, quien lo prueba y quien lo usa tengan una misma definición verificable. Cada requisito es una frase única que se puede comprobar, tiene un identificador estable y se traza hasta las reglas de negocio y hasta el trabajo que lo implementa.

### 1.2 Alcance

BarberHub es una plataforma web multi-inquilino donde varias barberías administran su agenda, cobran depósitos o pagos totales, venden bonos de sesiones, gestionan una lista de espera y reciben reservas de sus clientes, incluida una reserva asistida por IA. El caso de referencia es la Barbería Pura Navaja, de Ciudad Quesada, descrita en `business-rules.md`; el sistema debe soportar otras barberías con configuraciones distintas.

**Objetivos del producto**

- Eliminar dobles reservas, ausencias sin consecuencia y cobros por adelantado perdidos en WhatsApp y cuaderno.
- Separar completamente los datos de cada barbería.
- Dar a cada rol, dueño, colaborador y cliente, solo lo que le corresponde.
- Cobrar de forma confirmada, idempotente y trazable.

**Exclusiones.** Lo que el sistema no hace:

| # | Exclusión | Origen |
| --- | --- | --- |
| 1 | No se procesa dinero real: todos los pagos corren en el modo de pruebas de las pasarelas. | C |
| 2 | No se manejan datos personales de personas reales: los datos de demostración son ficticios. | C |
| 3 | No se publica el sistema como servicio comercial. | C |
| 4 | Cobro del saldo en el local, punto de venta e inventario de productos. | D |
| 5 | Nómina, comisiones y pago al personal. | D |
| 6 | Reseñas, precios dinámicos y servidor MCP. | D |
| 7 | Aplicación móvil nativa; el cliente web debe ser usable en celular. | D |
| 8 | Reservas recurrentes, reservas para varias personas a la vez y reservas con varios servicios de distintos barberos. | D |
| 9 | Varias sucursales por barbería y más idiomas que español e inglés. | D |
| 10 | Chat conversacional con IA. | D |
| 11 | Verificación de edad o de identidad del cliente, y verificación del correo al registrarse. | D |
| 12 | Eliminación o anonimización de la cuenta. | D |
| 13 | Directorio de barberías: cada cliente llega por el enlace de su barbería. | D |
| 14 | Más de un rol para una misma persona en una misma barbería. | D |

### 1.3 Definiciones, siglas y abreviaturas

| Término | Significado |
| --- | --- |
| BarberHub | La plataforma: un único sistema donde conviven varias barberías. |
| Barbería (inquilino) | Cada negocio registrado, con sus datos totalmente separados de los demás. En el código es `Barbershop`. |
| Dueño, colaborador, cliente | Los tres roles del sistema. Una persona tiene un único rol en cada barbería. |
| Cliente asistido | Persona que el dueño registra para una reserva asistida, sin cuenta. |
| Enlace público | Dirección propia de cada barbería, por la que sus clientes llegan a ella. |
| Espacio | Intervalo de tiempo de un colaborador en el que cabe un servicio. |
| Reserva | El espacio de un colaborador asignado a un cliente para un servicio. |
| Reserva temporal | Reserva que aparta el espacio durante 10 minutos mientras se completa el pago. |
| Depósito | Porcentaje del precio que se paga al reservar. |
| Bono y sesión | Paquete prepagado de sesiones para ciertos servicios, con vigencia; una sesión cubre un servicio. |
| Bono aplicable | Bono del cliente en esa barbería, vigente, con saldo y que cubre el servicio elegido. |
| Fila | La lista de espera de un servicio, un día y un barbero (o cualquiera). |
| Persona compatible | Quien está en la fila para el mismo servicio y día, con el mismo barbero o "cualquiera", cuya duración cabe en el espacio liberado y que no tiene otra reserva solapada. |
| Oferta | Espacio liberado que se propone a la primera persona compatible de la fila. |
| Reserva asistida | Reserva creada por el dueño para una persona que llega o llama. |
| Webhook | Aviso que envía la pasarela de pago para confirmar un pago. |
| Aviso | Mensaje al cliente, por correo o como aviso emergente dentro de la aplicación. |
| Registro técnico | Registro interno de acciones sensibles con autor, fecha y cambio, sin pantalla de consulta. |
| Tipo de error estable | Un tipo fijo de error RFC 9457 que el cliente web reconoce, por ejemplo `espacio-no-disponible`. |
| Enunciado (de un requisito) | La frase única y verificable "El sistema deberá…" que define el requisito. |
| Clase | Prioridad del requisito: esencial (alta), complementaria (media) o extra (baja). |
| Origen | C: requisito del cliente. N: norma o fuente externa. D: decisión de diseño. |
| API | Interfaz de programación del sistema (REST, JSON). |
| CI | Integración continua: verificaciones automáticas en cada pull request. |
| RLS | Seguridad a nivel de fila de PostgreSQL. |
| UUID v7 | Identificador único ordenable en el tiempo. |
| WCAG | Pautas de accesibilidad para el contenido web del W3C. |
| RFC 9457 | Formato estándar de errores de una API HTTP. |
| OpenAPI | Contrato de la API; se genera con Scramble. |
| SINPE Móvil | Transferencias de dinero entre teléfonos del sistema bancario costarricense. |
| IVA | Impuesto al valor agregado; en servicios, 13 %. |

### 1.4 Referencias

- `context/business-rules.md`: reglas de negocio vigentes (identificadores CL, SV, CO, HO, DI, RE, CA, PA, BO, LE, UP, MT, NO, IA, SE y AC).
- ISO/IEC/IEEE 29148:2018, Systems and software engineering, Life cycle processes, Requirements engineering.
- Web Content Accessibility Guidelines (WCAG) 2.2, W3C.
- RFC 9457, Problem Details for HTTP APIs.
- Ley 8968, Protección de la Persona frente al Tratamiento de sus Datos Personales (Costa Rica).
- Documentación de ONVO Pay, PayPal, Laravel 13, Sanctum, Scramble y PostgreSQL 18.
- `docs/`: arquitectura, base de datos, modelo de datos, lenguaje ubicuo y decisiones técnicas.

### 1.5 Visión general

La sección 2 describe el producto, sus usuarios y sus restricciones generales. La sección 3 contiene los requisitos específicos: interfaces, funcionales, no funcionales, restricciones de diseño y requisitos legales. La sección 4 define cómo se verifica cada requisito. La sección 5 da la trazabilidad con las reglas de negocio y la planificación. Los apéndices incluyen la equivalencia con la numeración anterior, los puntos pendientes de definir y el historial de cambios.

## 2. Descripción general

### 2.1 Perspectiva del producto

BarberHub es un sistema nuevo y autónomo, formado por dos componentes en un solo repositorio:

| Componente | Descripción |
| --- | --- |
| `barberhub-api` | API REST en Laravel 13 sobre PHP 8.4, con arquitectura hexagonal, PostgreSQL 18, tareas en cola y tiempo real. |
| `barberhub-web` | Cliente web separado, en Vue con TypeScript, que consume la API. |

Se apoya en servicios externos: dos pasarelas de pago (ONVO Pay y PayPal), un proveedor de IA y un servicio de correo. Los extras añaden WhatsApp Business Cloud, Google Calendar y el sistema de factura electrónica de Hacienda (sección 3.1.2).

### 2.2 Funciones del producto

| Módulo | Requisitos | Resumen |
| --- | --- | --- |
| Barbería y configuración | RF-01 a RF-09 | Alta, enlace público, servicios, horario, personal, bloqueos y políticas. |
| Disponibilidad y reservas | RF-10 a RF-19 | Espacios reales, reservas sin duplicados, reprogramación, cancelación, atendida o ausente y expiración. |
| Pagos | RF-20 a RF-25 | Cobro con dos pasarelas, confirmación por webhook, reembolsos e ingresos. |
| Bonos prepagados | RF-26 a RF-29 | Definición, compra, uso sin saldo negativo y consulta. |
| Lista de espera | RF-30 a RF-32 | Fila, ofertas con tiempo limitado y respuesta. |
| Avisos y tiempo real | RF-33 a RF-36 | Recordatorios, correos, aviso emergente y agenda en vivo. |
| Inteligencia artificial | RF-37 | Reserva a partir de una frase, con salida estructurada y validada. |
| Identidad, acceso y datos | RF-38 a RF-46 | Cuentas, contraseñas, roles, aislamiento, datos de la cuenta e imágenes. |
| Preferencias de la cuenta | RF-47 a RF-49 | Avisos, apariencia e idioma. |
| Extras | RF-50 a RF-53 | Propinas, WhatsApp, Google Calendar y factura electrónica. |

### 2.3 Usuarios y características

| Actor | Descripción | Qué puede hacer | Qué no puede hacer |
| --- | --- | --- | --- |
| Dueño | Administra una barbería. | Configurar su barbería, ver todas sus reservas e ingresos, reprogramar y cancelar reservas. | Ver o modificar datos de otra barbería. |
| Colaborador | Barbero con agenda propia. | Ver su agenda y marcar lo que atendió. | Ver ingresos, la agenda de otros o configurar la barbería. |
| Cliente | Persona que reserva. | Consultar disponibilidad, reservar, pagar, comprar bonos, cancelar y unirse a la lista de espera. | Ver reservas, pagos o bonos de otras personas. |
| Pasarela de pago | ONVO Pay o PayPal. | Confirmar pagos por webhook. | Cambiar datos de reservas por otra vía. |
| Proveedor de IA | Servicio externo de lenguaje. | Devolver datos estructurados a partir de una frase. | Crear reservas. |
| Servicio de correo | Entrega de mensajes. | Entregar confirmaciones, recordatorios y avisos. | No aplica. |
| Planificador de tareas | Procesos automáticos en cola. | Expirar reservas, enviar recordatorios y avanzar la lista de espera. | No aplica. |

Una persona tiene una sola cuenta y puede tener un rol distinto en cada barbería, pero un único rol en cada una (UP-07).

### 2.4 Restricciones generales

- Las exclusiones 1 a 3 de la sección 1.2 son restricciones del proyecto.
- Los precios incluyen el IVA de 13 % y se manejan en colones enteros.
- Todas las horas se expresan en la zona horaria de Costa Rica.
- Las restricciones de diseño están en la sección 3.4 y las legales, en la 3.5.

### 2.5 Suposiciones y dependencias

- ONVO Pay y PayPal ofrecen modo de pruebas con webhooks y cuentas ficticias.
- Hay una cuenta de un proveedor de IA con llave de pruebas.
- Los extras usan cuentas y ambientes de prueba de Meta, Google y Hacienda.
- El tratamiento de la moneda en PayPal se verifica al integrarlo.
- Cada barbería usa una sola pasarela a la vez.
- La cuenta del cliente es global y los roles se asignan por barbería (CL-01, UP-01).
- Existe autenticación básica desde el primer corte de trabajo, porque el aislamiento entre barberías se comprueba con sesiones distintas.
- Los puntos pendientes de definir están en el apéndice B.

### 2.6 Priorización y planificación

La clase de cada requisito fija su prioridad: **esencial** (39 requisitos), **complementaria** (10) y **extra** (4). Las complementarias se construyen después de las esenciales y los extras, después de todo lo demás. El trabajo se agrupa en 18 milestones con su fecha de entrega programada:

| Milestone | Contenido | Entrega programada |
| --- | --- | --- |
| M01 Proyecto base y base de datos | Proyecto de la API, PostgreSQL con roles, bases de desarrollo y pruebas | 21 oct |
| M02 Arquitectura y documentación | Estructura hexagonal, lenguaje ubicuo, decisiones y modelo de datos | 21 oct |
| M03 Calidad y contratos de la API | Pint, Larastan, PHPUnit, CI, errores RFC 9457, OpenAPI y ruta de salud | 21 oct |
| M04 Aislamiento entre barberías | Alta de la barbería, enlace público y aislamiento con seguridad a nivel de fila | 21 oct |
| M05 Cuentas, acceso y permisos | Registro, sesión, contraseña, roles y selección de barbería | 21 oct |
| M06 Catálogo, horarios y personal | Servicios, horario, colaboradores, políticas, activación de funciones e imágenes | 21 oct |
| M07 Disponibilidad y reservas | Espacios reales; crear, consultar, reprogramar, cancelar y marcar reservas sin duplicados | 21 oct |
| M08 Cobro con pasarelas y reserva temporal | ONVO Pay, PayPal, webhooks, expiración y consecuencia económica | 27 oct |
| M09 Reserva con IA (API) | Propuesta estructurada y validada | 5 nov |
| M10 Cliente web: base, acceso y administración | Base de Vue, acceso y administración de la barbería | 10 nov |
| M11 Cliente web: reserva, pago e IA | Reservar, pagar, reserva confirmada, mis reservas e IA | 17 nov |
| M12 Bonos, ingresos y lista de espera | Bonos, saldo, ingresos, fila, ofertas y su respuesta | 22 nov |
| M13 Avisos, agenda en vivo y auditoría de seguridad | Recordatorios, correos, aviso emergente, tiempo real y revisión externa | 24 nov |
| M14 Funciones complementarias | Invitaciones, bloqueos, reserva asistida, reembolsos y recuperación de contraseña | 3 dic |
| M15 Mi cuenta: avisos, apariencia e idioma | Datos de la cuenta, preferencias de avisos, apariencia e idioma | 3 dic |
| M16 Cliente web: bonos, lista de espera, agenda e ingresos | Pantallas de bonos, lista de espera, agenda en vivo e ingresos | 6 dic |
| M17 Operación en producción y cierre | Despliegue automático, colas en producción, monitoreo y cierre | 6 dic |
| M18 Extras | RF-50 a RF-53 | Sin fecha |

## 3. Requisitos específicos

### 3.1 Interfaces externas

#### 3.1.1 Interfaces de usuario (pantallas lógicas)

La guía visual del cliente web está pendiente de definir (apéndice B); las pantallas se describen por su función.

| Rol | Pantallas |
| --- | --- |
| Acceso (todos) | Registro, inicio de sesión, olvidé mi contraseña, crear nueva contraseña, elegir barbería, términos y condiciones, política de privacidad, "Esta barbería no existe" |
| Cliente | Reservar (con servicios con imagen), reservar con IA, resumen y pago, estado del pago y reserva confirmada, mis reservas, reprogramar o cancelar, bonos, lista de espera y ofertas, aviso emergente de ofertas |
| Dueño | Agenda en vivo con panel de detalle de reserva, reserva asistida, servicios, horario, personal con invitaciones, bloqueos, negocio y políticas, bonos del negocio, ingresos, reembolsos |
| Colaborador | Mi agenda en vivo con panel de detalle |
| Común | Mi cuenta, con las pestañas Datos, Seguridad, Avisos (solo cliente), Apariencia e Idioma |

#### 3.1.2 Interfaces con sistemas externos

| Sistema | Uso | Protocolo | Requisitos |
| --- | --- | --- | --- |
| ONVO Pay | Cobros con tarjeta y SINPE Móvil; webhooks de confirmación | HTTPS, JSON | RF-20, RF-22, RF-23, RF-24 |
| PayPal | Cobros con tarjeta; webhooks de confirmación | HTTPS, JSON | RF-20, RF-22, RF-23, RF-24 |
| Proveedor de IA | Extracción de datos estructurados de una frase; límite de 10 s | HTTPS, JSON | RF-37 |
| Servicio de correo | Correos transaccionales | SMTP o API | RF-06, RF-24, RF-33, RF-34, RF-41 |
| WhatsApp Business Cloud (extra) | Avisos por WhatsApp | HTTPS, JSON | RF-51 |
| Google Calendar (extra) | Eventos de reservas | HTTPS, OAuth | RF-52 |
| Sistema de factura electrónica de Hacienda (extra) | Comprobantes | HTTPS | RF-53 |

#### 3.1.3 Interfaces de comunicación

- API REST sobre HTTPS, con cuerpos JSON, versionada bajo `/api/v1`, con contrato OpenAPI generado con Scramble.
- Tiempo real por WebSockets, con canales privados por barbería y por persona (RF-35 y RF-36).
- Avisos de las pasarelas por webhooks con verificación de origen.

### 3.2 Requisitos funcionales

Son 53 requisitos. Cada uno tiene su clase (prioridad), enunciado, actores, precondiciones, flujo, reglas de negocio relacionadas, criterios de aceptación y método de verificación. Los criterios usan datos de la barbería de referencia.

**Plazos y umbrales comunes.** "En la misma operación" significa dentro de la misma solicitud, sin tareas diferidas. "Dentro de 60 s" se aplica a los correos. Las alternativas a un espacio no disponible son hasta 3 espacios del mismo día y servicio, los de inicio más próximo a la hora pedida.

#### 3.2.1 Barbería y configuración

##### RF-01 · Alta de la barbería y de su dueño
- **Clase:** Esencial
- **Enunciado:** El sistema deberá permitir registrar una barbería nueva y dejar a quien la registra como su dueño.
- **Actores:** Dueño.
- **Precondiciones:** La persona tiene una cuenta.
- **Flujo:** 1) La persona indica nombre, ciudad, contacto y, de forma opcional, el logo (RF-46). 2) El sistema crea la barbería sin servicios, sin horario y con la modalidad de cobro "sin pago". 3) La persona queda como dueño.
- **Reglas:** MT-01, MT-04, UP-01, UP-05, UP-07.
- **Criterios de aceptación:**
  - Al registrar "Barbería Pura Navaja", la persona queda como su dueña.
  - Una barbería sin servicio, sin colaborador o sin horario no ofrece espacios y muestra "Esta barbería aún no está lista para recibir reservas".
  - El dueño no ve ningún dato de otras barberías.
- **Verificación:** Prueba automatizada.

##### RF-02 · Enlace público de la barbería
- **Clase:** Esencial
- **Enunciado:** El sistema deberá asignar a cada barbería un enlace público único, derivado de su nombre, por el que sus clientes llegan a ella.
- **Actores:** Cliente; sistema.
- **Precondiciones:** La barbería existe.
- **Flujo:** 1) Al crearse la barbería, el sistema genera el enlace con el nombre en minúsculas, sin tildes y con guiones. 2) Si ya existe, agrega un número. 3) El enlace abre la barbería, con su logo y sus servicios. 4) El enlace no cambia si el dueño cambia el nombre.
- **Reglas:** MT-07, MT-08.
- **Criterios de aceptación:**
  - "Barbería Pura Navaja" recibe el enlace `pura-navaja`; una segunda barbería con el mismo nombre recibe `pura-navaja-2`.
  - Un enlace inexistente muestra "Esta barbería no existe".
  - Renombrar la barbería no cambia su enlace.
- **Verificación:** Prueba automatizada.

##### RF-03 · Catálogo de servicios
- **Clase:** Esencial
- **Enunciado:** El sistema deberá permitir al dueño crear, editar, activar e inactivar los servicios de su barbería, cada uno con nombre, duración, precio e imagen.
- **Actores:** Dueño.
- **Precondiciones:** La barbería existe.
- **Flujo:** 1) El dueño registra o edita un servicio. 2) Elige qué colaboradores lo ofrecen. 3) Lo activa. Un servicio con reservas pasadas solo se inactiva; no se elimina.
- **Reglas:** SV-01, SV-02, SV-03, SV-04, SV-05, SV-07, UP-03, MT-04.
- **Criterios de aceptación:**
  - Una duración de 7 min o de 200 min, o un precio de ₡0, se rechazan indicando el campo.
  - Al subir "Corte clásico" de ₡7.000 a ₡7.500, las reservas ya hechas conservan ₡7.000 y 30 min.
  - Un servicio sin colaborador activo que lo ofrezca no es reservable.
  - Un servicio inactivo no se reserva, pero se ve en el historial.
- **Verificación:** Prueba automatizada.

##### RF-04 · Horario de la barbería
- **Clase:** Esencial
- **Enunciado:** El sistema deberá permitir al dueño definir el horario semanal de la barbería, sus días cerrados y sus excepciones por fecha.
- **Actores:** Dueño.
- **Precondiciones:** La barbería existe.
- **Flujo:** 1) El dueño define las franjas de cada día. 2) Marca feriados o cierres especiales. 3) Guarda. 4) Si hay reservas confirmadas fuera del nuevo horario, el sistema se las muestra sin modificarlas.
- **Reglas:** HO-01, HO-03, HO-04, UP-03.
- **Criterios de aceptación:**
  - Si la barbería cierra el domingo, ningún espacio de domingo aparece.
  - Marcar un feriado como cerrado elimina sus espacios; las reservas ya confirmadas ese día aparecen en una lista para el dueño.
  - Dos franjas que se solapan el mismo día se rechazan.
  - Todas las horas se muestran en hora de Costa Rica.
- **Verificación:** Prueba automatizada.

##### RF-05 · Colaboradores, servicios habilitados y horarios
- **Clase:** Esencial
- **Enunciado:** El sistema deberá permitir al dueño crear colaboradores, asignarles los servicios que ofrecen y definir su horario semanal y sus pausas.
- **Actores:** Dueño.
- **Precondiciones:** La barbería tiene horario.
- **Flujo:** 1) El dueño crea la cuenta del colaborador con nombre, correo y contraseña temporal. 2) Asigna servicios, horario y pausas. 3) Puede activarlo o desactivarlo. 4) Puede crear su propio perfil de colaborador.
- **Reglas:** CO-01, CO-02, CO-03, HO-02, UP-04, UP-05, UP-07, SE-07.
- **Criterios de aceptación:**
  - Un horario que empieza antes de la apertura de la barbería se rechaza.
  - Desactivar a un colaborador con reservas futuras confirmadas se rechaza hasta que el dueño las reasigne o las cancele.
  - Kevin, sin el servicio infantil habilitado, no aparece para "Corte infantil".
  - El dueño con perfil de colaborador recibe reservas en su agenda y conserva el rol de dueño.
  - La contraseña temporal cumple la política de contraseñas (SE-07).
- **Verificación:** Prueba automatizada.

##### RF-06 · Invitación de colaboradores por correo
- **Clase:** Complementaria
- **Enunciado:** El sistema deberá permitir al dueño invitar a un colaborador por correo para que cree su propia contraseña.
- **Actores:** Dueño; colaborador.
- **Precondiciones:** RF-05 disponible.
- **Flujo:** 1) El dueño indica el correo y los servicios del colaborador. 2) El sistema envía un enlace de invitación, válido por 7 días y de un solo uso. 3) El colaborador lo abre y crea su contraseña. 4) La cuenta queda activa. El dueño ve las invitaciones pendientes y puede cancelarlas.
- **Reglas:** UP-04, SE-07, NO-03.
- **Criterios de aceptación:**
  - El enlace deja de funcionar a los 7 días y tras su primer uso.
  - Un colaborador invitado solo accede a su agenda y a su barbería.
  - Una invitación pendiente se puede cancelar.
- **Verificación:** Prueba automatizada.

##### RF-07 · Ausencias y bloqueos de agenda
- **Clase:** Complementaria
- **Enunciado:** El sistema deberá permitir al dueño bloquear la agenda de un colaborador por horas o por días.
- **Actores:** Dueño.
- **Precondiciones:** Colaborador activo.
- **Flujo:** 1) El dueño elige colaborador, fechas y, opcionalmente, horas. 2) Si hay reservas confirmadas dentro del bloqueo, el sistema las lista y exige resolverlas (reprogramar o cancelar). 3) Guarda el bloqueo.
- **Reglas:** CO-06, DI-01, CA-04.
- **Criterios de aceptación:**
  - Un bloqueo de 14:00 a 16:00 elimina esos espacios del colaborador y no los de otros.
  - Guardar un bloqueo sobre una reserva confirmada sin resolverla se rechaza.
  - Un bloqueo de varios días funciona igual que uno de horas.
  - El dueño puede ver y quitar los bloqueos vigentes.
- **Verificación:** Prueba automatizada.

##### RF-08 · Políticas de cobro y cancelación
- **Clase:** Esencial
- **Enunciado:** El sistema deberá permitir al dueño configurar la modalidad de cobro de su barbería, el porcentaje del depósito y el plazo de cancelación gratuita.
- **Actores:** Dueño.
- **Precondiciones:** La barbería existe.
- **Flujo:** 1) El dueño elige la modalidad: sin pago, depósito o pago total. 2) Si es depósito, indica el porcentaje. 3) Indica el plazo de cancelación gratuita en horas. 4) El sistema valida; el cambio aplica a las reservas nuevas y queda en el registro técnico.
- **Reglas:** PA-01, CA-01, MT-04, SE-04.
- **Criterios de aceptación:**
  - Un depósito de 5 % o de 120 % se rechaza; 30 % se acepta.
  - Cambiar el plazo de 4 h a 12 h no cambia las reservas ya creadas.
  - Cada cambio genera un registro con autor, fecha, valor anterior y valor nuevo.
- **Verificación:** Prueba automatizada.

##### RF-09 · Activación de bonos y lista de espera
- **Clase:** Esencial
- **Enunciado:** El sistema deberá permitir al dueño activar o desactivar la venta de bonos y la lista de espera de su barbería.
- **Actores:** Dueño.
- **Precondiciones:** La barbería existe.
- **Flujo:** 1) El dueño activa o desactiva cada función. 2) El cambio aplica a las operaciones nuevas y queda en el registro técnico.
- **Reglas:** BO-01, LE-01, MT-04, SE-04.
- **Criterios de aceptación:**
  - Con la lista de espera desactivada, el cliente no ve la opción de anotarse.
  - Con los bonos desactivados, no se pueden comprar bonos nuevos; los ya comprados siguen vigentes.
  - Desactivar una función no cancela las ofertas ni los bonos en curso.
- **Verificación:** Prueba automatizada.


#### 3.2.2 Disponibilidad y reservas

##### RF-10 · Consulta de disponibilidad
- **Clase:** Esencial
- **Enunciado:** El sistema deberá mostrar al cliente los espacios reales disponibles para un servicio, un barbero (o cualquiera) y un día.
- **Actores:** Cliente.
- **Precondiciones:** La barbería tiene al menos un servicio reservable. El cliente llega por el enlace público de la barbería (RF-02).
- **Flujo:** 1) El cliente ve los servicios activos, cada uno con su imagen, duración y precio con el IVA desglosado, y elige uno. 2) Elige el barbero o "cualquiera disponible" y el día. 3) El sistema calcula los espacios con el horario de la barbería y el del colaborador, sus pausas, bloqueos y reservas, y los muestra por hora. 4) Si no hay ninguno ese día, muestra los 3 días siguientes con espacios y, si la barbería activó la lista de espera, la opción de anotarse (RF-30).
- **Reglas:** DI-01, DI-02, DI-03, DI-04, DI-05, HO-01, HO-02, HO-03, SV-02, SV-05, SV-07, CO-01.
- **Criterios de aceptación:**
  - Para "Corte + barba" (60 min más 5 de limpieza) con Mauricio un martes (9:00 a 19:00, pausa de 12:00 a 13:00): el primer espacio es 9:00, el último antes de la pausa es 10:45 y el último del día es 17:45.
  - Ningún espacio empieza con menos de 60 min de anticipación ni a más de 30 días.
  - Un domingo no tiene espacios.
  - Un espacio con reserva temporal vigente no aparece.
  - Con "cualquiera disponible", cada espacio muestra el barbero asignado.
  - Un servicio sin imagen se muestra sin ella.
- **Verificación:** Prueba automatizada.

##### RF-11 · Creación de reserva
- **Clase:** Esencial
- **Enunciado:** El sistema deberá permitir al cliente reservar un espacio disponible sin que pueda duplicarse.
- **Actores:** Cliente; pasarela de pago.
- **Precondiciones:** Cliente autenticado, que puede no tener aún rol en la barbería. El espacio aparecía disponible.
- **Flujo:** 1) El cliente elige un espacio y confirma. 2) Si la persona es dueño o colaborador de esa barbería, el sistema responde 403 con el tipo `prohibido`. 3) El sistema vuelve a validar la disponibilidad en ese instante. 4) Si la persona no tiene rol en la barbería, el sistema crea su membresía de cliente en la misma transacción que la reserva. 5) Si no requiere pago, confirma la reserva en la misma operación; si requiere pago, aparta el espacio como reserva temporal y sigue con RF-20; si usa bono, sigue con RF-28. 6) Si el espacio ya no está disponible, responde con el error de tipo estable `espacio-no-disponible` y hasta 3 espacios alternativos, y no crea la membresía.
- **Reglas:** RE-01, RE-02, RE-03, RE-05, RE-06, RE-07, SV-03, DI-01, PA-06, CL-05, UP-07, UP-09.
- **Criterios de aceptación:**
  - Si dos clientes piden a la vez el mismo espacio de Daniela, exactamente uno lo obtiene y el otro recibe `espacio-no-disponible` con hasta 3 alternativas; no existe ninguna reserva duplicada.
  - La reserva guarda el precio, la duración y el plazo de cancelación vigentes al reservar.
  - Un cliente no puede reservar dos espacios solapados en la misma barbería.
  - Un corte infantil se registra a nombre de una persona adulta responsable.
  - Una reserva confirmada aparece en la agenda del colaborador (RF-36).
  - Una persona sin membresía en la barbería queda como cliente tras su primera reserva.
  - Si la creación de la reserva falla y se revierte (por ejemplo, con `espacio-no-disponible`), no queda ninguna membresía creada.
  - Un dueño o colaborador de esa barbería que intenta reservar recibe 403 con el tipo `prohibido`.
- **Verificación:** Prueba automatizada de concurrencia.

##### RF-12 · Reserva asistida
- **Clase:** Complementaria
- **Enunciado:** El sistema deberá permitir al dueño registrar una reserva para una persona que llega o llama, sin que tenga cuenta.
- **Actores:** Dueño.
- **Precondiciones:** La barbería tiene servicios y horarios.
- **Flujo:** 1) El dueño busca o crea un cliente asistido con nombre y teléfono, y correo opcional. 2) Elige servicio, barbero y espacio. 3) El sistema confirma la reserva en la misma operación, sin cobro en línea.
- **Reglas:** CL-06, RE-01, RE-07, DI-01, UP-03.
- **Criterios de aceptación:**
  - La reserva asistida ocupa el espacio igual que una reserva en línea: nadie más puede tomarlo.
  - Un cliente asistido sin correo no recibe recordatorios.
  - Un cliente asistido de una barbería no existe en otra.
  - Si el espacio ya no está libre, el sistema responde `espacio-no-disponible`.
- **Verificación:** Prueba automatizada.

##### RF-13 · Consulta de reservas por rol
- **Clase:** Esencial
- **Enunciado:** El sistema deberá mostrar a cada persona únicamente las reservas de su ámbito: al cliente las suyas, al colaborador las de su agenda y al dueño las de todos los colaboradores de su barbería.
- **Actores:** Cliente, colaborador, dueño.
- **Precondiciones:** Persona autenticada.
- **Flujo:** 1) La persona abre su vista. 2) Filtra por día y estado y, el dueño, también por colaborador. 3) El sistema lista las reservas de su ámbito.
- **Reglas:** CL-04, CO-04, UP-02, UP-06, MT-02.
- **Criterios de aceptación:**
  - Si Kevin pide la agenda de Daniela, recibe lo mismo que ante un recurso inexistente (404).
  - Un cliente que cambia el identificador de una reserva por el de otra persona no obtiene datos.
  - El dueño ve las reservas de todos los colaboradores de su barbería y de ninguna otra.
- **Verificación:** Prueba automatizada.

##### RF-14 · Detalle de una reserva
- **Clase:** Esencial
- **Enunciado:** El sistema deberá mostrar el detalle de una reserva en un panel que se abre desde la agenda o desde la lista del cliente.
- **Actores:** Cliente, colaborador, dueño.
- **Precondiciones:** La persona tiene acceso a la reserva (RF-13).
- **Flujo:** 1) La persona toca una reserva. 2) El panel muestra servicio, barbero, fecha, estado, y saldo pendiente. 3) El dueño ve además el pago, el estado del depósito y el historial de estados con autor y fecha. 4) Desde el panel, cada rol ejecuta las acciones que le permiten RF-15, RF-16 y RF-18.
- **Reglas:** CO-04, PA-10, RE-09, UP-02.
- **Criterios de aceptación:**
  - El colaborador ve el saldo pendiente de sus reservas, pero no el detalle de pagos ni los ingresos.
  - El cliente no ve el historial de autores ni reservas ajenas.
  - El dueño ve el historial de estados con fecha y autor.
- **Verificación:** Prueba automatizada y demostración.

##### RF-15 · Reprogramación de reservas
- **Clase:** Esencial
- **Enunciado:** El sistema deberá permitir al cliente y al dueño cambiar la fecha, la hora o el barbero de una reserva confirmada.
- **Actores:** Cliente, dueño.
- **Precondiciones:** Reserva confirmada y, para el cliente, dentro del plazo gratuito.
- **Flujo:** 1) La persona elige el nuevo espacio con la misma consulta de disponibilidad de RF-10. 2) El sistema valida la disponibilidad. 3) Si está libre, mueve la reserva y libera el espacio original; si no, deja todo igual. 4) Si reprograma el dueño, el cliente recibe un correo y puede cancelar sin penalización. El dueño reprograma desde el panel de RF-14.
- **Reglas:** RE-08, CA-01, CA-05, CA-08, DI-01, RE-01, CA-07.
- **Criterios de aceptación:**
  - Con plazo de 4 h, reprogramar 5 h antes funciona y 3 h antes se rechaza con el error `plazo-vencido`.
  - Si el nuevo espacio fue tomado un instante antes, la reserva original sigue intacta.
  - Una tercera reprogramación se rechaza.
  - No se vuelve a cobrar depósito.
  - Cuando reprograma el dueño, el cliente recibe el correo dentro de 60 s y puede cancelar sin perder el depósito.
  - El espacio original queda libre y, con RF-31, se ofrece a la lista de espera.
- **Verificación:** Prueba automatizada.

##### RF-16 · Cancelación y liberación del espacio
- **Clase:** Esencial
- **Enunciado:** El sistema deberá permitir al cliente cancelar su reserva y al dueño cancelar cualquiera de su barbería, liberando el espacio.
- **Actores:** Cliente, dueño.
- **Precondiciones:** Reserva confirmada (o temporal, en el caso del cliente).
- **Flujo:** 1) La persona cancela. 2) El sistema clasifica la cancelación como a tiempo, tardía o por la barbería. 3) Cambia el estado y libera el espacio en la misma operación. 4) RF-17 aplica la consecuencia económica. El dueño cancela desde el panel de RF-14.
- **Reglas:** CA-01, CA-04, CA-06, CA-07, RE-04, RE-09, SE-04.
- **Criterios de aceptación:**
  - Cancelar cambia el estado y libera el espacio en la misma operación; con RF-31 activa, el espacio se ofrece a la lista de espera.
  - Repetir la cancelación no cambia nada más.
  - Un cliente no puede cancelar reservas ajenas.
  - Una cancelación del dueño queda como "cancelada por la barbería", se registra en el registro técnico y avisa al cliente (RF-34).
- **Verificación:** Prueba automatizada.

##### RF-17 · Consecuencia económica de la cancelación
- **Clase:** Esencial
- **Enunciado:** El sistema deberá marcar el depósito como a devolver y reintegrar la sesión del bono cuando la cancelación es a tiempo o la hace la barbería, y retener el depósito y consumir la sesión cuando es tardía o hay ausencia.
- **Actores:** Sistema.
- **Precondiciones:** Reserva cancelada o ausente con depósito pagado o sesión de bono apartada.
- **Flujo:** 1) El sistema recibe el resultado de RF-16 o RF-18. 2) Aplica el caso que corresponde según CA-02, CA-03 y CA-04. 3) Registra el resultado.
- **Reglas:** CA-02, CA-03, CA-04, CA-06, BO-05.
- **Criterios de aceptación:**
  - A tiempo: el depósito queda "a devolver" una sola vez, y con RF-24 se reembolsa.
  - Tardía o ausencia: el depósito queda retenido y la sesión se consume.
  - Cancelada por la barbería: el cliente recupera todo.
  - La parte del bono se aplica según RF-28.
- **Verificación:** Prueba automatizada.

##### RF-18 · Marcar reserva atendida o ausente
- **Clase:** Esencial
- **Enunciado:** El sistema deberá permitir al colaborador marcar sus reservas como atendidas o ausentes, y al dueño marcar cualquiera de su barbería.
- **Actores:** Colaborador, dueño.
- **Precondiciones:** Reserva confirmada cuya hora de inicio ya pasó.
- **Flujo:** 1) La persona abre la reserva desde la agenda o el panel de RF-14. 2) Marca "atendida" o "ausente". 3) El sistema cierra la reserva y, si fue ausencia, activa RF-17.
- **Reglas:** CO-05, RE-04, RE-09, CA-03.
- **Criterios de aceptación:**
  - Marcar antes de la hora de inicio se rechaza; marcar "ausente" antes de 10 min de tolerancia también.
  - Una ausencia retiene el depósito y, con bono, consume la sesión.
  - Un colaborador no puede marcar reservas de otro.
  - Un estado final no se puede cambiar.
- **Verificación:** Prueba automatizada.

##### RF-19 · Expiración de reservas temporales
- **Clase:** Esencial
- **Enunciado:** El sistema deberá expirar las reservas temporales cuyo pago no se confirmó en 10 minutos y liberar el espacio.
- **Actores:** Planificador de tareas.
- **Precondiciones:** Existe una reserva temporal.
- **Flujo:** 1) Una tarea en cola revisa las reservas temporales vencidas. 2) Las pasa a expiradas. 3) Libera el espacio.
- **Reglas:** RE-05, RE-04, NO-04, CA-07.
- **Criterios de aceptación:**
  - Una reserva temporal creada a las 12:00:00 sin pago está expirada a más tardar a las 12:11:00 y su espacio vuelve a aparecer.
  - Si el aviso de pago llega antes del vencimiento, la reserva se confirma y no expira.
  - Ejecutar la tarea dos veces seguidas produce el mismo resultado.
  - La tarea corre en cola y no depende de que alguien abra una pantalla; con RF-31, el espacio liberado se ofrece a la lista de espera.
- **Verificación:** Prueba automatizada.


#### 3.2.3 Pagos

##### RF-20 · Cobro de una reserva
- **Clase:** Esencial
- **Enunciado:** El sistema deberá permitir al cliente pagar el depósito o el total de su reserva con la pasarela activa de la barbería.
- **Actores:** Cliente; pasarela de pago.
- **Precondiciones:** Reserva temporal vigente en una barbería con cobro y pasarela configurados.
- **Flujo:** 1) El sistema calcula el monto según la modalidad de la barbería. 2) Crea el cobro en la pasarela activa y lleva al cliente a pagar. 3) La reserva sigue temporal hasta recibir el aviso de la pasarela (RF-22).
- **Reglas:** PA-01, PA-02, PA-06, PA-09, RE-05, MT-04.
- **Criterios de aceptación:**
  - Con depósito de 30 % y un degradado de ₡8.500, el monto es ₡2.550; con pago total, ₡8.500.
  - Una barbería "sin pago" no genera ningún cobro.
  - Con ONVO Pay el cliente puede pagar con tarjeta o SINPE Móvil; con PayPal, con tarjeta.
  - Todos los cobros se ejecutan en el modo de pruebas de la pasarela; no se mueve dinero real.
  - Si el cliente cierra la página tras pagar, la reserva se confirma igual cuando llega el aviso de la pasarela.
- **Verificación:** Prueba automatizada con pasarela simulada.

##### RF-21 · Reserva confirmada tras el pago
- **Clase:** Esencial
- **Enunciado:** El sistema deberá mostrar al cliente la reserva confirmada, con su resumen, cuando el pago se acredite.
- **Actores:** Cliente.
- **Precondiciones:** Pago confirmado por la pasarela.
- **Flujo:** 1) El cliente espera con la cuenta regresiva de la reserva temporal. 2) Al confirmarse el pago, el sistema muestra la reserva confirmada con servicio, fecha, barbero, depósito pagado y saldo en el local. 3) Si el pago falla, muestra el error y permite reintentar hasta que la reserva expire. 4) Si la reserva expira, muestra el error `plazo-vencido` y ofrece elegir otro espacio.
- **Reglas:** PA-03, PA-06, RE-05.
- **Criterios de aceptación:**
  - La pantalla de confirmación aparece solo cuando el aviso de la pasarela confirmó el pago, nunca por la redirección del navegador.
  - Muestra el depósito pagado y el saldo pendiente correctos.
  - Un pago fallido deja la reserva temporal y permite reintentar.
  - Una reserva expirada muestra el error y ofrece volver a reservar.
- **Verificación:** Prueba automatizada y demostración.

##### RF-22 · Confirmación de pagos por webhook
- **Clase:** Esencial
- **Enunciado:** El sistema deberá recibir los avisos de la pasarela, rechazar los de origen no verificado, procesar una sola vez cada evento y, con cada evento válido, confirmar la reserva, activar el bono o registrar el fallo del pago.
- **Actores:** Pasarela de pago.
- **Precondiciones:** Existe un cobro creado.
- **Flujo:** 1) Llega un evento. 2) El sistema verifica su origen. 3) Comprueba si ya lo procesó. 4) Si es nuevo y el pago se acreditó, confirma la reserva o activa el bono; si el pago falló, lo registra y deja la reserva temporal. 5) Si la reserva ya expiró y su espacio fue ocupado, aplica PA-07 (reembolso con RF-24).
- **Reglas:** PA-03, PA-04, PA-05, PA-06, BO-02.
- **Criterios de aceptación:**
  - Un evento con firma inválida se rechaza y no cambia ningún estado.
  - El mismo evento recibido 10 veces deja un solo pago registrado y una sola reserva confirmada.
  - Una redirección de éxito del navegador sin evento de la pasarela no confirma la reserva.
  - Tras procesar un evento de pago acreditado, la reserva queda en estado confirmada; tras uno fallido, sigue temporal.
- **Verificación:** Prueba automatizada.

##### RF-23 · Pasarela de pago por barbería
- **Clase:** Esencial
- **Enunciado:** El sistema deberá permitir al dueño elegir si su barbería cobra con ONVO Pay o con PayPal, sin cambiar el resto del flujo.
- **Actores:** Dueño.
- **Precondiciones:** La barbería usa una modalidad con cobro.
- **Flujo:** 1) El dueño selecciona la pasarela. 2) El sistema la activa para los cobros nuevos. 3) Los cobros en curso siguen con su pasarela original.
- **Reglas:** PA-02, MT-04, SE-04.
- **Criterios de aceptación:**
  - Una barbería tiene una sola pasarela activa.
  - Un cobro iniciado con ONVO Pay se completa por ONVO Pay aunque el dueño cambie a PayPal.
  - Con cualquiera de las dos, el cliente recorre el mismo flujo de reserva y recibe las mismas respuestas.
  - El cambio queda en el registro técnico.
- **Verificación:** Prueba automatizada.

##### RF-24 · Reembolsos
- **Clase:** Complementaria
- **Enunciado:** El sistema deberá reembolsar el depósito o el pago en tres casos: cancelación a tiempo, cancelación de la barbería y pago confirmado después de que el espacio fue ocupado.
- **Actores:** Pasarela de pago; dueño (consulta); cliente.
- **Precondiciones:** Pago confirmado.
- **Flujo:** 1) Uno de los tres casos solicita el reembolso. 2) El sistema lo pide a la misma pasarela. 3) Registra el resultado y, al completarse, avisa al cliente por correo. 4) Si falla, queda pendiente y visible para el dueño, que puede reintentarlo.
- **Reglas:** PA-07, PA-08, CA-02, CA-04, NO-09, SE-04.
- **Criterios de aceptación:**
  - Cancelar a tiempo una reserva con depósito genera exactamente un reembolso, aunque se repita la solicitud.
  - Un reembolso fallido aparece como pendiente en la vista del dueño.
  - Un pago confirmado tras la expiración, con el espacio ya ocupado, termina en reembolso y aviso al cliente.
  - El cliente recibe un correo dentro de 60 s de completarse el reembolso.
  - Cada reembolso queda en el registro técnico.
- **Verificación:** Prueba automatizada.

##### RF-25 · Reporte de ingresos
- **Clase:** Esencial
- **Enunciado:** El sistema deberá mostrar al dueño, para un rango de fechas, el total cobrado en línea neto de reembolsos, el total de bonos vendidos y, aparte, el valor de los servicios atendidos.
- **Actores:** Dueño.
- **Precondiciones:** Existen pagos o reservas atendidas.
- **Flujo:** 1) El dueño elige un rango de fechas. 2) El sistema calcula las tres cifras. 3) Las muestra con su detalle.
- **Reglas:** PA-11, PA-10, MT-02, CO-04.
- **Criterios de aceptación:**
  - Los totales coinciden con los pagos y reembolsos de pruebas del rango.
  - Un colaborador no puede abrir el reporte.
  - Un rango sin datos muestra un estado vacío, no un error.
  - El reporte solo incluye datos de la barbería del dueño.
- **Verificación:** Prueba automatizada.


#### 3.2.4 Bonos prepagados

##### RF-26 · Definición de bonos
- **Clase:** Esencial
- **Enunciado:** El sistema deberá permitir al dueño crear, editar y retirar de la venta los bonos de su barbería.
- **Actores:** Dueño.
- **Precondiciones:** La barbería activó los bonos (RF-09).
- **Flujo:** 1) El dueño define nombre, sesiones, precio, vigencia en días y servicios cubiertos. 2) Lo publica. 3) Puede retirarlo de la venta.
- **Reglas:** BO-01, MT-04, UP-03.
- **Criterios de aceptación:**
  - Un bono con 0 sesiones o sin servicios cubiertos se rechaza.
  - "Bono 5 cortes" cubre solo corte clásico y degradado.
  - Retirar un bono de la venta no afecta los saldos de quienes ya lo compraron.
  - Cambiar el precio no cambia bonos ya comprados.
- **Verificación:** Prueba automatizada.

##### RF-27 · Compra de un bono
- **Clase:** Esencial
- **Enunciado:** El sistema deberá permitir al cliente comprar un bono y activarlo cuando se confirme su pago.
- **Actores:** Cliente; pasarela de pago.
- **Precondiciones:** Cliente autenticado, que puede no tener aún rol en la barbería; bono a la venta.
- **Flujo:** 1) El cliente elige el bono. 2) Si la persona es dueño o colaborador de esa barbería, el sistema responde 403 con el tipo `prohibido`; si no tiene rol en ella, crea su membresía de cliente en la misma transacción que registra la compra. 3) Paga con RF-20. 4) Al recibirse el aviso de la pasarela (RF-22), el bono se activa y su vigencia cuenta desde ese momento.
- **Reglas:** BO-02, BO-07, PA-03, MT-01, UP-07, UP-09.
- **Criterios de aceptación:**
  - Sin aviso de pago confirmado no hay bono.
  - El mismo evento de pago recibido dos veces no duplica las sesiones.
  - Tras el pago, el saldo es de 5 sesiones y vence a los 90 días.
  - Un bono comprado en una barbería no se puede usar en otra.
  - Una persona sin membresía en la barbería queda como cliente tras su primera compra de un bono.
  - Si el registro de la compra falla y se revierte, no queda ninguna membresía creada.
  - Un dueño o colaborador de esa barbería que intenta comprar un bono recibe 403 con el tipo `prohibido`.
- **Verificación:** Prueba automatizada.

##### RF-28 · Reserva con bono
- **Clase:** Esencial
- **Enunciado:** El sistema deberá permitir al cliente reservar usando una sesión de un bono aplicable, sin que el saldo pueda quedar negativo.
- **Actores:** Cliente.
- **Precondiciones:** El cliente tiene un bono aplicable.
- **Flujo:** 1) El cliente elige pagar con bono. 2) El sistema aparta una sesión y confirma la reserva sin depósito. 3) Al terminar la reserva, RF-17 reintegra o consume la sesión.
- **Reglas:** BO-03, BO-04, BO-05, BO-06, BO-07, BO-08, RE-07, CA-02, CA-03.
- **Criterios de aceptación:**
  - Con saldo de 1 sesión y dos reservas simultáneas con ese bono, una se confirma, la otra recibe `saldo-insuficiente` y el saldo termina en 0, no en -1.
  - Una reserva fuera de la vigencia del bono se rechaza con `bono-vencido`.
  - Con dos bonos aplicables se usa primero el que vence antes.
  - Un servicio no cubierto no permite usar el bono ni cobra diferencias.
  - Cancelar a tiempo reintegra la sesión; la ausencia la consume.
- **Verificación:** Prueba automatizada de concurrencia.

##### RF-29 · Consulta de saldo de bonos
- **Clase:** Esencial
- **Enunciado:** El sistema deberá mostrar al cliente el saldo y la vigencia de sus bonos, y al dueño los bonos de los clientes de su barbería.
- **Actores:** Cliente, dueño.
- **Precondiciones:** Existen bonos comprados.
- **Flujo:** 1) La persona abre la vista. 2) El sistema muestra saldo y vigencia, con las reservas ligadas. Los movimientos (compra, apartado, consumo, reintegro) se registran internamente.
- **Reglas:** BO-09, CL-04, MT-02.
- **Criterios de aceptación:**
  - El saldo mostrado es igual a la suma de los movimientos registrados.
  - Un cliente no ve bonos de otra persona.
  - El dueño solo ve bonos vendidos en su barbería.
  - Un bono vencido muestra saldo 0 y la fecha de vencimiento.
- **Verificación:** Prueba automatizada.


#### 3.2.5 Lista de espera

##### RF-30 · Unirse y salir de la lista de espera
- **Clase:** Esencial
- **Enunciado:** El sistema deberá permitir al cliente anotarse en una fila para un servicio, un día y un barbero (o cualquiera) cuando no hay espacio, y salir de ella.
- **Actores:** Cliente.
- **Precondiciones:** La barbería activó la lista de espera y no hay espacios para esa combinación. La persona puede no tener aún rol en la barbería.
- **Flujo:** 1) El cliente elige "avisarme si se libera". 2) Si la persona es dueño o colaborador de esa barbería, el sistema responde 403 con el tipo `prohibido`; si no tiene rol en ella, crea su membresía de cliente en la misma transacción que agrega la entrada a la fila. 3) El sistema lo agrega al final de la fila. 4) El cliente puede salir de la fila en cualquier momento.
- **Reglas:** LE-01, LE-02, LE-06, CL-04, UP-07, UP-09.
- **Criterios de aceptación:**
  - Una cuarta fila activa del mismo cliente se rechaza.
  - La fila queda ordenada por hora de ingreso.
  - Si hay espacios disponibles para esa combinación, el sistema los muestra en lugar de permitir anotarse.
  - Un cliente no ve ni modifica las filas de otros.
  - Una persona sin membresía en la barbería queda como cliente tras su primera anotación en una fila.
  - Si el registro en la fila falla y se revierte, no queda ninguna membresía creada.
  - Un dueño o colaborador de esa barbería que intenta anotarse en una fila recibe 403 con el tipo `prohibido`.
- **Verificación:** Prueba automatizada.

##### RF-31 · Oferta de espacios liberados
- **Clase:** Esencial
- **Enunciado:** El sistema deberá ofrecer un espacio liberado a la primera persona compatible de la fila, apartárselo por 15 minutos y pasar a la siguiente si no responde.
- **Actores:** Planificador de tareas; cliente en la fila.
- **Precondiciones:** Una cancelación, expiración o reprogramación liberó un espacio.
- **Flujo:** 1) El sistema busca a la primera persona compatible. 2) Crea la oferta, aparta el espacio y la avisa por correo (RF-34) y con un aviso emergente (RF-35). 3) Si la oferta vence sin respuesta, la pasa a la siguiente. 4) Si la fila se agota, el espacio vuelve a ser público.
- **Reglas:** LE-03, LE-04, LE-05, LE-06, DI-05, CA-07.
- **Criterios de aceptación:**
  - Con tres personas en la fila, la oferta llega solo a la primera compatible.
  - Un hueco de 30 min no se ofrece a quien espera un servicio de 60 min; pasa al siguiente compatible.
  - Si faltan menos de 60 min para la hora, no se ofrece.
  - Durante la oferta el espacio no aparece disponible para otros.
  - Una oferta vencida pasa a la siguiente persona en un máximo de 1 minuto.
- **Verificación:** Prueba automatizada.

##### RF-32 · Respuesta a una oferta
- **Clase:** Esencial
- **Enunciado:** El sistema deberá permitir al cliente aceptar o rechazar el espacio que se le ofrece.
- **Actores:** Cliente.
- **Precondiciones:** Oferta vigente a su nombre.
- **Flujo:** 1) El cliente abre la oferta, desde la lista de espera o desde el aviso emergente. 2) Si acepta, completa el pago o usa su bono dentro de los 15 minutos. 3) Si rechaza, la oferta pasa a la siguiente persona en la misma operación.
- **Reglas:** LE-04, LE-05, RE-07, PA-06.
- **Criterios de aceptación:**
  - Aceptar con un bono aplicable confirma la reserva y saca al cliente de la fila.
  - Si el pago no se confirma antes de que venzan los 15 minutos, la oferta se pierde.
  - Rechazar pasa la oferta al siguiente sin esperar el plazo.
  - Aceptar una oferta vencida devuelve el error `oferta-vencida`.
- **Verificación:** Prueba automatizada.


#### 3.2.6 Avisos y tiempo real

##### RF-33 · Recordatorios de la reserva
- **Clase:** Esencial
- **Enunciado:** El sistema deberá enviar por correo un recordatorio 24 horas y otro 2 horas antes de cada reserva confirmada.
- **Actores:** Planificador de tareas; servicio de correo; cliente.
- **Precondiciones:** Reserva confirmada con correo del cliente, y recordatorios activados (RF-47).
- **Flujo:** 1) Al confirmarse la reserva se programan los recordatorios. 2) Una tarea en cola los envía a su hora. 3) Si la reserva se cancela, expira o se marca ausente, no se envían.
- **Reglas:** NO-02, NO-03, NO-10, CL-06.
- **Criterios de aceptación:**
  - Una reserva hecha el lunes a las 9:00 para el martes a las 15:00 genera recordatorios el lunes a las 15:00 y el martes a las 13:00.
  - Una reserva hecha con menos de 2 h de anticipación no genera el recordatorio de 2 h, y una con menos de 24 h no genera el de 24 h.
  - Una reserva cancelada no envía recordatorios.
  - Si la tarea se reinicia, ningún recordatorio sale dos veces ni se pierde.
  - Un cliente que desactivó los recordatorios no los recibe, y uno asistido sin correo tampoco.
- **Verificación:** Prueba automatizada.

##### RF-34 · Avisos por correo de confirmación, oferta, cancelación y reprogramación
- **Clase:** Esencial
- **Enunciado:** El sistema deberá enviar un correo al cliente cuando se confirma su reserva, cuando se le ofrece un espacio y cuando la barbería cancela o reprograma su reserva.
- **Actores:** Planificador de tareas; servicio de correo; cliente.
- **Precondiciones:** Ocurre uno de los cuatro eventos.
- **Flujo:** 1) El evento genera el aviso. 2) Se encola. 3) La tarea lo envía dentro de 60 s. 4) Estos avisos no se pueden desactivar (NO-10).
- **Reglas:** NO-01, NO-03, NO-07, NO-10, CL-06.
- **Criterios de aceptación:**
  - Al confirmarse una reserva, el correo de confirmación sale dentro de 60 s.
  - Una oferta de la lista de espera, una cancelación y una reprogramación de la barbería se avisan por correo dentro de 60 s.
  - Si el correo falla, la reserva no se revierte y el envío se reintenta.
  - Un cliente asistido sin correo no recibe mensajes.
- **Verificación:** Prueba automatizada.

##### RF-35 · Aviso emergente de ofertas
- **Clase:** Complementaria
- **Enunciado:** El sistema deberá mostrar a la persona a quien se le ofrece un espacio, con la aplicación abierta, un aviso emergente con la cuenta regresiva y las acciones de aceptar y rechazar.
- **Actores:** Cliente.
- **Precondiciones:** Oferta vigente y aplicación abierta en cualquier pantalla.
- **Flujo:** 1) Se crea la oferta (RF-31). 2) El sistema la emite por un canal privado del cliente. 3) La aplicación muestra el aviso. 4) Aceptar o rechazar sigue RF-32.
- **Reglas:** NO-08, LE-04, MT-02.
- **Criterios de aceptación:**
  - Con la aplicación abierta, el aviso aparece en menos de 5 s, en cualquier pantalla.
  - Solo recibe el aviso la persona a quien se le ofrece el espacio.
  - Con la aplicación cerrada, solo llega el correo.
  - El aviso desaparece al vencer la oferta o al responderla.
- **Verificación:** Prueba automatizada y demostración.

##### RF-36 · Agenda en vivo
- **Clase:** Esencial
- **Enunciado:** El sistema deberá actualizar las pantallas de agenda del dueño y del colaborador cuando una reserva entra, se reprograma, cambia de estado o se cancela, sin recargar.
- **Actores:** Dueño, colaborador.
- **Precondiciones:** La persona tiene abierta su agenda.
- **Flujo:** 1) Ocurre un cambio en una reserva. 2) El sistema emite el evento a quienes tienen derecho a verlo. 3) La pantalla lo refleja sin recargar.
- **Reglas:** NO-06, CO-04, MT-02.
- **Criterios de aceptación:**
  - Con la agenda abierta, una reserva nueva aparece en menos de 5 s sin recargar; una cancelación la quita en el mismo plazo.
  - El colaborador recibe solo los eventos de su agenda.
  - El dueño de otra barbería no recibe ningún evento.
  - Tras perder la conexión, la pantalla se reconecta y se resincroniza.
- **Verificación:** Prueba automatizada y demostración.


#### 3.2.7 Inteligencia artificial

##### RF-37 · Reserva asistida por IA
- **Clase:** Esencial
- **Enunciado:** El sistema deberá convertir una frase libre del cliente en una propuesta de reserva estructurada y validada, que el cliente confirma.
- **Actores:** Cliente; proveedor de IA.
- **Precondiciones:** Cliente autenticado; barbería con catálogo y horarios.
- **Flujo:** 1) El cliente escribe la frase. 2) El sistema envía al proveedor la frase, la fecha y la zona horaria de la barbería, y los nombres de los servicios activos y de los colaboradores activos de esa barbería. 3) Recibe datos estructurados (servicio, barbero opcional, fecha, hora o franja, campos faltantes). 4) Los valida contra el catálogo y la disponibilidad reales. 5) Muestra la propuesta con espacios reales. 6) El cliente confirma y sigue RF-11.
- **Reglas:** IA-01, IA-02, IA-03, IA-04, IA-05, IA-06, IA-07, DI-01, RE-01.
- **Criterios de aceptación:**
  - Con fecha de hoy miércoles 7 de octubre de 2026, "corte y barba el sábado en la tarde con Kevin" produce: Corte + barba, Kevin, sábado 10 de octubre de 2026, franja de 12:00 a 17:59 con los espacios reales de Kevin ese día.
  - Una frase con un barbero o servicio que no existe descarta ese dato, lo marca como inexistente y no inventa uno.
  - Una frase sin servicio devuelve el servicio como campo faltante.
  - Si el proveedor falla, responde algo inválido o no responde en 10 s, el cliente recibe un aviso y puede reservar manualmente; el resto del sistema sigue funcionando.
  - La propuesta no crea ninguna reserva por sí sola.
  - Una prueba con proveedor simulado comprueba que no se envían correo ni teléfono del cliente.
- **Verificación:** Prueba automatizada con proveedor simulado.


#### 3.2.8 Identidad, acceso y datos

##### RF-38 · Registro de cuenta
- **Clase:** Esencial
- **Enunciado:** El sistema deberá permitir crear una cuenta con nombre, correo, teléfono y contraseña, y exigir dos aceptaciones expresas y separadas.
- **Actores:** Cliente.
- **Precondiciones:** Ninguna.
- **Flujo:** 1) La persona indica sus datos y acepta por separado los términos y condiciones de BarberHub y el tratamiento de sus datos según la política de privacidad. 2) El sistema valida los datos y la contraseña. 3) Crea la cuenta y guarda la fecha de cada aceptación.
- **Reglas:** CL-01, CL-02, CL-09, SE-01, SE-07.
- **Criterios de aceptación:**
  - Un correo repetido, o un registro sin las dos aceptaciones, se rechaza.
  - Una contraseña de menos de 8 caracteres o sin mayúscula, minúscula, número o signo especial se rechaza, y el formulario muestra qué requisito falta.
  - La fecha de cada aceptación queda guardada.
  - Ninguna respuesta ni registro contiene la contraseña.
- **Verificación:** Prueba automatizada.

##### RF-39 · Inicio y cierre de sesión
- **Clase:** Esencial
- **Enunciado:** El sistema deberá permitir iniciar y cerrar sesión con correo y contraseña mediante un token personal.
- **Actores:** Cliente, colaborador, dueño.
- **Precondiciones:** La persona tiene una cuenta.
- **Flujo:** 1) La persona indica correo y contraseña. 2) El sistema valida y entrega un token personal. 3) Tras 5 intentos fallidos seguidos, bloquea nuevos intentos por 15 minutos. 4) Al cerrar sesión, el token se invalida.
- **Reglas:** SE-01, SE-05, UP-01.
- **Criterios de aceptación:**
  - Un sexto intento dentro de los 15 minutos posteriores a 5 fallos seguidos se rechaza con un mensaje que indica cuándo reintentar.
  - Cerrar sesión invalida el token: una solicitud posterior con ese token se rechaza.
  - Los mensajes de error no revelan si el correo existe.
- **Verificación:** Prueba automatizada.

##### RF-40 · Cambio de contraseña
- **Clase:** Esencial
- **Enunciado:** El sistema deberá permitir a la persona autenticada cambiar su contraseña indicando la actual, y obligar el cambio en el primer ingreso con una contraseña temporal.
- **Actores:** Cliente, colaborador, dueño.
- **Precondiciones:** Persona autenticada.
- **Flujo:** 1) La persona abre "Mi cuenta" y elige cambiar la contraseña. 2) Indica la actual y la nueva. 3) El sistema valida la política (SE-07) y la aplica. 4) Una cuenta con contraseña temporal solo puede usar esta función hasta cambiarla.
- **Reglas:** SE-01, SE-07, UP-04.
- **Criterios de aceptación:**
  - Una contraseña actual incorrecta se rechaza.
  - Una contraseña nueva que no cumple la política se rechaza.
  - Un colaborador con contraseña temporal no accede a otras pantallas hasta cambiarla.
- **Verificación:** Prueba automatizada.

##### RF-41 · Recuperación de contraseña
- **Clase:** Complementaria
- **Enunciado:** El sistema deberá permitir restablecer la contraseña mediante un enlace enviado por correo.
- **Actores:** Cliente, colaborador, dueño.
- **Precondiciones:** La persona tiene una cuenta.
- **Flujo:** 1) En el inicio de sesión elige "Olvidé mi contraseña" e indica su correo. 2) El sistema responde siempre lo mismo y, si la cuenta existe, envía un enlace válido por 60 minutos y de un solo uso. 3) La persona define una contraseña nueva que cumpla la política. 4) El sistema cierra todas sus sesiones y confirma el cambio.
- **Reglas:** SE-05, SE-06, SE-07.
- **Criterios de aceptación:**
  - La respuesta es idéntica si el correo existe o no.
  - El enlace deja de funcionar a los 60 minutos y tras su primer uso.
  - Se aceptan como máximo 5 solicitudes por hora por correo y por dirección IP; la sexta se rechaza.
  - Tras restablecer, las sesiones y los tokens anteriores dejan de funcionar.
  - Una contraseña nueva que no cumple la política se rechaza.
- **Verificación:** Prueba automatizada.

##### RF-42 · Control de acceso por rol y propiedad
- **Clase:** Esencial
- **Enunciado:** El sistema deberá verificar en cada operación el rol de la persona en la barbería y que el recurso le pertenezca.
- **Actores:** Dueño, colaborador, cliente.
- **Precondiciones:** Persona autenticada.
- **Flujo:** 1) Cada operación comprueba el rol de la persona en esa barbería. 2) Comprueba que el recurso sea suyo o de su barbería. 3) Si el rol no alcanza para la operación, responde 403. 4) Si el recurso es de otra barbería o de otra persona, responde 404, como si no existiera.
- **Reglas:** UP-01, UP-02, UP-03, UP-06, UP-07, CO-04, CL-04, UP-09.
- **Criterios de aceptación:**
  - Un cliente que intenta crear un servicio recibe 403.
  - Un dueño o colaborador que intenta una operación de cliente en su propia barbería (reservar, comprar un bono o anotarse en una fila) recibe 403 con el tipo `prohibido`.
  - Un colaborador que pide la agenda de otro recibe 404.
  - Una persona nunca tiene dos roles en la misma barbería.
  - Una prueba por cada combinación de rol y operación sensible respalda la matriz de permisos.
- **Verificación:** Prueba automatizada.

##### RF-43 · Selección de barbería
- **Clase:** Esencial
- **Enunciado:** El sistema deberá permitir elegir la barbería con la que entra quien tiene acceso a varias.
- **Actores:** Dueño, colaborador, cliente.
- **Precondiciones:** Persona autenticada con acceso a más de una barbería.
- **Flujo:** 1) Tras iniciar sesión, el sistema lista las barberías donde la persona tiene un rol, con ese rol. 2) La persona elige una. 3) Con una sola barbería, entra directamente. 4) La persona puede cambiar de barbería sin cerrar sesión.
- **Reglas:** UP-07, UP-08.
- **Criterios de aceptación:**
  - Una persona dueña en Pura Navaja y clienta en Fade Lab ve ambas con su rol y entra a la elegida con los permisos de ese rol.
  - Con una sola barbería no ve la pantalla de selección.
  - La lista nunca incluye barberías donde la persona no tiene rol.
- **Verificación:** Prueba automatizada.

##### RF-44 · Aislamiento de datos por barbería
- **Clase:** Esencial
- **Enunciado:** El sistema deberá impedir que toda operación de la API lea o modifique datos de una barbería distinta a la de la persona.
- **Actores:** Todos.
- **Precondiciones:** Al menos dos barberías con datos.
- **Flujo:** 1) Cada operación determina la barbería a partir de la sesión o del recurso. 2) Restringe lo que lee y escribe a esa barbería. 3) La base de datos rechaza, como segunda barrera, el acceso a filas de otra barbería.
- **Reglas:** MT-01, MT-02, MT-03, MT-04, MT-05, MT-06, UP-06.
- **Criterios de aceptación:**
  - Si un usuario de Pura Navaja cambia en su petición el identificador de barbería o de un recurso por uno de Fade Lab, no ve ni modifica nada.
  - Ningún listado de una barbería incluye datos de otra.
  - Los archivos de una barbería no se sirven ni se escriben desde otra.
  - Una consulta que olvida el filtro de barbería no devuelve filas de otra.
  - Una prueba automatizada recorre cada recurso con dos barberías y falla si hay filtración.
- **Verificación:** Prueba automatizada.

##### RF-45 · Datos de la cuenta
- **Clase:** Complementaria
- **Enunciado:** El sistema deberá permitir a la persona autenticada ver y corregir su nombre, teléfono y correo.
- **Actores:** Cliente, colaborador, dueño.
- **Precondiciones:** Cuenta existente.
- **Flujo:** 1) La persona abre "Mi cuenta". 2) Edita nombre, teléfono o correo. 3) El sistema valida y aplica.
- **Reglas:** CL-02, CL-03.
- **Criterios de aceptación:**
  - Un correo ya registrado se rechaza.
  - Una persona solo ve y edita sus propios datos.
  - Un cambio de correo no deja la cuenta anterior accesible con el correo viejo.
- **Verificación:** Prueba automatizada.

##### RF-46 · Carga de imágenes
- **Clase:** Esencial
- **Enunciado:** El sistema deberá permitir al dueño subir el logo de su barbería y las imágenes de sus servicios, validando su tipo y tamaño.
- **Actores:** Dueño.
- **Precondiciones:** La barbería existe.
- **Flujo:** 1) El dueño elige un archivo. 2) El sistema valida el tipo (por su contenido, no por su nombre) y el tamaño. 3) Lo guarda en el espacio de su barbería. 4) El logo se muestra en el encabezado de las pantallas del cliente y en los correos; la imagen de un servicio, al elegirlo (RF-10).
- **Reglas:** SV-06, SV-07, MT-05, MT-08.
- **Criterios de aceptación:**
  - Un PDF o un ejecutable se rechaza, incluso si su nombre termina en .jpg.
  - Un PNG de 3 MB se rechaza; un JPG de 1 MB se acepta.
  - La imagen de una barbería no es modificable desde otra.
  - Una barbería sin logo muestra su nombre en el encabezado.
- **Verificación:** Prueba automatizada.


#### 3.2.9 Preferencias de la cuenta

Las tres preferencias se encuentran en la pantalla "Mi cuenta", común a todos los roles, con las pestañas Datos, Seguridad, Avisos (solo el cliente), Apariencia e Idioma.

##### RF-47 · Preferencias de avisos por correo
- **Clase:** Complementaria
- **Enunciado:** El sistema deberá permitir al cliente activar o desactivar el envío de recordatorios por correo.
- **Actores:** Cliente.
- **Precondiciones:** Cliente autenticado.
- **Flujo:** 1) El cliente abre la pestaña Avisos de "Mi cuenta". 2) Activa o desactiva los recordatorios de 24 h y de 2 h; por defecto están activados. 3) La preferencia aplica a todas sus reservas, en todas las barberías. 4) La pantalla indica que la confirmación, la oferta de espacio, la cancelación o reprogramación de la barbería, el reembolso y el restablecimiento de contraseña no se pueden desactivar.
- **Reglas:** NO-02, NO-10.
- **Criterios de aceptación:**
  - Con los recordatorios desactivados, una reserva nueva no genera recordatorios.
  - Desactivarlos no afecta la confirmación de una reserva ni una oferta de lista de espera.
  - La preferencia se conserva al cerrar sesión y volver a entrar.
- **Verificación:** Prueba automatizada.

##### RF-48 · Preferencias de apariencia y accesibilidad
- **Clase:** Complementaria
- **Enunciado:** El sistema deberá permitir a la persona elegir el tema de la interfaz, el tamaño del texto y la reducción de movimiento.
- **Actores:** Todos, con o sin cuenta.
- **Precondiciones:** Ninguna.
- **Flujo:** 1) La persona abre "Apariencia" (o el control equivalente antes de iniciar sesión). 2) Elige el tema: claro, oscuro o automático, que sigue al dispositivo; el valor por defecto es automático. 3) Elige el tamaño del texto: Normal (100 %), Grande (125 %) o Muy grande (150 %). 4) Activa o desactiva la reducción de movimiento; por defecto sigue la preferencia del dispositivo. 5) El cambio se aplica de inmediato y se guarda en el navegador.
- **Reglas:** AC-01, AC-02, AC-03, AC-04, AC-05, AC-06.
- **Criterios de aceptación:**
  - Con el tema "automático", la interfaz cambia al cambiar la preferencia del dispositivo.
  - Todo texto y componente cumple el contraste mínimo de WCAG 2.2 AA en el tema claro y en el oscuro.
  - Con "Muy grande", ninguna pantalla pierde contenido ni funciones y el texto se amplía hasta el 200 % sin desplazamiento horizontal.
  - Con la reducción de movimiento activada, no hay animaciones ni transiciones no esenciales.
  - La preferencia se mantiene al recargar y no requiere cuenta.
- **Verificación:** Prueba de accesibilidad automatizada, inspección y demostración.

##### RF-49 · Idioma de la interfaz
- **Clase:** Complementaria
- **Enunciado:** El sistema deberá permitir elegir el idioma de la interfaz entre español e inglés.
- **Actores:** Todos, con o sin cuenta.
- **Precondiciones:** RF-48 y los textos de las demás pantallas terminados.
- **Flujo:** 1) La persona elige el idioma; por defecto es español. 2) Sin sesión, la elección se guarda en el navegador. 3) Con sesión, se guarda también en la cuenta y los correos llegan en ese idioma. 4) Se traducen los textos fijos de la interfaz, los mensajes de error y los correos; no se traducen los datos que escribe la barbería, como los nombres y las descripciones de servicios. 5) Fechas, horas y montos se formatean según el idioma; los montos siguen en colones.
- **Reglas:** AC-07, AC-08.
- **Criterios de aceptación:**
  - Al elegir inglés, todas las pantallas y los mensajes de error se muestran en inglés.
  - Un cliente que eligió inglés recibe sus correos en inglés.
  - El nombre de un servicio como "Corte clásico" no se traduce.
  - El idioma se conserva al cerrar sesión y volver a entrar.
- **Verificación:** Prueba automatizada e inspección.


#### 3.2.10 Extras

Se construyen después de todo lo anterior, en este orden: RF-50, RF-51, RF-52 y RF-53. Sus reglas propias se definen al planificar cada extra.

##### RF-50 · Propinas al pagar
- **Clase:** Extra
- **Enunciado:** El sistema deberá permitir al cliente agregar una propina opcional al pagar, atribuida al colaborador que lo atiende.
- **Actores:** Cliente; pasarela de pago; dueño (consulta).
- **Precondiciones:** RF-20, RF-22 y RF-25 disponibles.
- **Flujo:** 1) En el pago, el cliente elige sin propina, ₡500, ₡1.000, 10 % del precio u otro monto entero. 2) El cobro incluye la propina. 3) El sistema la registra aparte del precio del servicio y la atribuye al colaborador. 4) El reporte de ingresos la muestra por separado y por colaborador.
- **Reglas:** PA-01, PA-06, PA-11.
- **Criterios de aceptación:**
  - Con un degradado de ₡8.500, depósito de 30 % y propina de ₡1.000, se cobran ₡3.550: ₡2.550 de depósito y ₡1.000 de propina.
  - La propina no cuenta para el cálculo del depósito ni del precio del servicio.
  - Una reserva pagada con bono no ofrece propina, porque no hay cobro.
- **Verificación:** Prueba automatizada.

##### RF-51 · WhatsApp Business Cloud
- **Clase:** Extra
- **Enunciado:** El sistema deberá enviar la confirmación, los recordatorios y los avisos también por WhatsApp, con el correo como respaldo.
- **Actores:** Cliente; servicio de WhatsApp Business Cloud.
- **Precondiciones:** RF-33 y RF-34 disponibles; el cliente aceptó recibir mensajes por WhatsApp.
- **Flujo:** 1) El cliente da su consentimiento y su número en "Mi cuenta". 2) Los avisos de RF-33 y RF-34 se envían también por WhatsApp con plantillas aprobadas. 3) Si el envío falla, se usa el correo.
- **Reglas:** NO-01, NO-02, NO-03, NO-07, NO-10, CL-02.
- **Criterios de aceptación:**
  - Sin consentimiento no se envía ningún mensaje de WhatsApp.
  - Los recordatorios de 24 h y 2 h salen por WhatsApp sin duplicarse con el correo.
  - Si WhatsApp falla, el cliente recibe el aviso por correo.
- **Verificación:** Prueba automatizada con servicio simulado.

##### RF-52 · Sincronización con Google Calendar
- **Clase:** Extra
- **Enunciado:** El sistema deberá reflejar las reservas de un colaborador o del dueño en su calendario de Google.
- **Actores:** Colaborador, dueño; servicio de Google Calendar.
- **Precondiciones:** RF-05, RF-13, RF-15 y RF-16 disponibles.
- **Flujo:** 1) La persona conecta su cuenta de Google desde "Mi cuenta". 2) Cada reserva confirmada crea un evento. 3) Reprogramar o cancelar actualiza o borra el evento. La sincronización es de una sola vía: del sistema hacia Google.
- **Reglas:** MT-02, CO-04.
- **Criterios de aceptación:**
  - Una reserva confirmada de Kevin crea un evento en su calendario con servicio, hora y cliente.
  - Reprogramar mueve el evento y cancelar lo elimina.
  - Desconectar la cuenta detiene la sincronización sin afectar las reservas.
- **Verificación:** Prueba automatizada con servicio simulado.

##### RF-53 · Factura electrónica
- **Clase:** Extra
- **Enunciado:** El sistema deberá generar el comprobante electrónico de los servicios cobrados.
- **Actores:** Dueño; sistema de Hacienda.
- **Precondiciones:** RF-20 y RF-25 disponibles; versión de factura definida (pendiente de confirmar: 4.4 u otra).
- **Flujo:** 1) Al confirmarse un pago, el sistema arma el comprobante con el IVA de 13 % y el código del medio de pago. 2) Lo envía a Hacienda. 3) Guarda el resultado y lo muestra al dueño en el detalle de la reserva y en los ingresos.
- **Reglas:** SV-02, PA-11.
- **Criterios de aceptación:**
  - El comprobante desglosa base e IVA de 13 %, y el código del medio de pago es el del pago real.
  - Un envío fallido queda pendiente y visible, y se reintenta.
  - Solo se usa el ambiente de pruebas.
- **Verificación:** Prueba automatizada con servicio simulado.

### 3.3 Requisitos no funcionales

Son 28 requisitos medibles. Los identificadores conservan su numeración original; los umbrales con origen D son decisiones del proyecto. La columna Entrega indica el milestone en que se cumple.

#### 3.3.1 Rendimiento y capacidad

| ID | Requisito | Verificación | Origen | Entrega |
| --- | --- | --- | --- | --- |
| RNF-01 | La consulta de disponibilidad de un día responde en 1 s o menos (percentil 95) con 4 colaboradores, 7 servicios y 30 días de reservas. | Prueba de carga con datos de Pura Navaja | D | M07 |
| RNF-02 | Crear una reserva responde en 2 s o menos (percentil 95), sin contar el tiempo de la pasarela. | Prueba de carga | D | M07 |
| RNF-03 | El sistema atiende 20 barberías y 100 solicitudes por minuto sostenidas sin errores por sobrecarga. | Prueba de carga | D | M17 |
| RNF-04 | La ruta de salud reporta el sistema disponible al menos el 99 % del tiempo en cualquier ventana de 14 días desde su despliegue. | Monitoreo | D | M17 |
| RNF-10 | Los cambios de agenda y las ofertas llegan a las pantallas en menos de 5 s con 20 pantallas conectadas. | Prueba cronometrada | C, D | M13 |

#### 3.3.2 Integridad, seguridad y privacidad

| ID | Requisito | Verificación | Origen | Entrega |
| --- | --- | --- | --- | --- |
| RNF-05 | Con 50 solicitudes simultáneas por el mismo espacio se confirma exactamente una; con N reservas simultáneas sobre un bono de k sesiones se confirman exactamente k y el saldo nunca es negativo. | Prueba automatizada de concurrencia | C | M07 y M12 |
| RNF-06 | Existe una prueba automatizada por cada recurso que demuestra que una persona de una barbería no puede leerlo ni modificarlo en otra. | PHPUnit | C | M04 |
| RNF-07 | Reintentar el mismo aviso de la pasarela 10 veces produce un solo cambio de estado y un solo registro de pago. | Prueba automatizada | C | M08 |
| RNF-08 | Todo el tráfico en producción usa HTTPS; las contraseñas usan hash adaptativo; ningún secreto aparece en el repositorio ni en los registros (se revisa en la integración continua). | Revisión e integración continua | C, D | M01 y M17 |
| RNF-23 | Solo se recolectan nombre, correo y teléfono, con las aceptaciones de RF-38 (Ley 8968); los registros y la IA no incluyen datos de contacto; los datos de demostración son ficticios. | Revisión | N, C | M01 |
| RNF-24 | Toda configuración sensible vive en variables de entorno; el repositorio incluye un `.env.example` completo y sin valores reales. | Revisión | C | M01 |

#### 3.3.3 Fiabilidad y tolerancia a fallos

| ID | Requisito | Verificación | Origen | Entrega |
| --- | --- | --- | --- | --- |
| RNF-09 | Recordatorios, expiraciones, avisos y avance de la lista de espera corren en workers; una tarea fallida se reintenta hasta 3 veces y, si agota los intentos, queda visible en el monitoreo. | Prueba y monitoreo | C, D | M08 y M17 |
| RNF-11 | La caída o lentitud de ONVO Pay, PayPal, el proveedor de IA o el correo no detiene el resto del sistema; cada llamada externa tiene un límite de 10 s y devuelve un error de tipo estable. | Pruebas con servicios simulados | C, D | M08, M09 y M13 |
| RNF-17 | Todo error usa RFC 9457 y los casos de negocio tienen un tipo de error estable: `espacio-no-disponible`, `saldo-insuficiente`, `plazo-vencido`, `bono-vencido` y `oferta-vencida`. | Pruebas automatizadas | C | M03 |

#### 3.3.4 Usabilidad, accesibilidad e idioma

| ID | Requisito | Verificación | Origen | Entrega |
| --- | --- | --- | --- | --- |
| RNF-20 | El cliente web (Vue con TypeScript) cubre ver la barbería y su disponibilidad, reservar, pagar, cancelar y ver las propias reservas, y el dueño y el colaborador ven su agenda; toda pantalla que consume la API muestra estados de carga, error y vacío, y los tipos del cliente se generan desde el contrato OpenAPI. | Revisión | C | M10, M11 y M16 |
| RNF-21 | El cliente web es utilizable desde 360 px de ancho y en las dos últimas versiones de los navegadores principales; una reserva se completa en 5 pasos o menos; los errores se indican por campo. | Prueba manual | D | M10 |
| RNF-22 | La interfaz está en español por defecto y en inglés (RF-49); los montos usan colones (₡) con separador de miles; las horas, la zona horaria de Costa Rica. | Revisión | D | M01 y M15 |
| RNF-27 | Toda la interfaz cumple WCAG 2.2 nivel AA en los temas claro y oscuro: contraste, uso completo con teclado, foco visible, etiquetas en los formularios, errores identificados y objetivos táctiles de al menos 24 por 24 px. | Prueba de accesibilidad automatizada e inspección | N, D | M10 en adelante |

#### 3.3.5 Mantenibilidad, calidad y proceso

| ID | Requisito | Verificación | Origen | Entrega |
| --- | --- | --- | --- | --- |
| RNF-13 | Cada pull request ejecuta Pint, Larastan y PHPUnit y debe quedar en verde para integrarse. | Integración continua | C | M03 |
| RNF-16 | La documentación de la API generada con Scramble refleja la API vigente y se actualiza con cada cambio; la API está versionada bajo `/api/v1`. | Revisión | C | M03 |
| RNF-18 | PHPUnit cubre como mínimo disponibilidad, concurrencia, saldo de bonos, cobro y webhook, aislamiento y permisos. | Integración continua | C | M04 a M12 |
| RNF-19 | El trabajo se hace en ramas creadas desde cada issue; todo cambio entra por pull request con revisión del autor asistida por IA, con comentario visible y lista de verificación; los commits siguen la convención y citan el issue. | Historial de GitHub | C | M01 |
| RNF-26 | El README declara qué partes del sistema se generaron con asistencia de IA y qué se verificó; cada hallazgo de una auditoría se corrige con su pull request y su prueba, y los no corregidos se justifican por escrito. | Inspección | C | M01, M13 y M17 |
| RNF-28 | El dominio no depende de Laravel ni de la infraestructura; una prueba de arquitectura lo verifica en cada pull request. | Prueba de arquitectura | D | M02 |

#### 3.3.6 Operación

| ID | Requisito | Verificación | Origen | Entrega |
| --- | --- | --- | --- | --- |
| RNF-12 | El sistema está accesible por una dirección pública con HTTPS y su despliegue es automático desde la rama principal. | Inspección y demostración | C | M03 y M17 |
| RNF-14 | Una ruta pública de salud responde bien cuando la aplicación, la base de datos y la cola funcionan, y avisa la falla en caso contrario, sin revelar detalles internos. | Prueba automatizada | C | M03 |
| RNF-15 | Un panel de monitoreo muestra al menos errores, tiempos de respuesta, estado de las colas y tareas fallidas del sistema desplegado. | Demostración | C | M17 |
| RNF-25 | Cada pago, aviso de la pasarela, reembolso, tarea en cola y llamada a la IA deja un registro con un identificador de correlación para seguirlo de punta a punta. | Revisión | D | M17 |

### 3.4 Restricciones de diseño e implementación

| ID | Restricción | Origen |
| --- | --- | --- |
| RD-01 | Laravel 13 sobre PHP 8.4 como API, con autenticación mediante Sanctum y tokens personales. | C |
| RD-02 | PostgreSQL 18 con dos bases de datos, `barberhub` para desarrollo y `barberhub_testing` solo para pruebas, y dos roles: uno dueño de las tablas, que ejecuta las migraciones, y otro de la aplicación, sin privilegios de dueño. | D |
| RD-03 | El aislamiento entre barberías usa un puerto que responde a qué barbería pertenece la operación y seguridad a nivel de fila en PostgreSQL; las claves y restricciones únicas incluyen la barbería. | D |
| RD-04 | Arquitectura hexagonal con DDD ligero: `src/<Contexto>/<Módulo>/{Domain,Application,Infrastructure}`; un caso de uso por clase; puertos en el dominio; el tiempo entra como parámetro. | D |
| RD-05 | Contextos del dominio: identidad y acceso, catálogo y horarios, disponibilidad, reservas, pagos, bonos, lista de espera y notificaciones; agregados con invariantes solo en reserva, bono, oferta y pago. | D |
| RD-06 | Identificadores UUID v7; dinero en colones enteros; marcas de tiempo con zona horaria. | D |
| RD-07 | Rutas, atributos JSON y mensajes al usuario en español; clases, tablas y columnas en inglés. | D |
| RD-08 | Errores en formato RFC 9457 con el encabezado `X-Request-Id`; contrato OpenAPI generado con Scramble. | C |
| RD-09 | Cliente web separado, en Vue con TypeScript; su estructura está pendiente de definir. | C, D |
| RD-10 | Dos pasarelas intercambiables por configuración, ONVO Pay (tarjeta y SINPE Móvil) y PayPal (tarjeta); el cobro solo se confirma por webhook verificado e idempotente. | C |
| RD-11 | Tareas en cola con workers para recordatorios, expiración, lista de espera y avisos; el driver de colas está pendiente de definir. | C, D |
| RD-12 | Tiempo real por WebSockets para la agenda y las ofertas; la tecnología está pendiente de definir. | D |
| RD-13 | Pruebas con PHPUnit 12 en las suites Unit, Architecture y Feature; Pint y Larastan; GitHub Actions con PostgreSQL 18. | C |
| RD-14 | Un solo repositorio con las carpetas `barberhub-api` y `barberhub-web`; una rama por issue, commits convencionales que citan el issue y un pull request por cambio. | C, D |
| RD-15 | Toda configuración sensible en variables de entorno; `.env.example` sin valores reales. | C |
| RD-16 | Zona horaria de la aplicación: America/Costa_Rica. | D |
| RD-17 | Archivos subidos con validación de tipo y tamaño, guardados y servidos por barbería. | C |
| RD-18 | En la API, `{barberia}` en las rutas es el UUID v7 de la barbería; el enlace público solo sirve para resolverla, con la ruta pública `GET /api/v1/enlaces/{enlace}`. No existe un encabezado de barbería activa y nunca se acepta la barbería en el cuerpo, en la consulta ni en un encabezado: el UUID de la ruta identifica el recurso y el contexto de barbería se establece solo después de verificar que la sesión tiene un rol en esa barbería; si no lo tiene o el recurso es de otra barbería, la respuesta es 404 (UP-06, MT-03). Esa regla tiene tres excepciones: (1) las operaciones de cliente que crean la primera membresía, que son crear una reserva (RF-11), comprar un bono (RF-27) y anotarse en una fila de espera (RF-30): una persona sin rol en la barbería puede hacerlas y el sistema crea su membresía de cliente en la misma transacción (UP-09), mientras que un dueño o colaborador de esa barbería recibe 403 con el tipo `prohibido` (UP-07); (2) la propuesta de IA (RF-37), que no crea ninguna reserva ni guarda datos: la puede pedir cualquier persona autenticada sin rol en la barbería, no crea la membresía y, si es dueño o colaborador de esa barbería, recibe 403 con el tipo `prohibido`; (3) las lecturas de datos propios (mis reservas, mis bonos y mis filas de espera) en una barbería donde la persona aún no tiene rol, que devuelven una lista vacía y no un error. El resto de las rutas sigue respondiendo 404 sin membresía. Las rutas públicas son la resolución del enlace, la lista de servicios activos y la consulta de disponibilidad, y solo exponen datos públicos. | D |

### 3.5 Requisitos legales y de cumplimiento

| ID | Requisito | Origen |
| --- | --- | --- |
| RL-01 | Para crear una cuenta se obtienen dos aceptaciones expresas y separadas: términos y condiciones de BarberHub y tratamiento de datos personales según la política de privacidad (Ley 8968), con la fecha de cada una (CL-02, RF-38). | N |
| RL-02 | Los precios incluyen el IVA de 13 % y se muestran con base e impuesto desglosados (SV-02). | N |
| RL-03 | No se procesa dinero real: todos los pagos usan el modo de pruebas de las pasarelas (PA-09). | C |
| RL-04 | No se almacenan datos personales de personas reales; los datos de demostración son ficticios (CL-08). | C |
| RL-05 | El sistema no se publica como servicio comercial. | C |

## 4. Verificación

### 4.1 Métodos

| Método | Descripción |
| --- | --- |
| Prueba automatizada | Prueba de PHPUnit, de carga, de arquitectura o de accesibilidad que se ejecuta en la integración continua o bajo demanda. |
| Demostración | Recorrido funcional con datos de Pura Navaja y de otras barberías de demostración. |
| Inspección | Revisión de código, configuración, documentación o historial. |
| Análisis | Revisión de un cálculo o de un diseño frente a una regla o norma. |

### 4.2 Cobertura de los requisitos funcionales

Los 53 requisitos funcionales se verifican con al menos una prueba automatizada (53 de 53); 6 de ellos se complementan además con demostración o inspección. El método de cada uno consta en su ficha.

### 4.3 Criterios generales

- Un requisito se considera cumplido cuando todos sus criterios de aceptación pasan y la integración continua está en verde.
- Los requisitos de concurrencia, saldo de bonos, webhook, aislamiento y permisos tienen pruebas automatizadas obligatorias (RNF-05, RNF-06, RNF-07, RNF-18).
- Los requisitos no funcionales se verifican con el método indicado en su fila.

## 5. Trazabilidad

### 5.1 Requisitos funcionales, reglas de negocio y planificación

| RF | Nombre | Clase | Reglas | API | Cliente web |
| --- | --- | --- | --- | --- | --- |
| RF-01 | Alta de la barbería y de su dueño | Esencial | MT-01, MT-04, UP-01, UP-05, UP-07 | M04 | M10 |
| RF-02 | Enlace público de la barbería | Esencial | MT-07, MT-08 | M04 | M10 |
| RF-03 | Catálogo de servicios | Esencial | SV-01, SV-02, SV-03, SV-04, SV-05, SV-07, UP-03, MT-04 | M06 | M10 |
| RF-04 | Horario de la barbería | Esencial | HO-01, HO-03, HO-04, UP-03 | M06 | M10 |
| RF-05 | Colaboradores, servicios habilitados y horarios | Esencial | CO-01, CO-02, CO-03, HO-02, UP-04, UP-05, UP-07, SE-07 | M06 | M10 |
| RF-06 | Invitación de colaboradores por correo | Complementaria | UP-04, SE-07, NO-03 | M14 | M14 |
| RF-07 | Ausencias y bloqueos de agenda | Complementaria | CO-06, DI-01, CA-04 | M14 | M14 |
| RF-08 | Políticas de cobro y cancelación | Esencial | PA-01, CA-01, MT-04, SE-04 | M06 | M10 |
| RF-09 | Activación de bonos y lista de espera | Esencial | BO-01, LE-01, MT-04, SE-04 | M06 | M10 |
| RF-10 | Consulta de disponibilidad | Esencial | DI-01, DI-02, DI-03, DI-04, DI-05, HO-01, HO-02, HO-03, SV-02, SV-05, SV-07, CO-01 | M07 | M11 |
| RF-11 | Creación de reserva | Esencial | RE-01, RE-02, RE-03, RE-05, RE-06, RE-07, SV-03, DI-01, PA-06, CL-05, UP-07, UP-09 | M07 | M11 |
| RF-12 | Reserva asistida | Complementaria | CL-06, RE-01, RE-07, DI-01, UP-03 | M14 | M14 |
| RF-13 | Consulta de reservas por rol | Esencial | CL-04, CO-04, UP-02, UP-06, MT-02 | M07 | M10 y M11 |
| RF-14 | Detalle de una reserva | Esencial | CO-04, PA-10, RE-09, UP-02 | M07 | M10 y M11 |
| RF-15 | Reprogramación de reservas | Esencial | RE-08, CA-01, CA-05, CA-08, DI-01, RE-01, CA-07 | M07 | M11 |
| RF-16 | Cancelación y liberación del espacio | Esencial | CA-01, CA-04, CA-06, CA-07, RE-04, RE-09, SE-04 | M07 | M11 |
| RF-17 | Consecuencia económica de la cancelación | Esencial | CA-02, CA-03, CA-04, CA-06, BO-05 | M08 | M11 |
| RF-18 | Marcar reserva atendida o ausente | Esencial | CO-05, RE-04, RE-09, CA-03 | M07 | M10 |
| RF-19 | Expiración de reservas temporales | Esencial | RE-05, RE-04, NO-04, CA-07 | M08 | — |
| RF-20 | Cobro de una reserva | Esencial | PA-01, PA-02, PA-06, PA-09, RE-05, MT-04 | M08 | M11 |
| RF-21 | Reserva confirmada tras el pago | Esencial | PA-03, PA-06, RE-05 | M08 | M11 |
| RF-22 | Confirmación de pagos por webhook | Esencial | PA-03, PA-04, PA-05, PA-06, BO-02 | M08 | — |
| RF-23 | Pasarela de pago por barbería | Esencial | PA-02, MT-04, SE-04 | M08 | M10 |
| RF-24 | Reembolsos | Complementaria | PA-07, PA-08, CA-02, CA-04, NO-09, SE-04 | M14 | M14 |
| RF-25 | Reporte de ingresos | Esencial | PA-11, PA-10, MT-02, CO-04 | M12 | M16 |
| RF-26 | Definición de bonos | Esencial | BO-01, MT-04, UP-03 | M12 | M16 |
| RF-27 | Compra de un bono | Esencial | BO-02, BO-07, PA-03, MT-01, UP-07, UP-09 | M12 | M16 |
| RF-28 | Reserva con bono | Esencial | BO-03, BO-04, BO-05, BO-06, BO-07, BO-08, RE-07, CA-02, CA-03 | M12 | M16 |
| RF-29 | Consulta de saldo de bonos | Esencial | BO-09, CL-04, MT-02 | M12 | M16 |
| RF-30 | Unirse y salir de la lista de espera | Esencial | LE-01, LE-02, LE-06, CL-04, UP-07, UP-09 | M12 | M16 |
| RF-31 | Oferta de espacios liberados | Esencial | LE-03, LE-04, LE-05, LE-06, DI-05, CA-07 | M12 | M16 |
| RF-32 | Respuesta a una oferta | Esencial | LE-04, LE-05, RE-07, PA-06 | M12 | M16 |
| RF-33 | Recordatorios de la reserva | Esencial | NO-02, NO-03, NO-10, CL-06 | M13 | — |
| RF-34 | Avisos por correo de confirmación, oferta, cancelación y reprogramación | Esencial | NO-01, NO-03, NO-07, NO-10, CL-06 | M13 | — |
| RF-35 | Aviso emergente de ofertas | Complementaria | NO-08, LE-04, MT-02 | M13 | M16 |
| RF-36 | Agenda en vivo | Esencial | NO-06, CO-04, MT-02 | M13 | M16 |
| RF-37 | Reserva asistida por IA | Esencial | IA-01, IA-02, IA-03, IA-04, IA-05, IA-06, IA-07, DI-01, RE-01 | M09 | M11 |
| RF-38 | Registro de cuenta | Esencial | CL-01, CL-02, CL-09, SE-01, SE-07 | M05 | M10 |
| RF-39 | Inicio y cierre de sesión | Esencial | SE-01, SE-05, UP-01 | M05 | M10 |
| RF-40 | Cambio de contraseña | Esencial | SE-01, SE-07, UP-04 | M05 | M10 y M15 |
| RF-41 | Recuperación de contraseña | Complementaria | SE-05, SE-06, SE-07 | M14 | M14 |
| RF-42 | Control de acceso por rol y propiedad | Esencial | UP-01, UP-02, UP-03, UP-06, UP-07, CO-04, CL-04, UP-09 | M05 | — |
| RF-43 | Selección de barbería | Esencial | UP-07, UP-08 | M05 | M10 |
| RF-44 | Aislamiento de datos por barbería | Esencial | MT-01, MT-02, MT-03, MT-04, MT-05, MT-06, UP-06 | M04 | — |
| RF-45 | Datos de la cuenta | Complementaria | CL-02, CL-03 | M15 | M15 |
| RF-46 | Carga de imágenes | Esencial | SV-06, SV-07, MT-05, MT-08 | M06 | M10 |
| RF-47 | Preferencias de avisos por correo | Complementaria | NO-02, NO-10 | M15 | M15 |
| RF-48 | Preferencias de apariencia y accesibilidad | Complementaria | AC-01, AC-02, AC-03, AC-04, AC-05, AC-06 | — | M15 |
| RF-49 | Idioma de la interfaz | Complementaria | AC-07, AC-08 | M15 | M15 |
| RF-50 | Propinas al pagar | Extra | PA-01, PA-06, PA-11 | M18 | M18 |
| RF-51 | WhatsApp Business Cloud | Extra | NO-01, NO-02, NO-03, NO-07, NO-10, CL-02 | M18 | M18 |
| RF-52 | Sincronización con Google Calendar | Extra | MT-02, CO-04 | M18 | M18 |
| RF-53 | Factura electrónica | Extra | SV-02, PA-11 | M18 | M18 |

### 5.2 Reglas cubiertas por requisitos no funcionales o restricciones

| Regla | Cubierta por |
| --- | --- |
| CL-08 | RNF-23, RL-04 |
| SE-01 | RF-38, RF-39, RNF-08 |
| SE-02 | RNF-24, RD-15 |
| SE-03 | RNF-17, RD-08 |

Las 121 reglas vigentes de `business-rules.md` están cubiertas por al menos un requisito funcional, un requisito no funcional, una restricción o un requisito legal.

## Apéndice A. Equivalencia con la numeración anterior de los requisitos funcionales

| Anterior | Nuevo | Anterior | Nuevo |
| --- | --- | --- | --- |
| RF-01 | RF-01 y RF-02 | RF-17 | RF-23 |
| RF-02 | RF-03 | RF-18 | RF-24 |
| RF-03 | RF-04 | RF-19 | RF-25 |
| RF-04a | RF-05 | RF-20 | RF-26 |
| RF-04b | RF-06 | RF-21 | RF-27 |
| RF-05 | RF-07 | RF-22 | RF-28 |
| RF-06 | RF-08 y RF-09 | RF-23 | RF-29 |
| RF-07 | RF-10 | RF-25 | RF-30 |
| RF-08 | RF-11 | RF-26 | RF-31 |
| RF-09 | RF-12 | RF-27 | RF-32 |
| RF-10 | RF-13 y RF-14 | RF-28a | RF-33 |
| RF-11 | RF-15 | RF-28b | RF-34 y RF-35 |
| RF-12a | RF-16 | RF-29 | RF-36 |
| RF-12b | RF-17 | RF-30 | RF-37 |
| RF-13 | RF-18 | RF-31 | RF-38, RF-39 y RF-40 |
| RF-14 | RF-19 | RF-32 | RF-42 y RF-43 |
| RF-15 | RF-20 y RF-21 | RF-33 | RF-44 |
| RF-16a | RF-22 | RF-34a | RF-45 |
| RF-36 | RF-46 | RF-38 | RF-41 |
| RF-E1 | RF-50 | RF-E2 | RF-51 |
| RF-E3 | RF-52 | RF-E4 | RF-53 |

Requisitos nuevos sin equivalente anterior: RF-47, RF-48 y RF-49.

## Apéndice B. Puntos pendientes de definir

| ID | Pendiente | Afecta a | Estado |
| --- | --- | --- | --- |
| PD-01 | Estructura del cliente web con Vue | RD-09, RNF-20 | Pendiente |
| PD-02 | Guía visual del cliente web | RNF-21, RNF-27, RF-48 | Pendiente |
| PD-03 | Alojamiento, driver de colas y tecnología de tiempo real | RD-11, RD-12, RNF-09, RNF-10, RNF-12 | Pendiente |
| PD-04 | Proveedor del servicio de correo | RF-33, RF-34, RF-41 | Pendiente |
| PD-05 | Almacenamiento de imágenes | RF-46, RD-17 | Pendiente |
| PD-06 | Proveedor y modelo de IA | RF-37 | Pendiente |
| PD-07 | Detalles de ONVO Pay (SINPE Móvil) y de PayPal (moneda y firma del webhook) | RF-20, RF-22, RF-23 | Pendiente |
| PD-08 | Versión de la factura electrónica de Hacienda | RF-53 | Pendiente |

## Apéndice C. Historial de cambios

| Versión | Fecha | Cambios |
| --- | --- | --- |
| 1.3 | 14 de octubre de 2026 | Primera versión del documento en este formato. Divide los requisitos compuestos y los renumera de forma consecutiva (53 en total); concreta los valores ambiguos; incorpora la recuperación de contraseña, el aviso emergente de ofertas, "Mi cuenta" con avisos, apariencia e idioma, WCAG 2.2 AA, el aislamiento con seguridad a nivel de fila y la arquitectura hexagonal; reemplaza Pest por PHPUnit. |
| 1.6 | 16 de octubre de 2026 | RNF-19 deja de exigir una declaración de uso de IA en cada pull request; la declaración de uso de IA se mantiene solo en el README (RNF-26). Sin otros cambios. |
| 1.5 | 16 de octubre de 2026 | La membresía de cliente se crea al primer uso en la barbería (RF-11, RF-27 y RF-30), con 403 de tipo `prohibido` para quien ya es dueño o colaborador de ella (RF-42); RD-18 documenta las excepciones a la regla de 404 sin rol (operaciones que crean la primera membresía, propuesta de IA y lecturas propias con lista vacía); se agrega la regla UP-09. Sin otros cambios. |
| 1.4 | 15 de octubre de 2026 | Alinea la numeración de milestones a los 18 del backlog (§2.6, columna Entrega de los RNF y matriz de trazabilidad §5.1); RF-17 cita CA-02, CA-03 y CA-04 en lugar de una tabla inexistente; agrega RD-18 (identificación de la barbería en la API); RNF-12 se entrega en M03 (despliegue mínimo) y M17 (automatización). Sin otros cambios en los requisitos. |

