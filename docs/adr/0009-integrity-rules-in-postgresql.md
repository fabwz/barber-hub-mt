# ADR 0009: Las reglas de integridad también viven en PostgreSQL

Estado: aceptada.
Fecha: 16 de octubre de 2026.

## Contexto

El dominio valida cada dato, pero no todo llega por el dominio: un script, una importación o un error de programación pueden escribir directo en la base. Dos reglas no pueden depender del orden en que lleguen las solicitudes: que un colaborador no tenga dos reservas solapadas (RE-01) y que el saldo de un bono no sea negativo (BO-03).

## Decisión

PostgreSQL hace cumplir la integridad: restricciones `CHECK` para los rangos del negocio, `EXCLUDE` para los intervalos que no pueden traslaparse, llaves únicas y foráneas que incluyen la barbería, y el esquema en tercera forma normal. Las pruebas corren contra PostgreSQL. El dominio decide las reglas del negocio; la base garantiza integridad, aislamiento y consultas por conjuntos.

## Alternativas descartadas

- **Validar solo en el código.** Un camino que no pase por el dominio deja datos imposibles, y dos solicitudes simultáneas podrían duplicar un espacio.
- **Lógica de negocio en procedimientos almacenados.** Duplica los casos de uso y los saca de las pruebas unitarias.

## Consecuencias

- Los límites se declaran dos veces: como constante en el objeto de valor y como `CHECK` en la migración.
- Una violación de restricción se traduce en el repositorio a la excepción del dominio correspondiente.
- Las convenciones de la base están en [database.md](../database.md).
