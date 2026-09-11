<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Return to TotalLog</title>
    <style>
        :root { color-scheme: light dark; font-family: system-ui, sans-serif; }
        body { margin: 0; padding: 24px; min-height: 80vh; display: grid; place-items: center; background: Canvas; color: CanvasText; }
        main { max-width: 380px; text-align: center; line-height: 1.6; }
        h1 { line-height: 1.2; }
        a { display: block; padding: 14px 24px; margin: 28px 0; border-radius: 14px; background: #4f46e5; color: white; text-decoration: none; font-weight: 600; }
        a[aria-disabled="true"] { background: #777; pointer-events: none; }
        p { opacity: .8; }
    </style>
</head>
<body>
<main>
    <h1>You’re signed in</h1>
    <p>Continue as {{ $accountName }} in the TotalLog iPhone app.</p>
    <a id="return-to-app" href="{{ $callbackURL }}">Open TotalLog</a>
    <p id="sign-in-status" role="status">Tap Open TotalLog when you’re ready. If you cancel the browser’s prompt, this page will stay open.</p>
    <p>You can close this tab after returning to the app.</p>
</main>
<script>
    const statusURL = @json($statusURL);
    const link = document.getElementById('return-to-app');
    async function checkStatus() {
        try {
            const response = await fetch(statusURL, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) return;
            const status = await response.json();
            if (!status.active) {
                link.removeAttribute('href');
                link.setAttribute('aria-disabled', 'true');
                document.getElementById('sign-in-status').textContent = 'This sign-in has finished, expired, or been cancelled. Return to the app to start a new sign-in if needed.';
                clearInterval(timer);
            }
        } catch (_) { /* Leave the manual link usable during a temporary network failure. */ }
    }
    const timer = setInterval(checkStatus, 3000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) checkStatus(); });
    // Deliberately no automatic redirect: the user controls the browser-to-app handoff.
</script>
</body>
</html>
