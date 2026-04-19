FROM php:8.3-cli
WORKDIR /app
COPY mock-merchant/index.php ./
COPY mocks/AuthVerifier.php ./
EXPOSE 8002
CMD ["php", "-S", "0.0.0.0:8002", "index.php"]
