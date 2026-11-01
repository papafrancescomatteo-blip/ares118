#!/bin/sh
set -e
PORT="${PORT:-10000}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# crea tabelle e utente Admin se il database e' vuoto (errori non bloccanti)
php /var/www/html/tools/install_db.php || true

exec apache2-foreground
