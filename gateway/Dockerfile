FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    libicu-dev libzip-dev unzip git curl \
    && docker-php-ext-install pdo pdo_mysql intl zip bcmath \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY gateway/composer.json ./
RUN composer install --optimize-autoloader --no-scripts

COPY gateway .

# The shared test mocks live at repo-root /mocks. The gateway unit test
# tests/Unit/Mocks/AuthVerifierTest.php require_once's ../../../../mocks/AuthVerifier.php
# which from /app/tests/Unit/Mocks/ resolves to /mocks/AuthVerifier.php.
COPY mocks /mocks

RUN composer run-script post-install-cmd || true

# Bundle Redoc into the image (no CDN at runtime). Pinned for reproducibility.
ARG REDOC_VERSION=2.5.1
RUN mkdir -p public/vendor/redoc \
 && curl -fsSL "https://cdn.jsdelivr.net/npm/redoc@${REDOC_VERSION}/bundles/redoc.standalone.js" \
      -o public/vendor/redoc/redoc.standalone.js

EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
