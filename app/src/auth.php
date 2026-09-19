<?php
/**
 * src/auth.php: bearer token validation against the wanportal session
 * API for the catalog sidecar.
 *
 * Auth model per SPEC: there is no app-level password. Mutating calls
 * carry the wanportal JWT in the Authorization header; the token is
 * validated live with GET http://wanportal/cgi-bin/api/session and any
 * signed-in user is allowed. is_admin is not required. The token is
 * never stored in the sqlite file and never logged here.
 *
 * Honesty rule (mirrors the SPA): "signed out" (401 / no token) and
 * "portal unavailable" (transport failure, 5xx, bad payload) stay
 * separate. A dead portal API is never reported as signed out.
 */

if (defined('CATALOG_AUTH_LOADED')) {
    return;
}
define('CATALOG_AUTH_LOADED', '1');

/** Session endpoint used to validate bearer tokens (overridable for tests). */
function catalog_session_url(): string
{
    $env = getenv('CATALOG_SESSION_URL');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    return 'http://wanportal/cgi-bin/api/session';
}

/** The Bearer token from the Authorization header, or null. */
function catalog_bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(\S+)$/i', (string)$header, $m)) {
        return trim($m[1]);
    }
    return null;
}

/**
 * Validate a bearer token live against the wanportal session API.
 * Returns ['state' => 'ok'|'signed-out'|'unavailable', 'username' => ?string].
 */
function catalog_session_check(?string $token): array
{
    if ($token === null || $token === '') {
        return ['state' => 'signed-out', 'username' => null];
    }
    $ch = curl_init(catalog_session_url());
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code === 0) {
        error_log('catalog auth: session check failed: ' . $err);
        return ['state' => 'unavailable', 'username' => null];
    }
    if ($code === 401) {
        return ['state' => 'signed-out', 'username' => null];
    }
    if ($code < 200 || $code >= 300) {
        return ['state' => 'unavailable', 'username' => null];
    }
    $claims = json_decode((string)$body, true);
    if (!is_array($claims) || ($claims['status'] ?? null) !== 'success') {
        return ['state' => 'unavailable', 'username' => null];
    }
    return ['state' => 'ok', 'username' => (string)($claims['username'] ?? '')];
}

/**
 * Gate for mutating requests: on success returns the username; on
 * failure sends the JSON error and exits (401 signed-out, 503 portal
 * unreachable, so callers can tell the two apart).
 */
function catalog_require_writer(): string
{
    $res = catalog_session_check(catalog_bearer_token());
    if ($res['state'] === 'ok') {
        return $res['username'];
    }
    http_response_code($res['state'] === 'signed-out' ? 401 : 503);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => false,
        'error' => $res['state'] === 'signed-out'
            ? 'Sign in on the portal to make changes.'
            : 'Portal session unavailable; changes are disabled (not the same as signed out).',
        'reason' => $res['state'],
    ]);
    exit;
}