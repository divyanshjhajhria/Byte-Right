<?php
/**
 * ByteRight - Database Configuration & Connection
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'byteright');
define('DB_USER', 'root');
define('DB_PASS', '');          // Change for production
define('DB_CHARSET', 'utf8mb4');

// Spoonacular API key - sign up at https://spoonacular.com/food-api
define('SPOONACULAR_API_KEY', '');  // Add your key here

// Upload settings
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB

/**
 * Get PDO database connection
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }
    return $pdo;
}

/**
 * Start or resume session
 */
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Check if user is logged in, return user_id or false
 */
function getLoggedInUser(): int|false {
    startSession();
    return $_SESSION['user_id'] ?? false;
}

/**
 * Require login - redirect or send 401
 */
function requireLogin(): int {
    $userId = getLoggedInUser();
    if ($userId === false) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }
    return $userId;
}

/**
 * Send JSON response
 */
function jsonResponse(mixed $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Get POST body as assoc array (JSON or form data)
 */
function getRequestBody(): array {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $body = json_decode(file_get_contents('php://input'), true);
        return is_array($body) ? $body : [];
    }
    return $_POST;
}
