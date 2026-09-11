<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#00a884">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <title>@yield('title', 'Faheem Innovations')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/chat.css') }}">
    <style>
        body { font-family: 'Inter', sans-serif; }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.9rem;
            background: #050b17;
            color: #f8fafc;
            border-radius: 1rem;
            padding: 0.9rem 1.2rem;
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.18);
        }

        .brand-badge-mark {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 3.2rem;
            height: 3.2rem;
            border-radius: 0.9rem;
            background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
            color: #f8fafc;
            font-size: 1.3rem;
            font-weight: 800;
            letter-spacing: -0.08em;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.08);
        }

        .brand-badge-text {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            line-height: 1;
            font-weight: 800;
            letter-spacing: -0.04em;
        }

        .brand-badge-text span:first-child {
            font-size: 1.35rem;
            color: #f8fafc;
        }

        .brand-badge-text span:last-child {
            font-size: 1.15rem;
            color: #bfdbfe;
            margin-top: 0.18rem;
        }

        .brand-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            border-radius: 0.65rem;
            background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.08);
        }

        .brand-chip-mark {
            font-size: 0.8rem;
            font-weight: 800;
            color: white;
            letter-spacing: -0.12em;
        }
    </style>
</head>
<body class="h-screen antialiased">
    @yield('content')
</body>
</html>
