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
| Directorio (lista pública y opcional de las barberías cuyo dueño activó su aparición; no reemplaza al enlace público) | `ListDirectoryBarbershops`, `BarbershopDirectoryReader` | `barbershops.listed_in_directory` (`false` por defecto) | `/directorio`, `aparece_en_directorio` |
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
| Operador de plataforma (condición de la cuenta, no un rol) | `User::$platformOperator`, `PlatformOperators`, `RegisterPlatformOperator` | `users.is_platform_operator` | rutas bajo `plataforma` |
| Barbería bloqueada (por falta de pago del alquiler; propuesta, se confirma en su issue) | `BarbershopStatus::Blocked` | por confirmar | `bloqueada` |

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
| Ventana de pago (10 minutos que una reserva temporal espera su pago antes de expirar) | `Reservation::PAYMENT_WINDOW_MINUTES` | `expires_at` | `expira_en` |
| Reserva asistida | `assisted` | `created_by_membership_id` | `asistida` |
| Reprogramación | `reschedule` | `reschedule_count` | `reprogramaciones` |
| Historial de estados | `ReservationStatusChange` | `reservation_status_history` | `historial` |
| Autor de un cambio de estado (una persona o el sistema) | `Author` | `changed_by_user_id`, nulo si es el sistema | — |
| Limpieza entre reservas | `Reservation::CLEANUP_MINUTES` | `blocked_until` | — |

### Pagos y bonos

| Negocio | Código | Base de datos | API |
| --- | --- | --- | --- |
| Política de cobro (modalidad, depósito, plazo y pasarela de la barbería) | `PaymentPolicy` | columnas de `barbershops` | `cobro` (recurso `administracion/cobro`) |
| Modalidad de cobro | `PaymentMode` | `payment_mode` | `modalidad` |
| Sin pago, depósito, pago total | `None`, `Deposit`, `Full` | `none`, `deposit`, `full` | `sin_pago`, `deposito`, `total` |
| Porcentaje del depósito (de 10 a 100 %; solo con la modalidad depósito) | `PaymentPolicy::depositPercentage` | `deposit_percentage` | `porcentaje_deposito` |
| Depósito (monto que se paga al reservar) | `Money` | `reservations.deposit_amount` | `monto_deposito` |
| Plazo de cancelación gratuita (de 0 a 720 horas) | `cancellationDeadlineHours` | `cancellation_deadline_hours` | `plazo_cancelacion_horas` |
| Pasarela elegida por la barbería | `Barbershops` `PaymentGateway` | `payment_gateway` | `pasarela` |
| ONVO Pay, PayPal | `Onvo`, `Paypal` | `onvo`, `paypal` | `onvo`, `paypal` |
| Pago | `Payment` | `payments` | `pagos` |
| Estado del pago (pendiente, aprobado, rechazado, fallido, reembolsado) | `PaymentStatus` | `status` | `estado` |
| Proveedor de pago | `PaymentProvider` | `payments.provider` | — |
| Medio de pago (tarjeta, SINPE Móvil) | `PaymentMethod` | `payments.method` | `metodo` |
| Pasarela (el puerto con el que el sistema cobra, consulta y reembolsa en un proveedor) | `Payments` `PaymentGateway` | — | — |
| Elección de la pasarela de un cobro | `PaymentGatewayResolver` | — | — |
| Cobro (el cargo que se crea en el proveedor) | `ChargeRequest`, `ChargeResult` | `provider_payment_id` | — |
| Acción siguiente del cobro (ir a una URL o seguir instrucciones) | `NextAction` | — | — |
| Monto y moneda del proveedor | `ProviderAmount` | `provider_currency`, `provider_amount` | — |
| Pago a devolver (aprobado cuando la reserva ya no se pudo confirmar, PA-07, o se canceló a tiempo o por la barbería, CA-02 y CA-04) | `Payment::refundPending` | `payments.refund_pending` | `devolucion_pendiente` |
| Datos del medio de pago (token de la tarjeta, o teléfono e identificación de SINPE Móvil) | `PaymentDetails` | — | `token`, `telefono`, `identificacion`, `tipo_identificacion` |
| Resultado de pagar (el pago, si se creó, la acción siguiente y si la pasarela no respondió) | `PaymentOutcome` | — | — |
| Reembolso | `Refund` | `refunds` | `reembolsos` |
| Depósito a devolver | `RefundStatus::Due` | `due` | `a_devolver` |
| Webhook | `WebhookEvent` | `webhook_events` | — |
| Saldo pendiente por cobrar en el local | `outstandingBalance` | — (se calcula) | `saldo_pendiente` |
| Bono (definición de la barbería) | `VoucherDefinition` | `voucher_definitions` | `bonos` (recurso del dueño en `administracion/bonos`; el cliente lee los que están a la venta en `bonos`) |
| Bono adquirido | `PurchasedVoucher` | `purchased_vouchers` | `mis-bonos` (recurso del cliente) y `administracion/bonos-vendidos` (recurso del dueño) |
| Sesión | `Sessions` | `remaining_sessions` | `sesiones_restantes` |
| Movimiento de saldo | `VoucherMovement` | `voucher_movements` | — |
| Bono aplicable | `applicable` | — | — |
| Pagar con bono | `withVoucher` | `reservations.purchased_voucher_id` | `con_bono` |
| Funciones de la barbería (bonos y lista de espera activados o no) | `BarbershopFeatures` | `vouchers_enabled`, `waitlist_enabled` | `funciones` (recurso `administracion/funciones`), `bonos_activos`, `lista_espera_activa` |

La **pasarela** es la integración con un proveedor de pago, el puerto `PaymentGateway`; **ONVO Pay** y **PayPal** son los proveedores (`PaymentProvider`). PayPal cobra en dólares: el **tipo de cambio** (`exchangeRate`, `provider_exchange_rate`) son los colones por dólar con que el adaptador convirtió el monto, y la **captura** (`providerCaptureId`, `provider_capture_id`) es el paso con que PayPal toma el dinero de una orden ya aprobada por el cliente. La **dirección de retorno** es la ruta pública a la que el proveedor devuelve al cliente; no confirma nada. La política de la barbería guarda cuál de las dos pasarelas eligió el dueño (`Barbershops`). El **cobro** es el cargo que el sistema crea en el proveedor; el **pago** es el registro propio de ese cobro (`Payment`), con su estado. El **depósito** es la parte del precio que se paga al reservar (el precio por el porcentaje de la política, redondeado hacia arriba al colón); con la modalidad de pago total, el monto del pago es el precio completo.

El **saldo pendiente** es lo que falta cobrar del servicio en el local (precio menos depósito). El **saldo del bono** se mide en sesiones. Son cosas distintas y no comparten nombre.

### Lista de espera, avisos y IA

| Negocio | Código | Base de datos | API |
| --- | --- | --- | --- |
| Fila (de un cliente, para un servicio y un día) | `WaitlistEntry` | `waitlist_entries` | `filas` |
| Estado de la fila (esperando, ofrecida, cumplida, retirada) | `WaitlistStatus` (`Waiting`, `Offered`, `Fulfilled`, `Left`) | `waitlist_entries.status` (`waiting`, `offered`, `fulfilled`, `left`) | `estado` (`esperando`, `ofrecida`, `cumplida`, `retirada`) |
| Hora de ingreso a la fila | `joinedAt` | `joined_at` | `ingreso` |
| Día que busca | `DesiredDate` | `desired_date` | `fecha` |
| Mis filas activas | `ListMyWaitlistEntries` | — | `mis-filas` |
| Espacio libre que se muestra en lugar de anotarse | `OpenSpace` | — | `espacios` |
| Oferta | `Offer` | `waitlist_offers` | `ofertas` |
| Estado de la oferta (pendiente, aceptada, rechazada, vencida) | `OfferStatus` (`Pending`, `Accepted`, `Rejected`, `Expired`) | `waitlist_offers.status` (`pending`, `accepted`, `rejected`, `expired`) | `estado` |
| Espacio liberado (el tiempo que dejó una reserva cancelada, vencida o movida) | `ReleasedSpace`; evento `SpaceReleased` | — | — |
| Persona que espera, vista desde las ofertas | `WaitingCustomer` | — | — |
| Persona compatible | `WaitingCustomer::isCompatibleWith()` | — | — |
| Oferta creada, oferta vencida (hechos que avisan) | `OfferCreated`, `OfferExpired` | — | — |
| Mis ofertas (las que aún se pueden responder) | `ListMyOffers`, `OfferView` | — | `mis-ofertas` |
| Aceptar una oferta | `AcceptOffer`, `Offer::accept()` | `waitlist_offers.status = 'accepted'` | `aceptacion` (con `con_bono`) |
| Rechazar una oferta | `RejectOffer`, `Offer::reject()` | `waitlist_offers.status = 'rejected'` | `rechazo` |
| Reserva que responde una oferta | `Offer::answerWith()`, `OfferedBookings` | `waitlist_offers.reservation_id` | `reserva` |
| Reserva confirmada (hecho que acepta la oferta y saca de las filas del día) | `ReservationConfirmed` | — | — |
| Aviso | `Notification` | — | — |
| Recordatorio | `Reminder` | `reservation_reminders` | — |
| Aviso emergente | `Realtime` | — | — |
| Propuesta de IA | `BookingProposal` | — (no se guarda) | `propuesta` |
| Franja del día (IA) | `DayPart` | — | `franja` |
| Mañana, tarde, noche | `Morning`, `Afternoon`, `Evening` | — | `manana`, `tarde`, `noche` |
| Registro técnico | `AuditLog` | `audit_log` | — |

### Alquiler y bloqueo (previsto, M19)

Nombres propuestos; cada uno se confirma cuando su issue lo crea.

| Negocio | Código | Base de datos | API |
| --- | --- | --- | --- |
| Alquiler | `Rent` | — | `alquiler` |
| Cuota (el alquiler de un mes) | `RentInstallment` | `rent_installments` | `cuotas` |
| Vencimiento de la cuota | `dueOn` | `due_on` | `vence` |
| Estado de la cuota (pendiente, pagada) | `InstallmentStatus` | `status` | `pendiente`, `pagada` |
| Meses pendientes (se cuentan, no se guardan) | `pendingMonths()` | — (se calcula) | `meses_pendientes` |

### Mi cuenta y sus atajos (cliente web)

| Negocio | Código | Base de datos | API |
| --- | --- | --- | --- |
| Mi cuenta (pantalla común a todos los roles: datos, seguridad, avisos, apariencia e idioma) | — (solo cliente web) | — | — |
| Mi perfil (atajo del menú de cuenta a la pestaña de datos de Mi cuenta) | `profile` (nombre de ruta, solo cliente web) | — | — |
| Preferencias (atajo del menú de cuenta a las pestañas de avisos, apariencia e idioma de Mi cuenta) | `preferences` (nombre de ruta, solo cliente web) | — | — |

Mi perfil y Preferencias no son pantallas distintas: son accesos directos a partes de Mi cuenta (RF-45, RF-47, RF-48 y RF-49).

### Perfil público de la barbería (previsto, #187 y #46)

| Negocio | Código | Base de datos | API |
| --- | --- | --- | --- |
| Dirección | `address` | `barbershops.address` | `direccion` |
| Redes sociales (WhatsApp, Instagram, Facebook y TikTok) | `SocialNetwork` | `barbershops.whatsapp_url`, `instagram_url`, `facebook_url`, `tiktok_url` | `redes` |
| Logo | `logoPath` | `barbershops.logo_path` | `logo` |
| Banner | `bannerPath` | `barbershops.banner_path` | `banner` |
| Cabecera de la barbería | — (solo cliente web) | — | — |
| Monograma (iniciales del nombre cuando no hay logo) | — (solo cliente web) | — | — |

## Roles

La lista de roles es cerrada y se asigna por barbería (UP-01). Una persona tiene un único rol en cada barbería y puede tener uno distinto en otra (UP-07).

| Rol | Valor en la base | Cómo se obtiene |
| --- | --- | --- |
| Dueño | `owner` | Cuando el operador de plataforma registra la barbería y su cuenta (RF-01) |
| Colaborador | `collaborator` | Lo crea el dueño, con una contraseña temporal o por invitación (UP-04) |
| Cliente | `customer` | Se crea sola al primer uso de una operación de cliente en la barbería (UP-09) |

El **operador de plataforma** no es un rol: no tiene fila en `memberships` y su acceso lo fija PL-01. Es la marca `users.is_platform_operator`, que solo escribe el comando `platform:create-operator` (#293).

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
| `contrasena-temporal` | La cuenta debe cambiar su contraseña temporal antes de usar el resto de la API (UP-04, RF-40) |
| `metodo-no-permitido` | La ruta no acepta el método HTTP usado |
| `conflicto` | Una regla del dominio sin tipo propio impide la operación, por ejemplo reservar con un bono que no cubre el servicio (BO-06) |
| `espacio-no-disponible` | El espacio ya no está libre (RE-02), con hasta 3 alternativas |
| `saldo-insuficiente` | El bono ya no tiene sesiones, o el cliente no tiene ningún bono pagado (BO-03) |
| `plazo-vencido` | La cancelación o reprogramación llegó fuera de plazo |
| `bono-vencido` | El bono no está vigente en la fecha de la cita (BO-06) |
| `oferta-vencida` | La oferta caducó antes de la respuesta (LE-05) |
| `bono-con-ventas` | El bono ya se vendió: sus sesiones, su vigencia y sus servicios no se cambian (RF-26) |
| `colaborador-con-reservas` | El colaborador tiene reservas confirmadas por atender: no se desactiva hasta que el dueño las reprograme o las cancele (CO-03) |
| `ausencia-con-reservas` | El bloqueo de agenda cae sobre reservas confirmadas o temporales vigentes del colaborador: no se guarda hasta que el dueño las reprograme o las cancele (CO-06) |
| `barberia-bloqueada` | La barbería está bloqueada por falta de pago del alquiler (PL-08) |
| `demasiadas-solicitudes` | Se superó el límite de solicitudes |
| `error-interno` | Cualquier otro fallo, sin detalles técnicos |
| `servicio-no-disponible` | El servicio no está disponible en este momento |

## Dinero y fechas

- Los montos son colones enteros. Nunca se usan decimales ni punto flotante. El precio incluye el IVA de 13 % y se muestra desglosado (base e impuesto) al cliente (SV-02).
- El depósito es un porcentaje del precio, redondeado hacia arriba al colón (PA-06).
- Los instantes se guardan con zona horaria y viajan en ISO 8601. La aplicación opera en `America/Costa_Rica`, sin horario de verano (HO-03).
- Las horas de los horarios semanales viajan como `HH:MM` y las fechas como `AAAA-MM-DD`; ambas se leen en hora de Costa Rica. El día 1 es lunes y el 7 es domingo (ISO 8601).
- El inicio de una franja pertenece a ella y el fin no: una franja de 09:00 a 12:00 atiende a las 11:59 y ya no a las 12:00.
