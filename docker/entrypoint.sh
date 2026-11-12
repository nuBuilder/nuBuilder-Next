#!/bin/bash
# nuBuilder Next in Docker – runs before Apache:
#   1. copies the program files of the image into the volume /var/www/html on the first start, and again when the image is newer
#      than the files there (never touching nuconfig.php, custom pages, uploads, backups, library packs);
#   2. with NUNEXT_DB_HOST set and no nuconfig.php yet: writes nuconfig.php from the environment, waits for the database and sets it
#      up (nuBuilder Forte's tables + nuBuilder Next's own), optionally with the sample application (NUNEXT_SAMPLE=1);
#      without NUNEXT_DB_HOST the installer runs in the browser – its setup token is printed here;
#   3. after a newer image: the database steps of the new version (as the update page does).
set -euo pipefail
SRC=${NUNEXT_SRC:-/usr/src/nunext}
APP=${NUNEXT_APP:-/var/www/html}
SETUP=${NUNEXT_SETUP:-/usr/local/lib/nunext-setup.php}
log() { echo "[nunext] $*"; }
ver() { sed -n "s/^define('NU_VERSION', '\([0-9.]*\)');.*/\1/p" "$1/core/nuapp.php" 2>/dev/null || true; }

new=$(ver "$SRC"); old=$(ver "$APP")
fresh=0
if [ -z "$old" ]; then
	log "first start: copying nuBuilder Next $new"
	rsync -a "$SRC"/ "$APP"/
	fresh=1
elif [ "$(printf '%s\n%s\n' "$old" "$new" | sort -V | tail -n1)" = "$new" ] && [ "$old" != "$new" ]; then
	log "newer image: $old → $new (data, custom pages and settings stay)"
	rsync -a \
		--exclude '/nuconfig.php' --exclude '/vendor/packs/' --exclude '/_backup/' --exclude '/_update/' --exclude '/_libsrc/' --exclude '/temp/' \
		--include '/custom/' --include '/custom/.htaccess' --include '/custom/PAGE_GUIDE.md' --include '/custom/pages/' \
		--include '/custom/pages/_template/***' --exclude '/custom/**' \
		"$SRC"/ "$APP"/
else
	log "nuBuilder Next $old (image $new) – files left as they are"
fi
mkdir -p "$APP/_install" "$APP/temp"
chown -R www-data:www-data "$APP"

if [ ! -f "$APP/nuconfig.php" ] && [ -n "${NUNEXT_DB_HOST:-}" ]; then
	pw="${NUNEXT_ADMIN_PASSWORD:-}"
	if [ -z "$pw" ]; then pw=$(head -c 18 /dev/urandom | base64 | tr -d '/+=' | cut -c1-20); log "globeadmin password (set NUNEXT_ADMIN_PASSWORD to choose it): $pw"; fi
	q() { php -r 'echo var_export((string)$argv[1], true);' -- "$1"; }
	{
		echo '<?php'
		echo '// written by the Docker entrypoint from the environment – edit it in Settings → Configuration file or here'
		echo "\$nuConfigDBType = $(q "${NUNEXT_DB_TYPE:-mysql}");"
		echo "\$nuConfigDBHost = $(q "$NUNEXT_DB_HOST");"
		[ -n "${NUNEXT_DB_PORT:-}" ] && echo "\$nuConfigDBPort = ${NUNEXT_DB_PORT//[^0-9]/};"
		echo "\$nuConfigDBName = $(q "${NUNEXT_DB_NAME:-nunext}");"
		echo "\$nuConfigDBUser = $(q "${NUNEXT_DB_USER:-nunext}");"
		echo "\$nuConfigDBPassword = $(q "${NUNEXT_DB_PASSWORD:-}");"
		[ "${NUNEXT_DB_TYPE:-mysql}" = "sqlsrv" ] && echo '$nuConfigDBTrustServerCertificate = true;'
		echo '$nuConfigDBGlobeadminUsername = "globeadmin";'
		echo "\$nuConfigDBGlobeadminPassword = $(q "$pw");"
		echo "\$nuConfigSecretKey = $(q "$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')");"
		[ -n "${NUNEXT_BASE_URL:-}" ] && echo "\$nuConfigBaseURL = $(q "$NUNEXT_BASE_URL");"
		echo "\$nuConfigTitle = $(q "${NUNEXT_TITLE:-nuBuilder Next}");"
		echo '$nuConfigShowInstaller = false;'
	} > "$APP/nuconfig.php"
	chown www-data:www-data "$APP/nuconfig.php"; chmod 640 "$APP/nuconfig.php"
	log "nuconfig.php written for ${NUNEXT_DB_TYPE:-mysql} at $NUNEXT_DB_HOST"
fi

if [ -f "$APP/nuconfig.php" ]; then
	cd "$APP"
	# the database may start more slowly than this container
	for i in $(seq 1 60); do
		if su -s /bin/sh www-data -c "php -r 'require \"core/nuapp.php\"; nu_db(); exit(0);'" > /dev/null 2>&1; then break; fi
		[ "$i" = 60 ] && { log "the database cannot be reached – starting anyway (the pages explain the problem)"; break; }
		sleep 2
	done
	su -s /bin/sh www-data -c "cd \"$APP\" && php \"$SETUP\" ${NUNEXT_SAMPLE:-0}" 2>&1 | sed 's/^/[nunext] /' || true
else
	tok=$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n' | sed 's/\(......\)\(......\)\(......\)\(......\)/\1-\2-\3-\4/')
	[ -f "$APP/_install/setup-token.txt" ] && tok=$(tr -d '\n' < "$APP/_install/setup-token.txt")
	echo "$tok" > "$APP/_install/setup-token.txt"; chmod 644 "$APP/_install/setup-token.txt"; chown www-data:www-data "$APP/_install/setup-token.txt"
	log "open the installer in the browser – setup token: $tok"
fi

if command -v docker-php-entrypoint > /dev/null; then exec docker-php-entrypoint "$@"; else exec "$@"; fi
