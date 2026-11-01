FROM php:8.3-apache

# Estensione PHP per MongoDB + strumenti per composer
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates git unzip \
 && rm -rf /var/lib/apt/lists/* \
 && install-php-extensions mongodb \
 && a2enmod rewrite headers \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf '<Directory /var/www/html>\n  AllowOverride All\n  Require all granted\n</Directory>\nServerName localhost\nServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/ares.conf \
 && a2enconf ares

WORKDIR /var/www/html

# Libreria PHP di MongoDB (scelta in automatico compatibile con l'estensione installata)
COPY composer.json ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

COPY . /var/www/html
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh && chown -R www-data:www-data /var/www/html

# Render passa la porta nella variabile PORT (default 10000)
EXPOSE 10000
CMD ["docker-entrypoint.sh"]
