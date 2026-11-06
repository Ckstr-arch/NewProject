FROM php:8.2-apache-bookworm

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY backend/api/ /opt/sporthub/backend/api/
COPY backend/config/ /opt/sporthub/backend/config/
COPY backend/core/ /opt/sporthub/backend/core/
COPY backend/bootstrap.php backend/index.php /opt/sporthub/backend/
COPY nsbm-sportshub-frontend/ /var/www/html/
RUN mv /var/www/html/js/config.js /var/www/html/js/config.native.js
COPY docker/frontend-config.js /var/www/html/js/config.js

RUN find /opt/sporthub/backend /var/www/html -type d -exec chmod 755 {} + \
    && find /opt/sporthub/backend /var/www/html -type f -exec chmod 644 {} + \
    && printf 'display_errors=Off\nlog_errors=On\nexpose_php=Off\n' > /usr/local/etc/php/conf.d/sporthub.ini

EXPOSE 80
