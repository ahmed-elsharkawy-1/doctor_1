#!/usr/bin/env bash
# Fetches one backup, unlocks it and unpacks it into a folder.
#
#   restore.sh                  the newest backup on Google Drive
#   restore.sh <file name>      a named backup on Google Drive
#   restore.sh /path/file.gpg   a backup file already on this machine
#   restore.sh ... --into DIR   unpack into DIR (default: a new folder under /root)
#
# It never touches the live database. Loading db.sql into production is a
# deliberate step — see docs/deploy/backups.md.

HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
. "$HERE/lib.sh"

SOURCE="" INTO=""
while [ $# -gt 0 ]; do
  case "$1" in
    --into) INTO="$2"; shift 2 ;;
    *) SOURCE="$1"; shift ;;
  esac
done

if [ -z "$SOURCE" ]; then
  SOURCE="$("$RCLONE" lsf "$REMOTE" --files-only --include "${NAME_PREFIX}-*.tar.gz.gpg" | sort | tail -n 1)"
  [ -n "$SOURCE" ] || { echo "No backups found in $REMOTE" >&2; exit 1; }
fi

INTO="${INTO:-/root/restore-$(date -u +%Y%m%dT%H%M%SZ)}"
mkdir -p "$INTO"

if [ -f "$SOURCE" ]; then
  FILE="$SOURCE"
else
  log "downloading $SOURCE"
  "$RCLONE" copyto "$REMOTE/$SOURCE" "$INTO/$SOURCE" --retries 5
  FILE="$INTO/$SOURCE"
fi

log "unlocking and unpacking into $INTO"
gpg --batch --quiet --pinentry-mode loopback --passphrase-file "$PASSPHRASE_FILE" --decrypt "$FILE" | tar -xzf - -C "$INTO"

# The checksums recorded at backup time must still match.
(cd "$INTO" && sed -n '/^sha256:/,$p' manifest.txt | tail -n +2 | sed 's/^  //' | sha256sum --quiet -c -)

log "ready. Contents:"
cat "$INTO/manifest.txt"
echo
echo "Files in $INTO: db.sql (database), storage.tar (uploads), env (.env)."
echo "To load them into production, follow docs/deploy/backups.md."
