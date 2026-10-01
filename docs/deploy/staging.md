# Staging

<https://staging.elayadah.com> — a full copy of the app for testing changes
before they reach production. Same VPS, nothing shared with production.

## Flow

1. Work on a feature branch, open a pull request into `develop`. Tests run on it.
2. Merging into `develop` deploys to staging (`deploy-staging.yml`).
3. Test on staging.
4. Pull request `develop` → `main`. Merging deploys to production
   (`deploy-production.yml`).

Both deploys run the full test suite (SQLite, MySQL, Pint) first and deploy
nothing if it fails.

## On the box

| | Production | Staging |
|---|---|---|
| Checkout | `/docker/doctor_1` (branch `main`) | `/docker/doctor_1_staging` (branch `develop`) |
| Compose project | `doctor_1` | `doctor_1_staging` |
| Images | `doctor_1-*:latest` | `doctor_1_staging-*:latest` |
| Traefik router | `doctor1` | `doctor1-staging` |
| Database | its own volume | its own volume, demo data only |

Both use the same `compose.prod.yml`. Staging's `.env` must set:

```
STACK_NAME=doctor_1_staging
ROUTER_NAME=doctor1-staging
DEPLOY_APP_ENV=staging
APP_HOST=staging.elayadah.com
STAGING_GATE_PASSWORD=...
CLINIC_OTP_DRIVER=log
CLINIC_MESSAGING_DRIVER=log
```

Without `STACK_NAME` and `ROUTER_NAME` the staging checkout would rebuild
production's containers and claim its route. The deploy workflow refuses to run
when the checkout's compose project is not the one it expects, but do not rely
on that when running `docker compose` by hand: run it from the right folder.

## Rules

- **No production data.** Staging holds demo clinics and made-up patients only
  (`DemoClinicSeeder`, `clinic:seed-web-demo`).
- **No real messages.** OTP and WhatsApp use the `log` driver. Codes are in the
  app log: `docker compose -f compose.prod.yml logs app | grep "OTP log driver"`.
- **Not public.** Every web page asks for the team password
  (`STAGING_GATE_USER` / `STAGING_GATE_PASSWORD`) and tells search engines not
  to index it. The API, the WhatsApp webhook and `/up` stay open — the mobile
  app's staging build talks to the API directly.
