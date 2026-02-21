<?php
/**
 * ByteRight - Authentication API
 *
 * POST /api/auth.php?action=register   - Register new user
 * POST /api/auth.php?action=login      - Login
 * GET  /api/auth.php?action=logout     - Logout
 * GET  /api/auth.php?action=status     - Check login status
 */

require_once __DIR__ . '/../config/database.php';
startSession();

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'register':
        handleRegister();
        break;
    case 'login':
        handleLogin();
        break;
    case 'logout':
        handleLogout();
        break;
    case 'status':
        handleStatus();
        break;
    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}

function handleRegister(): void {
    $data = getRequestBody();

    $name     = trim($data['name'] ?? '');
    $email    = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $confirm  = $data['confirm_password'] ?? '';

    // Validation
    if ($name === '' || $email === '' || $password === '') {
        jsonResponse(['error' => 'Name, email and password are required'], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Invalid email address'], 400);
    }
    if (strlen($password) < 8) {
        jsonResponse(['error' => 'Password must be at least 8 characters'], 400);
    }
    if ($password !== $confirm) {
        jsonResponse(['error' => 'Passwords do not match'], 400);
    }

    $db = getDB();

    // Check if email already exists
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'Email already registered'], 409);
    }

    // Create user
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
    $stmt->execute([$name, $email, $hash]);
    $userId = (int) $db->lastInsertId();

    // Auto-login after registration
    $_SESSION['user_id'] = $userId;

    // Log activity
    $stmt = $db->prepare('INSERT INTO activity_log (user_id, action_type, description) VALUES (?, "friend_added", "Account created")');
    $stmt->execute([$userId]);

    jsonResponse([
        'success' => true,
        'user' => [
            'id'    => $userId,
            'name'  => $name,
            'email' => $email,
        ]
    ], 201);
}

function handleLogin(): void {
    $data = getRequestBody();

    $email    = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';

    if ($email === '' || $password === '') {
        jsonResponse(['error' => 'Email and password are required'], 400);
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT id, name, email, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        jsonResponse(['error' => 'Invalid email or password'], 401);
    }

    $_SESSION['user_id'] = (int) $user['id'];

    jsonResponse([
        'success' => true,
        'user' => [
            'id'    => $user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
        ]
    ]);
}

function handleLogout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    jsonResponse(['success' => true]);
}

function handleStatus(): void {
    $userId = getLoggedInUser();
    if ($userId === false) {
        jsonResponse(['logged_in' => false]);
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT id, name, email, avatar_path FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        $_SESSION = [];
        jsonResponse(['logged_in' => false]);
    }

    jsonResponse([
        'logged_in' => true,
        'user' => $user
    ]);
}
