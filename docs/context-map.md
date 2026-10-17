# Mapa de contextos

BarberHub se divide en ocho contextos (RD-05). El diseño es guiado por el dominio en su versión ligera: hay agregados solo donde hay invariantes (reserva, bono adquirido, oferta y pago); el CRUD simple no los necesita. Eloquent es solo el adaptador de persistencia (ver [ADR 0003](adr/0003-eloquent-only-in-infrastructure.md)). Los nombres del código siguen [ubiquitous-language.md](ubiquitous-language.md).

> **Estado:** documento inicial (issue #23). Lo que existe hoy es el núcleo compartido `Src\Shared` (#22) y las carpetas de los contextos Identity, Catalog, Availability y Reservations (#21). Los módulos, objetos de valor y eventos marcados "(previsto)" se confirman cuando su issue los crea y entonces se actualiza este archivo.

## Mapa

Los objetos de valor `Money`, `Duration`, `Percentage`, `TimeInterval` e `Identifier` ya existen en `Src\Shared\Domain` (#22).

| Contexto | Módulos | Agregados | Objetos de valor | Eventos que publica | Carpeta |
| --- | --- | --- | --- | --- | --- |
| Identity (identidad y acceso) | `Users`, `Memberships` | — | `Email`, `PlainPassword`, `Role` (previstos, #40) | — | Creada (#21) |
| Catalog (catálogo y horarios) | `Barbershops`, `Services`, `OpeningHours`, `Collaborators` | — | `Money`, `Duration`, `Percentage` (en Shared); `TimeRange`, `PublicLink` (previstos) | — | Creada (#21) |
| Availability (disponibilidad) | `Slots` | — | `Slot` (previsto); `TimeInterval` (en Shared) | — | Creada (#21) |
| Reservations (reservas) | `Reservations` y, más adelante, `AssistedClients` | `Reservation` | `ReservationStatus` (previsto); `TimeInterval` y `Money` (en Shared) | `ReservationConfirmed`, `ReservationCancelled`, `ReservationRescheduled`, `ReservationExpired`, `ReservationAttended`, `ReservationMissed`, `SpaceReleased` (previstos) | Creada (#21) |
| Payments (pagos) | `Payments`, `Refunds`, `Webhooks` | `Payment` | `PaymentStatus`, `GatewayEventId` (previstos); `Money` (en Shared) | `PaymentConfirmed`, `PaymentFailed`, `RefundCompleted` (previstos) | Llega en su milestone |
| Vouchers (bonos) | `VoucherDefinitions`, `PurchasedVouchers` | `PurchasedVoucher` | `Sessions` (previsto); `Money` (en Shared) | `VoucherActivated`, `VoucherSessionHeld`, `VoucherSessionReleased` (previstos) | Llega en su milestone |
| Waitlist (lista de espera) | `Entries`, `Offers` | `Offer` | `OfferStatus` (previsto); `TimeInterval` (en Shared) | `OfferCreated`, `OfferAccepted`, `OfferExpired` (previstos) | Llega en su milestone |
| Notifications (avisos) | `Reminders`, `Mail`, `Realtime` | — | `Locale` (previsto) | — | Llega en su milestone |

Un módulo corresponde a un agregado o a un conjunto de datos que se administra junto, no a una tabla. Los eventos de dominio y qué contexto reacciona a cada uno están en [architecture.md](architecture.md), en la sección "Colaboración entre contextos".

## Invariantes de los agregados

Los cuatro agregados guardan reglas que no pueden romperse nunca, ni siquiera por una petición simultánea. Cada invariante se prueba con pruebas unitarias del dominio, sin base de datos, y las que dependen de la concurrencia se refuerzan además en PostgreSQL (ver [database.md](database.md)).

| Agregado | Invariantes | Reglas |
| --- | --- | --- |
| `Reservation` | Los estados son temporal, confirmada, completada, ausente, cancelada por el cliente, cancelada por la barbería y expirada; solo se permiten las transiciones de RE-04 y los demás estados son finales. Tiene un solo servicio y un solo colaborador. Se reprograma como máximo 2 veces y no vuelve a cobrar depósito. Conserva el precio, la duración y el plazo de cancelación vigentes cuando se hizo. | RE-03, RE-04, CA-01, CA-05, SV-03 |
| `PurchasedVoucher` | El saldo en sesiones nunca es negativo. Se activa solo cuando el webhook confirma el pago. Cubre solo los servicios indicados y la reserva debe ocurrir dentro de su vigencia. Al vencer pierde las sesiones restantes; la vigencia se valida al reservar y al consultar el saldo, no hay proceso de vencimiento. | BO-02, BO-03, BO-06, BO-07 |
| `Offer` | Aparta el espacio 15 minutos en exclusiva. Si la persona no confirma o rechaza, pasa a la siguiente (en un máximo de 1 minuto tras vencer). Una persona no recibe dos ofertas solapadas. | LE-04, LE-05, LE-06 |
| `Payment` | Su estado lo fija solo la confirmación del proveedor por webhook, y solo si se verifica el origen. Procesar el mismo evento varias veces produce el mismo resultado que procesarlo una vez. | PA-03, PA-04, PA-05 |

## Lo que no es un agregado

`Users`, `Memberships`, `Barbershops`, `Services`, `OpeningHours`, `Collaborators`, los avisos y los reembolsos son datos que se administran con casos de uso simples. No tienen invariantes que crucen varias filas, así que no necesitan un agregado: basta una entidad y su repositorio. `Slots` no guarda datos: calcula la disponibilidad a partir del horario, los turnos y las reservas (DI-01).

## Cómo se actualiza

Un contexto nuevo, un módulo nuevo o un evento nuevo se agrega a este documento en el mismo pull request que crea su carpeta. Un agregado solo se agrega cuando aparece una invariante que lo exija.
