# Backups

Production is backed up **every hour**, automatically, to the owner's
Google Drive. Each backup is **one encrypted file** holding everything needed
to rebuild the system:

| Inside | What |
|---|---|
| `db.sql` | the whole database, taken without locking (`--single-transaction`) |
| `storage.tar` | uploaded files (doctor photos and the like) |
| `env` | the server's `.env` — every key and password |
| `manifest.txt` | when, which commit, row counts, checksums |

**The one thing kept outside the backups is the encryption password.** It is
in the owner's password manager. Without it no backup can be opened — and it
can never be inside the backup it unlocks.

## How it works

- `scripts/backup/backup.sh` runs from **root's cron on the host**, not from
  the app, so it runs even when the app is down.
- Every run dumps, checks the dump finished and has this database's shape,
  encrypts (GPG, AES-256), copies to Google Drive, checks the copy's size
  there, thins old copies, then reports to healthchecks.io.
- **Any failure reports `/fail`; a run that never reports is the alarm too.**
  healthchecks.io emails the owner either way.
- `scripts/backup/restore-test.sh` runs **weekly**: it loads the newest Drive
  backup into a throwaway MySQL (no network, never the live database) and
  compares row counts with those the backup recorded.

**Kept:** on Google Drive, every backup from the last 48 hours, then the first
of each day for 30 days (Drive's trash holds deleted ones 30 days more). On
the server, the last 48 hours, for quick fixes.

**Worst case:** a server lost at 15:40 loses what happened after 15:00.

## Files on the server

| Path | What |
|---|---|
| `/root/.config/elayadah-backup.conf` | settings (from `scripts/backup/backup.conf.example`), `chmod 600` |
| `/root/.config/elayadah-backup.pass` | the encryption password, one line, `chmod 600` |
| `/root/.config/rclone/rclone.conf` | the Google Drive connection |
| `/root/backups/doctor_1/` | local copies |
| `/var/log/elayadah-backup.log` | every run's output (rotated weekly) |
| `/opt/elayadah-backup/` | the installed scripts |
| `/etc/cron.d/elayadah-backup` | the schedule |

The scripts are **copied** to `/opt/elayadah-backup/` rather than run from
the app's checkout, so a broken deploy can never stop the backups. After
changing them in `scripts/backup/`, copy them over again (step 3 below).

## Install (once)

```bash
# 1. rclone, the Google Drive client
curl -fsSL https://rclone.org/install.sh | bash

# 2. Connect Google Drive. On a computer with a browser:
#      rclone authorize "drive" --drive-scope drive.file
#    and paste the token it prints into, on the server:
rclone config create gdrive drive scope=drive.file config_token='<token>'
#    drive.file = rclone sees only the files it created, not the rest of the Drive.

#    Use your own Google OAuth client (Google Cloud project "Elayadah
#    Backups", Desktop app, published "In production" with no logo, so the
#    access neither expires weekly nor needs verification). rclone's shared
#    client is being retired:
#      rclone authorize "drive" "<client_id>" "<client_secret>"   (with RCLONE_DRIVE_SCOPE=drive.file)
#    and put client_id, client_secret and the token in the [gdrive] section.

# 3. The scripts, settings and password (typed, never pasted into chat or git)
mkdir -p /opt/elayadah-backup && cp scripts/backup/* /opt/elayadah-backup/ && chmod 755 /opt/elayadah-backup/*.sh
install -m 600 /opt/elayadah-backup/backup.conf.example /root/.config/elayadah-backup.conf
#    edit: HC_BACKUP_URL, HC_RESTORE_URL
( umask 077; read -rsp 'Encryption password: ' p; echo; printf '%s' "$p" > /root/.config/elayadah-backup.pass )

# 4. Schedule: /etc/cron.d/elayadah-backup
0 * * * *  root /opt/elayadah-backup/backup.sh       >> /var/log/elayadah-backup.log 2>&1
40 4 * * 5 root /opt/elayadah-backup/restore-test.sh >> /var/log/elayadah-backup.log 2>&1
#    and /etc/logrotate.d/elayadah-backup (weekly, rotate 8, compress, "su root root").

# 5. First run, and check the file reached Drive
/opt/elayadah-backup/backup.sh && rclone lsl "gdrive:Elayadah Backups"
```

## Restore

**First, get a backup open** (any machine with rclone, gpg and the password):

```bash
/opt/elayadah-backup/restore.sh                     # newest on Drive
/opt/elayadah-backup/restore.sh elayadah-prod-20261021T130000Z.tar.gz.gpg
```

It unpacks into `/root/restore-<time>/`, checks the checksums and prints the
manifest. **It never touches the live database.** Without the scripts, the
same by hand: download the file from Drive, then
`gpg --decrypt FILE | tar -xzf -`.

### A. Undo a mistake (the server is fine)

Load the backup **next to** the live database first, check it, then decide:

```bash
cd /docker/doctor_1
docker compose -f compose.prod.yml exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "CREATE DATABASE restore_check"'
docker compose -f compose.prod.yml exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot restore_check' < /root/restore-…/db.sql
# compare, copy back what was lost, then: DROP DATABASE restore_check;
```

Replacing the whole live database is a last resort: take a fresh backup of
it first (`backup.sh`), then load `db.sql` into the live database the same way.

### B. The server is gone

1. New server: Docker, clone the repo into `/docker/doctor_1`, `git checkout main`.
2. Open the newest backup (above) on any machine.
3. Put its `env` back as `/docker/doctor_1/.env`.
4. `docker compose -f compose.prod.yml up -d --build`, wait for MySQL.
5. Load `db.sql` into the database named in `.env`.
6. Unpack `storage.tar` into the app's `storage/` (`docker compose cp`).
7. Point DNS (Cloudflare) at the new server; reinstall backups (above).

## Checking on it

- healthchecks.io shows the last run of each check.
- `tail -n 50 /var/log/elayadah-backup.log`
- Does the server's password match the password manager's? Compare
  fingerprints: on the server `sha256sum < /root/.config/elayadah-backup.pass | cut -c1-12`,
  and locally `read -rsp 'Password: ' p; echo; printf '%s' "$p" | sha256sum | cut -c1-12`.
- `rclone lsl "gdrive:Elayadah Backups"`
