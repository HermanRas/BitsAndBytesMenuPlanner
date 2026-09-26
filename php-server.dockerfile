FROM php:8.3.21-cli-alpine3.20

# Install system dependencies
RUN apk update && apk upgrade && \
    apk add --no-cache sqlite sqlite-dev

RUN docker-php-ext-install pdo_sqlite

RUN mkdir /app
WORKDIR /app

CMD ["php", "-S", "0.0.0.0:8000", "-t", "/app", "/app/router.php"]
# Expose the port the app runs on
EXPOSE 8000
