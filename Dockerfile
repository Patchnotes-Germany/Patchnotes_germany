#syntax=docker/dockerfile:1

# Versions
FROM dunglas/frankenphp:1.12-php8.4-trixie AS frankenphp_upstream

# Base FrankenPHP image: shared by the web server, all Messenger workers and the scheduler.
FROM frankenphp_upstream AS frankenphp_base

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

WORKDIR /app

# System packages:
# - git/openssh-client: GitRepository service (laws/content repositories)
# - poppler-utils + tesseract (deu): BGBl PDF -> text, OCR fallback
# - fonts-noto-core: OpenGraph images (Latin incl. Turkish, Cyrillic)
# hadolint ignore=DL3008
RUN <<-EOF
	apt-get update
	apt-get install -y --no-install-recommends \
		file \
		git \
		openssh-client \
		unzip \
		tzdata \
		libcap2-bin \
		poppler-utils \
		tesseract-ocr \
		tesseract-ocr-deu \
		fonts-noto-core
	install-php-extensions \
		@composer \
		apcu \
		gd \
		gmp \
		intl \
		opcache \
		pcntl \
		pdo_mysql \
		sodium \
		zip
	rm -rf /var/lib/apt/lists/*
EOF

# known_hosts for the public forges; self-hosted forges are added at runtime from GIT_KNOWN_HOSTS.
RUN <<-EOF
	mkdir -p /etc/ssh
	ssh-keyscan -t ed25519,ecdsa,rsa github.com gitlab.com codeberg.org > /etc/ssh/ssh_known_hosts 2>/dev/null
	test -s /etc/ssh/ssh_known_hosts
	git config --system --add safe.directory '*'
EOF

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1

ENV PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"

COPY --link frankenphp/conf.d/10-app.ini $PHP_INI_DIR/app.conf.d/
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link --chmod=755 frankenphp/worker-healthcheck.sh /usr/local/bin/worker-healthcheck
COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s CMD php -r 'exit(false === @file_get_contents("http://localhost:2019/metrics", context: stream_context_create(["http" => ["timeout" => 5]])) ? 1 : 0);'
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]

# Dev FrankenPHP image
FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev
ENV XDEBUG_MODE=off
ENV FRANKENPHP_WORKER_CONFIG=watch

# hadolint ignore=DL3008
RUN <<-EOF
	mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
	install-php-extensions xdebug
	useradd -m -s /bin/bash nonroot
EOF

COPY --link frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/app.conf.d/

CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch" ]

# Prod FrankenPHP image
FROM frankenphp_base AS frankenphp_prod

ENV APP_ENV=prod

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --link frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/app.conf.d/

# prevent the reinstallation of vendors at every changes in the source code
COPY --link composer.* symfony.* ./
RUN composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

# copy sources
COPY --link --exclude=frankenphp/ . ./

RUN <<-EOF
	mkdir -p var/cache var/log var/share var/repos var/storage
	composer dump-autoload --classmap-authoritative --no-dev
	composer dump-env prod
	composer run-script --no-dev post-install-cmd
	if [ -f importmap.php ]; then
		php bin/console asset-map:compile
	fi
	chmod +x bin/console
	# Run as an unprivileged user; allow binding :80/:443 without root.
	setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp
	mkdir -p /data/caddy /config/caddy
	chown -R www-data:www-data /data /config var
	sync
EOF

USER www-data
