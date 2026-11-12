<!DOCTYPE html>
{{-- Shown by the service worker when a page of FreeScout cannot load (no network). Kept in the browser's cache when the
     worker installs: it must stand alone, without FreeScout's layout, stylesheets or scripts. --}}
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#ffffff">
    <title>{{ $app_name }}</title>
    <style>
        html, body { height: 100%; margin: 0; }
        body { display: flex; align-items: center; justify-content: center; background: #f8fafc; color: #1e293b;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        .box { max-width: 320px; padding: 24px; text-align: center; }
        h1 { font-size: 20px; font-weight: 600; margin: 0 0 8px; }
        p { font-size: 15px; line-height: 1.5; color: #475569; margin: 0 0 20px; }
        a { display: inline-block; padding: 10px 20px; border-radius: 6px; background: #4f46e5; color: #fff;
            text-decoration: none; font-weight: 500; }
    </style>
</head>
<body>
    <div class="box">
        <h1>{{ __('webpush::messages.offline_title') }}</h1>
        <p>{{ __('webpush::messages.offline_text') }}</p>
        <a href="">{{ __('webpush::messages.offline_retry') }}</a>
    </div>
    <script>
        // back online: the page the agent wanted loads by itself
        window.addEventListener('online', function () { window.location.reload(); });
    </script>
</body>
</html>
