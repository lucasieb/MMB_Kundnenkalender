<?php
declare(strict_types=1);

const MMB_ADMIN_PASSWORD = 'MMB';
const MMB_ADMIN_PASSWORD_HEADER = 'HTTP_X_ADMIN_PASSWORD';
const MMB_ADMIN_PASSWORD_STORAGE_KEY = 'mmb_admin_password';

/**
 * Returns the password that was provided with the current HTTP request via header.
 */
function admin_request_password(): ?string
{
    if (!empty($_SERVER[MMB_ADMIN_PASSWORD_HEADER])) {
        return (string) $_SERVER[MMB_ADMIN_PASSWORD_HEADER];
    }

    return null;
}

/**
 * Checks whether the provided password matches the configured admin password.
 */
function admin_password_is_valid(?string $password): bool
{
    if (!is_string($password)) {
        return false;
    }

    return hash_equals(MMB_ADMIN_PASSWORD, trim($password));
}

/**
 * Ensures that the incoming request contains the correct admin password header.
 *
 * Terminates the request with HTTP 401 when the password is missing or invalid.
 */
function admin_require_password(): void
{
    $password = admin_request_password();

    if (!admin_password_is_valid($password)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'Ungültiges Admin-Passwort.',
        ]);
        exit;
    }
}
