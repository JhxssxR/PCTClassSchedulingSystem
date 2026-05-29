FROM php:8.2-cli

WORKDIR /var/www/html

# Install MySQL extension and other dependencies
RUN apt-get update && apt-get install -y \
    php-mysql \
    php-pdo \
    php-pdo-mysql \
    && docker-php-ext-install pdo pdo_mysql

# Copy application files
COPY . .

# Create logs directory
RUN mkdir -p logs && chmod -R 755 logs

# Expose port 3000
EXPOSE 3000

# Start PHP built-in server
CMD ["php", "-S", "0.0.0.0:3000"]
