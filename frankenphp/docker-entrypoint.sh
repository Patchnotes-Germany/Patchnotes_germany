#!/bin/sh
set -e

# Only the "primary" container (the web server, APP_PRIMARY=1) installs vendors and runs
# migrations. Workers and the scheduler depend on it being healthy, so they never race it.

if [ -n "${GIT_KNOWN_HOSTS:-}" ]; then
	# Self-hosted forges: extra known_hosts lines, separated by "\n" or real newlines.
	printf '%b\n' "$GIT_KNOWN_HOSTS" > /tmp/patchnotes_known_hosts
	export GIT_SSH_COMMAND="${GIT_SSH_COMMAND:-ssh -o UserKnownHostsFile=/tmp/patchnotes_known_hosts -o GlobalKnownHostsFile=/etc/ssh/ssh_known_hosts}"
fi

if [ "${APP_PRIMARY:-0}" = '1' ] && { [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; }; then
	if [ -z "$(ls -A 'vendor/' 2>/dev/null)" ]; then
		composer install --prefer-dist --no-progress --no-interaction
	fi

	mkdir -p var/repos var/storage

	# Display information about the current project
	php bin/console -V

	echo 'Waiting for database to be ready...'
	ATTEMPTS_LEFT_TO_REACH_DATABASE=60
	until [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ] || DATABASE_ERROR=$(php bin/console dbal:run-sql -q "SELECT 1" 2>&1); do
		if [ $? -eq 255 ]; then
			# If the Doctrine command exits with 255, an unrecoverable error occurred
			ATTEMPTS_LEFT_TO_REACH_DATABASE=0
			break
		fi
		sleep 1
		ATTEMPTS_LEFT_TO_REACH_DATABASE=$((ATTEMPTS_LEFT_TO_REACH_DATABASE - 1))
		echo "Still waiting for database to be ready... $ATTEMPTS_LEFT_TO_REACH_DATABASE attempts left."
	done

	if [ $ATTEMPTS_LEFT_TO_REACH_DATABASE -eq 0 ]; then
		echo 'The database is not up or not reachable:'
		echo "$DATABASE_ERROR"
		exit 1
	fi
	echo 'The database is now ready and reachable'

	if find ./migrations -iname '*.php' -print -quit | grep --quiet .; then
		php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing --allow-no-migration
	fi

	echo 'PHP app ready!'
fi

exec docker-php-entrypoint "$@"
