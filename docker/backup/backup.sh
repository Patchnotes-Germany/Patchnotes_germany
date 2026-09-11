#!/bin/bash
# Patchnotes backup service (runs in a mysql:8.4 container).
#   backup.sh loop            — daemon: runs a backup every day at $BACKUP_TIME (TZ=Europe/Berlin)
#   backup.sh run             — one backup now (MySQL dump + storage mirror) and rotation
#   backup.sh restore <file>  — restore a MySQL dump (*.sql.gz) from /backups/mysql
#   backup.sh healthcheck     — container healthcheck (heartbeat written by "loop")
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/backups}"
BACKUP_TIME="${BACKUP_TIME:-04:30}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
HEARTBEAT_FILE="/tmp/backup.heartbeat"

mysql_args=(-h "${MYSQL_HOST:-mysql}" -uroot)
export MYSQL_PWD="${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD is required}"

log() { echo "[backup] $(date -Iseconds) $*"; }

run_backup() {
	local stamp target
	stamp="$(date +%Y%m%d-%H%M%S)"
	mkdir -p "$BACKUP_DIR/mysql" "$BACKUP_DIR/storage"

	target="$BACKUP_DIR/mysql/${MYSQL_DATABASE:-patchnotes}-$stamp.sql.gz"
	log "dumping database to $target"
	mysqldump "${mysql_args[@]}" --single-transaction --quick --routines --triggers --events \
		--set-gtid-purged=OFF --default-character-set=utf8mb4 "${MYSQL_DATABASE:-patchnotes}" \
		| gzip -6 > "$target.tmp"
	mv "$target.tmp" "$target"

	# Raw source documents are immutable and content-addressed: an additive mirror is a full backup.
	if [ -d /storage ]; then
		log "mirroring storage volume"
		cp -a --no-clobber /storage/. "$BACKUP_DIR/storage/"
	fi

	log "removing database dumps older than $RETENTION_DAYS days"
	find "$BACKUP_DIR/mysql" -name '*.sql.gz' -type f -mtime "+$((RETENTION_DAYS - 1))" -print -delete
	log "done"
}

restore() {
	local file="$1"
	[ -f "$file" ] || file="$BACKUP_DIR/mysql/$file"
	[ -f "$file" ] || { log "backup file not found: $1"; exit 1; }
	log "restoring $file into ${MYSQL_DATABASE:-patchnotes}"
	gunzip -c "$file" | mysql "${mysql_args[@]}" "${MYSQL_DATABASE:-patchnotes}"
	log "restore finished"
}

loop() {
	local last_run_file="$BACKUP_DIR/.last_run_date"
	log "scheduler started, daily at $BACKUP_TIME ($(date +%Z))"
	while true; do
		touch "$HEARTBEAT_FILE"
		if [ "$(date +%H:%M)" \> "$BACKUP_TIME" ] || [ "$(date +%H:%M)" = "$BACKUP_TIME" ]; then
			if [ "$(cat "$last_run_file" 2>/dev/null || true)" != "$(date +%F)" ]; then
				if run_backup; then
					date +%F > "$last_run_file"
				else
					log "backup FAILED, retrying in 10 minutes"
					sleep 540
				fi
			fi
		fi
		sleep 60
	done
}

case "${1:-loop}" in
	loop) loop ;;
	run) run_backup ;;
	restore) restore "${2:?usage: backup.sh restore <file>}" ;;
	healthcheck)
		[ -f "$HEARTBEAT_FILE" ] && [ $(( $(date +%s) - $(stat -c %Y "$HEARTBEAT_FILE") )) -le 180 ]
		;;
	*) echo "usage: $0 loop|run|restore <file>|healthcheck" >&2; exit 2 ;;
esac
