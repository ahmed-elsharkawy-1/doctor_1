# Rolling back a production release

Every production deploy keeps **one** copy of the images that were running
before it, tagged `:rollback`, and replaces it on the next release — never more
than one, so it costs about 0.3 GB of disk. Staging keeps none. See
`.github/workflows/deploy.yml`.

This puts the **code** back. It never touches the data: bookings and patients
stay as they are. Data is restored from the hourly backups instead
(`docs/deploy/backups.md`).

## When a release breaks the site

```bash
cd /docker/doctor_1
cat /var/tmp/doctor_1-rollback-commit          # the commit the copy was built from

docker tag doctor_1-app:rollback doctor_1-app:latest
docker tag doctor_1-web:rollback doctor_1-web:latest
docker compose -f compose.prod.yml up -d --no-build --force-recreate web app scheduler queue
docker compose -f compose.prod.yml exec -T app php artisan optimize:clear
```

About a minute. Then fix forward: revert or fix the bad commit, and push it to
`main` like any release — the next deploy rebuilds from git and makes a new
rollback copy.

## Before you roll back

- **Migrations are not undone.** They are forward-only and add columns, so the
  older code normally runs on the newer schema. A release that renamed or
  dropped something needs its own plan.
- The checkout stays on the new commit. That is fine: the next deploy pulls
  whatever `main` points at.
- **WhatsApp template names live in the database**, not in the image: every
  deploy runs `MessageTemplateSeeder`, and the rollback above does not. If the
  release changed template names (November 2026: `_v2`/`_v4` and
  `visit_guidelines`), the older code would send its own values to the new
  templates and Meta would refuse every message. Put the older names back with
  the older image's own seeder, right after the `up`:

  ```bash
  docker compose -f compose.prod.yml exec -T app php artisan db:seed --class=MessageTemplateSeeder --force
  ```

  It runs the seeder baked into the rollback image, so it restores exactly the
  names that code expects. A template the older seeder does not know (such as
  `visit_guidelines`) stays in the table but is never sent by the older code.

## Build cache

Each deploy also caps Docker's build cache at 3 GB
(`docker builder prune --max-used-space 3gb`), dropping the least recently used
first. Without it the cache only grows — it had reached 8.8 GB by November
2026. By size rather than age: we build several times a week, so "older than a
week" left almost all of it.
