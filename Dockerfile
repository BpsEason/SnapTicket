FROM php:8.2-fpm-alpine

# 安裝系統依賴
RUN apk add --no-cache \
    nginx \
    mysql-client \
    curl \
    git \
    supervisor \
    libzip-dev \
    libpng-dev \
    jpeg-dev \
    libwebp-dev \
    libxml2-dev \
    icu-dev \
    zlib-dev \
    oniguruma-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    gmp-dev \
    unzip \
    build-base

# 安裝 PHP 擴展
RUN docker-php-ext-install pdo_mysql opcache bcmath exif gd pcntl zip sockets
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp
RUN docker-php-ext-install -j$(nproc) gd

# 安裝 Redis 擴展
RUN pecl install -o -f redis \
    && rm -rf /tmp/pear \
    && docker-php-ext-enable redis

# 安裝 Swoole 擴展
RUN pecl install -o -f swoole \
    && rm -rf /tmp/pear \
    && docker-php-ext-enable swoole

# 設定工作目錄
WORKDIR /var/www/html

# 複製 Composer 檔案
# 注意：這裡複製的是 host 上的 composer.json 和 .lock，它們會被 volume mount 覆蓋
# 但此步驟是為了確保 Docker build 階段可以運行 composer install
COPY composer.json composer.lock ./

# 安裝 Composer 依賴
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# 複製應用程式程式碼
# COPY . . 這一行會在 docker-compose up 時被 volume mount 取代，因此可以省略
# 但為了構建時有檔案，可以保留或者僅複製必要檔案

# 設定權限
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
RUN chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# 複製 Nginx 設定
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf

# 複製 supervisor 設定
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# 暴露端口
EXPOSE 9000 # PHP-FPM
EXPOSE 80 # Nginx
EXPOSE 9501 # Swoole HTTP Server

# 啟動 Nginx 和 PHP-FPM (由 supervisord 管理)
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
