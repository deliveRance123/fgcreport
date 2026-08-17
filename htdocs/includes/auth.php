<?php
/**
 * Authentication helpers.
 * Must be required after session_start().
 */

/**
 * Start secure session if not already started.
 */
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => false,   // set true in production (HTTPS)
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}

/**
 * Check if the current user is logged in.
 */
function isLoggedIn(): bool {
    startSession();
    return !empty($_SESSION['user_id']);
}

/**
 * Get the logged-in user's role, or null if not logged in.
 */
function currentRole(): ?string {
    return $_SESSION['role'] ?? null;
}

/**
 * Get the logged-in user's ID, or null.
 */
function currentUserId(): ?int {
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/**
 * Get the logged-in church_id (for church_admin only), or null.
 */
function currentChurchId(): ?int {
    return isset($_SESSION['church_id']) ? (int)$_SESSION['church_id'] : null;
}

/**
 * Get the logged-in zone_id (for zonal_admin only), or null.
 */
function currentZoneId(): ?int {
    return isset($_SESSION['zone_id']) ? (int)$_SESSION['zone_id'] : null;
}

/**
 * Require the user to be logged in; redirect to login if not.
 */
function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: /fgc_report_web/login.php');
        exit;
    }
}

/**
 * Require a specific role; redirect to dashboard if wrong role.
 */
function requireRole(string ...$roles): void {
    requireLogin();
    if (!in_array(currentRole(), $roles, true)) {
        header('Location: /fgc_report_web/login.php');
        exit;
    }
}

/**
 * Redirect logged-in user to their appropriate dashboard.
 */
function redirectToDashboard(): void {
    $role = currentRole();
    $map = [
        'church_admin' => '/fgc_report_web/church-dashboard.php',
        'zonal_admin'  => '/fgc_report_web/zone-dashboard.php',
        'super_admin'  => '/fgc_report_web/admin-dashboard.php',
    ];
    header('Location: ' . ($map[$role] ?? '/fgc_report_web/login.php'));
    exit;
}

/**
 * Log the user out and destroy the session.
 */
function logout(): void {
    startSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}
