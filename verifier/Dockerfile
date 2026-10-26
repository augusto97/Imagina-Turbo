# Verificador de Imagina Turbo: Node 22 + Chromium de Playwright (también lo usa Lighthouse).
FROM node:22-bookworm-slim

ENV NODE_ENV=production \
    PLAYWRIGHT_BROWSERS_PATH=/ms-playwright \
    VERIFIER_DATA=/data \
    PORT=8787 \
    TZ=America/Bogota

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev \
    && npx playwright install --with-deps chromium \
    && apt-get update && apt-get install -y --no-install-recommends tzdata \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /data && chown node:node /data
COPY src ./src

USER node
EXPOSE 8787
VOLUME /data
CMD ["node", "src/server.js"]
