<?php

/******************************************************************************
 * IndexNow Sitemap Submitter
 *
 * A simple password-protected PHP utility that:
 * - Reads every URL from sitemap.xml
 * - Submits them to the IndexNow API
 * - Records the last successful submission
 * - Displays the current sitemap URLs
 *
 * SETUP
 * 1. Upload your IndexNow key file to your website root.
 * 2. Edit the configuration values below.
 * 3. Upload this file as indexnow.php to your website root.
 * 4. Visit https://example.com/indexnow.php
 ******************************************************************************/

session_start();

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/******************************************************************************
 * CONFIGURATION
 ******************************************************************************/

$siteName = 'Example Website';

$host = 'example.com';

$key = 'YOUR_INDEXNOW_KEY';

$password = 'CHANGE_THIS_TO_A_LONG_RANDOM_PASSWORD';

/*
 * These paths assume indexnow.php, sitemap.xml and the IndexNow key file
 * all sit in the website root.
 */

$sitemapFile = __DIR__ . '/sitemap.xml';

$statusFile = __DIR__ . '/indexnow-status.json';

$keyLocation = 'https://' . $host . '/' . $key . '.txt';

$indexNowEndpoint = 'https://api.indexnow.org/IndexNow';

/******************************************************************************
 * HELPERS
 ******************************************************************************/

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function redirectToSelf(): void
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/indexnow.php', PHP_URL_PATH);

    if (!$path) {
        $path = '/indexnow.php';
    }

    header('Location: ' . $path);
    exit;
}

function loadStatus(string $statusFile): ?array
{
    if (!is_file($statusFile) || !is_readable($statusFile)) {
        return null;
    }

    $contents = file_get_contents($statusFile);

    if ($contents === false || $contents === '') {
        return null;
    }

    $status = json_decode($contents, true);

    return is_array($status) ? $status : null;
}

function saveStatus(string $statusFile, array $status): bool
{
    $json = json_encode(
        $status,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        return false;
    }

    return file_put_contents($statusFile, $json, LOCK_EX) !== false;
}

function readSitemapUrls(string $sitemapFile, string $host): array
{
    if (!is_file($sitemapFile)) {
        throw new RuntimeException('sitemap.xml was not found.');
    }

    if (!is_readable($sitemapFile)) {
        throw new RuntimeException('sitemap.xml could not be read.');
    }

    libxml_use_internal_errors(true);

    $xml = simplexml_load_file($sitemapFile);

    if ($xml === false) {
        $errors = libxml_get_errors();
        libxml_clear_errors();

        $message = 'sitemap.xml could not be parsed.';

        if (!empty($errors)) {
            $message .= ' ' . trim($errors[0]->message);
        }

        throw new RuntimeException($message);
    }

    $urls = [];

    /*
     * Supports a standard sitemap <urlset>.
     */

    if (isset($xml->url)) {
        foreach ($xml->url as $urlNode) {
            $loc = trim((string) $urlNode->loc);

            if ($loc !== '') {
                $urls[] = $loc;
            }
        }
    }

    /*
     * Handles sitemap namespaces where SimpleXML does not expose
     * the nodes directly.
     */

    if (empty($urls)) {
        $namespaces = $xml->getNamespaces(true);

        foreach ($namespaces as $namespace) {
            $children = $xml->children($namespace);

            foreach ($children->url as $urlNode) {
                $loc = trim((string) $urlNode->loc);

                if ($loc !== '') {
                    $urls[] = $loc;
                }
            }
        }
    }

    $urls = array_values(array_unique($urls));

    if (empty($urls)) {
        throw new RuntimeException('No URLs were found in sitemap.xml.');
    }

    /*
     * IndexNow expects every submitted URL to belong to the declared host.
     */

    foreach ($urls as $url) {
        $urlHost = parse_url($url, PHP_URL_HOST);

        if (!$urlHost || strcasecmp($urlHost, $host) !== 0) {
            throw new RuntimeException(
                'The sitemap contains a URL that does not match the configured host: ' .
                $url
            );
        }
    }

    return $urls;
}

function submitToIndexNow(
    string $endpoint,
    string $host,
    string $key,
    string $keyLocation,
    array $urls
): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is not available.');
    }

    $payload = [
        'host' => $host,
        'key' => $key,
        'keyLocation' => $keyLocation,
        'urlList' => $urls
    ];

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        throw new RuntimeException('The IndexNow request could not be encoded.');
    }

    $ch = curl_init($endpoint);

    if ($ch === false) {
        throw new RuntimeException('The cURL request could not be initialised.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json'
        ],
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false
    ]);

    $response = curl_exec($ch);

    $curlError = curl_error($ch);

    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException(
            $curlError !== ''
                ? 'cURL error: ' . $curlError
                : 'The IndexNow request failed.'
        );
    }

    return [
        'http_code' => $httpCode,
        'response' => trim((string) $response)
    ];
}

/******************************************************************************
 * LOGOUT
 ******************************************************************************/

if (isset($_GET['logout'])) {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $cookieParameters = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $cookieParameters['path'],
            $cookieParameters['domain'],
            $cookieParameters['secure'],
            $cookieParameters['httponly']
        );
    }

    session_destroy();

    redirectToSelf();
}

/******************************************************************************
 * LOGIN
 ******************************************************************************/

$loginError = '';

if (empty($_SESSION['indexnow_logged_in'])) {
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['login_password'])
    ) {
        $submittedPassword = (string) $_POST['login_password'];

        if (hash_equals($password, $submittedPassword)) {
            session_regenerate_id(true);

            $_SESSION['indexnow_logged_in'] = true;

            redirectToSelf();
        }

        $loginError = 'Incorrect password.';
    }

    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta
            name="viewport"
            content="width=device-width, initial-scale=1"
        >
        <meta name="robots" content="noindex, nofollow, noarchive">
        <title><?php echo escapeHtml($siteName); ?> IndexNow Login</title>

        <style>
            :root {
                color-scheme: light;
                font-family:
                    -apple-system,
                    BlinkMacSystemFont,
                    "Segoe UI",
                    sans-serif;
            }

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                padding: 2rem 1rem;
                background: #f3f4f6;
                color: #222;
            }

            main {
                width: min(100%, 26rem);
                margin: 5rem auto 0;
                padding: 2rem;
                background: #fff;
                border: 1px solid #d8d8d8;
                border-radius: 0.5rem;
                box-shadow: 0 0.75rem 2rem rgba(0, 0, 0, 0.08);
            }

            h1 {
                margin-top: 0;
            }

            label {
                display: block;
                margin-bottom: 0.4rem;
                font-weight: 600;
            }

            input {
                width: 100%;
                padding: 0.75rem;
                border: 1px solid #aaa;
                border-radius: 0.25rem;
                font: inherit;
            }

            button {
                margin-top: 1rem;
                padding: 0.75rem 1.25rem;
                border: 0;
                border-radius: 0.25rem;
                background: #1f5f8b;
                color: #fff;
                font: inherit;
                font-weight: 600;
                cursor: pointer;
            }

            button:hover,
            button:focus-visible {
                background: #17496b;
            }

            .error {
                padding: 0.75rem;
                background: #fdecec;
                color: #8a1616;
                border: 1px solid #e3aaaa;
                border-radius: 0.25rem;
            }
        </style>
    </head>

    <body>
    <main>
        <h1><?php echo escapeHtml($siteName); ?> IndexNow</h1>

        <?php if ($loginError !== ''): ?>
            <p class="error">
                <?php echo escapeHtml($loginError); ?>
            </p>
        <?php endif; ?>

        <form method="post" action="">
            <label for="login_password">Password</label>

            <input
                id="login_password"
                name="login_password"
                type="password"
                autocomplete="current-password"
                required
                autofocus
            >

            <button type="submit">Log in</button>
        </form>
    </main>
    </body>
    </html>
    <?php

    exit;
}

/******************************************************************************
 * READ SITEMAP AND STATUS
 ******************************************************************************/

$pageError = '';

try {
    $urls = readSitemapUrls($sitemapFile, $host);
} catch (Throwable $exception) {
    $urls = [];
    $pageError = $exception->getMessage();
}

$status = loadStatus($statusFile);

$resultType = '';
$resultTitle = '';
$resultMessage = '';
$resultResponse = '';

/******************************************************************************
 * SUBMIT SITEMAP
 ******************************************************************************/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['submit_indexnow']) &&
    $pageError === ''
) {
    try {
        $result = submitToIndexNow(
            $indexNowEndpoint,
            $host,
            $key,
            $keyLocation,
            $urls
        );

        $httpCode = $result['http_code'];
        $resultResponse = $result['response'];

        if ($httpCode === 200 || $httpCode === 202) {
            $status = [
                'last_submission' => date(DATE_ATOM),
                'urls_submitted' => count($urls),
                'http_code' => $httpCode
            ];

            $statusSaved = saveStatus($statusFile, $status);

            $resultType = 'success';
            $resultTitle = 'Submission successful';
            $resultMessage =
                count($urls) .
                ' URLs were accepted by IndexNow with HTTP ' .
                $httpCode .
                '.';

            if (!$statusSaved) {
                $resultMessage .=
                    ' The submission succeeded, but the status file could not be written.';
            }
        } else {
            $resultType = 'error';
            $resultTitle = 'Submission failed';
            $resultMessage =
                'IndexNow returned HTTP ' . $httpCode . '.';
        }
    } catch (Throwable $exception) {
        $resultType = 'error';
        $resultTitle = 'Submission failed';
        $resultMessage = $exception->getMessage();
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <meta name="robots" content="noindex, nofollow, noarchive">

    <title><?php echo escapeHtml($siteName); ?> IndexNow</title>

    <style>
        :root {
            color-scheme: light;
            font-family:
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 2rem 1rem;
            background: #f3f4f6;
            color: #222;
            line-height: 1.55;
        }

        main {
            width: min(100%, 62rem);
            margin: 0 auto;
        }

        header {
            display: flex;
            gap: 1rem;
            align-items: baseline;
            justify-content: space-between;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
        }

        h1,
        h2 {
            margin-top: 0;
        }

        a {
            color: #1f5f8b;
        }

        .panel {
            margin-bottom: 1.5rem;
            padding: 1.25rem;
            background: #fff;
            border: 1px solid #d8d8d8;
            border-radius: 0.5rem;
            box-shadow: 0 0.3rem 1rem rgba(0, 0, 0, 0.04);
        }

        .success {
            border-color: #78b88a;
            background: #eef9f1;
        }

        .error {
            border-color: #d99a9a;
            background: #fff0f0;
        }

        .status-grid {
            display: grid;
            grid-template-columns: repeat(
                auto-fit,
                minmax(10rem, 1fr)
            );
            gap: 1rem;
        }

        .status-item strong {
            display: block;
            margin-bottom: 0.2rem;
        }

        button {
            padding: 0.8rem 1.25rem;
            border: 0;
            border-radius: 0.25rem;
            background: #1f5f8b;
            color: #fff;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        button:hover,
        button:focus-visible {
            background: #17496b;
        }

        button:disabled {
            cursor: not-allowed;
            opacity: 0.55;
        }

        ul {
            margin: 0;
            padding-left: 1.4rem;
            columns: 2;
            column-gap: 2.5rem;
        }

        li {
            break-inside: avoid;
            margin-bottom: 0.45rem;
        }

        code,
        pre {
            font-family:
                ui-monospace,
                SFMono-Regular,
                Menlo,
                Consolas,
                monospace;
        }

        code {
            overflow-wrap: anywhere;
        }

        pre {
            overflow: auto;
            padding: 1rem;
            background: #f2f2f2;
            border-radius: 0.25rem;
            white-space: pre-wrap;
        }

        .muted {
            color: #666;
        }

        @media (max-width: 44rem) {
            ul {
                columns: 1;
            }
        }
    </style>
</head>

<body>
<main>
    <header>
        <h1><?php echo escapeHtml($siteName); ?> IndexNow</h1>

        <a href="?logout=1">Log out</a>
    </header>

    <?php if ($pageError !== ''): ?>
        <section class="panel error">
            <h2>Configuration error</h2>

            <p><?php echo escapeHtml($pageError); ?></p>
        </section>
    <?php endif; ?>

    <?php if ($resultMessage !== ''): ?>
        <section class="panel <?php echo escapeHtml($resultType); ?>">
            <h2><?php echo escapeHtml($resultTitle); ?></h2>

            <p><?php echo escapeHtml($resultMessage); ?></p>

            <?php if ($resultResponse !== ''): ?>
                <h3>API response</h3>

                <pre><?php echo escapeHtml($resultResponse); ?></pre>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="panel">
        <h2>Last successful submission</h2>

        <?php if (is_array($status)): ?>
            <div class="status-grid">
                <div class="status-item">
                    <strong>Date and time</strong>

                    <?php
                    $timestamp = $status['last_submission'] ?? '';

                    if ($timestamp !== '') {
                        echo escapeHtml(
                            date(
                                'j F Y, H:i:s',
                                strtotime($timestamp)
                            )
                        );
                    } else {
                        echo 'Unknown';
                    }
                    ?>
                </div>

                <div class="status-item">
                    <strong>URLs submitted</strong>

                    <?php
                    echo escapeHtml(
                        (string) ($status['urls_submitted'] ?? 'Unknown')
                    );
                    ?>
                </div>

                <div class="status-item">
                    <strong>HTTP response</strong>

                    <?php
                    echo escapeHtml(
                        (string) ($status['http_code'] ?? 'Unknown')
                    );
                    ?>
                </div>
            </div>
        <?php else: ?>
            <p>No successful submissions have been recorded yet.</p>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Submit sitemap</h2>

        <p>
            Current sitemap:
            <strong><?php echo count($urls); ?> URLs</strong>
        </p>

        <form method="post" action="">
            <input
                type="hidden"
                name="submit_indexnow"
                value="1"
            >

            <button
                type="submit"
                <?php echo $pageError !== '' ? 'disabled' : ''; ?>
            >
                Submit Sitemap to IndexNow
            </button>
        </form>

        <p class="muted">
            Every URL listed in sitemap.xml will be submitted.
        </p>
    </section>

    <section class="panel">
        <h2>URLs in sitemap.xml</h2>

        <?php if (!empty($urls)): ?>
            <ul>
                <?php foreach ($urls as $url): ?>
                    <li>
                        <code><?php echo escapeHtml($url); ?></code>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>No URLs are available.</p>
        <?php endif; ?>
    </section>
</main>
</body>
</html>