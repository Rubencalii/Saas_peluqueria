# Imagen de PRODUCCIÓN del frontend (Next.js standalone).
# Build:  docker build -t peluqueria-frontend ./frontend
# API_BASE (build-arg y env) es la URL interna del backend para SSR/rewrites.

# ---- Etapa 1: dependencias ---------------------------------------------------
FROM node:22-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci

# ---- Etapa 2: build ----------------------------------------------------------
FROM node:22-alpine AS build
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY . .
ARG API_BASE=http://backend
ENV API_BASE=$API_BASE NEXT_OUTPUT=standalone NEXT_TELEMETRY_DISABLED=1
# public/ está vacío en el repo (git no guarda carpetas vacías): asegúrala.
RUN mkdir -p public && npm run build

# ---- Etapa 3: runtime --------------------------------------------------------
FROM node:22-alpine
WORKDIR /app
ENV NODE_ENV=production NEXT_TELEMETRY_DISABLED=1 PORT=3000 HOSTNAME=0.0.0.0
COPY --from=build --chown=node:node /app/.next/standalone ./
COPY --from=build --chown=node:node /app/.next/static ./.next/static
COPY --from=build --chown=node:node /app/public ./public
USER node
EXPOSE 3000
CMD ["node", "server.js"]
