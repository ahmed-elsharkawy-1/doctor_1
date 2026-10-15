# Adding a new clinic

`php artisan clinic:onboard` creates a real clinic from one JSON description:
the clinic, its doctor, the logins (one doctor login plus any secretaries) and
a starting week. The same file is run on staging first, checked, then run on
production, so both get exactly the same clinic.

It only ever creates. An existing slug, a slug another clinic gave up, an email
or phone already in use, or impossible hours are refused, and a refusal writes
nothing. Passwords are generated and printed once; they are not in the file.

## The description

Kept in `storage/onboarding/<slug>.json`, which git ignores: this repository is
public and the file holds a doctor's contact details.

```json
{
  "clinic": {
    "name": "عيادة د. …",
    "slug": "dr-first-last",
    "specialty": "obstetrics-gynecology",
    "phone": "010…",
    "address": "…",
    "city": "…",
    "self_booking_enabled": true,
    "whatsapp_enabled": true,
    "reports_enabled": true
  },
  "doctor": { "name": "د. …", "title": "استشاري …", "sex": "male" },
  "accounts": [
    { "name": "عيادة د. …", "email": "doctor@…", "main": true },
    { "name": "سكرتيرة 1 - د. …", "email": "…1@elayadah.com" }
  ],
  "schedule": {
    "saturday": [["17:00", "23:00"]],
    "thursday": [["13:00", "16:00"]]
  }
}
```

- `specialty` is a specialty slug; its visit types are created at price 0 for
  the doctor to set.
- Exactly one account is `main`: the doctor's own login, linked to the doctor,
  with reports access. The others are secretaries.
- A switch left out is off. A day left out of `schedule` is closed.

## The routine

```bash
F=storage/onboarding/dr-first-last.json
run() { ssh root@doctor1.srv1362420.hstgr.cloud "cd /docker/$1 && docker compose -f compose.prod.yml exec -T app php artisan clinic:onboard - $2" < "$F"; }

run doctor_1_staging --dry-run   # read the plan
run doctor_1_staging --force     # create on staging, check it there
run doctor_1 --dry-run           # production: read the plan again
run doctor_1 --force             # create on production; save the passwords
```

Staging and production each generate their own passwords; only the production
ones go to the clinic.
