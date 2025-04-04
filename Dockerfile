# Usa la imagen oficial de PHP 8.2 para arquitectura ARM64
FROM php:8.0-fpm

# Instala dependencias necesarias
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libpq-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libwebp-dev \
    libzip-dev \
    cron

# Install PDO extensions
RUN docker-php-ext-install pdo_mysql pdo_pgsql


# Instala la extensión GD
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd

# Instala la extensión zip
RUN docker-php-ext-install zip

# Instala Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Configura el directorio de trabajo
WORKDIR /var/www

# Copia los archivos del proyecto al contenedor
COPY . .

# Instala las dependencias de Composer
RUN composer install

# Añade la configuración del cron
COPY crontab /etc/cron.d/laravel-cron
RUN chmod 0644 /etc/cron.d/laravel-cron
RUN crontab /etc/cron.d/laravel-cron


# Expone el puerto .env PHP_LOCAL_PORT
EXPOSE $PHP_LOCAL_PORT

CMD sh -c "php artisan serve --host=0.0.0.0 --port=$PHP_LOCAL_PORT & cron -f"


