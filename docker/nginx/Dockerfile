# syntax=docker/dockerfile:1
# Web: Nginx + собранная статика React (multi-stage, ТЗ §12.1).
# Node.js нужен только на этапе сборки; отдельный production-контейнер Node не требуется.
FROM node:22.22-bookworm-slim AS frontend-build

WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY frontend/ ./
RUN npm run build

FROM nginx:1.28.3

COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# Только публичная часть: собранные ассеты и точку входа PHP.
# Приватные исходники приложения в этот образ не попадают (ТЗ §12.1).
COPY --from=frontend-build /app/dist/ /var/www/html/public/
COPY backend/public/index.php /var/www/html/public/index.php
COPY backend/public/robots.txt /var/www/html/public/robots.txt
