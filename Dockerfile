FROM php:8.2-apache

# Yahan paste karo
ENV BOT_TOKEN=8315381064:AAGk0FGVGmB8j5SjpBvW3rD3_kQHe_hyOWU

RUN docker-php-ext-install mysqli

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html


# System dependencies install karo
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    && docker-php-ext-install zip

# Composer install karo
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Apache configuration
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf
RUN a2enmod rewrite headers

# Document root set karo
ENV APACHE_DOCUMENT_ROOT /var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Working directory set karo
WORKDIR /var/www/html

# Copy application files
COPY . .

# File permissions set karo (FIXED)
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && touch movies.csv users.json error.log \
    && chmod 666 movies.csv users.json error.log

# Port expose karo
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

# File permissions set karo
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && touch movies.csv users.json error.log \
    && chmod 666 movies.csv users.json error.log

# Port expose karo
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1
