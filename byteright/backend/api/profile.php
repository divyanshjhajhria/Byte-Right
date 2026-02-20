<?php
/**
 * ByteRight - User Profile API
 *
 * GET    /api/profile.php                      - Get current user profile
 * POST   /api/profile.php?action=update         - Update profile settings
 * POST   /api/profile.php?action=password       - Change password
 * POST   /api/profile.php?action=dietary        - Update dietary preferences
 * GET    /api/profile.php?action=stats          - Get user stats
 * GET    /api/profile.php?action=activity       - Get recent activity
 */

require_once __DIR__ . '/../config/database.php';
startSession();

$action = $_GET['action'] ?? 'get';

switch ($action) {
    case 'get':
        getProfile();
        break;
    case 'update':
        updateProfile();
        break;
    case 'password':
        changePassword();
        break;
    case 'dietary':
        updateDietary();
        break;
    case 'stats':
        getStats();
        break;
    case 'activity':
        getActivity();
        break;
    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}

function getProfile(): void {
    $userId = requireLogin();
    $db = getDB();

    // User info
    $stmt = $db->prepare('
        SELECT id, name, email, university, avatar_path, weekly_budget,
               cooking_time_pref, meal_plan_pref, allergies, created_at
        FROM users WHERE id = ?
    ');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    // Dietary preferences
    $stmt = $db->prepare('
        SELECT dp.id, dp.name
        FROM user_dietary_preferences udp
        JOIN dietary_preferences dp ON dp.id = udp.preference_id
        WHERE udp.user_id = ?
    ');
    $stmt->execute([$userId]);
    $user['dietary_preferences'] = $stmt->fetchAll();

    jsonResponse($user);
}

function updateProfile(): void {
    $userId = requireLogin();
    $data = getRequestBody();
    $db = getDB();

    $allowed = ['name', 'university', 'weekly_budget', 'cooking_time_pref', 'meal_plan_pref', 'allergies'];
    $sets = [];
    $params = [];

    foreach ($allowed as $field) {
        if (array_key_exists($field, $data)) {
            $sets[] = "$field = ?";
            $params[] = $data[$field];
        }
    }

    if (empty($sets)) {
        jsonResponse(['error' => 'No fields to update'], 400);
    }

    $params[] = $userId;
    $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $db->prepare($sql)->execute($params);

    // Update dietary preferences if provided
    if (isset($data['dietary_preference_ids']) && is_array($data['dietary_preference_ids'])) {
        $db->prepare('DELETE FROM user_dietary_preferences WHERE user_id = ?')->execute([$userId]);
        $stmt = $db->prepare('INSERT INTO user_dietary_preferences (user_id, preference_id) VALUES (?, ?)');
        foreach ($data['dietary_preference_ids'] as $prefId) {
            $stmt->execute([$userId, (int)$prefId]);
        }
    }

    jsonResponse(['success' => true]);
}

function changePassword(): void {
    $userId = requireLogin();
    $data = getRequestBody();
    $db = getDB();

    $current = $data['current_password'] ?? '';
    $newPass = $data['new_password'] ?? '';
    $confirm = $data['confirm_password'] ?? '';

    if ($current === '' || $newPass === '' || $confirm === '') {
        jsonResponse(['error' => 'All password fields are required'], 400);
    }
    if (strlen($newPass) < 8) {
        jsonResponse(['error' => 'New password must be at least 8 characters'], 400);
    }
    if ($newPass !== $confirm) {
        jsonResponse(['error' => 'New passwords do not match'], 400);
    }

    $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!password_verify($current, $user['password_hash'])) {
        jsonResponse(['error' => 'Current password is incorrect'], 401);
    }

    $hash = password_hash($newPass, PASSWORD_DEFAULT);
    $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);

    jsonResponse(['success' => true]);
}

function updateDietary(): void {
    $userId = requireLogin();
    $data = getRequestBody();
    $db = getDB();

    $prefIds = $data['preference_ids'] ?? [];
    if (!is_array($prefIds)) {
        jsonResponse(['error' => 'preference_ids must be an array'], 400);
    }

    $db->prepare('DELETE FROM user_dietary_preferences WHERE user_id = ?')->execute([$userId]);

    $stmt = $db->prepare('INSERT INTO user_dietary_preferences (user_id, preference_id) VALUES (?, ?)');
    foreach ($prefIds as $prefId) {
        $stmt->execute([$userId, (int)$prefId]);
    }

    jsonResponse(['success' => true]);
}

function getStats(): void {
    $userId = requireLogin();
    $db = getDB();

    // Recipes saved
    $stmt = $db->prepare('SELECT COUNT(*) as count FROM saved_recipes WHERE user_id = ?');
    $stmt->execute([$userId]);
    $recipesSaved = $stmt->fetch()['count'];

    // Posts made
    $stmt = $db->prepare('SELECT COUNT(*) as count FROM posts WHERE user_id = ?');
    $stmt->execute([$userId]);
    $postsCount = $stmt->fetch()['count'];

    // Likes received
    $stmt = $db->prepare('
        SELECT COALESCE(SUM(p.likes_count), 0) as total
        FROM posts p WHERE p.user_id = ?
    ');
    $stmt->execute([$userId]);
    $likesReceived = $stmt->fetch()['total'];

    // Friends count
    $stmt = $db->prepare('
        SELECT COUNT(*) as count FROM friendships
        WHERE (requester_id = ? OR addressee_id = ?) AND status = "accepted"
    ');
    $stmt->execute([$userId, $userId]);
    $friendsCount = $stmt->fetch()['count'];

    // Meal plans created
    $stmt = $db->prepare('SELECT COUNT(*) as count FROM meal_plans WHERE user_id = ?');
    $stmt->execute([$userId]);
    $plansCount = $stmt->fetch()['count'];

    // Estimated total saved (sum of savings from meal plans)
    $stmt = $db->prepare('
        SELECT COALESCE(SUM(budget_target - total_estimated_cost), 0) as saved
        FROM meal_plans
        WHERE user_id = ? AND budget_target IS NOT NULL AND total_estimated_cost < budget_target
    ');
    $stmt->execute([$userId]);
    $totalSaved = $stmt->fetch()['saved'];

    jsonResponse([
        'recipes_saved'  => (int) $recipesSaved,
        'posts_count'    => (int) $postsCount,
        'likes_received' => (int) $likesReceived,
        'friends_count'  => (int) $friendsCount,
        'plans_count'    => (int) $plansCount,
        'total_saved'    => round((float) $totalSaved, 2),
    ]);
}

function getActivity(): void {
    $userId = requireLogin();
    $db = getDB();

    $stmt = $db->prepare('
        SELECT action_type, description, created_at
        FROM activity_log
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 20
    ');
    $stmt->execute([$userId]);

    jsonResponse($stmt->fetchAll());
}
