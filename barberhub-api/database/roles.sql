-- Run once per server as a superuser (the passwords never live in this file):
--   psql -U postgres -v "owner_password=..." -v "app_password=..." -f database/roles.sql

-- The owner holds the tables and runs the migrations. The application serves
-- requests and must never own tables, so that row-level security applies to it.
CREATE ROLE barberhub_owner LOGIN PASSWORD :'owner_password'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
CREATE ROLE barberhub_app LOGIN PASSWORD :'app_password'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;

CREATE DATABASE barberhub OWNER barberhub_owner;
CREATE DATABASE barberhub_testing OWNER barberhub_owner;

REVOKE ALL ON DATABASE barberhub, barberhub_testing FROM PUBLIC;
GRANT CONNECT ON DATABASE barberhub, barberhub_testing TO barberhub_app;
