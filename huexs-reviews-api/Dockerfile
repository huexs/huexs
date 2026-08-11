# Imagen de producción para easypanel / cualquier host con Docker.

FROM node:22-slim AS base

# better-sqlite3 se compila en la instalación: hacen falta herramientas de build.
RUN apt-get update \
  && apt-get install -y --no-install-recommends python3 make g++ \
  && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY package.json ./
RUN npm install --omit=dev

COPY src ./src
COPY scripts ./scripts

# El volumen de datos guarda licencias y caché: debe persistir entre despliegues.
RUN mkdir -p /data && chown -R node:node /data /app
VOLUME ["/data"]

ENV NODE_ENV=production \
    PORT=8080 \
    DATABASE_PATH=/data/huexs-reviews.sqlite

USER node
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD node -e "fetch('http://127.0.0.1:8080/health').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

CMD ["node", "src/server.js"]
