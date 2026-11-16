# ADR 0011: El directorio de barberías es opcional y el enlace sigue siendo la puerta de entrada

Estado: aceptada.
Fecha: 16 de noviembre de 2026.

## Contexto

Un cliente llegaba a una barbería solo por su enlace público (MT-07), y el directorio estaba excluido del alcance. Se quiere que el cliente pueda descubrir barberías, pero no todo dueño desea aparecer, y el directorio es una lectura que cruza barberías, algo que la regla de aislamiento (MT-02, RD-18) no permite en las rutas con `{barberia}`.

## Decisión

El directorio es una lista opt-in: `GET /api/v1/directorio`, sin sesión, devuelve solo las barberías cuyo dueño activó `aparece_en_directorio` (`barbershops.listed_in_directory`, `false` al nacer; RF-54, MT-10). Solo el dueño cambia el indicador, con las demás funciones, y el cambio queda en el registro técnico. La lectura expone únicamente nombre, ciudad, logo y enlace (MT-09). El enlace público sigue siendo la puerta de entrada: una barbería que no aparece sigue accesible por `GET /api/v1/enlaces/{enlace}` (MT-07).

La lectura entre barberías no necesita una función de base de datos nueva: `barbershops` no lleva `barbershop_id` ni política de fila (cada fila es el inquilino), así que el directorio la lee igual que la resolución del enlace, sin fijar barbería. Ninguna otra tabla se toca, así que no puede salir un dato operativo de otra barbería.

## Alternativas descartadas

- **Directorio con todas las barberías.** Expone barberías que no lo pidieron y deja sin sentido el enlace como puerta de entrada.
- **Reemplazar el enlace público por el directorio.** Rompe los enlaces ya compartidos y la regla MT-07.
- **Una función de base de datos que salte la seguridad a nivel de fila.** Innecesaria: `barbershops` no tiene esa política, y la función abriría un camino más a datos de otras barberías.

## Consecuencias

- Una barbería nueva no aparece hasta que su dueño lo activa.
- El directorio es una excepción de lectura pública a RD-18, igual que la resolución del enlace, y solo devuelve datos públicos.
- Pausar las reservas de una barbería queda fuera del alcance (exclusión 15 de `requirements.md`).
