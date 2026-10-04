<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#F6F5F1" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#111317" media="(prefers-color-scheme: dark)">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Saku">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/icon-192.png">

    {{-- Tema dipasang sebelum render agar tidak berkedip. --}}
    <script>
        (function () {
            var pref = 'system';
            try { pref = localStorage.getItem('saku:theme') || 'system'; } catch (e) {}
            var dark = pref === 'dark' || (pref === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.dataset.theme = dark ? 'dark' : 'light';
        })();
    </script>

    @routes
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
