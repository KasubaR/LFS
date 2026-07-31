@php
/**
 * LFS COOKIE BANNER PARTIAL — partials/cookie-banner.blade.php
 */
$consentName = config('cookies.names.consent', 'lfs_consent');
@endphp
@if(empty($_COOKIE[$consentName]))
<!-- Cookie banner placeholder — implement consent logic here -->
@endif
