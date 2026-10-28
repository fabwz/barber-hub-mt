# Investigación aplicada 1: informe comparativo de proveedores de pago

**Proyecto:** BarberHub · **Todas las fuentes se consultaron el 27/10/2026**

## 1. Qué comparamos y cómo

Integramos ONVO Pay y PayPal en nuestro sistema de reservas (ambiente de pruebas), y analizamos Tilopay y GreenPay solo con su documentación. Siempre buscamos primero en la fuente oficial. Cuando un dato no estaba ahí, lo tomamos de fuentes secundarias y lo marcamos así, y si algo oficial contradice a un tercero, mandamos lo oficial y lo anotamos. Los cálculos de costos usan ₡500 por dólar (el valor por defecto de `PAYPAL_CRC_PER_USD` en nuestro proyecto; el real cambia) y no incluyen IVA ni retenciones.

## 2. Comparación

| | ONVO Pay | PayPal | Tilopay | GreenPay |
|---|---|---|---|---|
| **Credenciales de prueba y trámite** | Llaves `onvo_test_` al crear la cuenta, sin espera [5][6]. Cuenta real: 24 a 48 h tras la afiliación [4] | Aplicación sandbox y dos cuentas de prueba desde el portal de desarrolladores, sin espera [6] | Registro digital y aprobación en máximo 48 h [12]. La documentación pública no describe un ambiente de pruebas autoservicio | Cuenta sandbox gratuita que uno mismo crea en su panel [18][20]. Cuenta real: formulario de afiliación con documentos legales [18] |
| **Costo por transacción** | Tarjeta 3,9 % + US$0,35; SINPE Móvil 1,5 %; mensualidad US$5 [3] | 5,40 % + US$0,30 [7] | Su sitio no publica tarifas [12]. Fuentes secundarias: tarjeta 4,25 % + US$0,35 [14][15][16]; SINPE Móvil 2 % + US$0,35 [15]. Sin cuota mensual [12] | Sin tarifa oficial para la API. Una fuente secundaria reporta 4,8 % + US$33,9 al mes [21]; otra, 3,8 % [15] |
| **SINPE Móvil** | Sí, solo en colones: la persona transfiere al número de ONVO y se asocia por identificación y monto [2] | No [6] | Sí, figura como método soportado [12] | No figura entre sus métodos publicados (tarjetas Visa y Mastercard) [21][23] |
| **Recibir y retirar desde Costa Rica** | Liquida a cualquier banco local: diario, semanal, quincenal o mensual, por US$3 cada vez [3][4] | Sí. Retiro a banco costarricense: ₡625 en colones, o 1 % (mínimo US$10) en dólares; convertir a colones suma 3,5 % [7] | Liquida a cualquier cuenta local, con la frecuencia que elija el comercio [12]; US$2 por liquidación según fuentes secundarias [16] | Liquida a cuenta bancaria en 1 a 3 días hábiles; diaria, semanal, quincenal o mensual; US$2 por transferencia a otros bancos [17][18] |
| **Verificación de notificaciones** | Secreto estático en el encabezado `X-Webhook-Secret` [1] | Firma asimétrica con certificado; verificación delegable a PayPal [8][9] | Solo un `orderHash` cuyo algoritmo no es público: se entrega a quien lo pide a soporte [13] | La documentación pública revisada no describe cómo se autentica el origen [19] |

## 3. Costos: cien reservas de ₡15.000 al mes (₡1.500.000)

| Proveedor y método | Costo mensual | % del volumen |
|---|---|---|
| ONVO, SINPE Móvil | ₡26.500 | 1,77 % |
| Tilopay, SINPE Móvil | ₡48.500 | 3,23 % |
| ONVO, tarjeta | ₡80.000 | 5,33 % |
| Tilopay, tarjeta | ₡82.250 | 5,48 % |
| GreenPay, tarjeta | ₡89.950 | 6,00 % |
| PayPal, solo el cobro | ₡96.000 | 6,40 % |
| PayPal con retiro en dólares | ₡110.040 | 7,34 % |
| PayPal con retiro en colones | ₡145.765 | 9,72 % |

Cómo salieron los números:

- **ONVO:** (3,9 % × ₡15.000 + US$0,35) × 100 = ₡76.000, más la mensualidad (US$5) y una liquidación mensual (US$3). Por SINPE Móvil: 1,5 % × ₡1.500.000 = ₡22.500, más esos mismos costos fijos.
- **Tilopay:** (4,25 % × ₡15.000 + US$0,35) × 100, más una liquidación de US$2. Por SINPE Móvil, 2 % en vez de 4,25 %.
- **GreenPay:** 4,8 % × ₡1.500.000 = ₡72.000, más US$33,9 de cuota mensual y una liquidación de US$2.
- **PayPal:** el volumen son US$3.000, justo el tope del primer tramo: 5,40 % × US$3.000 = US$162, más 100 × US$0,30 = US$30, o sea US$192 (₡96.000). Si se retira todo en dólares son US$28,08 más; si se convierte a colones, 3,5 % (US$98,28) y ₡625 [7].

Dos advertencias. Las cifras de Tilopay y GreenPay son de fuentes secundarias y no oficiales. Sobre ONVO, un artículo de 2022 [22] decía 3,9 % + US$0,25 y sin mensualidad; mandamos su página oficial de hoy [3]. Lo que sí queda claro es que el SINPE Móvil abarata mucho el cobro, y que PayPal es la opción más cara en todos los escenarios.

## 4. Cómo verifica cada proveedor que una notificación es suya

Este es el punto más importante: si aceptáramos una notificación falsa, daríamos por pagada una reserva que nadie pagó.

- **PayPal** firma cada notificación con una clave asimétrica. Manda el id y la hora de la transmisión, la firma, el algoritmo y la dirección de su certificado X.509; quien recibe reconstruye el mensaje y valida la firma con la clave pública, o delega la comprobación en el servicio `verify-webhook-signature` [8][9]. Falsificarla exige la clave privada de PayPal.
- **ONVO** manda un secreto estático compartido, `X-Webhook-Secret`, que se compara con el configurado en el panel [1]. Si el secreto se filtra, cualquiera puede suplantarlo, y no protege el contenido del mensaje.
- **Tilopay** solo ofrece un `orderHash` y no publica su algoritmo, así que hay que pedirlo a su soporte. Su propia documentación aconseja no tomar decisiones irreversibles con el webhook y confirmar el estado contra la API; además, para compras envía el aviso una sola vez, sin reintentos [13].
- **GreenPay:** en la documentación pública que revisamos hay webhooks, pero no explica cómo comprobar su origen [19][23].

El más robusto es PayPal, porque su firma se verifica sin compartir un secreto con quien llama. En esta entrega no usamos webhooks: el estado siempre se le consulta al proveedor con nuestras credenciales, que es el mecanismo más seguro de todos. Cuando migremos a webhooks, con ONVO el aviso solo disparará una consulta a su API antes de confirmar la reserva, justo lo que Tilopay recomienda.

## 5. Qué integración fue más difícil y en qué se nos fue el tiempo

Las dos tuvieron el mismo primer obstáculo: PHP en Windows no traía certificados raíz, así que toda llamada HTTPS fallaba (error 60 de cURL) y la API respondía un 503, hasta que configuramos `curl.cainfo` y `openssl.cafile`.

- **ONVO.** Lo que más tiempo nos costó fue SINPE Móvil. El campo `identificationType` debe ser un entero y nosotros lo reenviábamos como texto: ONVO respondía 400 y nuestra API lo mostraba como error de comunicación (503), sin dejar nada en el registro. Lo encontramos repitiendo la llamada directo contra ONVO. Arreglamos el tipo, la clasificación de errores (un 4xx del proveedor a datos nuestros, como 400 o 422, es «datos inválidos» y responde 422; tiempo agotado, conexión caída o 5xx son 503; un 401 o 403 son nuestras credenciales y también responden un 503 genérico, registrado como `error`) y el registro del código y el cuerpo del error. Además, la documentación revisada no menciona un encabezado de idempotencia, así que lo resolvimos con un índice único: un solo pago activo por reserva.
- **PayPal.** Tiene más pasos y estados (crear la orden, aprobarla en su sitio, capturar) y la persona tiene que aprobar en el navegador. El colón no es una moneda estándar para recibir pagos: su documentación lo ofrece solo como moneda de presentación en un programa de acceso limitado [10], así que cobramos en dólares con un tipo de cambio configurable. Y descubrimos que nuestro propio diseño había filtrado un detalle de ONVO: el endpoint exigía el campo `token` para la tarjeta y con PayPal respondía 422. Lo corregimos haciendo que cada pasarela declare qué campos requiere.

Para nosotros, ONVO costó más tiempo de depuración; PayPal fue más complejo de entender.

## 6. Qué pasa con la reserva si el proveedor no responde

Cada llamada tiene tiempo de espera (10 s, y 5 s para conectar) y un cobro nunca se reintenta solo. Si el fallo ocurre antes de tener el identificador del cobro, el pago queda `failed` y la reserva sigue temporal para poder reintentar; si ocurre después, queda `pending` y la siguiente consulta de estado lo resuelve. Una reserva temporal vence a los 10 minutos y libera el espacio. Pulsar dos veces «pagar» no duplica el cobro (el índice único devuelve el pago existente) y PayPal recibe un `PayPal-Request-Id` estable [11]. El error del proveedor queda en el registro con su código HTTP y su cuerpo (recortado a 500 caracteres y con las llaves reemplazadas por `[redacted]`), el identificador del pago y el de la petición; nunca los encabezados ni lo que enviamos.

## 7. Recomendación

Para barberías costarricenses recomendamos **ONVO como pasarela principal y PayPal como respaldo**. Con los datos de arriba, ONVO es más barato que PayPal en cualquier escenario (₡26.500 a ₡80.000 contra ₡96.000 a ₡145.765 al mes), cobra en colones y es el único que admite SINPE Móvil con una tarifa oficial publicada, el método con el que paga buena parte de la clientela local. PayPal queda para clientes con tarjetas extranjeras y como alternativa si ONVO falla. Su debilidad es el secreto estático de los webhooks; por eso nuestro diseño confirma siempre consultando la API.

Cambiaríamos de proveedor si: (1) ONVO resulta tener una tarifa real distinta de la publicada, o retenciones adicionales (antes de producción hay que pedir la tabla por escrito); (2) necesitáramos webhooks con firma verificable, caso en que usaríamos PayPal, o Tilopay una vez que nos den su algoritmo de `orderHash` y una tarifa menor; (3) el volumen creciera y valiera la pena negociar la tarifa, como Tilopay admite hacerlo según su propio representante [14].

## Fuentes

1. ONVO, webhooks. https://docs.onvopay.com/en/webhooks.md
2. ONVO, SINPE Móvil. https://docs.onvopay.com/en/payments/sinpe-mobile
3. ONVO, precios (Costa Rica). https://onvopay.com/pricing
4. ONVO, afiliación y liquidación. https://onvopay.com/business-industry-services
5. ONVO, guía de inicio. https://docs.onvopay.com/en/getting-started.md
6. Enunciado de la Investigación aplicada 1, ISW-621, UTN, 2026, secciones 2 y 3.
7. PayPal, comisiones para comercios, mercado «CR» (el PDF declara «última actualización: 28 de octubre de 2026»). https://www.paypalobjects.com/marketing/ua/OA/merchant-fees/es-ROW-102826.pdf
8. PayPal, Verify webhook signature. https://docs.paypal.ai/reference/api/rest/verify-webhook-signature/verify-webhook-signature
9. Hookdeck, verificación de webhooks de PayPal (tercero). https://hookdeck.com/webhooks/platforms/how-to-secure-and-verify-paypal-webhooks-with-hookdeck
10. PayPal, monedas de presentación. https://developer.paypal.com/limited-release/presentment-currency/reference/presentment-currency-codes
11. PayPal, Orders v2. https://developer.paypal.com/sdk/orders/v2/orders-capture
12. Tilopay, tarifas. https://tilopay.com/tarifas
13. Tilopay, webhooks (verificada por ellos el 28/08/2026). https://tilopay.com/en/developers/api/webhooks
14. Revista Apetito, entrevista a Tilopay (tercero). https://apetitoenlinea.com/strongtilopay-sdatafono-virtual-para-reservaciones-de-hotel-strong/
15. El Colectivo 506, pasarelas en Costa Rica (tercero). https://elcolectivo506.com/pasarelas-de-pago-en-costa-rica-todo-lo-que-debo-saber/
16. Pymes Modernas, calculadora de Tilopay (tercero). https://pymesmodernas.com/comisiones-tilopay-costa-rica/
17. GreenPay, preguntas frecuentes. https://greenpay.me/preguntas-frecuentes/
18. GreenPay, términos y condiciones (mayo 2024). https://greenpay.me/wp-content/uploads/2024/05/Terminos-y-Condiciones-1.pdf
19. GreenPay, API de pagos V2. https://support.greenpay.me/portal/es/kb/articles/api-pagos-v2-10-12-2024
20. GreenPay, colección de la API (sandbox). https://documenter.getpostman.com/view/21265782/UzQyq3fo
21. El Periódico CR, cobrar en línea (tercero). https://elperiodicocr.com/transando-dinero-digital-en-costa-rica-como-empezar-a-cobrar-por-internet-a-sus-clientes/
22. El Financiero, lanzamiento de ONVO (2022, tercero). https://www.elfinancierocr.com/emprender/onvo-la-startup-que-lanza-un-multifuncional-motor/MLIU346YYRFLFPZTMOAQTQ3WJU/story/
23. GreenPay, plugin de WooCommerce. https://wordpress.org/plugins/greenpay-payment-service/
