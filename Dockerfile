FROM php:8.2-apache

# Enable Apache modules
RUN a2enmod rewrite headers

# Install SQLite build deps + sqlite tools
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      sqlite3 \
      libsqlite3-dev \
      pkg-config \
 && rm -rf /var/lib/apt/lists/*

# Enable PDO + SQLite extensions
RUN docker-php-ext-install pdo pdo_sqlite

# Increase Upload Limit to 500MB and Memory to 512MB to handle large images
RUN echo "upload_max_filesize = 200M\npost_max_size = 2050M\nmax_file_uploads = 20\nmemory_limit = 512M" > /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html

# Create data and uploads directories with correct permissions
RUN mkdir -p /var/www/html/data /var/www/html/uploads \
 && chown -R www-data:www-data /var/www/html \
 && chmod -R 775 /var/www/html/data /var/www/html/uploads