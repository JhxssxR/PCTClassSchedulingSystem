FROM php:8.2-cli

WORKDIR /var/www/html

# Update and install dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql

# Copy application files
COPY . .

# Create logs directory with proper permissions
RUN mkdir -p logs && chmod -R 777 logs

# Set environment variables for PHP
ENV PHP_IDE_CONFIG="serverName=localhost"

# Expose port 3000 (Render default)
EXPOSE 3000

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD php -r "fsockopen('localhost', 3000) || exit(1);" || exit 1

# Start PHP built-in server on all interfaces
CMD ["php", "-S", "0.0.0.0:3000", "-t", "/var/www/html"]
