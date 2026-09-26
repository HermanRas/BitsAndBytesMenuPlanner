FROM php:8.3.21-cli-alpine3.20

# Install system dependencies
RUN apk update && apk upgrade && \
    apk add --no-cache sqlite sqlite-dev

RUN docker-php-ext-install pdo_sqlite

WORKDIR /app

# Bakes the app into the image so it's runnable standalone (docker run, no
# bind mount needed) -- e.g. for the published GHCR image. Local dev's
# docker-compose.yml bind-mounts ./src:/app on top of this at runtime, which
# takes precedence, so live-editing during development is unaffected.
COPY src/ /app/

CMD ["php", "-S", "0.0.0.0:8000", "-t", "/app", "/app/router.php"]
# Expose the port the app runs on
EXPOSE 8000
