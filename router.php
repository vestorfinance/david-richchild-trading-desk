<?php
$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;

// Let the server handle real files (images, css, js, etc.)
if (is_file($file)) {
    return false;
}

// Rewrite extensionless URL -> .php
if (is_file($file . '.php')) {
    include $file . '.php';
    return true;
}

// Fallback 404
http_response_code(404);
echo '404 Not Found';
