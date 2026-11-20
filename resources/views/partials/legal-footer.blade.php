{{-- The operating company's legal line and contact details, in Arabic and
     English on every locale. Meta Business Verification reads it from the
     server-rendered HTML, so it stays plain text. Expects $brand. --}}
@php
    $legal = config('clinic.legal');
    $year = now()->year;
@endphp
<div class="legal">
    <p lang="ar" dir="rtl">© {{ $year }} {{ $brand }} (<bdi lang="en">{{ $legal['brand_latin'] }}</bdi>) — تشغيل وإدارة <bdi lang="en">{{ $legal['name'] }}</bdi> ({{ $legal['name_ar'] }})</p>
    <p lang="en" dir="ltr">© {{ $year }} {{ $legal['brand_latin'] }} — operated by {{ $legal['name'] }}</p>
    <p><bdi dir="ltr">{{ $legal['email'] }}</bdi> · <bdi dir="ltr">{{ $legal['phone'] }}</bdi></p>
</div>
