FROM composer:2-alpine AS builder

WORKDIR /app
COPY composer.json ./
RUN composer dump-autoload --optimize --no-dev --no-interaction

FROM php:8.2-cli-alpine

RUN apk add --no-cache postgresql-dev tzdata curl \
    && cp /usr/share/zoneinfo/Asia/Ho_Chi_Minh /etc/localtime \
    && echo "Asia/Ho_Chi_Minh" > /etc/timezone \
    && docker-php-ext-install pdo pdo_pgsql pgsql

WORKDIR /var/www/html
COPY . /var/www/html
COPY --from=builder /app/vendor /var/www/html/vendor

RUN sed -i 's/\r$//' /var/www/html/docker/cloud-entrypoint.sh \
    && chmod +x /var/www/html/docker/cloud-entrypoint.sh

EXPOSE 8080
CMD ["sh", "/var/www/html/docker/cloud-entrypoint.sh"]
