@php
    $appName = config('app.name', 'Galileo');
    $year = now()->year;
    $iconPath = resource_path('images/galileo-icon.png');
    $icon = is_file($iconPath) ? 'data:image/png;base64,'.base64_encode(file_get_contents($iconPath)) : null;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Error') · {{ $appName }}</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #fafaf9;
            --bg-glow: rgba(247, 31, 61, 0.10);
            --card: #ffffff;
            --border: #e7e5e4;
            --text: #1c1917;
            --muted: #78716c;
            --primary: #f71f3d;
            --primary-dark: #d3112c;
            --ring: rgba(247, 31, 61, 0.18);
        }

        html.dark {
            color-scheme: dark;
            --bg: #1c1f1e;
            --bg-glow: rgba(247, 31, 61, 0.14);
            --card: #201f20;
            --border: rgba(255, 255, 255, 0.10);
            --text: #e9edef;
            --muted: #8696a0;
            --ring: rgba(247, 31, 61, 0.28);
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            margin: 0;
            display: grid;
            place-items: center;
            padding: 24px;
            background:
                radial-gradient(640px 420px at 50% -10%, var(--bg-glow), transparent 70%),
                var(--bg);
            color: var(--text);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto,
                "Helvetica Neue", Arial, "Noto Sans", sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .card {
            width: 100%;
            max-width: 460px;
            padding: 40px 32px 30px;
            text-align: center;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: 0 24px 60px -24px rgba(28, 25, 23, 0.28);
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 28px;
        }

        .brand img {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            box-shadow: 0 0 0 1px var(--border), 0 8px 20px -10px var(--ring);
        }

        .brand span {
            font-size: 15px;
            font-weight: 700;
            letter-spacing: -0.01em;
        }

        .code {
            margin-bottom: 14px;
            font-size: 72px;
            font-weight: 800;
            line-height: 1;
            letter-spacing: -0.04em;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        p {
            margin: 0;
            font-size: 15px;
            line-height: 1.6;
            color: var(--muted);
        }

        .hint {
            margin-top: 16px;
            font-size: 13px;
        }

        .btn {
            display: inline-block;
            margin-top: 26px;
            padding: 12px 22px;
            border-radius: 10px;
            background: var(--primary);
            color: #ffffff;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            box-shadow: 0 10px 24px -10px var(--ring);
            transition: background 0.15s ease, transform 0.15s ease;
        }

        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn:active {
            transform: translateY(0);
        }

        .foot {
            margin-top: 30px;
            padding-top: 18px;
            border-top: 1px solid var(--border);
            font-size: 11px;
            color: var(--muted);
        }

        @media (max-width: 420px) {
            .card {
                padding: 32px 22px 26px;
            }

            .code {
                font-size: 60px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .btn {
                transition: none;
            }
        }
    </style>
    <script>
        (function () {
            try {
                var theme = localStorage.getItem('theme');
                if (theme === 'dark' || (!theme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {
                /* Sin acceso a localStorage: se usa el tema del sistema. */
            }
        })();
    </script>
</head>
<body>
    <main class="card" role="main">
        <div class="brand">
            @if ($icon)
                <img src="{{ $icon }}" alt="{{ $appName }}">
            @endif
            <span>{{ $appName }}</span>
        </div>

        <div class="code">@yield('code', 'Error')</div>
        <h1>@yield('title', 'Ha ocurrido un error')</h1>
        <p>@yield('message', 'No se pudo completar la solicitud. Inténtalo de nuevo.')</p>

        @hasSection('hint')
            <p class="hint">@yield('hint')</p>
        @endif

        <a class="btn" href="@yield('actionUrl', url('/'))">@yield('action', 'Volver al inicio')</a>

        <div class="foot">{{ $appName }} © {{ $year }} · Todos los derechos reservados</div>
    </main>
</body>
</html>
