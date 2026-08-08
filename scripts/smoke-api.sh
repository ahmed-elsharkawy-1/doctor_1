#!/usr/bin/env bash
# End-to-end smoke test of the v1 API against the real MySQL database.
set -u

BASE="http://127.0.0.1:8123/api/v1"
H_JSON='-H Accept:application/json -H Content-Type:application/json -H Accept-Language:ar'

pass=0; fail=0

check() { # check <label> <expected_http> <actual_http> <body>
  if [ "$2" = "$3" ]; then
    echo "  PASS  $1  ($3)"
    pass=$((pass+1))
  else
    echo "  FAIL  $1  expected $2 got $3"
    echo "        $(echo "$4" | head -c 300)"
    fail=$((fail+1))
  fi
}

call() { # call <method> <path> <token> [json]
  local method="$1" path="$2" token="$3" data="${4:-}"
  local auth=()
  [ -n "$token" ] && auth=(-H "Authorization: Bearer $token")
  if [ -n "$data" ]; then
    curl -s -w '\n%{http_code}' -X "$method" $H_JSON "${auth[@]}" -d "$data" "$BASE$path"
  else
    curl -s -w '\n%{http_code}' -X "$method" $H_JSON "${auth[@]}" "$BASE$path"
  fi
}

body()  { echo "$1" | head -n -1; }
code()  { echo "$1" | tail -n 1; }
jqv()   { echo "$1" | python3 -c "import sys,json;d=json.load(sys.stdin);
import functools
p='$2'.split('.')
v=d
for k in p:
    v = v[int(k)] if k.isdigit() else v.get(k) if isinstance(v,dict) else None
    if v is None: break
print(v if v is not None else '')" 2>/dev/null; }

echo "=== 1. AUTH ==="
r=$(call POST /auth/login "" '{"email":"nour@doctor1.test","password":"password","device_name":"smoke"}')
check "secretary logs in" 200 "$(code "$r")" "$(body "$r")"
SEC_TOKEN=$(jqv "$(body "$r")" "data.token")
echo "        role=$(jqv "$(body "$r")" "data.user.role.display")  clinic=$(jqv "$(body "$r")" "data.clinic.name")"

r=$(call POST /auth/login "" '{"email":"doctor@doctor1.test","password":"password"}')
check "owner logs in" 200 "$(code "$r")" "$(body "$r")"
OWNER_TOKEN=$(jqv "$(body "$r")" "data.token")

r=$(call POST /auth/login "" '{"email":"nour@doctor1.test","password":"wrong"}')
check "wrong password -> 401" 401 "$(code "$r")" "$(body "$r")"
echo "        code=$(jqv "$(body "$r")" "error.code")"

r=$(call GET /auth/me "")
check "no token -> 401" 401 "$(code "$r")" "$(body "$r")"

echo
echo "=== 2. BOOTSTRAP ==="
r=$(call GET /bootstrap "$SEC_TOKEN")
check "bootstrap" 200 "$(code "$r")" "$(body "$r")"
echo "        clinic=$(jqv "$(body "$r")" "data.clinic.name")  window=$(jqv "$(body "$r")" "data.clinic.booking_window_days")d"
echo "        secretary sees price key: $(echo "$(body "$r")" | python3 -c "import sys,json;d=json.load(sys.stdin);print('price' in d['data']['visit_types'][0])")"

r=$(call GET /bootstrap "$OWNER_TOKEN")
echo "        owner sees price key:     $(echo "$(body "$r")" | python3 -c "import sys,json;d=json.load(sys.stdin);print('price' in d['data']['visit_types'][0])")"
VT_CHECKUP=$(echo "$(body "$r")" | python3 -c "import sys,json;d=json.load(sys.stdin)
print(next(v['id'] for v in d['data']['visit_types'] if v['is_new_patient_type']))")
VT_FOLLOWUP=$(echo "$(body "$r")" | python3 -c "import sys,json;d=json.load(sys.stdin)
print(next(v['id'] for v in d['data']['visit_types'] if not v['is_new_patient_type']))")
echo "        checkup=$VT_CHECKUP  followup=$VT_FOLLOWUP"

echo
echo "=== 3. DAYS & SLOTS ==="
r=$(call GET /booking-days "$SEC_TOKEN")
check "booking days" 200 "$(code "$r")" "$(body "$r")"
echo "$(body "$r")" | python3 -c "
import sys,json
d=json.load(sys.stdin)['data']['days']
for x in d[:7]:
    print('        %s %-10s open=%-5s holiday=%-5s pending=%s' % (x['date']['value'], x['day_of_week']['display'], x['is_open'], x['is_holiday'], x['pending_count']))"

TODAY=$(date +%F)
r=$(call GET "/slots?date=$TODAY&visit_type_id=$VT_CHECKUP" "$SEC_TOKEN")
check "slots today (checkup)" 200 "$(code "$r")" "$(body "$r")"
echo "$(body "$r")" | python3 -c "
import sys,json
d=json.load(sys.stdin)['data']
print('        open=%s  available=%d/%d' % (d['is_open'], d['available_count'], len(d['slots'])))
taken=[s['start_time']['value'] for s in d['slots'] if not s['is_available']]
print('        taken: %s' % (', '.join(taken) if taken else 'none'))"

FREE=$(echo "$(body "$r")" | python3 -c "
import sys,json
d=json.load(sys.stdin)['data']['slots']
print(next((s['start_time']['value'] for s in d if s['is_available']), ''))")
echo "        first free slot: $FREE"

echo
echo "=== 4. PATIENT LOOKUP ==="
r=$(call POST /patients/lookup "$SEC_TOKEN" "{\"phone\":\"01012225521\",\"visit_type_id\":$VT_CHECKUP}")
check "known phone, new-concern type" 200 "$(code "$r")" "$(body "$r")"
echo "        found=$(jqv "$(body "$r")" "data.found") code=$(jqv "$(body "$r")" "data.patient.code") returning=$(jqv "$(body "$r")" "data.is_returning") mismatch=$(jqv "$(body "$r")" "data.visit_type_mismatch")"

r=$(call POST /patients/lookup "$SEC_TOKEN" "{\"phone\":\"01012225521\",\"visit_type_id\":$VT_FOLLOWUP}")
echo "        follow-up mismatch=$(jqv "$(body "$r")" "data.visit_type_mismatch")  (expected False)"

r=$(call POST /patients/lookup "$SEC_TOKEN" '{"phone":"+20 101 222 5521"}')
echo "        same number typed differently -> found=$(jqv "$(body "$r")" "data.found")"

r=$(call POST /patients/lookup "$SEC_TOKEN" '{"phone":"01099998888"}')
echo "        unknown number -> found=$(jqv "$(body "$r")" "data.found")"

r=$(call POST /patients/lookup "$SEC_TOKEN" '{"phone":"123"}')
check "invalid phone -> 422" 422 "$(code "$r")" "$(body "$r")"

echo
echo "=== 5. BOOKING ==="
r=$(call POST /bookings "$SEC_TOKEN" "{\"patient_name\":\"ملك عمرو\",\"phone\":\"01277776666\",\"visit_type_id\":$VT_CHECKUP,\"date\":\"$TODAY\",\"start_time\":\"$FREE\"}")
check "create booking" 201 "$(code "$r")" "$(body "$r")"
NEW_ID=$(jqv "$(body "$r")" "data.id")
echo "        id=$NEW_ID code=$(jqv "$(body "$r")" "data.patient.code") $(jqv "$(body "$r")" "data.start_time.display") -> $(jqv "$(body "$r")" "data.end_time.display")"
echo "        secretary sees price: $(echo "$(body "$r")" | python3 -c "import sys,json;print('price' in json.load(sys.stdin)['data'])")"

r=$(call POST /bookings "$SEC_TOKEN" "{\"patient_name\":\"تجربة\",\"phone\":\"01266665555\",\"visit_type_id\":$VT_CHECKUP,\"date\":\"$TODAY\",\"start_time\":\"$FREE\"}")
check "double-book same slot -> 409" 409 "$(code "$r")" "$(body "$r")"
echo "        code=$(jqv "$(body "$r")" "error.code")"

r=$(call POST /bookings "$SEC_TOKEN" "{\"patient_name\":\"تجربة\",\"phone\":\"01266665555\",\"visit_type_id\":$VT_CHECKUP,\"date\":\"$TODAY\",\"start_time\":\"$FREE\",\"force\":true}")
check "force overrides -> 201" 201 "$(code "$r")" "$(body "$r")"
FORCED_ID=$(jqv "$(body "$r")" "data.id")
echo "        overbooked=$(jqv "$(body "$r")" "data.is_overbooked")"

r=$(call POST /bookings "$SEC_TOKEN" "{\"patient_name\":\"تجربة\",\"phone\":\"01266664444\",\"visit_type_id\":$VT_CHECKUP,\"date\":\"$TODAY\",\"start_time\":\"03:00\"}")
check "outside working hours -> 409" 409 "$(code "$r")" "$(body "$r")"
echo "        code=$(jqv "$(body "$r")" "error.code")"

FAR=$(date -d "+40 days" +%F)
r=$(call POST /bookings "$SEC_TOKEN" "{\"patient_name\":\"تجربة\",\"phone\":\"01266663333\",\"visit_type_id\":$VT_CHECKUP,\"date\":\"$FAR\",\"start_time\":\"10:00\"}")
check "beyond booking window -> 409" 409 "$(code "$r")" "$(body "$r")"
echo "        code=$(jqv "$(body "$r")" "error.code")"

echo
echo "=== 6. QUEUE ==="
r=$(call GET /queue "$SEC_TOKEN")
check "queue" 200 "$(code "$r")" "$(body "$r")"
echo "$(body "$r")" | python3 -c "
import sys,json
d=json.load(sys.stdin)['data']
print('        counts: %s' % d['counts'])
print('        awaiting rebooking: %s' % d['awaiting_rebooking_count'])
for i in d['items']:
    pos = i['queue_position'] if i['queue_position'] is not None else '-'
    print('        [%s] %-12s %-16s %s' % (pos, i['status']['value'], i['patient']['name'], ','.join(i['available_actions'])))"

echo
echo "=== 7. TRANSITIONS ==="
r=$(call POST "/bookings/$NEW_ID/call-in" "$SEC_TOKEN" '{}')
check "skip a step -> 409" 409 "$(code "$r")" "$(body "$r")"
echo "        code=$(jqv "$(body "$r")" "error.code") expected=$(jqv "$(body "$r")" "error.details.expected")"

r=$(call POST "/bookings/$NEW_ID/arrive" "$SEC_TOKEN" '{}')
check "arrive" 200 "$(code "$r")" "$(body "$r")"
echo "        status=$(jqv "$(body "$r")" "data.status.display") arrived_at=$(jqv "$(body "$r")" "data.arrived_at")"

r=$(call POST "/bookings/$NEW_ID/call-in" "$SEC_TOKEN" '{}')
check "call in" 200 "$(code "$r")" "$(body "$r")"

r=$(call POST "/bookings/$NEW_ID/cancel" "$SEC_TOKEN" '{"reason":"no_show"}')
check "cancel while with doctor -> 400" 400 "$(code "$r")" "$(body "$r")"
echo "        code=$(jqv "$(body "$r")" "error.code")"

r=$(call POST "/bookings/$NEW_ID/complete" "$SEC_TOKEN" '{}')
check "complete" 200 "$(code "$r")" "$(body "$r")"

r=$(call POST "/bookings/$FORCED_ID/cancel" "$SEC_TOKEN" '{"reason":"emergency"}')
check "system-only reason -> 422" 422 "$(code "$r")" "$(body "$r")"

r=$(call POST "/bookings/$FORCED_ID/cancel" "$SEC_TOKEN" '{"reason":"no_show"}')
check "no-show" 200 "$(code "$r")" "$(body "$r")"

echo
echo "=== 8. PATIENTS ==="
QNAME=$(python3 -c "import urllib.parse;print(urllib.parse.quote('سارة'))")
r=$(call GET "/patients?q=$QNAME" "$SEC_TOKEN")
check "search by name" 200 "$(code "$r")" "$(body "$r")"
echo "$(body "$r")" | python3 -c "
import sys,json
d=json.load(sys.stdin)['data']
for i in d['items'][:3]:
    print('        %-10s %-16s %-12s visits=%s' % (i['code'], i['name'], i['phone']['display'], i['visits_count']))"

r=$(call GET "/patients?q=5521" "$SEC_TOKEN")
echo "        by phone tail -> $(echo "$(body "$r")" | python3 -c "import sys,json;d=json.load(sys.stdin)['data']['items'];print(d[0]['code'] if d else 'none')")"

PID=$(echo "$(body "$r")" | python3 -c "import sys,json;d=json.load(sys.stdin)['data']['items'];print(d[0]['id'] if d else '')")
r=$(call GET "/patients/$PID" "$OWNER_TOKEN")
check "patient file" 200 "$(code "$r")" "$(body "$r")"
echo "$(body "$r")" | python3 -c "
import sys,json
d=json.load(sys.stdin)['data']
print('        %s (%s) phone=%s' % (d['patient']['name'], d['patient']['code'], d['patient']['phone']['display']))
print('        summary: %s' % d['summary'])
for v in d['visits'][:4]:
    print('        %s %-12s %-10s %s' % (v['date']['value'], v['visit_type']['name'], v['status']['value'], v.get('price',{}).get('display','-')))"

echo
echo "=== 9. POSTPONE & CALL LIST ==="
r=$(call GET /rebooking-list "$SEC_TOKEN")
check "rebooking list" 200 "$(code "$r")" "$(body "$r")"
echo "$(body "$r")" | python3 -c "
import sys,json
d=json.load(sys.stdin)['data']['items']
for i in d:
    print('        %-16s %-12s was %s %s  contacted=%s' % (i['patient']['name'], i['patient']['phone']['display'], i['original_date']['value'], i['original_start_time']['value'], i['contacted']))"

RB_ID=$(echo "$(body "$r")" | python3 -c "import sys,json;d=json.load(sys.stdin)['data']['items'];print(d[0]['booking_id'] if d else '')")
r=$(call POST "/bookings/$RB_ID/contacted" "$SEC_TOKEN" '{}')
check "mark contacted" 200 "$(code "$r")" "$(body "$r")"

r=$(call GET /postpone/candidates "$SEC_TOKEN")
check "postpone candidates" 200 "$(code "$r")" "$(body "$r")"
echo "        candidates=$(echo "$(body "$r")" | python3 -c "import sys,json;print(len(json.load(sys.stdin)['data']['items']))")"

echo
echo "=== 10. AUTHORIZATION ==="
r=$(call PUT /settings/general "$SEC_TOKEN" '{"booking_window_days":10}')
check "secretary may edit settings" 200 "$(code "$r")" "$(body "$r")"
r=$(call PUT /settings/general "$OWNER_TOKEN" '{"booking_window_days":7}')
check "owner restores setting" 200 "$(code "$r")" "$(body "$r")"

sleep 61  # login throttle is 10/min and this script has used them
r=$(call POST /auth/login "" '{"email":"admin@doctor1.test","password":"password"}')
check "super admin blocked from app" 403 "$(code "$r")" "$(body "$r")"
echo "        code=$(jqv "$(body "$r")" "error.code")"

r=$(call GET /bookings/999999 "$SEC_TOKEN")
check "unknown booking -> 404" 404 "$(code "$r")" "$(body "$r")"

r=$(call GET /nope "$SEC_TOKEN")
check "unknown route -> envelope 404" 404 "$(code "$r")" "$(body "$r")"
echo "        code=$(jqv "$(body "$r")" "error.code")"

echo
echo "=== 11. LOCALE ==="
r=$(curl -s -H Accept:application/json -H "Accept-Language: en" -H "Authorization: Bearer $SEC_TOKEN" "$BASE/queue")
echo "        en: $(echo "$r" | python3 -c "import sys,json;print(json.load(sys.stdin)['message'])")"
r=$(curl -s -H Accept:application/json -H "Accept-Language: ar" -H "Authorization: Bearer $SEC_TOKEN" "$BASE/queue")
echo "        ar: $(echo "$r" | python3 -c "import sys,json;print(json.load(sys.stdin)['message'])")"

echo
echo "======================================"
echo "  passed: $pass   failed: $fail"
echo "======================================"
exit $([ "$fail" -eq 0 ] && echo 0 || echo 1)
