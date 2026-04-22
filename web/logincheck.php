<?php

function is_trusted_requester(): bool
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $server = $_SERVER['SERVER_ADDR'] ?? '';
    $trusted = ['127.0.0.1', '::1'];
    if ($remote === $server && $remote !== '') {
        return true;
    }
    if (in_array($remote, $trusted, true)) {
        return true;
    }
    return false;
}

if (!is_trusted_requester()) {
    require __DIR__ . "/../login/lib.php";

    $currentEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    $isAllowedUser = false;

    foreach ($allowedUsers as $emailKey => $value) {
        // Supports both legacy ['user@domain'] and new ['user@domain' => [15, 40]] formats.
        $allowedEmail = is_int($emailKey) ? (string) $value : (string) $emailKey;
        if (strtolower(trim($allowedEmail)) === $currentEmail && $currentEmail !== '') {
            $isAllowedUser = true;
            break;
        }
    }

    if (!$isAllowedUser) {
        require __DIR__ . "/../login/403.php";
        die();
    }
}