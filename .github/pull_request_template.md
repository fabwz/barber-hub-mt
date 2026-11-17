Closes #

## Qué incluye

<!-- Qué cambia y por qué, en pocas líneas. Divergencias de la referencia y decisiones, si las hay. -->

## Criterios de aceptación

<!-- Copie los criterios del issue y marque los que se cumplen. -->

- [ ]

## Verificación

- [ ] Rama creada desde el issue y commits convencionales, en inglés, con el pie `Refs: #n`.
- [ ] API (`barberhub-api`): `composer lint`, `composer analyse` y `php artisan test` en verde (o no aplica).
- [ ] Web (`barberhub-web`): `npm run lint`, `npm run format:check`, `npm run type-check`, `npm run test` y `npm run build` en verde (o no aplica).
- [ ] Si cambió la API, `openapi/v1.json` está regenerado con `composer openapi` y los tipos del cliente con `npm run types`.
- [ ] Si cambió el esquema, la migración es reversible y `docs/data-model.md` está al día.
- [ ] Recurso nuevo: tiene su caso en la suite de aislamiento (`tests/Feature/Tenancy`) y en la matriz de permisos.
- [ ] Sin secretos ni datos reales; los textos al usuario salen de `lang/es`.
- [ ] La CI está en verde.
