# WhatsApp alerts: codes not reaching patients

Two **UTILITY** templates, sent only to the platform's admins (the numbers set on
the «إعدادات النظام» page), never to patients:

1. `otp_alarm_started`: verification codes stopped reaching patients.
2. `otp_alarm_recovered`: they are reaching patients again.

**Status: not yet submitted to Meta.** Kept short on purpose: the only
variable is a time. The counts and clinics are in the alarm email. Keep the
names, the single `{{1}}` and the button.

A variable must not start or end the body; Meta rejects that.

---

## 1. `otp_alarm_started`

| Field | Value |
|---|---|
| Name | `otp_alarm_started` |
| Category | **Utility** |
| Language | Arabic (`ar`) |
| Header | none |
| Footer | none |
| Button | **Visit website**, static URL. Text: `إعدادات النظام`. URL: `https://elayadah.com/admin/system-settings` |

**Body**

```
⚠️ رسائل كود التأكيد مش بتوصل للعملاء من {{1}}.

راجع مزود الرسائل، أو اقفل طلب الكود من إعدادات النظام.
```

| Variable | Meaning | Sample for Meta |
|---|---|---|
| `{{1}}` | when the first untyped code was sent | `17/11 الساعة 08:15` |

---

## 2. `otp_alarm_recovered`

| Field | Value |
|---|---|
| Name | `otp_alarm_recovered` |
| Category | **Utility** |
| Language | Arabic (`ar`) |
| Header | none |
| Footer | none |
| Button | **Visit website**, static URL. Text: `إعدادات النظام`. URL: `https://elayadah.com/admin/system-settings` |

**Body**

```
✅ رسائل كود التأكيد رجعت توصل للعملاء من {{1}}.

لو قفلت طلب الكود، رجّعه من إعدادات النظام.
```

| Variable | Meaning | Sample for Meta |
|---|---|---|
| `{{1}}` | when a code was typed again | `17/11 الساعة 17:25` |

---

## Once Meta approves both

1. If the approved wording changed, update `body_ar` in
   `database/seeders/MessageTemplateSeeder.php`. That copy is only for reference;
   Meta sends its own approved text.
2. Add the admins' numbers in «إعدادات النظام» → «أرقام واتساب للتنبيه».

Nothing is sent before step 2, so an unapproved template is never tried.

## When an alert is sent

The check runs every 5 minutes. The alert goes out when, within the last 60
minutes, 5 or more codes went to 3 or more different numbers and nobody typed any.
Codes from the last 5 minutes are not counted yet. All four numbers can be changed
on the settings page.

After that:
- one message goes out when the problem starts, and nothing more while it lasts;
- one more goes out when the first code is typed again.

The same alert also goes out by email.
