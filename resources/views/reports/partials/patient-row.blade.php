<tr class="rp-patientrow">
    <td>{{ $patient['name'] }}</td>
    <td>@if ($patient['phone'])<a href="tel:{{ $patient['phone'] }}"><bdi>{{ $patient['phone'] }}</bdi></a>@endif</td>
    <td>{{ $patient['visit_type'] }}</td>
</tr>
