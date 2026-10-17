# BarberHub: reglas de negocio

Versión 1.5 (16 de octubre de 2026). Contiene 121 reglas vigentes en 16 categorías. Define cómo funciona una barbería costarricense dentro de BarberHub, sin diseñar tablas, endpoints ni clases. Los requisitos que las aplican están en `requirements.md` y citan estas reglas por su identificador.

## 1. Cómo leer este documento

Cada regla tiene un identificador estable (categoría y número), su texto y su origen.

| Origen | Significado |
| --- | --- |
| C | Requisito del cliente: se pidió de forma explícita. |
| N | Norma o fuente externa: una ley, un impuesto o una práctica documentada del sector. |
| D | Decisión de diseño: una regla razonada para que el sistema sea coherente, que el cliente puede cambiar. |

Los identificadores no se reutilizan. Una regla retirada deja su número vacío y consta en el apéndice A.

## 2. Glosario

| Término | Significado |
| --- | --- |
| BarberHub | La plataforma: un único sistema donde conviven varias barberías. |
| Barbería | Cada negocio registrado en la plataforma, con sus datos totalmente separados de los demás. En el código es `Barbershop`. |
| Enlace público | La dirección propia de cada barbería, por la que sus clientes llegan a ella. |
| Dueño | Persona que administra una barbería. |
| Colaborador | Barbero o barbera que atiende y tiene agenda propia. |
| Cliente | Persona que reserva, paga y cancela. |
| Cliente asistido | Persona que el dueño registra para una reserva asistida, sin cuenta. |
| Espacio | Intervalo de tiempo de un colaborador en el que cabe un servicio. |
| Reserva temporal | Espacio apartado durante 10 minutos mientras se completa el pago. |
| Depósito | Porcentaje del precio que se paga al reservar. |
| Bono | Paquete prepagado de sesiones para ciertos servicios, con vigencia. |
| Sesión | Una unidad de saldo de un bono; cubre un servicio. |
| Oferta | Espacio liberado que se propone a quien está primero en la fila de espera. |
| Reserva asistida | Reserva creada por el dueño para una persona que llega o llama. |
| Webhook | Aviso que envía la pasarela de pago para confirmar un pago. |
| Fila | La lista de espera de un servicio, un día y un barbero (o cualquiera). |
| Persona compatible | Quien está en la fila para el mismo servicio y día, con el mismo barbero o "cualquiera", cuya duración cabe en el espacio liberado y que no tiene otra reserva solapada. |
| Bono aplicable | Bono del cliente en esa barbería, vigente, con saldo y que cubre el servicio elegido. |
| Registro técnico | Registro interno de acciones sensibles con autor, fecha y cambio, sin pantalla de consulta. |
| Aviso | Mensaje al cliente, por correo o como aviso emergente dentro de la aplicación. |
| Mi cuenta | Pantalla común a todos los roles con los datos, la seguridad, los avisos, la apariencia y el idioma de la persona. |

## 3. Barbería de referencia: Pura Navaja

Barbería ficticia de barrio en Ciudad Quesada, con 4 sillas: el dueño, que también corta, y 3 barberos. Todos los nombres, precios y horarios son inventados y sirven para los ejemplos y los criterios de aceptación.

**Servicios.** La agenda se divide en pasos de 15 minutos y entre dos reservas del mismo barbero se dejan 5 minutos de limpieza. Los precios incluyen el IVA de 13 %.

| Servicio | Duración | Precio |
| --- | --- | --- |
| Corte clásico | 30 min | ₡7.000 |
| Corte degradado | 40 min | ₡8.500 |
| Corte infantil (hasta 12 años) | 30 min | ₡6.000 |
| Rapado | 20 min | ₡5.500 |
| Perfilado de barba | 20 min | ₡4.500 |
| Corte + barba | 60 min | ₡11.500 |
| Corte + barba + tinte de barba | 90 min | ₡19.000 |

**Horario y personal.** La barbería abre de lunes a viernes de 9:00 a 19:00 y los sábados de 8:00 a 17:00; los domingos y los feriados cierra, salvo que el dueño los abra.

| Colaborador | Días y horas | Pausa | Servicios |
| --- | --- | --- | --- |
| Mauricio (dueño) | Lunes a viernes 9:00 a 19:00; sábado 8:00 a 17:00 | 12:00 a 13:00 | Todos |
| Daniela | Lunes a viernes 9:00 a 17:00 | 12:30 a 13:30 | Todos menos el combo con tinte |
| Kevin | Martes a sábado 10:00 a 19:00 (sábado hasta 17:00) | 13:00 a 14:00 | Todos menos infantil |
| José | Miércoles a sábado 11:00 a 19:00 (sábado 8:00 a 17:00) | 14:00 a 15:00 | Todos menos el combo con tinte |

**Políticas por defecto.**

| Política | Valor |
| --- | --- |
| Cobro al reservar en línea | Depósito de 30 %; el saldo se cobra en el local, fuera del sistema |
| Cancelación gratuita | Hasta 4 horas antes de la reserva |
| Bono 5 cortes | ₡32.000, 5 sesiones, 90 días, cubre corte clásico y degradado |
| Bono 4 corte + barba | ₡42.000, 4 sesiones, 60 días, cubre corte + barba |

Lo que cambia entre barberías son parámetros, no reglas. La plataforma demostrará esto con tres barberías de ejemplo: Pura Navaja, Fade Lab y Barbería Don Chepe.

## 4. Parámetros

### 4.1 Constantes del sistema

| Parámetro | Valor | Regla |
| --- | --- | --- |
| Moneda | Colones, en enteros | SV-01 |
| Duración de un servicio | De 10 a 180 min, en múltiplos de 5 | SV-01 |
| IVA | 13 %, incluido en el precio | SV-02 |
| Imágenes | JPG, PNG o WebP, hasta 2 MB | SV-06 |
| Zona horaria | Costa Rica (UTC−6, sin horario de verano) | HO-03 |
| Paso de la agenda | 15 min | DI-02 |
| Limpieza entre reservas | 5 min | DI-01 |
| Anticipación mínima para reservar | 60 min | DI-03 |
| Ventana de reserva | 30 días | DI-03 |
| Tolerancia para marcar ausente | 10 min | CO-05 |
| Reserva temporal | 10 min desde que inicia el pago | RE-05 |
| Retraso máximo al expirar reservas temporales | 1 min | NO-04 |
| Reprogramaciones por reserva | 2 | CA-05 |
| Redondeo del depósito | Hacia arriba, al colón | PA-06 |
| Oferta de lista de espera | 15 min | LE-04 |
| Retraso máximo al pasar una oferta vencida a la siguiente persona | 1 min | LE-05 |
| Mínimo para ofrecer un espacio | 60 min antes de la hora | LE-03 |
| Filas de espera activas por cliente | 3 | LE-01 |
| Recordatorios | 24 h y 2 h antes, por correo | NO-02 |
| Actualización de la agenda en vivo | Menos de 5 s | NO-06 |
| Aviso emergente de una oferta | Menos de 5 s | NO-08 |
| Franjas del día para la IA | Mañana 6:00 a 11:59; tarde 12:00 a 17:59; noche 18:00 a 23:59 | IA-07 |
| Contraseña | Mínimo 8 caracteres, con mayúscula, minúscula, número y signo especial | SE-07 |
| Intentos fallidos de inicio de sesión | Bloqueo de 15 min tras 5 seguidos | SE-05 |
| Vigencia del enlace de restablecimiento | 60 min, de un solo uso | SE-06 |
| Solicitudes de restablecimiento | 5 por hora, por correo y por dirección IP | SE-06 |
| Vigencia de la invitación de colaborador | 7 días, de un solo uso | UP-04 |
| Alternativas a un espacio no disponible | Hasta 3, del mismo día y servicio | RE-02 |
| Envío de correos de confirmación, oferta, cancelación, reprogramación y reembolso | Dentro de 60 s | NO-01, NO-07, NO-09 |
| Tiempo máximo de respuesta del proveedor de IA | 10 s | IA-05 |
| Tamaños de texto | 100 %, 125 % y 150 % | AC-03 |

### 4.2 Parámetros que configura cada barbería

| Parámetro | Opciones | Regla |
| --- | --- | --- |
| Horario semanal, cierres y excepciones | Franjas por día | HO-01 |
| Servicios | Nombre, duración, precio, imagen y estado | SV-01 |
| Colaboradores | Servicios que ofrece, horario y pausas | CO-01, HO-02 |
| Modalidad de cobro | Sin pago; depósito de 10 a 100 %; pago total | PA-01 |
| Plazo de cancelación gratuita | Horas antes de la reserva | CA-01 |
| Pasarela de pago | ONVO Pay o PayPal | PA-02 |
| Bonos | Activarlos y definirlos | BO-01 |
| Lista de espera | Activarla | LE-01 |

### 4.3 Preferencias de cada persona

| Preferencia | Opciones | Dónde se guarda | Regla |
| --- | --- | --- | --- |
| Tema | Claro, oscuro o automático (por defecto) | Navegador | AC-02, AC-05 |
| Tamaño del texto | Normal, Grande o Muy grande | Navegador | AC-03, AC-05 |
| Reducción de movimiento | Activada o desactivada (sigue al dispositivo) | Navegador | AC-04, AC-05 |
| Idioma | Español (por defecto) o inglés | Navegador y cuenta | AC-07, AC-08 |
| Recordatorios por correo | Activados (por defecto) o desactivados; solo el cliente | Cuenta | NO-10 |

## 5. Reglas por categoría

### Clientes (CL)

| ID | Regla | Origen |
| --- | --- | --- |
| CL-01 | Una persona tiene una sola cuenta de cliente (correo único) y puede reservar en varias barberías; el historial, los bonos y los depósitos pertenecen a la barbería donde se generaron. | D |
| CL-02 | No se crea una cuenta sin dos aceptaciones expresas y separadas: los términos y condiciones de BarberHub y el tratamiento de los datos personales (nombre, correo, teléfono) según la política de privacidad. Se guarda la fecha de cada una. | N, D |
| CL-03 | El cliente puede ver y corregir sus datos personales y cambiar su contraseña. | N, D |
| CL-04 | Un cliente ve únicamente sus propias reservas, pagos, bonos y ofertas de lista de espera. | C |
| CL-05 | El servicio infantil se reserva a nombre de una persona adulta responsable; no existen cuentas de menores. | D |
| CL-06 | Un cliente asistido (creado por la barbería) existe solo dentro de esa barbería, con nombre y teléfono obligatorios y correo opcional; sin correo no recibe recordatorios. Complementaria, con RF-12. | D |
| CL-08 | Los datos de prueba y de demostración son ficticios; no se cargan datos de personas reales. | C |
| CL-09 | Los términos y condiciones y la política de privacidad pertenecen a BarberHub como plataforma; cada barbería muestra sus propias condiciones, como la cancelación, al reservar. | D |

### Servicios (SV)

| ID | Regla | Origen |
| --- | --- | --- |
| SV-01 | Todo servicio tiene nombre, duración (10 a 180 min, múltiplos de 5) y precio en colones enteros mayor que cero. | C, D |
| SV-02 | El precio incluye el IVA de 13 % y se muestra desglosado (base e impuesto) al cliente. | N, D |
| SV-03 | Una reserva conserva el precio y la duración vigentes cuando se hizo; cambiar el catálogo no altera reservas existentes. | D |
| SV-04 | Un servicio inactivo no se puede reservar, pero sigue visible en el historial. | D |
| SV-05 | Un servicio es reservable solo si al menos un colaborador activo lo ofrece. | D |
| SV-06 | Las imágenes de servicios y el logo aceptan solo JPG, PNG o WebP de hasta 2 MB. | C, D |
| SV-07 | La imagen de un servicio se muestra al cliente al elegirlo; un servicio sin imagen se muestra sin ella. | D |

### Colaboradores (CO)

| ID | Regla | Origen |
| --- | --- | --- |
| CO-01 | Un colaborador pertenece a una única barbería y solo se reserva para los servicios que tiene habilitados. | D |
| CO-02 | El dueño puede tener un perfil de colaborador con agenda propia sin dejar de ser dueño. | D |
| CO-03 | Un colaborador con reservas futuras confirmadas no se puede desactivar hasta que el dueño las reasigne o las cancele. | D |
| CO-04 | Un colaborador ve solo su agenda: no ve ingresos, el detalle de pagos ni la agenda de otros; sí ve el saldo pendiente por cobrar de sus propias reservas. | C |
| CO-05 | Un colaborador marca como atendidas o ausentes solo sus propias reservas, después de la hora de inicio; "ausente" solo tras 10 min de tolerancia. | C, D |
| CO-06 | Una ausencia (vacaciones, incapacidad) bloquea la agenda del colaborador; si cae sobre reservas confirmadas, el dueño debe resolverlas antes de guardarla. Complementaria, con RF-07. | D |

### Horarios (HO)

| ID | Regla | Origen |
| --- | --- | --- |
| HO-01 | La barbería define su horario semanal por día con una o más franjas, más días cerrados y excepciones por fecha. | C, D |
| HO-02 | El horario de un colaborador (franjas y pausas por día) debe caer dentro del horario de la barbería; el sistema rechaza lo contrario. | C, D |
| HO-03 | Todas las horas se interpretan en la zona horaria de Costa Rica (UTC−6, sin horario de verano). | D |
| HO-04 | Cambiar horarios no altera reservas confirmadas; el sistema muestra al dueño las que quedan fuera del nuevo horario. | D |

### Disponibilidad (DI)

| ID | Regla | Origen |
| --- | --- | --- |
| DI-01 | Un espacio está disponible solo si la barbería está abierta, el colaborador está en turno, no hay pausa ni ausencia, no solapa otra reserva (confirmada o temporal vigente) y el servicio más 5 min de limpieza cabe en el turno. | C, D |
| DI-02 | Los espacios empiezan cada 15 minutos. | D |
| DI-03 | Se reserva con al menos 60 min de anticipación y hasta 30 días adelante. | D |
| DI-04 | Con "cualquiera disponible", el sistema asigna al barbero con menos reservas ese día entre los disponibles; el empate se resuelve por antigüedad de alta. | D |
| DI-05 | Un espacio apartado por una reserva temporal o por una oferta de lista de espera no aparece disponible para otras personas. | C, D |

### Reservas (RE)

| ID | Regla | Origen |
| --- | --- | --- |
| RE-01 | Un colaborador no puede tener dos reservas (confirmadas o temporales vigentes) con intervalos solapados, tampoco cuando las solicitudes llegan a la vez. | C |
| RE-02 | Ante dos solicitudes simultáneas por el mismo espacio, una se confirma y la otra recibe el error de tipo estable "espacio-no-disponible" (RNF-17) con hasta 3 espacios alternativos del mismo día y servicio, los de inicio más próximo a la hora pedida. | C |
| RE-03 | Una reserva tiene un solo servicio y un solo colaborador; los combos son servicios del catálogo. | D |
| RE-04 | Los estados son temporal, confirmada, completada, ausente, cancelada por cliente, cancelada por la barbería y expirada. Solo se permite temporal → confirmada, expirada o cancelada por cliente, y confirmada → completada, ausente o cancelada; los demás son finales. | D |
| RE-05 | La reserva temporal dura 10 min desde que inicia el pago; si el pago no se confirma en ese plazo, expira y libera el espacio. | C, D |
| RE-06 | Un cliente no puede tener dos reservas confirmadas solapadas en una barbería. | D |
| RE-07 | Una reserva que no requiere pago (bono, barbería sin pago o reserva asistida) se confirma de inmediato. | D |
| RE-08 | Reprogramar es atómico: si el nuevo espacio no está disponible, la reserva original no cambia. | D |
| RE-09 | Cada cambio de estado de una reserva queda registrado con fecha y autor. | D |

### Cancelaciones y reprogramaciones (CA)

| ID | Regla | Origen |
| --- | --- | --- |
| CA-01 | La barbería define el plazo de cancelación gratuita en horas antes de la reserva; hasta ese plazo el cliente cancela o reprograma sin penalización. El plazo vigente al reservar rige esa reserva. | D |
| CA-02 | Cancelación a tiempo: se devuelve el depósito y se reintegra la sesión del bono. Mientras no exista el reembolso automático (RF-24), el depósito queda marcado como "a devolver". | D |
| CA-03 | Cancelación tardía o ausencia: el depósito no se devuelve y la sesión del bono se consume. | D |
| CA-04 | Si cancela la barbería, el cliente recupera todo y recibe aviso inmediato. | D |
| CA-05 | Una reserva se reprograma como máximo 2 veces y no vuelve a cobrar depósito. | D |
| CA-06 | Cancelar es idempotente: repetir la cancelación no devuelve dos veces el depósito ni la sesión. | D |
| CA-07 | Al cancelarse una reserva, su espacio se libera de inmediato y activa la lista de espera. | C |
| CA-08 | Si el dueño reprograma una reserva, el cliente recibe un aviso por correo y puede cancelarla sin penalización. | D |

### Pagos (PA)

| ID | Regla | Origen |
| --- | --- | --- |
| PA-01 | Cada barbería elige su modalidad de cobro: sin pago, depósito (10 a 100 % del precio) o pago total. | C, D |
| PA-02 | Cada barbería tiene una sola pasarela activa: ONVO Pay (tarjeta y SINPE Móvil) o PayPal (tarjeta); cambiarla no afecta los pagos en curso. | C, D |
| PA-03 | El estado de un pago lo fija solo la confirmación del proveedor por webhook; nada que venga del navegador confirma un pago. | C |
| PA-04 | Un webhook se acepta solo si se verifica su origen; si no, se rechaza sin cambiar ningún estado. | C |
| PA-05 | Procesar el mismo evento varias veces produce el mismo resultado que procesarlo una vez. | C |
| PA-06 | La reserva se confirma solo cuando el webhook confirma el pago; el depósito es el porcentaje del precio reservado, redondeado hacia arriba al colón. | C, D |
| PA-07 | Si un pago confirmado llega después de expirar la reserva temporal y el espacio ya no está libre, se reembolsa y se avisa al cliente (con RF-24; antes, queda marcado como "a devolver"). | D |
| PA-08 | Los reembolsos (RF-24) usan la misma pasarela del pago; si fallan, quedan pendientes y visibles para el dueño. | D |
| PA-09 | Todos los pagos corren en modo de pruebas de las pasarelas; no se procesa dinero real. | C |
| PA-10 | El saldo del servicio se cobra en el local fuera del sistema; el sistema solo muestra el saldo pendiente a dueño y colaborador. | D |
| PA-11 | El reporte de ingresos del dueño separa lo cobrado en línea, los bonos vendidos y el valor de los servicios atendidos, y suma solo su barbería. | C, D |

### Bonos prepagados (BO)

| ID | Regla | Origen |
| --- | --- | --- |
| BO-01 | La barbería define cada bono: nombre, número de sesiones, precio, vigencia en días y servicios que cubre. | C, D |
| BO-02 | Un bono se activa solo cuando el webhook confirma su pago y pertenece a la barbería donde se compró. | C, D |
| BO-03 | El saldo en sesiones nunca es negativo; si dos reservas compiten por la última sesión, una se confirma y la otra recibe el error de tipo estable "saldo-insuficiente" (RNF-17). | C |
| BO-04 | La sesión se aparta al confirmar la reserva, no al asistir. | D |
| BO-05 | Cancelar a tiempo o cancelar la barbería reintegra la sesión; la ausencia o la cancelación tardía la consume. | D |
| BO-06 | Un bono cubre solo los servicios indicados, sin cobro de diferencias, y la reserva debe ocurrir dentro de su vigencia. | D |
| BO-07 | Al vencer, las sesiones restantes se pierden sin reembolso; el bono no es transferible ni reembolsable. La vigencia se valida al reservar y al consultar el saldo; no hay proceso de vencimiento. | D |
| BO-08 | Con varios bonos aplicables se usa primero el que vence antes. | D |
| BO-09 | Cada movimiento de saldo (compra, apartado, consumo, reintegro) queda registrado y ligado a su reserva. | D |

### Lista de espera (LE)

| ID | Regla | Origen |
| --- | --- | --- |
| LE-01 | Cuando no hay espacio, el cliente se une a la fila indicando servicio, día y barbero (o cualquiera); tiene como máximo 3 filas activas. | C, D |
| LE-02 | Cada fila se ordena por hora de ingreso (primero en entrar, primero en recibir oferta). | D |
| LE-03 | Al liberarse un espacio se ofrece a la primera persona compatible en servicio, día, barbero y duración y sin reserva propia solapada; no se ofrece si faltan menos de 60 min para la hora. | C, D |
| LE-04 | La oferta aparta el espacio 15 min en exclusiva; confirmarla exige completar el pago o usar el bono dentro de ese plazo. | C, D |
| LE-05 | Si la persona no confirma o rechaza, la oferta pasa a la siguiente, y cuando vence lo hace en un máximo de 1 minuto; si la fila se agota, el espacio vuelve a ser público. | C |
| LE-06 | Una persona no recibe dos ofertas solapadas y sale de la fila al reservar por otra vía ese mismo servicio y día. | D |

### Usuarios y permisos (UP)

| ID | Regla | Origen |
| --- | --- | --- |
| UP-01 | Existen tres roles (dueño, colaborador, cliente) y el rol de una persona se asigna por barbería. | C, D |
| UP-02 | Toda acción verifica el rol y que el recurso pertenezca al usuario o a su barbería. | C |
| UP-03 | El dueño configura servicios, precios, horarios, personal, bonos, políticas y pasarela de su barbería, ve sus ingresos y cancela reservas; con RF-12 también crea reservas asistidas. | C |
| UP-04 | Una cuenta de colaborador la crea el dueño con una contraseña temporal que debe cambiar en su primer ingreso; la invitación por correo (RF-06), válida por 7 días y de un solo uso, es complementaria. | D |
| UP-05 | Una barbería siempre conserva al menos un dueño activo. | D |
| UP-06 | Pedir un recurso de otra barbería o de otra persona responde como si no existiera. | D |
| UP-07 | Una persona tiene un único rol en cada barbería; puede tener un rol distinto en otra. | D |
| UP-08 | Quien tiene acceso a más de una barbería elige con cuál entra; con una sola, entra directamente. | D |
| UP-09 | La persona recibe su rol de cliente en una barbería la primera vez que reserva, compra un bono o se anota en una fila de espera en ella: el sistema crea esa membresía en la misma operación, y no antes. Quien ya es dueño o colaborador de esa barbería no puede recibir también el rol de cliente. | D |

### Multi-inquilino (MT)

| ID | Regla | Origen |
| --- | --- | --- |
| MT-01 | Todo dato operativo (servicios, horarios, reservas, pagos, bonos, filas) pertenece a una única barbería. | C |
| MT-02 | Una persona de una barbería no puede leer ni modificar datos de otra, aunque conozca identificadores o altere datos de la petición. | C |
| MT-03 | La barbería sobre la que se actúa se deduce de la sesión o del recurso, nunca de un dato que el usuario pueda manipular. | C |
| MT-04 | Catálogo, horarios, políticas, bonos y pasarela son independientes por barbería. | C, D |
| MT-05 | Los archivos subidos (logo, fotos) se guardan y se sirven separados por barbería. | D |
| MT-06 | La base de datos rechaza el acceso a datos de otra barbería aunque una consulta olvide el filtro. | D |
| MT-07 | Cada barbería tiene un enlace público propio por el que sus clientes llegan a ella. | D |
| MT-08 | El logo de la barbería se muestra a sus clientes en el encabezado de sus pantallas y en sus correos. | D |

### Notificaciones y procesos automáticos (NO)

| ID | Regla | Origen |
| --- | --- | --- |
| NO-01 | Al confirmarse una reserva se envía una confirmación por correo dentro de 60 s. | D |
| NO-02 | Se envían recordatorios por correo 24 h y 2 h antes; si la reserva se hizo con menos de 24 h, solo se envía el que aún aplique, y no se envían si el cliente los desactivó (NO-10). | C, D |
| NO-03 | No se envían recordatorios de reservas canceladas, expiradas o ausentes, y ninguno se envía dos veces. | D |
| NO-04 | Las reservas temporales vencidas se expiran automáticamente con un retraso máximo de 1 minuto. | C, D |
| NO-06 | Cuando una reserva entra o se cancela, la agenda del dueño y del colaborador afectado se actualiza en menos de 5 s sin recargar la pantalla. | C, D |
| NO-07 | Una oferta de lista de espera, y una cancelación o una reprogramación hecha por la barbería, se notifican por correo dentro de 60 s. | D |
| NO-08 | Cuando se ofrece un espacio a una persona de la fila, además del correo recibe un aviso emergente dentro de la aplicación, con la cuenta regresiva, si la tiene abierta. | D |
| NO-09 | Se avisa por correo al cliente cuando se completa un reembolso. | D |
| NO-10 | El cliente puede desactivar los recordatorios (NO-02); la confirmación, la oferta de espacio, la cancelación o reprogramación de la barbería, el reembolso y el restablecimiento de contraseña no se pueden desactivar porque protegen su reserva, su dinero o su cuenta. | D |

### Inteligencia artificial (IA)

| ID | Regla | Origen |
| --- | --- | --- |
| IA-01 | El cliente escribe una frase libre y el sistema devuelve datos estructurados: servicio, barbero (opcional), fecha y hora o franja. | C |
| IA-02 | La propuesta se valida contra el catálogo y la disponibilidad reales de la barbería; lo que no exista se descarta y la IA nunca inventa servicios, barberos ni espacios. | C, D |
| IA-03 | La IA no crea reservas: el cliente confirma la propuesta y sigue el flujo normal de reserva. | D |
| IA-04 | Las fechas relativas ("el sábado") se resuelven con la fecha y la zona horaria de la barbería. | D |
| IA-05 | Si el proveedor de IA falla o devuelve algo inválido, el resto del sistema sigue funcionando y se ofrece la reserva manual. | C |
| IA-06 | Al proveedor de IA solo se envían la frase, la fecha y la zona horaria de la barbería, y los nombres de los servicios y colaboradores activos de esa barbería; nunca el correo ni el teléfono del cliente. | D, N |
| IA-07 | Las franjas del día son: mañana de 6:00 a 11:59, tarde de 12:00 a 17:59 y noche de 18:00 a 23:59. | D |

### Seguridad (SE)

| ID | Regla | Origen |
| --- | --- | --- |
| SE-01 | Las contraseñas se almacenan con hash; ninguna se muestra ni se registra. | D |
| SE-02 | Las credenciales y los secretos viven en el archivo de entorno y nunca en el repositorio. | C |
| SE-03 | Los errores usan un formato único (RFC 9457) y no exponen detalles internos. | C |
| SE-04 | Las acciones sensibles (cancelar por la barbería, cambiar políticas o pasarela, ajustar saldos, reembolsar) quedan en un registro técnico con autor, fecha y cambio, sin pantalla de consulta. | D |
| SE-05 | Tras 5 intentos fallidos seguidos de inicio de sesión se bloquean nuevos intentos por 15 minutos. | D |
| SE-06 | El enlace para restablecer la contraseña caduca a los 60 minutos y se usa una sola vez; la respuesta es la misma exista o no el correo; los intentos se limitan y, al restablecer, se cierran todas las sesiones. | D |
| SE-07 | La contraseña tiene al menos 8 caracteres, con mayúscula, minúscula, número y signo especial. | D |

### Apariencia, accesibilidad e idioma (AC)

| ID | Regla | Origen |
| --- | --- | --- |
| AC-01 | La interfaz cumple las pautas WCAG 2.2 de nivel AA en todas sus pantallas, desde la primera. | N, D |
| AC-02 | La persona elige el tema claro, oscuro o automático; el automático sigue la preferencia del dispositivo y es el valor por defecto. | D |
| AC-03 | El tamaño del texto tiene tres niveles: Normal (100 %), Grande (125 %) y Muy grande (150 %). | D |
| AC-04 | Con la reducción de movimiento activada no hay animaciones ni transiciones no esenciales; por defecto se respeta la preferencia del dispositivo. | N, D |
| AC-05 | Las preferencias de apariencia se guardan en el navegador y se aplican sin necesidad de tener cuenta. | D |
| AC-06 | El texto se puede ampliar hasta el 200 % sin perder contenido ni funciones. | N, D |
| AC-07 | La interfaz está disponible en español e inglés; el idioma por defecto es el español. | D |
| AC-08 | El idioma elegido se guarda en el navegador y, con sesión, también en la cuenta, y se usa en los correos; los datos que escribe la barbería no se traducen. | D |

## 6. Estados de la reserva

Los estados y sus transiciones los fija RE-04. Un estado final no cambia.

| Estado | Significado | Puede pasar a |
| --- | --- | --- |
| Temporal | Espacio apartado por 10 min; el pago está pendiente | Confirmada, Expirada, Cancelada por el cliente |
| Confirmada | Pago confirmado, o reserva que no requiere pago | Completada, Ausente, Cancelada por el cliente, Cancelada por la barbería |
| Completada | El cliente fue atendido | Final |
| Ausente | El cliente no llegó | Final |
| Cancelada por el cliente | El cliente canceló | Final |
| Cancelada por la barbería | El dueño canceló | Final |
| Expirada | El pago no se confirmó en 10 min | Final |

## 7. Ejemplos con Pura Navaja

1. **Espacios de un día.** Corte + barba (60 min más 5 de limpieza) con Mauricio un martes, con pausa de 12:00 a 13:00: el primer espacio es 9:00, el último antes de la pausa es 10:45 y el último del día es 17:45 (DI-01, DI-02).
2. **Dos personas, un espacio.** Ana y Luis piden a la vez el degradado de las 10:00 con Daniela. Una reserva queda confirmada y la otra recibe “espacio no disponible” con alternativas cercanas; nunca hay dos reservas (RE-01, RE-02).
3. **Depósito.** Degradado de ₡8.500 con depósito de 30 %: ₡2.550 hoy y ₡5.950 en el local, sin decimales (PA-06, PA-10).
4. **Cancelación.** Reserva del sábado a las 14:30 con cancelación gratuita de 4 h. Cancelar a las 10:00 es a tiempo: el depósito queda a devolver. Cancelar a las 11:00 es tardío: el depósito se retiene (CA-01, CA-02, CA-03).
5. **Última sesión del bono.** Un cliente con 1 sesión reserva dos servicios a la vez. Una reserva se confirma y la otra recibe “saldo insuficiente”; el saldo termina en 0 (BO-03).
6. **Lista de espera.** Tres personas esperan un corte clásico con Daniela. Al cancelarse una reserva a las 11:00 para la 1:00 de la tarde, la oferta de 15 min va solo a la primera compatible, con correo y aviso emergente; si no responde, pasa a la segunda. Si faltaran menos de 60 min, no se ofrecería (LE-03, LE-04, LE-05, NO-08).

## Apéndice A. Reglas retiradas

No se reutilizan sus identificadores.

| ID | Qué decía | Por qué se retiró |
| --- | --- | --- |
| CL-07 | Tras 2 ausencias en 90 días, el cliente debía pagar el 100 % al reservar | Complejidad sin valor claro; la ausencia ya retiene el depósito |
| NO-05 | Los canales eran el correo y la notificación en la aplicación | Se acotó el aviso en la aplicación a las ofertas (NO-08) |
| RE-06 (parte) | Un cliente no podía tener más de 3 reservas futuras confirmadas | Un tope arbitrario; queda solo la regla de no solapar |
| CL-03 (parte) | Eliminar la cuenta y anonimizar los datos | Fuera del alcance |
| BO-09 (parte) | El vencimiento como movimiento de saldo | La vigencia se valida al reservar, sin proceso de vencimiento |

## Apéndice B. Historial de cambios

### Versión 1.5

| Cambio | Reglas |
| --- | --- |
| Regla nueva | UP-09: la membresía de cliente se crea al primer uso en la barbería (reservar, comprar un bono o anotarse en una fila de espera) |

### Versión 1.4

| Cambio | Reglas |
| --- | --- |
| Valor concretado | LE-05 y tabla 4.1: una oferta vencida pasa a la siguiente persona en un máximo de 1 minuto |

### Versión 1.3

| Cambio | Reglas |
| --- | --- |
| Reglas nuevas | CL-09, SV-07, UP-07, UP-08, MT-06, MT-07, MT-08, NO-08, NO-09, NO-10, SE-06, SE-07, CA-08 y la categoría AC (AC-01 a AC-08) |
| Valores concretados | RE-02, SE-05, SE-06, UP-04, NO-01, NO-07, IA-06 |
| Redacción actualizada | CL-02, CL-03, NO-02, BO-03 |
| Terminología | "Negocio" pasa a "barbería" y los orígenes se simplifican a C, N y D |

