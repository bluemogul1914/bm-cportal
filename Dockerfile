FROM node:20-bookworm AS builder

WORKDIR /app

COPY package*.json ./
RUN npm ci

COPY . .
RUN npm run build

FROM node:20-bookworm-slim AS runner

RUN apt-get update && apt-get install -y \
    php \
    php-pgsql \
    php-curl \
    php-mbstring \
    php-xml \
    --no-install-recommends \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY package*.json ./
RUN npm ci --omit=dev

COPY --from=builder /app/dist ./dist

COPY . .

EXPOSE 3000
ENV PORT=3000
ENV NODE_ENV=production

CMD ["node", "dist/index.cjs"]
