# Web client of BarberHub: builds the static files with Node and serves them with Caddy, which is also the reverse proxy and the
# TLS terminator of the deployment. The Caddyfile is mounted by docker-compose.yml. Build context: the root of the monorepo.

# Node version of barberhub-web/.nvmrc; deploy.sh passes the exact value, the default is only a fallback.
ARG NODE_VERSION=26

FROM node:${NODE_VERSION}-alpine AS build
WORKDIR /app

COPY barberhub-web/package.json barberhub-web/package-lock.json ./
RUN npm ci

COPY barberhub-web/ ./

# Public values only, fixed at build time (Vite writes them into the bundle). Never a secret: REVERB_APP_SECRET does not go here.
ARG VITE_ONVO_PUBLISHABLE_KEY=
ARG VITE_ONVO_API_URL=https://api.onvopay.com/v1
ARG VITE_REVERB_APP_KEY=
ARG VITE_REVERB_HOST=
ARG VITE_REVERB_PORT=443
ARG VITE_REVERB_SCHEME=https
ENV VITE_ONVO_PUBLISHABLE_KEY=$VITE_ONVO_PUBLISHABLE_KEY \
    VITE_ONVO_API_URL=$VITE_ONVO_API_URL \
    VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY \
    VITE_REVERB_HOST=$VITE_REVERB_HOST \
    VITE_REVERB_PORT=$VITE_REVERB_PORT \
    VITE_REVERB_SCHEME=$VITE_REVERB_SCHEME

RUN npm run build

FROM caddy:2-alpine
COPY --from=build /app/dist /srv/www
