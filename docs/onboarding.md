# Adding a new clinic

`php artisan clinic:onboard` creates a real clinic from one JSON description:
the clinic, its doctor, the logins (one doctor login plus any secretaries) and
a starting week. The same file is run on staging first, checked, then run on
production, so both get exactly the same clinic.

It only ever creates. An existing slug, a slug another clinic gave up, an email
or phone already in use, or impossible hours are refused, and a refusal writes
nothing. An account's password can be set in the file; one left out is
generated. Either way all of them are printed once at the end.

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
  "doctor": {
    "name": "د. …", "title": "استشاري …", "sex": "male",
    "bio": "…",
    "treatment_areas": [
      { "title": "متابعة الحمل والولادة", "icon": "baby", "description": "…" }
    ]
  },
  "accounts": [
    { "name": "عيادة د. …", "email": "doctor@…", "main": true, "password": "…" },
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
- `bio` and `treatment_areas` fill the "نظرة عامة" tab of the public page;
  without them it is empty. Icons are the keys in `clinic.public.icons`.
- The photo sits beside the file as `<slug>.jpg` (JPEG, PNG or WebP; shown as a
  116 px square, so crop to head and shoulders). It is embedded into the
  description when the command runs — see below.

## The routine

```bash
F=storage/onboarding/dr-first-last
H=root@doctor1.srv1362420.hstgr.cloud

# The description, with the photo embedded when there is one, copied to the
# server. Copied rather than piped: a description with a photo is ~75 KB, and
# piping that through ssh into `docker compose exec` has hung.
if [ -f "$F.jpg" ]; then jq '.doctor.photo = $p' --arg p "$(base64 -w0 "$F.jpg")" "$F.json"; else cat "$F.json"; fi > /tmp/onboard.json
scp -q /tmp/onboard.json $H:/root/onboard.json && rm /tmp/onboard.json

run() { ssh $H "cd /docker/$1 && docker compose -f compose.prod.yml exec -T app php artisan clinic:onboard - $2 < /root/onboard.json"; }

run doctor_1_staging --dry-run   # read the plan
run doctor_1_staging --force     # create on staging, check it there
run doctor_1 --dry-run           # production: read the plan again
run doctor_1 --force             # create on production; save the passwords

ssh $H rm /root/onboard.json
```

Generated passwords differ between staging and production; only the production
ones go to the clinic. Passwords set in the file are the same on both.
