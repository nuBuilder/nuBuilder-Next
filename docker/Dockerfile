# nuBuilder Next – image for trying it out and for small installations (not hardened for production).
# Build from the project folder:   docker build -f docker/Dockerfile -t nunext .
# Start with a database:          docker compose -f docker/compose.yml up -d      (see docker/README.md)
FROM php:8.3-apache-bookworm

ARG WITH_SQLSRV=1
ENV ACCEPT_EULA=Y

# PHP extensions nuBuilder Next uses: pdo_mysql, gd (JPEG / PNG / WebP / fonts), zip, intl, bcmath, opcache; sodium and mbstring
# are part of the official image. SQL Server: Microsoft's ODBC driver + pdo_sqlsrv (WITH_SQLSRV=0 leaves them out).
RUN set -eux; \
	apt-get update; \
	apt-get install -y --no-install-recommends libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libzip-dev libicu-dev \
		unzip rsync curl gnupg ca-certificates default-mysql-client; \
	docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
	docker-php-ext-install -j"$(nproc)" pdo_mysql gd zip intl bcmath opcache; \
	if [ "$WITH_SQLSRV" = "1" ]; then \
		curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --batch --yes --dearmor -o /usr/share/keyrings/microsoft-prod.gpg; \
		curl -fsSL https://packages.microsoft.com/config/debian/12/prod.list > /etc/apt/sources.list.d/mssql-release.list; \
		apt-get update; apt-get install -y --no-install-recommends msodbcsql18 unixodbc-dev; \
		pecl install sqlsrv pdo_sqlsrv; docker-php-ext-enable sqlsrv pdo_sqlsrv; \
	fi; \
	a2enmod rewrite headers expires deflate; \
	apt-get purge -y --auto-remove gnupg; rm -rf /var/lib/apt/lists/* /tmp/pear

COPY docker/php.ini /usr/local/etc/php/conf.d/nunext.ini
COPY docker/apache.conf /etc/apache2/conf-enabled/nunext.conf
COPY docker/entrypoint.sh /usr/local/bin/nunext-entrypoint
COPY docker/setup.php /usr/local/lib/nunext-setup.php
# the program files wait here; the entrypoint copies them into the volume /var/www/html (first start, or a newer image)
COPY . /usr/src/nunext
RUN chmod +x /usr/local/bin/nunext-entrypoint && rm -rf /usr/src/nunext/docker && mkdir -p /usr/src/nunext/_install

VOLUME /var/www/html
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s CMD curl -fsS -o /dev/null http://127.0.0.1/index.php?p=pwa\&a=offline || exit 1
ENTRYPOINT ["nunext-entrypoint"]
CMD ["apache2-foreground"]
