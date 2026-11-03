<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }} · XFunding Hub</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}">
</head>
<body>
<main class="auth--single">
    <section class="auth__card">
        <a class="brand auth__brand" href="{{ url('/') }}"><span class="brand-mark">X</span> XFunding<span class="brand-light">Hub</span></a>
        <p class="error-code">Error {{ $code }}</p>
        <h1>{{ $heading }}</h1>
        <p>{{ $text }}</p>
        <a class="btn btn--primary" href="{{ url('/') }}">Back to your workspace</a>
    </section>
</main>
</body>
</html>
