<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Demo Partner | Jenang Gemi Partner</title>
    <style>
        :root {
            color-scheme: light;
            --page-bg: #f4efe4;
            --card-bg: rgba(255, 252, 247, 0.94);
            --text-main: #1f241c;
            --text-muted: #5d6656;
            --accent: #6f8f31;
            --accent-soft: #dce8b5;
            --border: rgba(31, 36, 28, 0.08);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: "Plus Jakarta Sans", Arial, sans-serif;
            color: var(--text-main);
            background:
                radial-gradient(circle at top left, rgba(220, 232, 181, 0.95), transparent 32%),
                radial-gradient(circle at bottom right, rgba(111, 143, 49, 0.18), transparent 28%),
                var(--page-bg);
        }

        .partner-page {
            width: min(720px, 100%);
            padding: 32px;
            border: 1px solid var(--border);
            border-radius: 28px;
            background: var(--card-bg);
            box-shadow: 0 24px 60px rgba(31, 36, 28, 0.12);
        }

        .partner-kicker {
            display: inline-flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--accent-soft);
            color: var(--accent);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        h1 {
            margin: 18px 0 12px;
            font-size: clamp(2rem, 5vw, 3.5rem);
            line-height: 1;
        }

        p {
            margin: 0;
            font-size: 1rem;
            line-height: 1.7;
            color: var(--text-muted);
        }

        .partner-code {
            margin-top: 24px;
            font-size: 0.95rem;
            color: var(--text-main);
            font-weight: 700;
        }
    </style>
</head>
<body>
    <main class="partner-page">
        <span class="partner-kicker">Partner Homepage</span>
        <h1>Demo Partner</h1>
        <p>This feature is under construction.</p>
        <p class="partner-code">Partner code: partner-001-demo-partner</p>
    </main>
</body>
</html>
