FROM php:8.1-apache

WORKDIR /var/www/html

# Copy all application files
COPY . .

# Enable Apache modules
RUN a2enmod rewrite

# Install system dependencies for PostgreSQL
RUN apt-get update && apt-get install -y libpq-dev && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql pdo_pgsql

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html

# Expose port 80
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
