# Hostinger VPS Production Deployment

This project is deployed as its own Docker Compose project and routed by the
existing Traefik container from the `n8n` stack. Do not publish Laravel, MySQL,
or internal service ports on the host.

## Server Layout

Expected production path:

```bash
/docker/doctor_1
```

Clone or pull this repository directly into that directory. The existing stacks
remain independent:

```bash
/docker/n8n
/docker/gotenberg-lutf
```

The Laravel web container joins the existing external Docker network
`n8n_default` so Traefik can route to it.

Production request flow:

```text
Internet
  -> existing Traefik container on ports 80/443
  -> doctor_1 Nginx container on the Docker network
  -> doctor_1 PHP-FPM container
  -> Laravel
  -> private MySQL container
```

## First Deploy

From the VPS:

```bash
mkdir -p /docker/doctor_1
cd /docker/doctor_1
git clone <repo-url> .
cp .env.production.example .env
```

Edit `.env` on the server only. Set at minimum:

```bash
APP_KEY=
APP_URL=https://doctor1.srv1362420.hstgr.cloud
APP_HOST=doctor1.srv1362420.hstgr.cloud
DB_PASSWORD=
DB_ROOT_PASSWORD=
SUPER_ADMIN_EMAIL=
SUPER_ADMIN_PASSWORD=
```

Generate `APP_KEY` on the server without committing it:

```bash
sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" .env
```

Use strong unique values for `DB_PASSWORD`, `DB_ROOT_PASSWORD`, and
`SUPER_ADMIN_PASSWORD`.

Build and start:

```bash
docker compose -f compose.prod.yml up -d --build
```

Run the database setup:

```bash
docker compose -f compose.prod.yml exec app php artisan migrate --force
docker compose -f compose.prod.yml exec app php artisan db:seed --class=SpecialtySeeder --force
docker compose -f compose.prod.yml exec app php artisan filament:optimize
```

Do not run `DemoClinicSeeder` in production.

## Updates

```bash
cd /docker/doctor_1
git pull
docker compose -f compose.prod.yml up -d --build
docker compose -f compose.prod.yml exec app php artisan migrate --force
docker compose -f compose.prod.yml exec app php artisan optimize:clear
docker compose -f compose.prod.yml exec app php artisan filament:optimize
```

If the server was deployed before the bootstrap cache volume was removed, the
old unused volume can be deleted after the updated containers are running:

```bash
docker volume rm doctor_1_app_bootstrap_cache
```

## Automatic Deploys

GitHub Actions can deploy every push to `main` through SSH. The workflow lives
at `.github/workflows/deploy-production.yml` and runs the same production update
commands:

```bash
cd /docker/doctor_1
git fetch origin main
git pull --ff-only origin main
docker compose -f compose.prod.yml up -d --build --force-recreate
docker compose -f compose.prod.yml exec -T app php artisan migrate --force
docker compose -f compose.prod.yml exec -T app php artisan optimize:clear
docker compose -f compose.prod.yml exec -T app php artisan filament:optimize
```

The VPS must already be able to pull the private GitHub repository from
`/docker/doctor_1`. The GitHub Actions deploy key below is only for
GitHub Actions to SSH into the VPS; it does not replace the VPS-to-GitHub key
used by `git pull`.

Create a dedicated deploy key on your local machine:

```bash
ssh-keygen -t ed25519 -C "doctor-1-github-deploy" -f ~/.ssh/doctor_1_github_deploy
```

Add the public key to the VPS:

```bash
ssh-copy-id -i ~/.ssh/doctor_1_github_deploy.pub root@doctor1.srv1362420.hstgr.cloud
```

Add these GitHub repository secrets:

```text
VPS_HOST=doctor1.srv1362420.hstgr.cloud
VPS_PORT=22
VPS_USER=root
VPS_SSH_KEY=<contents of ~/.ssh/doctor_1_github_deploy>
DEPLOY_URL=https://doctor1.srv1362420.hstgr.cloud
```

`DEPLOY_URL` is optional. When present, the workflow checks `/up` after deploy.

## Runtime Services

The production Compose file starts:

- `web`: Nginx web server, routed internally by Traefik.
- `app`: Laravel PHP-FPM runtime, reachable only by Nginx.
- `mysql`: private MySQL database, no host port.
- `scheduler`: runs `php artisan schedule:work` for the hourly
  `clinic:close-day` task.

It intentionally does not start Redis, a queue worker, Node, or Gotenberg.

## Notes

- `package-lock.json` is currently absent, so the Docker asset stage falls back
  to `npm install`. Add and commit a lockfile before production if you want
  fully reproducible frontend builds.
- Database cache tables are required because booking creation uses
  `Cache::lock()` to prevent double-booking.
- Queue tables exist, but no application jobs are currently dispatched. Add a
  queue worker only after code starts dispatching jobs.
