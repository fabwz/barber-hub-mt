import { buildDocument, LEGAL_UPDATED, type RawDocument } from './legalText'

// Text of docs/legal/terms-and-conditions.md. The bracketed placeholders ([razón social],
// [Por definir: …]) are still pending on the business side and are kept as they are.
const raw: RawDocument = {
  title: 'Términos y Condiciones de Uso de BarberHub',
  updated: LEGAL_UPDATED,
  version: '1.0',
  sections: [
    {
      heading: '1. Identificación y aceptación',
      blocks: [
        {
          kind: 'p',
          text: '1.1. La plataforma **BarberHub** (en adelante, la "Plataforma") es un servicio de software como servicio (SaaS) de arquitectura multi-tenant, destinado a la gestión de barberías, operado por **[razón social]**, cédula jurídica **[número]**, con domicilio en **[dirección], Costa Rica**, y correo electrónico de contacto **[correo]** (en adelante, "BarberHub", "nosotros" o "nuestro").',
        },
        {
          kind: 'p',
          text: '1.2. Al registrarse, acceder o utilizar la Plataforma, usted manifiesta haber leído, comprendido y aceptado los presentes Términos y Condiciones, así como la [Política de Privacidad](/privacidad). Si no está de acuerdo con su contenido, deberá abstenerse de utilizar la Plataforma.',
        },
        {
          kind: 'p',
          text: '1.3. Para crear una cuenta se requiere ser mayor de dieciocho (18) años. Las personas menores de edad podrán recibir servicios de una barbería únicamente cuando su representante legal gestione la cita y otorgue el consentimiento correspondiente.',
        },
      ],
    },
    {
      heading: '2. Definiciones',
      blocks: [
        {
          kind: 'ul',
          items: [
            '**Barbería o Cliente Empresarial:** persona física o jurídica que contrata los servicios de BarberHub y administra un espacio independiente (*tenant*) dentro de la Plataforma.',
            '**Usuario Final:** persona que se registra, o es registrada, para agendar citas con una Barbería.',
            '**Personal de la Barbería:** administradores, barberos y colaboradores autorizados por la Barbería para acceder a la Plataforma.',
            '**Contenido:** datos, textos, imágenes, registros de clientes, citas y cualquier otra información cargada o generada en la Plataforma.',
          ],
        },
      ],
    },
    {
      heading: '3. Naturaleza del servicio',
      blocks: [
        {
          kind: 'p',
          text: '3.1. BarberHub proporciona una herramienta tecnológica para la gestión de agendas, citas, clientes, servicios y comunicaciones. **BarberHub no presta servicios de barbería ni forma parte de la relación entre la Barbería y el Usuario Final.**',
        },
        {
          kind: 'p',
          text: '3.2. Cada Barbería opera en un entorno lógicamente aislado. BarberHub implementa medidas razonables para impedir que una Barbería acceda a los datos de otra.',
        },
        {
          kind: 'p',
          text: '3.3. BarberHub podrá modificar, mejorar, suspender o descontinuar funcionalidades de la Plataforma, comunicándolo con una antelación razonable cuando el cambio sea sustancial.',
        },
      ],
    },
    {
      heading: '4. Cuentas y seguridad',
      blocks: [
        {
          kind: 'p',
          text: '4.1. El usuario se obliga a suministrar información veraz, completa y actualizada.',
        },
        {
          kind: 'p',
          text: '4.2. El usuario es responsable de la custodia de sus credenciales de acceso y de toda actividad realizada desde su cuenta. Deberá notificar de inmediato a BarberHub cualquier uso no autorizado.',
        },
        {
          kind: 'p',
          text: '4.3. La Barbería es responsable de administrar los permisos y accesos de su Personal, y de dar de baja oportunamente a quienes ya no deban contar con acceso.',
        },
      ],
    },
    {
      heading: '5. Planes, precios y pagos',
      blocks: [
        {
          kind: 'p',
          text: '5.1. **[Por definir: modelo de planes y precios de la Plataforma, ciclos de facturación y condiciones.]**',
        },
        {
          kind: 'p',
          text: '5.2. Los precios se expresan en **[colones / dólares]** e incluyen o excluyen el Impuesto al Valor Agregado (IVA), según corresponda conforme a la legislación tributaria costarricense.',
        },
        {
          kind: 'p',
          text: '5.3. La falta de pago podrá dar lugar a la suspensión del servicio, previo aviso. Salvo disposición legal en contrario o falla imputable a BarberHub, los pagos realizados no serán reembolsables.',
        },
        {
          kind: 'p',
          text: '5.4. **Pagos de reservas.** Cuando una Barbería lo configure, la Plataforma permite cobrar al Usuario Final un depósito o el total del servicio al reservar, mediante pasarelas de pago de terceros (ONVO Pay y PayPal). La reserva queda pendiente de pago durante diez (10) minutos; si el pago no se aprueba en ese plazo, la reserva expira y el espacio se libera. BarberHub no almacena los datos de la tarjeta, que se envían directamente a la pasarela. Los pagos con PayPal se procesan en dólares estadounidenses, al tipo de cambio que la Plataforma tenga configurado. **[Por definir: quién recibe el pago y la política de cancelación y reembolsos.]**',
        },
      ],
    },
    {
      heading: '6. Uso aceptable',
      blocks: [
        { kind: 'p', text: 'El usuario se compromete a **no**:' },
        {
          kind: 'p',
          text: 'a) Utilizar la Plataforma para fines ilícitos, fraudulentos o contrarios a la moral y al orden público.',
        },
        {
          kind: 'p',
          text: 'b) Cargar datos personales de terceros sin contar con la base legítima o el consentimiento informado exigidos por la Ley N.° 8968, Protección de la Persona frente al tratamiento de sus datos personales.',
        },
        {
          kind: 'p',
          text: 'c) Intentar acceder sin autorización a cuentas, *tenants*, sistemas o datos ajenos.',
        },
        {
          kind: 'p',
          text: 'd) Introducir virus, código malicioso o ejecutar acciones que afecten la disponibilidad, integridad o seguridad de la Plataforma.',
        },
        {
          kind: 'p',
          text: 'e) Realizar ingeniería inversa, descompilar, copiar o revender la Plataforma, salvo autorización expresa y por escrito.',
        },
        {
          kind: 'p',
          text: 'f) Utilizar bots, *scrapers* u otros medios automatizados no autorizados.',
        },
        {
          kind: 'p',
          text: 'g) Enviar comunicaciones no solicitadas (*spam*) o difundir contenido ofensivo, difamatorio, discriminatorio o que vulnere derechos de terceros.',
        },
        { kind: 'p', text: 'h) Suplantar la identidad de otra persona o entidad.' },
        { kind: 'p', text: 'i) Eludir los límites técnicos o del plan contratado.' },
        {
          kind: 'p',
          text: 'El incumplimiento de estas obligaciones faculta a BarberHub para advertir, suspender o cancelar la cuenta, sin perjuicio de las acciones legales que correspondan.',
        },
      ],
    },
    {
      heading: '7. Propiedad intelectual',
      blocks: [
        {
          kind: 'p',
          text: '7.1. **Titularidad de BarberHub.** La Plataforma, su código fuente, diseño, interfaces, estructura de bases de datos, marcas, logotipos, nombres comerciales y documentación son propiedad de BarberHub o de sus licenciantes, y se encuentran protegidos por la Ley N.° 6683 de Derechos de Autor y Derechos Conexos, la Ley N.° 7978 de Marcas y Otros Signos Distintivos, y demás normativa aplicable.',
        },
        {
          kind: 'p',
          text: '7.2. **Licencia de uso.** BarberHub otorga a la Barbería y a sus usuarios autorizados una licencia limitada, revocable, no exclusiva, intransferible y no sublicenciable para utilizar la Plataforma conforme a los presentes Términos durante la vigencia del servicio.',
        },
        {
          kind: 'p',
          text: '7.3. **Contenido del usuario.** El usuario conserva la titularidad sobre el Contenido que cargue en la Plataforma. Con ello, otorga a BarberHub una licencia limitada, no exclusiva y de alcance mundial, exclusivamente para alojar, procesar, respaldar y mostrar dicho Contenido con el fin de prestar el servicio.',
        },
        {
          kind: 'p',
          text: '7.4. **Signos distintivos de la Barbería.** La Barbería autoriza a BarberHub a mostrar su nombre, logotipo y banner dentro de la Plataforma y en su página de reservas. BarberHub no los utilizará con fines publicitarios sin autorización adicional.',
        },
        {
          kind: 'p',
          text: '7.5. **Retroalimentación.** Las sugerencias o comentarios que el usuario remita a BarberHub podrán ser utilizados sin obligación de compensación.',
        },
      ],
    },
    {
      heading: '8. Protección de datos personales',
      blocks: [
        {
          kind: 'p',
          text: 'El tratamiento de datos personales se rige por la [Política de Privacidad](/privacidad), la cual forma parte integral de estos Términos y se fundamenta en la **Ley N.° 8968, Protección de la Persona frente al tratamiento de sus datos personales**, y su Reglamento (Decreto Ejecutivo N.° 37554-JP).',
        },
        {
          kind: 'p',
          text: '8.1. Respecto de los datos de sus propios clientes cargados en la Plataforma, la **Barbería actúa como responsable** del tratamiento y BarberHub como **encargado**, tratando los datos por cuenta de aquella y conforme a sus instrucciones.',
        },
        {
          kind: 'p',
          text: '8.2. La Barbería garantiza que cuenta con el consentimiento informado u otra base legítima para tratar y cargar los datos personales de sus clientes, y que ha cumplido con el deber de información previsto en la Ley N.° 8968.',
        },
      ],
    },
    {
      heading: '9. Disponibilidad y soporte',
      blocks: [
        {
          kind: 'p',
          text: '9.1. BarberHub procura mantener la Plataforma disponible de forma continua; sin embargo, no garantiza un servicio ininterrumpido ni libre de errores. Podrán presentarse interrupciones por mantenimiento, fallas de terceros (proveedores de infraestructura en la nube o telecomunicaciones) o causas de fuerza mayor.',
        },
        {
          kind: 'p',
          text: '9.2. El soporte técnico se brinda a través de **[canales y horario]**.',
        },
      ],
    },
    {
      heading: '10. Limitación de responsabilidad',
      blocks: [
        {
          kind: 'p',
          text: '10.1. En la máxima medida permitida por la ley, la Plataforma se ofrece "tal cual" y "según disponibilidad".',
        },
        { kind: 'p', text: '10.2. BarberHub **no será responsable** por:' },
        {
          kind: 'p',
          text: 'a) La calidad, ejecución o resultado de los servicios prestados por las Barberías.',
        },
        {
          kind: 'p',
          text: 'b) Citas incumplidas, cancelaciones, conflictos o reclamos entre la Barbería y sus clientes.',
        },
        {
          kind: 'p',
          text: 'c) Pérdida de ingresos, lucro cesante, daño a la reputación o daños indirectos, incidentales o consecuenciales.',
        },
        { kind: 'p', text: 'd) Datos incorrectos o incompletos suministrados por los usuarios.' },
        {
          kind: 'p',
          text: 'e) Accesos no autorizados derivados del uso indebido o de la deficiente custodia de credenciales por parte del usuario.',
        },
        {
          kind: 'p',
          text: 'f) Hechos ajenos a su control razonable, tales como caso fortuito, fuerza mayor, ataques de terceros pese a la adopción de medidas razonables de seguridad, o fallas de proveedores o de la red de internet.',
        },
        {
          kind: 'p',
          text: 'g) Rechazos, demoras o fallas de las pasarelas de pago de terceros.',
        },
        {
          kind: 'p',
          text: '10.3. En cualquier caso, la responsabilidad total acumulada de BarberHub frente a un Cliente Empresarial se limitará al monto efectivamente pagado por este en los **[3 / 6 / 12]** meses anteriores al hecho que originó el reclamo.',
        },
        {
          kind: 'p',
          text: '10.4. Las limitaciones anteriores no serán aplicables en caso de dolo o culpa grave, ni respecto de aquello que la legislación costarricense, incluida la Ley N.° 7472 de Promoción de la Competencia y Defensa Efectiva del Consumidor, declare irrenunciable.',
        },
      ],
    },
    {
      heading: '11. Indemnización',
      blocks: [
        {
          kind: 'p',
          text: 'La Barbería se obliga a mantener indemne a BarberHub frente a reclamos de terceros derivados de: (i) el incumplimiento de estos Términos; (ii) el uso indebido de la Plataforma; (iii) el tratamiento de datos personales sin base legítima por parte de la Barbería; o (iv) la infracción de derechos de terceros mediante el Contenido cargado.',
        },
      ],
    },
    {
      heading: '12. Vigencia, suspensión y terminación',
      blocks: [
        {
          kind: 'p',
          text: '12.1. Los presentes Términos rigen mientras el usuario utilice la Plataforma.',
        },
        {
          kind: 'p',
          text: '12.2. El usuario podrá cancelar su cuenta en cualquier momento a través de **[configuración / correo]**.',
        },
        {
          kind: 'p',
          text: '12.3. BarberHub podrá suspender o terminar el acceso por incumplimiento de estos Términos, falta de pago, riesgo para la seguridad o requerimiento legal.',
        },
        {
          kind: 'p',
          text: '12.4. Tras la terminación, la Barbería podrá exportar sus datos durante **[30]** días. Vencido dicho plazo, los datos serán eliminados o anonimizados, salvo que deban conservarse por obligación legal.',
        },
      ],
    },
    {
      heading: '13. Modificaciones',
      blocks: [
        {
          kind: 'p',
          text: 'BarberHub podrá actualizar los presentes Términos. Los cambios sustanciales serán notificados por correo electrónico o dentro de la Plataforma con al menos **[15]** días de antelación. El uso continuado de la Plataforma con posterioridad a dicha notificación implicará la aceptación de los cambios.',
        },
      ],
    },
    {
      heading: '14. Comunicaciones y firma electrónica',
      blocks: [
        {
          kind: 'p',
          text: 'El usuario acepta recibir comunicaciones electrónicas relacionadas con el servicio. La aceptación electrónica de estos Términos tendrá plena validez conforme a la Ley N.° 8454 de Certificados, Firmas Digitales y Documentos Electrónicos.',
        },
      ],
    },
    {
      heading: '15. Disposiciones generales',
      blocks: [
        {
          kind: 'p',
          text: '15.1. **Ley aplicable.** Los presentes Términos se rigen por las leyes de la República de Costa Rica.',
        },
        {
          kind: 'p',
          text: '15.2. **Jurisdicción.** Salvo normas imperativas de protección al consumidor, las partes se someten a los tribunales competentes de **[San José]**, Costa Rica.',
        },
        {
          kind: 'p',
          text: '15.3. **Divisibilidad.** Si alguna cláusula fuera declarada nula o inaplicable, las demás conservarán su plena vigencia.',
        },
        {
          kind: 'p',
          text: '15.4. **Cesión.** BarberHub podrá ceder los presentes Términos en caso de fusión, adquisición o reorganización societaria. La Barbería no podrá cederlos sin autorización previa y por escrito.',
        },
        {
          kind: 'p',
          text: '15.5. **Acuerdo íntegro.** Estos Términos, junto con la [Política de Privacidad](/privacidad), constituyen el acuerdo completo entre las partes respecto del uso de la Plataforma.',
        },
        { kind: 'p', text: '**Contacto:** [correo] · [teléfono] · [dirección]' },
      ],
    },
  ],
}

export const termsDocument = buildDocument(raw)
