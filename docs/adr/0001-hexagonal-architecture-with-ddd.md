# ADR 0001: Arquitectura hexagonal con diseño guiado por el dominio

Estado: aceptada.
Fecha: 16 de octubre de 2026.

## Contexto

BarberHub tiene reglas de negocio con mucho peso: disponibilidad de espacios, reservas sin duplicados, depósitos, bonos con saldo, lista de espera con ofertas y cobros confirmados por webhook. Esas reglas deben poder leerse, probarse y cambiarse sin arrastrar el framework ni la base de datos. La restricción RD-04 y el proyecto de referencia del docente piden esta arquitectura.

## Decisión

El código del producto vive en `src/` y se organiza en tres capas: `Domain`, `Application` e `Infrastructure`. Las dependencias apuntan hacia adentro. El dominio declara puertos (interfaces) y la infraestructura los implementa con adaptadores. El diseño guiado por el dominio es ligero: hay agregados solo donde hay invariantes (reserva, bono adquirido, oferta y pago); el CRUD simple no los necesita.

## Alternativas descartadas

- **MVC de Laravel con la lógica en modelos y controladores.** Es más rápido al inicio, pero las reglas quedan repartidas y solo se prueban con base de datos.
- **Capa de servicios sobre Eloquent.** Ordena los controladores, pero el dominio sigue siendo el modelo Eloquent.

## Consecuencias

- El dominio y los casos de uso se prueban sin arrancar Laravel.
- Hay más archivos y un mapeo explícito entre entidades y modelos.
- Una prueba de arquitectura (#30) hace cumplir la regla de dependencias en cada cambio.
