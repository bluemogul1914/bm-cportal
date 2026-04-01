FROM node:20-alpine AS builder

RUN apk add --no-cache python3 make g++

WORKDIR /app

COPY package*.json ./
RUN npm ci

COPY . .
RUN npm run build

FROM node:20-alpine AS runner

RUN apk add --no-cache \
    php \
    php-pgsql \
    php-pdo \
    php-pdo_pgsql \
    php-curl \
    php-mbstring \
    php-dom \
    php-openssl \
    php-session \
    php-tokenizer \
    php-xml \
    php-simplexml

WORKDIR /app

COPY package*.json ./
RUN npm ci --omit=dev

COPY --from=builder /app/dist ./dist

COPY . .

EXPOSE 3000
ENV PORT=3000
ENV NODE_ENV=production

CMD ["node", "dist/index.cjs"]
