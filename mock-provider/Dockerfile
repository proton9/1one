FROM php:8.3-cli
WORKDIR /app
COPY mock-provider/index.php ./
COPY mocks/AuthVerifier.php ./
EXPOSE 8001
CMD ["php", "-S", "0.0.0.0:8001", "index.php"]
