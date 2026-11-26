-- Roles and database of PRODUCTION. It creates ONLY `barberhub` (no barberhub_testing) and runs once, when the data volume of the
-- PostgreSQL container is empty, through postgres/init/01-roles.sh. The passwords arrive as psql variables, never written here:
--   psql -v owner_password=... -v app_password=... -f roles.production.sql
-- database/roles.sql stays the script of development and CI.

-- The owner holds the tables and runs the migrations. The application serves requests and must never own tables, be a superuser
-- or bypass row-level security (ADR 0005 and 0006).
CREATE ROLE barberhub_owner LOGIN PASSWORD :'owner_password'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
CREATE ROLE barberhub_app LOGIN PASSWORD :'app_password'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;

CREATE DATABASE barberhub OWNER barberhub_owner;

REVOKE ALL ON DATABASE barberhub FROM PUBLIC;
GRANT CONNECT ON DATABASE barberhub TO barberhub_app;

-- The EXCLUDE constraints of the schema compare uuid and smallint columns with "=" (docs/database.md).
\connect barberhub
CREATE EXTENSION IF NOT EXISTS btree_gist;
