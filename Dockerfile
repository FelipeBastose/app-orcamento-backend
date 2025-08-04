FROM php:8.1-fpm

# Instalar dependências do sistema
RUN apt-get update && apt-get install -y \
    build-essential \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    locales \
    zip \
    jpegoptim optipng pngquant gifsicle \
    vim \
    unzip \
    git \
    curl \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    default-mysql-client

# Limpar cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Instalar extensões PHP
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Criar diretório da aplicação
WORKDIR /var/www/html

# Criar usuário www-data se não existir e configurar permissões
RUN groupadd -g 1000 appgroup || true \
    && useradd -u 1000 -ms /bin/bash -g appgroup appuser || true

# Expor porta 8000
EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]