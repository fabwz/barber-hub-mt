# Lenguaje ubicuo y convenciones de nombres

La misma palabra significa lo mismo en la conversación con el dueño de la barbería, en el contrato de la API, en el código y en la base de datos. Este documento relaciona cada término del negocio con su nombre en cada lugar. Los términos del negocio salen del glosario de `context/business-rules.md`.

> **Estado:** documento inicial (issue #24). Los nombres de código, tablas y API son una propuesta coherente con las reglas; cada uno se confirma cuando su issue lo crea y entonces se actualiza este archivo.

## Convención de idioma

| Dónde | Idioma | Ejemplo |
| --- | --- | --- |
| Clases, métodos, variables y comentarios | Inglés | `Reservation`, `cancellationDeadline` |
| Tablas y columnas | Inglés, `snake_case` | `reservations.cancellation_deadline_hours` |
| Rutas de la API | Español, en plural | `/api/v1/barberias/{barberia}/servicios` |
| Atributos JSON | Español, `snake_case` | `plazo_cancelacion_horas` |
| Valores de estados y tipos en JSON | Español, `snake_case` | `cancelada_por_cliente` |
| Tipos de error | Español, con guiones | `espacio-no-disponible` |
| Mensajes al usuario | Español, desde `lang/es` | "El campo nombre es obligatorio." |

Las rutas y los atributos JSON son parte de lo que ve quien consume la API, por eso usan el vocabulario del negocio. La traducción entre ambos idiomas ocurre en un solo lugar: los Form Requests de entrada y los API Resources de salida.

`Barbershop` es el nombre en el código de la barbería. Los textos que escribe la barbería (nombres de servicios, descripciones) no se traducen.

## Glosario

### Barbería, personas y roles

| Negocio | Código | Base de datos | API |
| --- | --- | --- | --- |
| Barbería (inquilino) | `Barbershop` | `barbershops` | `barberias` |
| Ciudad de la barbería | `City` | `barbershops.city` | `ciudad` |
| Teléfono de la barbería (contacto) | `Phone` | `barbershops.phone` | `telefono` |
| Enlace público (se genera una vez; el segundo homónimo lleva `-2`) | `PublicLink`, `PublicLinkGenerator` | `barbershops.public_link` | `enlace` |
| Datos públicos de la barbería (los que ve cualquier visitante) | `PublicBarbershop` | — | `BarberiaPublica` |
| Persona (cuenta) | `User` | `users` | — |
| Nombre | `name` | `name` | `nombre` |
| Correo | `Email` | `email` | `correo` |
| Teléfono | `Phone` | `phone` | `telefono` |
| Contraseña | `PlainPassword` | `password`, solo el hash | `contrasena` |
| Rol | `Role` | `memberships.role` | `rol` |
| Dueño | `Role::Owner` | `owner` | `dueno` |
| Colaborador (rol) | `Role::Collaborator` | `collaborator` | `colaborador` |
| Cliente | `Role::Customer` | `customer` | `cliente` |
| Membresía (rol de una persona en una barbería) | `Membership` | `memberships` | — |
| Colaborador con agenda | `Collaborator` | `collaborators` | `colaboradores` |
| Contraseña temporal (la fija el dueño y se cambia en el primer ingreso) | `RegisterStaffAccountCommand::$temporaryPassword` | `users.must_change_password` | `contrasena_temporal` |
| Perfil propio del dueño | `CreateOwnCollaboratorProfile` | `collaborators` (sin membresía nueva) | `mi-perfil` |
| Cliente asistido | `AssistedClient` | `assisted_clients` | `cliente_asistido` |
| Invitación de colaborador | `Invitation` | `invitations` | `invitaciones` |
| Aceptación de términos | `terms_accepted_at` | `terms_accepted_at` | `acepta_terminos` |
| Aceptación de privacidad | `privacy_accepted_at` | `privacy_accepted_at` | `acepta_privacidad` |
| Idioma de la cuenta | `Locale` | `locale` | `idioma` |
| Recordatorios por correo | `remindersEnabled` | `reminders_enabled` | `recordatorios_correo` |
| Token de acceso | `IssuedToken` | `personal_access_tokens` | `token` |

Un **colaborador** es un barbero o barbera que atiende y tiene agenda propia. La palabra nombra dos cosas distintas: el **rol** (`Role::Collaborator`, el permiso que tiene en la barbería) y el **perfil con agenda** (`Collaborator`, la persona que atiende). Un dueño con agenda propia (CO-02) conserva el rol `owner` y además tiene un perfil `Collaborator`.

### Servicios y horarios

| Negocio | Código | Base de datos | API |
| --- | --- | --- | --- |
| Servicio | `Service` | `services` | `servicios` |
| Duración | `ServiceDuration` (envuelve `Duration`) | `duration_minutes` | `duracion_minutos` |
| Precio (con IVA incluido) | `ServicePrice` (envuelve `Money`) | `price` | `precio` |
| Base (precio sin IVA) | `base()` | — (se calcula) | `base` |
| IVA (impuesto dentro del precio) | `tax()` | — (se calcula) | `iva` |
| Servicio activo | `active` | `is_active` | `activo` |
| Servicios que ofrece un colaborador | `Collaborator::$services` (`OfferedServiceId`) | `collaborator_services` | `servicios` |
| Horario de la barbería | `OpeningHours` (módulo) | `opening_hours` | `horario` |
| Franja del horario (inicio incluido, fin excluido) | `TimeSlot` (entidad), `TimeRange` (rango de horas) | `opens_at`, `closes_at` | `franja`, `abre`, `cierra` |
| Hora del día (`HH:MM`) | `TimeOfDay` | `time` sin zona | `HH:MM` |
| Día de la semana (1 lunes a 7 domingo) | `DayOfWeek` | `day_of_week` | `dia` |
| Cierre (fecha en que la barbería no atiende, por feriado o cierre especial) | `Closure`, `ClosureDate`, `ClosureReason` | `closures` (`closed_on`, `reason`) | `cierres`, `fecha`, `motivo` |
| Horario semanal de un colaborador | `WorkSchedule` | `collaborator_shifts` y `collaborator_breaks` | `horario` |
| Turno de un colaborador | `Shift` | `collaborator_shifts` (`starts_at`, `ends_at`) | `turnos`, `inicia`, `termina` |
| Pausa de un colaborador | `BreakPeriod` | `collaborator_breaks` (`starts_at`, `ends_at`) | `pausas`, `inicia`, `termina` |
| Ausencia o bloqueo de agenda | `Absence` | `collaborator_absences` | `ausencias` |
| Imagen y logo | `imagePath`, `logoPath` | `image_path`, `logo_path` | `imagen`, `logo` |

### Disponibilidad y reservas

| Negocio | Código | Base de datos | API |
| --- | --- | --- | --- |
| Espacio | `Slot` | — (se calcula) | `espacios` |
| Disponibilidad | `Availability` | — | `disponibilidad` |
| Intervalo de tiempo | `TimeInterval` | `starts_at`, `ends_at` | `inicio`, `fin` |
| Cualquiera disponible | `AnyCollaborator` | `collaborator_id` nulo en la consulta | `cualquiera` |
| Reserva | `Reservation` | `reservations` | `reservas` |
| Estado de la reserva | `ReservationStatus` | `status` | `estado` |
| Reserva temporal | `ReservationStatus::Temporary` | `temporary` | `temporal` |
| Reserva confirmada | `ReservationStatus::Confirmed` | `confirmed` | `confirmada` |
| Reserva completada | `ReservationStatus::Completed` | `completed` | `completada` |
| Cliente ausente | `ReservationStatus::NoShow` | `no_show` | `ausente` |
| Cancelada por el cliente | `ReservationStatus::CancelledByCustomer` | `cancelled_by_customer` | `cancelada_por_cliente` |
| Cancelada por la barbería | `ReservationStatus::CancelledByBarbershop` | `cancelled_by_barbershop` | `cancelada_por_barberia` |
| Reserva expirada | `ReservationStatus::Expired` | `expired` | `expirada` |
| Reserva asistida | `assisted` | `created_by_membership_id` | `asistida` |
| Reprogramación | `reschedule` | `reschedule_count` | `reprogramaciones` |
| Historial de estados | `ReservationStatusChange` | `reservation_status_history` | `historial` |
| Limpieza entre reservas | `cleanupMinutes` | `blocked_until` | — |

### Pagos y bonos

| Negocio | Código | Base de datos | API |
| --- | --- | --- | --- |
| Modalidad de cobro | `PaymentMode` | `payment_mode` | `modalidad_cobro` |
| Sin pago, depósito, pago total | `None`, `Deposit`, `Full` | `none`, `deposit`, `full` | `sin_pago`, `deposito`, `pago_total` |
| Porcentaje del depósito | `Percentage` | `deposit_percentage` | `porcentaje_deposito` |
| Depósito (monto que se paga al reservar) | `Money` | `reservations.deposit_amount` | `monto_deposito` |
| Plazo de cancelación gratuita | `cancellationDeadline` | `cancellation_deadline_hours` | `plazo_cancelacion_horas` |
| Pasarela de pago | `PaymentGateway` | `payment_gateway` | `pasarela` |
| ONVO Pay, PayPal | `Onvo`, `Paypal` | `onvo`, `paypal` | `onvo`, `paypal` |
| Pago | `Payment` | `payments` | `pagos` |
| Estado del pago | `PaymentStatus` | `status` | `estado` |
| Reembolso | `Refund` | `refunds` | `reembolsos` |
| Depósito a devolver | `RefundStatus::Due` | `due` | `a_devolver` |
| Webhook | `WebhookEvent` | `webhook_events` | — |
| Saldo pendiente por cobrar en el local | `outstandingBalance` | — (se calcula) | `saldo_pendiente` |
| Bono (definición de la barbería) | `VoucherDefinition` | `voucher_definitions` | `bonos` |
| Bono adquirido | `PurchasedVoucher` | `purchased_vouchers` | `mis_bonos` |
| Sesión | `Sessions` | `remaining_sessions` | `sesiones_restantes` |
| Movimiento de saldo | `VoucherMovement` | `voucher_movements` | — |
| Bono aplicable | `applicable` | — | — |

El **saldo pendiente** es lo que falta cobrar del servicio en el local (precio menos depósito). El **saldo del bono** se mide en sesiones. Son cosas distintas y no comparten nombre.

### Lista de espera, avisos y IA

| Negocio | Código | Base de datos | API |
| --- | --- | --- | --- |
| Fila (de un cliente, para un servicio y un día) | `WaitlistEntry` | `waitlist_entries` | `filas` |
| Oferta | `Offer` | `waitlist_offers` | `ofertas` |
| Estado de la oferta | `OfferStatus` | `status` | `estado` |
| Persona compatible | `isCompatibleWith()` | — | — |
| Aviso | `Notification` | — | — |
| Recordatorio | `Reminder` | `reservation_reminders` | — |
| Aviso emergente | `Realtime` | — | — |
| Propuesta de IA | `BookingProposal` | — (no se guarda) | `propuesta` |
| Franja del día (IA) | `DayPart` | — | `franja` |
| Mañana, tarde, noche | `Morning`, `Afternoon`, `Evening` | — | `manana`, `tarde`, `noche` |
| Registro técnico | `AuditLog` | `audit_log` | — |

## Roles

La lista de roles es cerrada y se asigna por barbería (UP-01). Una persona tiene un único rol en cada barbería y puede tener uno distinto en otra (UP-07).

| Rol | Valor en la base | Cómo se obtiene |
| --- | --- | --- |
| Dueño | `owner` | Al registrar la barbería (RF-01) |
| Colaborador | `collaborator` | Lo crea el dueño, con una contraseña temporal o por invitación (UP-04) |
| Cliente | `customer` | Se crea sola al primer uso de una operación de cliente en la barbería (UP-09) |

Las abilities de los tokens de Sanctum y la matriz de permisos por rol se definen en #43 y se documentan en `docs/` en ese issue.

## Estados de la reserva

Los fija RE-04. Un estado final no cambia.

| Estado | Puede pasar a |
| --- | --- |
| `temporary` | `confirmed`, `expired`, `cancelled_by_customer` |
| `confirmed` | `completed`, `no_show`, `cancelled_by_customer`, `cancelled_by_barbershop` |
| `completed`, `no_show`, `cancelled_by_customer`, `cancelled_by_barbershop`, `expired` | Final |

## Tipos de error estables

Un **tipo de error estable** es un tipo fijo de error RFC 9457 que el cliente web reconoce, por ejemplo `espacio-no-disponible`.

| Tipo | Cuándo |
| --- | --- |
| `solicitud-invalida` | La solicitud no se puede procesar tal como viene |
| `datos-invalidos` | Un dato no cumple su regla de forma |
| `no-encontrado` | El recurso no existe o es de otra barbería |
| `credenciales-invalidas` | Correo o contraseña incorrectos |
| `no-autenticado` | Falta un token válido |
| `prohibido` | La persona autenticada no tiene permiso suficiente |
| `metodo-no-permitido` | La ruta no acepta el método HTTP usado |
| `conflicto` | Una regla del dominio sin tipo propio impide la operación |
| `espacio-no-disponible` | El espacio ya no está libre (RE-02), con hasta 3 alternativas |
| `saldo-insuficiente` | El bono ya no tiene sesiones (BO-03) |
| `plazo-vencido` | La cancelación o reprogramación llegó fuera de plazo |
| `bono-vencido` | El bono ya no está vigente (BO-06) |
| `oferta-vencida` | La oferta caducó antes de la respuesta (LE-05) |
| `demasiadas-solicitudes` | Se superó el límite de solicitudes |
| `error-interno` | Cualquier otro fallo, sin detalles técnicos |
| `servicio-no-disponible` | El servicio no está disponible en este momento |

## Dinero y fechas

- Los montos son colones enteros. Nunca se usan decimales ni punto flotante. El precio incluye el IVA de 13 % y se muestra desglosado (base e impuesto) al cliente (SV-02).
- El depósito es un porcentaje del precio, redondeado hacia arriba al colón (PA-06).
- Los instantes se guardan con zona horaria y viajan en ISO 8601. La aplicación opera en `America/Costa_Rica`, sin horario de verano (HO-03).
- Las horas de los horarios semanales viajan como `HH:MM` y las fechas como `AAAA-MM-DD`; ambas se leen en hora de Costa Rica. El día 1 es lunes y el 7 es domingo (ISO 8601).
- El inicio de una franja pertenece a ella y el fin no: una franja de 09:00 a 12:00 atiende a las 11:59 y ya no a las 12:00.
