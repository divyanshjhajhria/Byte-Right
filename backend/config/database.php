<?php
// ByteRight — Database config & shared helpers

define('DB_HOST', 'localhost');
define('DB_NAME', 'byteright');
define('DB_USER', 'root');
define('DB_PASS', '');          // Change for production
define('DB_CHARSET', 'utf8mb4');

// Spoonacular API key — sign up at https://spoonacular.com/food-api
define('SPOONACULAR_API_KEY', '');  // Add your key here

// Upload settings
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB

// Allow cross-origin requests from localhost during development
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = ['http://localhost', 'http://localhost:8080', 'http://127.0.0.1'];
if (in_array($origin, $allowedOrigins) || str_starts_with($origin, 'http://localhost:')) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Returns a reusable PDO connection (singleton)
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

// Starts a PHP session if one isn't active already
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// Returns the logged-in user's ID, or false if not logged in
function getLoggedInUser(): int|false {
    startSession();
    return $_SESSION['user_id'] ?? false;
}

// Blocks access for unauthenticated users — sends a 401 and stops
function requireLogin(): int {
    $userId = getLoggedInUser();
    if ($userId === false) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }
    return $userId;
}

// Sends a JSON response and exits (cleans any stray output first)
function jsonResponse(mixed $data, int $code = 200): void {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Reads the request body — handles both JSON and form-encoded POST data
function getRequestBody(): array {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $body = json_decode(file_get_contents('php://input'), true);
        return is_array($body) ? $body : [];
    }
    return $_POST;
}
