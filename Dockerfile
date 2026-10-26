FROM php:8.2-cli-alpine

RUN apk add --no-cache postgresql-dev tzdata \
    && docker-php-ext-install pdo pdo_pgsql pgsql

WORKDIR /var/www/html
COPY . /var/www/html

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
