<?php
/**
 * StockPulse WMS — Server & Database Configuration (Save & Load)
 *
 * Provides default Google Apps Script Web App URL and branding configuration,
 * plus server-side save/load support via config.override.json when hosted on PHP.
 */

// Default Google Apps Script Web App URL (replace with your deployed /exec URL)
define('DEFAULT_GOOGLE_APPS_SCRIPT_URL', 'https://script.google.com/macros/s/aaAKfycbx_DEFAULT_DEPLOYMENT_ID/exec');

// Optional default workspace branding settings
define('DEFAULT_APP_TITLE', 'StockPulse WMS');
define('DEFAULT_APP_LOGO_URL', '');
define('DEFAULT_APP_FAVICON_URL', '');

// Path to persisted override configuration file
define('STOCKPULSE_OVERRIDE_FILE', __DIR__ . DIRECTORY_SEPARATOR . 'config.override.json');

/**
 * Returns the base default configuration array.
 */
function stockpulse_default_config(): array
{
    $envUrl = getenv('GOOGLE_APPS_SCRIPT_URL');
    $scriptUrl = (is_string($envUrl) && trim($envUrl) !== '')
        ? trim($envUrl)
        : DEFAULT_GOOGLE_APPS_SCRIPT_URL;

    return [
        'googleAppsScriptUrl' => $scriptUrl,
        'branding' => [
            'title'   => DEFAULT_APP_TITLE,
            'logo'    => DEFAULT_APP_LOGO_URL,
            'favicon' => DEFAULT_APP_FAVICON_URL,
        ],
    ];
}

/**
 * Sanitizes and validates a URL string (allowing https://, http://, relative paths, or data:image/*).
 */
function stockpulse_sanitize_asset_url($value, string $fallback = ''): string
{
    if (!is_string($value)) {
        return $fallback;
    }
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }
    if (preg_match('#^(https?://|/)#i', $trimmed) || preg_match('#^data:image/(png|jpe?g|gif|webp|svg\+xml);base64,#i', $trimmed)) {
        return $trimmed;
    }
    return $fallback;
}

/**
 * Loads the resolved configuration, merging saved overrides from config.override.json
 * on top of defaults.
 */
function stockpulse_load_config(): array
{
    try {
        $base = stockpulse_default_config();

        if (is_file(STOCKPULSE_OVERRIDE_FILE) && is_readable(STOCKPULSE_OVERRIDE_FILE)) {
            $raw = file_get_contents(STOCKPULSE_OVERRIDE_FILE);
            if ($raw !== false && trim($raw) !== '') {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    if (isset($decoded['googleAppsScriptUrl']) && is_string($decoded['googleAppsScriptUrl'])) {
                        $candidateUrl = trim($decoded['googleAppsScriptUrl']);
                        if ($candidateUrl !== '') {
                            $base['googleAppsScriptUrl'] = $candidateUrl;
                        }
                    }
                    if (isset($decoded['branding']) && is_array($decoded['branding'])) {
                        if (isset($decoded['branding']['title']) && is_string($decoded['branding']['title']) && trim($decoded['branding']['title']) !== '') {
                            $base['branding']['title'] = trim($decoded['branding']['title']);
                        }
                        if (array_key_exists('logo', $decoded['branding'])) {
                            $base['branding']['logo'] = stockpulse_sanitize_asset_url($decoded['branding']['logo'], $base['branding']['logo']);
                        }
                        if (array_key_exists('favicon', $decoded['branding'])) {
                            $base['branding']['favicon'] = stockpulse_sanitize_asset_url($decoded['branding']['favicon'], $base['branding']['favicon']);
                        }
                    }
                }
            }
        }

        return [
            'status' => 'ok',
            'googleAppsScriptUrl' => $base['googleAppsScriptUrl'],
            'branding' => $base['branding'],
        ];
    } catch (Throwable $e) {
        $fallback = stockpulse_default_config();
        return [
            'status' => 'error',
            'googleAppsScriptUrl' => $fallback['googleAppsScriptUrl'],
            'branding' => $fallback['branding'],
            'message' => $e->getMessage(),
        ];
    }
}

/**
 * Backward-compatible alias for loading configuration.
 */
function stockpulse_get_config(): array
{
    return stockpulse_load_config();
}

/**
 * Saves configuration overrides to config.override.json using an exclusive file lock.
 */
function stockpulse_save_config(array $input): array
{
    try {
        $current = stockpulse_load_config();
        $scriptUrl = $current['googleAppsScriptUrl'];
        $branding = $current['branding'];

        if (array_key_exists('googleAppsScriptUrl', $input)) {
            $rawUrl = is_string($input['googleAppsScriptUrl']) ? trim($input['googleAppsScriptUrl']) : '';
            $scriptUrl = $rawUrl !== '' ? $rawUrl : DEFAULT_GOOGLE_APPS_SCRIPT_URL;
        }

        if (isset($input['branding']) && is_array($input['branding'])) {
            if (array_key_exists('title', $input['branding'])) {
                $rawTitle = is_string($input['branding']['title']) ? trim($input['branding']['title']) : '';
                $branding['title'] = $rawTitle !== '' ? $rawTitle : DEFAULT_APP_TITLE;
            }
            if (array_key_exists('logo', $input['branding'])) {
                $branding['logo'] = stockpulse_sanitize_asset_url($input['branding']['logo'], '');
            }
            if (array_key_exists('favicon', $input['branding'])) {
                $branding['favicon'] = stockpulse_sanitize_asset_url($input['branding']['favicon'], '');
            }
        }

        $toSave = [
            'googleAppsScriptUrl' => $scriptUrl,
            'branding' => $branding,
            'updatedAt' => gmdate('c'),
        ];

        $encoded = json_encode($toSave, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode configuration as JSON.');
        }

        $written = file_put_contents(STOCKPULSE_OVERRIDE_FILE, $encoded . PHP_EOL, LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('Unable to write config.override.json. Check directory write permissions.');
        }

        return [
            'status' => 'saved',
            'googleAppsScriptUrl' => $scriptUrl,
            'branding' => $branding,
            'updatedAt' => $toSave['updatedAt'],
        ];
    } catch (Throwable $e) {
        return [
            'status' => 'error',
            'message' => $e->getMessage(),
        ];
    }
}

// Handle POST requests to save configuration (e.g., POST config.php or POST config.php?action=save)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $rawBody = file_get_contents('php://input');
    $payload = [];
    if (is_string($rawBody) && trim($rawBody) !== '') {
        $parsed = json_decode($rawBody, true);
        if (is_array($parsed)) {
            $payload = $parsed;
        }
    } elseif (!empty($_POST)) {
        $payload = $_POST;
    }

    $result = stockpulse_save_config($payload);
    if (($result['status'] ?? '') === 'error') {
        http_response_code(500);
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Serve JSON when requested directly (e.g., config.php?json=1 or config.php?action=load)
if ((isset($_GET['json']) && $_GET['json'] === '1') || (isset($_GET['action']) && $_GET['action'] === 'load')) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode(stockpulse_load_config(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}