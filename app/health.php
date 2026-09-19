<?php
/**
 * health.php: container /health probe for the wanportal service catalog
 * sidecar. Static on purpose: it never touches the SQLite database or
 * the portal session API, so the compose healthcheck reports only
 * "Apache is up and serving this tree".
 *
 * Reachable two ways:
 *   - GET /health inside the container (Alias in the Dockerfile, used
 *     by the docker-compose healthcheck)
 *   - GET /catalog/health.php through the wanportal proxy
 */

http_response_code(200);
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['status' => 'ok']);