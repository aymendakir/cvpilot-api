<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Microsoft email connection · CVPilot</title></head>
<body style="font:16px/1.6 system-ui,sans-serif;background:#091727;color:#eaf2ff;margin:0;padding:48px 24px">
<main style="max-width:600px;margin:auto">
<h1>{{ $success ? 'Microsoft connected' : 'Microsoft connection incomplete' }}</h1>
<p>{{ $message }}</p>
@if ($returnUrl)<a href="{{ $returnUrl }}" style="color:#7dd3fc">Return to SMTP settings</a>@endif
</main>
</body>
</html>
