import { buildDocument, LEGAL_UPDATED, type RawDocument } from './legalText'

// Text of docs/legal/privacy-policy.md. The bracketed placeholders ([razón social],
// [correo de privacidad], …) are still pending on the business side and are kept as they are.
const raw: RawDocument = {
  title: 'Política de Privacidad y Tratamiento de Datos Personales de BarberHub',
  updated: LEGAL_UPDATED,
  version: '1.0',
  sections: [
    {
      heading: '1. Marco normativo',
      blocks: [
        {
          kind: 'p',
          text: 'La presente Política se emite en cumplimiento de la **Ley N.° 8968, Protección de la Persona frente al tratamiento de sus datos personales** (2011), su Reglamento (Decreto Ejecutivo N.° 37554-JP), y los artículos 24 y 41 de la Constitución Política de la República de Costa Rica, en lo atinente al derecho a la intimidad y a la autodeterminación informativa. La autoridad de control es la **Agencia de Protección de Datos de los Habitantes (PRODHAB)**.',
        },
        {
          kind: 'p',
          text: 'Esta Política forma parte integral de los [Términos y Condiciones](/terminos) de BarberHub.',
        },
      ],
    },
    {
      heading: '2. Roles en el tratamiento de datos',
      blocks: [
        {
          kind: 'table',
          head: ['Tipo de dato', 'Responsable', 'Encargado'],
          rows: [
            [
              'Datos de cuenta de las Barberías y su Personal (registro, facturación y uso de la Plataforma)',
              '**BarberHub**',
              'No aplica',
            ],
            [
              'Datos de la cuenta de los clientes finales en la Plataforma (registro e inicio de sesión)',
              '**BarberHub**',
              'No aplica',
            ],
            [
              'Datos de clientes finales y citas, cargados o generados dentro del espacio de cada Barbería',
              '**La Barbería**',
              '**BarberHub**',
            ],
          ],
        },
        {
          kind: 'p',
          text: 'Cuando BarberHub actúa como encargado, trata los datos exclusivamente conforme a las instrucciones de la Barbería y para la prestación del servicio.',
        },
        {
          kind: 'p',
          text: '**Responsable (BarberHub):** [razón social], cédula jurídica [número], [dirección]. Correo de privacidad: **[correo]**.',
        },
      ],
    },
    {
      heading: '3. Datos personales que tratamos',
      blocks: [
        {
          kind: 'ul',
          items: [
            '**Identificación y contacto:** nombre, apellidos, correo electrónico, número de teléfono, usuario y contraseña (almacenada de forma cifrada o mediante *hash*).',
            '**Datos de citas:** servicios solicitados, fechas, horas, barbero asignado, historial, notas del servicio y preferencias.',
            '**Datos de la Barbería y su Personal:** razón social, cédula, cargo, roles y permisos.',
            '**Datos de facturación:** información necesaria para cobros y comprobantes **[si aplica]**.',
            '**Datos de pago:** la información de las transacciones (monto, estado y referencia) de los depósitos pagados mediante pasarelas de pago. BarberHub **no almacena** números de tarjeta, fechas de vencimiento ni códigos de seguridad: se envían directamente a la pasarela.',
            '**Datos técnicos:** dirección IP, tipo de dispositivo y navegador, registros de acceso (*logs*) y el identificador de sesión.',
          ],
        },
        {
          kind: 'p',
          text: 'BarberHub **no solicita datos sensibles** (tales como salud, origen racial, ideología política, convicciones religiosas u orientación sexual), ni los requiere para la prestación del servicio. Si una Barbería registra voluntariamente notas que pudieran calificar como datos sensibles (por ejemplo, alergias o condiciones dermatológicas), deberá contar con el consentimiento expreso del titular, conforme a la Ley N.° 8968.',
        },
      ],
    },
    {
      heading: '4. Finalidades del tratamiento',
      blocks: [
        { kind: 'p', text: 'Los datos personales son tratados para las siguientes finalidades:' },
        {
          kind: 'ul',
          items: [
            'Crear y administrar cuentas y los espacios independientes (*tenants*) de cada Barbería.',
            'Gestionar agendas, reservas, recordatorios y confirmaciones de citas.',
            'Facilitar la comunicación entre la Barbería y sus clientes.',
            'Brindar soporte técnico y atender solicitudes.',
            'Facturar y cumplir obligaciones contables y tributarias.',
            'Garantizar la seguridad, prevenir fraudes y mantener la integridad de la Plataforma.',
            'Mejorar el servicio mediante análisis estadístico, preferentemente con datos anonimizados.',
            'Cumplir obligaciones legales o requerimientos de autoridad competente.',
            'Enviar comunicaciones comerciales **únicamente con consentimiento previo y con opción de baja**.',
          ],
        },
        {
          kind: 'p',
          text: 'BarberHub no utilizará los datos para finalidades incompatibles con las aquí descritas sin informar al titular y, de ser necesario, obtener un nuevo consentimiento.',
        },
      ],
    },
    {
      heading: '5. Consentimiento informado y base legítima',
      blocks: [
        {
          kind: 'p',
          text: 'De conformidad con el principio de consentimiento informado de la Ley N.° 8968, el tratamiento de datos se fundamenta en:',
        },
        {
          kind: 'p',
          text: 'a) El **consentimiento expreso** del titular, otorgado de forma libre, específica e informada (por ejemplo, al registrarse o agendar una cita, mediante casilla no premarcada).',
        },
        {
          kind: 'p',
          text: 'b) La **ejecución de la relación contractual** o la gestión de la cita solicitada.',
        },
        { kind: 'p', text: 'c) El **cumplimiento de obligaciones legales**.' },
        {
          kind: 'p',
          text: 'Al otorgar su consentimiento, el titular declara haber sido informado sobre: la existencia de la base de datos, las finalidades del tratamiento, los destinatarios de la información, el carácter obligatorio o facultativo de sus respuestas, las consecuencias de suministrar o negarse a suministrar los datos, la posibilidad de ejercer sus derechos, y la identidad y dirección del responsable. El consentimiento podrá revocarse en cualquier momento, sin efectos retroactivos.',
        },
      ],
    },
    {
      heading: '6. Destinatarios y transferencias',
      blocks: [
        { kind: 'p', text: '6.1. BarberHub **no vende** datos personales.' },
        { kind: 'p', text: '6.2. Los datos podrán ser compartidos con:' },
        {
          kind: 'ul',
          items: [
            '**La Barbería** con la que el usuario agenda o a la cual pertenece.',
            '**Proveedores de servicios** (alojamiento en la nube, correo electrónico, mensajería y pasarelas de pago: ONVO Pay y PayPal), que actúan como subencargados bajo obligaciones contractuales de confidencialidad y seguridad.',
            '**Autoridades** judiciales o administrativas, cuando exista requerimiento legal.',
          ],
        },
        {
          kind: 'p',
          text: '6.3. **Transferencias internacionales.** Algunos proveedores podrían alojar datos fuera de Costa Rica. Dichas transferencias se realizan con las salvaguardas exigidas por la Ley N.° 8968 y su Reglamento, incluyendo el consentimiento del titular cuando corresponda y garantías contractuales de protección equivalente. Proveedores actuales: **[listar, por ejemplo AWS, Google Cloud, y país de alojamiento]**.',
        },
      ],
    },
    {
      heading: '7. Plazo de conservación',
      blocks: [
        {
          kind: 'p',
          text: 'Los datos se conservarán mientras la cuenta permanezca activa y durante el plazo necesario para cumplir las finalidades descritas y las obligaciones legales aplicables (por ejemplo, contables y tributarias). Posteriormente, serán **eliminados o anonimizados**. Plazo general posterior a la terminación: **[30 a 90 días]**, salvo obligación legal de conservación.',
        },
      ],
    },
    {
      heading: '8. Derechos del titular (ARCO)',
      blocks: [
        { kind: 'p', text: 'Conforme a la Ley N.° 8968, el titular tiene derecho a:' },
        {
          kind: 'ul',
          items: [
            '**Acceso:** conocer qué datos suyos son tratados y cómo.',
            '**Rectificación:** corregir datos inexactos, incompletos o desactualizados.',
            '**Cancelación o supresión:** solicitar la eliminación de sus datos cuando proceda.',
            '**Oposición y revocatoria del consentimiento:** negarse al tratamiento o retirar su consentimiento.',
            '**Información:** ser informado sobre el tratamiento y sus finalidades.',
          ],
        },
        {
          kind: 'p',
          text: '**Procedimiento para ejercerlos.** El titular deberá remitir una solicitud a **[correo de privacidad]** que contenga: (i) identificación del solicitante; (ii) descripción clara del derecho que ejerce; y (iii) medio para recibir respuesta. BarberHub responderá dentro de los plazos legales aplicables. Cuando el dato sea tratado por una Barbería en calidad de responsable, la solicitud será redirigida o coordinada con ella.',
        },
        {
          kind: 'p',
          text: '**Reclamo ante la autoridad.** Si el titular considera vulnerados sus derechos, podrá acudir a la **PRODHAB** (www.prodhab.go.cr).',
        },
      ],
    },
    {
      heading: '9. Medidas de seguridad',
      blocks: [
        {
          kind: 'p',
          text: 'BarberHub aplica medidas técnicas, organizativas y administrativas razonables para proteger los datos contra pérdida, alteración, acceso no autorizado o tratamiento indebido, de conformidad con la Ley N.° 8968 y sus protocolos mínimos de actuación, entre ellas:',
        },
        {
          kind: 'ul',
          items: [
            'Cifrado en tránsito (TLS) y cifrado de contraseñas.',
            'Aislamiento lógico de datos entre *tenants* y control de acceso por roles.',
            'Respaldos periódicos y registros de auditoría.',
            'Principio de mínimo privilegio para el personal interno.',
            'Acuerdos de confidencialidad con empleados y proveedores.',
          ],
        },
        {
          kind: 'p',
          text: 'Ningún sistema es infalible. En caso de una vulneración de seguridad que afecte datos personales, BarberHub informará a los titulares y a las Barberías afectadas, y a la PRODHAB cuando corresponda, con la mayor brevedad posible.',
        },
      ],
    },
    {
      heading: '10. Deber de confidencialidad',
      blocks: [
        {
          kind: 'p',
          text: 'BarberHub y todas las personas que intervengan en el tratamiento de datos personales están sujetos al deber de confidencialidad, el cual subsistirá aun después de finalizada la relación.',
        },
      ],
    },
    {
      heading: '11. Cookies y tecnologías similares',
      blocks: [
        {
          kind: 'p',
          text: 'BarberHub no utiliza cookies de seguimiento, publicitarias ni analíticas. Para mantener la sesión iniciada, el navegador guarda un identificador en su almacenamiento temporal (`sessionStorage`), que se elimina al cerrar la pestaña. Las páginas de las pasarelas de pago pueden utilizar sus propias cookies, conforme a las políticas de cada proveedor.',
        },
      ],
    },
    {
      heading: '12. Menores de edad',
      blocks: [
        {
          kind: 'p',
          text: 'La Plataforma no está dirigida a menores de dieciocho (18) años para la creación de cuentas. Los datos de menores solo serán tratados cuando su padre, madre o representante legal agende el servicio y otorgue el consentimiento correspondiente, respetando en todo momento el interés superior de la persona menor de edad.',
        },
      ],
    },
    {
      heading: '13. Inscripción de bases de datos',
      blocks: [
        {
          kind: 'p',
          text: 'BarberHub **[ha inscrito / evaluará inscribir]** sus bases de datos ante la PRODHAB cuando así lo exijan la Ley N.° 8968 y su Reglamento. Las Barberías son responsables de evaluar sus propias obligaciones de inscripción respecto de las bases de datos de sus clientes.',
        },
      ],
    },
    {
      heading: '14. Obligaciones de las Barberías (Clientes Empresariales)',
      blocks: [
        {
          kind: 'p',
          text: 'Cada Barbería se compromete a: (i) informar a sus clientes sobre el tratamiento de sus datos y obtener su consentimiento cuando corresponda; (ii) tratar los datos únicamente para finalidades legítimas; (iii) atender los derechos ARCO de sus clientes; (iv) mantener la confidencialidad y seguridad de las credenciales y de los datos a los que acceda; y (v) notificar a BarberHub cualquier incidente de seguridad.',
        },
      ],
    },
    {
      heading: '15. Cambios a esta Política',
      blocks: [
        {
          kind: 'p',
          text: 'BarberHub podrá actualizar la presente Política. Los cambios relevantes serán notificados por correo electrónico o dentro de la Plataforma. La versión vigente estará siempre disponible en **[URL]**.',
        },
      ],
    },
    {
      heading: '16. Contacto',
      blocks: [
        {
          kind: 'p',
          text: 'Para consultas o para el ejercicio de derechos: **[correo de privacidad]** · **[teléfono]** · **[dirección]**.',
        },
      ],
    },
  ],
}

export const privacyDocument = buildDocument(raw)
