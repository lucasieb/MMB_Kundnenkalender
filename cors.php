<?php

if (!function_exists('mmb_admin_allowed_origins')) {
    function mmb_admin_allowed_origins(): array
    {
        $origins = [
            'https://www.mietmichbox.de',
            'https://mietmichbox.de',
        ];

        if (!empty($_SERVER['HTTP_HOST'])) {
            $origins[] = 'https://' . $_SERVER['HTTP_HOST'];
        }

        $configured = getenv('ADMIN_APP_ORIGINS');
        if ($configured) {
            foreach (explode(',', $configured) as $origin) {
                $origin = trim($origin);
                if ($origin !== '') {
                    $origins[] = rtrim($origin, '/');
                }
            }
        }

        return array_values(array_unique(array_map(
            static fn (string $origin): string => rtrim($origin, '/'),
            array_filter($origins)
        )));
    }
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed_origins = mmb_admin_allowed_origins();

if ($origin && in_array($origin, $allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
} else {
    header('Access-Control-Allow-Origin: *');
}

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
