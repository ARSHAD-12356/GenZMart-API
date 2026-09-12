# Production Dockerfile for GenZMart-API on Render using PHP 8.2 + Apache
FROM php:8.2-apache

# Install required system packages and PHP extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo pdo_mysql mysqli gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache modules required for .htaccess rewrite and headers
RUN a2enmod rewrite headers

# Configure Apache to allow .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Default port configuration for compatibility (Render injects PORT dynamically)
ENV PORT=80

# Configure Apache to bind to $PORT environment variable
RUN sed -i 's/80/${PORT}/g' /etc/apache2/ports.conf /etc/apache2/sites-available/*.conf

# Set working directory
WORKDIR /var/www/html

# Copy project files into container
COPY . /var/www/html/

# Create uploads directory and set permissions for Apache (www-data)
RUN mkdir -p /var/www/html/uploads && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 775 /var/www/html/uploads

# Expose container port
EXPOSE 80

# Run Apache in foreground
CMD ["apache2-foreground"]
