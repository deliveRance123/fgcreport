<?php
/**
 * Authentication helpers.
 * Must be required after session_start().
 */

/**
 * Compute the app's root URL path dynamically so the app works on any host/subdirectory.
 * e.g. on localhost: "/fgc_report_web"
 * e.g. on free host at root: ""
 */
function appBasePath(): string {
    // __DIR__ = .../includes,  we need one level up (project root)
    $projectRoot = dirname(__DIR__);
    $docRoot     = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
    // Normalise to forward slashes for comparison
    $projectRoot = str_replace('\\', '/', $projectRoot);
    $docRoot     = str_replace('\\', '/', $docRoot);
    // Strip document root prefix to get URL path
    $base = str_replace($docRoot, '', $projectRoot);
    return rtrim($base, '/');
}

/**
 * Start secure session if not already started.
 * Auto-detects HTTPS so the 'secure' flag is correct on both localhost and live hosting.
 */
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // Explicitly check if real HTTPS is active
        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off');

        if ($isHttps) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
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
 * Get the logged-in church_id (for church_admin only), with robust fallback resolution.
 */
function currentChurchId(): ?int {
    if (!empty($_SESSION['church_id'])) {
        return (int)$_SESSION['church_id'];
    }
    if (!empty($_SESSION['user_id'])) {
        try {
            $db = db();
            // 1. Check if churches table has created_by = user_id
            $stmt = $db->prepare("SELECT id FROM churches WHERE created_by = ? LIMIT 1");
            $stmt->execute([$_SESSION['user_id']]);
            $cid = $stmt->fetchColumn();

            // 2. Fallback: return first church in database if available
            if (!$cid) {
                $cid = $db->query("SELECT id FROM churches ORDER BY id ASC LIMIT 1")->fetchColumn();
            }

            if ($cid) {
                $_SESSION['church_id'] = (int)$cid;
                return (int)$cid;
            }
        } catch (Exception $e) {}
    }
    return null;
}

/**
 * Get the logged-in zone_id (for zonal_admin only), with robust fallback resolution.
 */
function currentZoneId(): ?int {
    if (!empty($_SESSION['zone_id'])) {
        return (int)$_SESSION['zone_id'];
    }
    if (!empty($_SESSION['user_id'])) {
        try {
            $db = db();
            // 1. Check if zones table has created_by = user_id
            $stmt = $db->prepare("SELECT id FROM zones WHERE created_by = ? LIMIT 1");
            $stmt->execute([$_SESSION['user_id']]);
            $zid = $stmt->fetchColumn();

            // 2. Fallback: return first zone in database if available
            if (!$zid) {
                $zid = $db->query("SELECT id FROM zones ORDER BY id ASC LIMIT 1")->fetchColumn();
            }

            if ($zid) {
                $_SESSION['zone_id'] = (int)$zid;
                return (int)$zid;
            }
        } catch (Exception $e) {}
    }
    return null;
}

/**
 * Require the user to be logged in; redirect to login if not.
 */
function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . appBasePath() . '/login.php');
        exit;
    }
}

/**
 * Require a specific role; redirect to login if wrong role.
 */
function requireRole(string ...$roles): void {
    requireLogin();
    if (!in_array(currentRole(), $roles, true)) {
        header('Location: ' . appBasePath() . '/login.php');
        exit;
    }
}

/**
 * Redirect logged-in user to their appropriate dashboard.
 */
function redirectToDashboard(): void {
    $base = appBasePath();
    $role = currentRole();
    $map = [
        'church_admin' => $base . '/church-dashboard.php',
        'zonal_admin'  => $base . '/zone-dashboard.php',
        'super_admin'  => $base . '/admin-dashboard.php',
    ];
    header('Location: ' . ($map[$role] ?? $base . '/login.php'));
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
