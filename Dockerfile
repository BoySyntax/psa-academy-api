FROM php:8.1-apache

# Install MySQL PDO driver
RUN docker-php-ext-install pdo_mysql

# Copy files
COPY . /var/www/html/

# Enable Apache rewrite
RUN a2enmod rewrite

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Expose port
EXPOSE 8080

# Start Apache
CMD apache2-foreground
