# WeaponPaints website (from the cs2-WeaponPaints build-466 release). PHP with PDO MySQL for the MariaDB database.
# Pinned to php:8.3 (8.3.36 or newer includes the fixes for CVE-2026-109618/109619, "php2shell").
FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql \
    && printf 'expose_php=Off\nsession.upload_progress.enabled=Off\n' > /usr/local/etc/php/conf.d/security.ini
COPY --chown=www-data:www-data ./ /var/www/html/
