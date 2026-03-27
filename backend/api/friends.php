<?php
// ByteRight — Friends (send requests, accept/decline, list, remove)

require_once __DIR__ . '/../config/database.php';
startSession();

$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'list':    getFriends();     break;
    case 'request': sendRequest();    break;
    case 'pending': getPending();     break;
    case 'accept':  acceptRequest();  break;
    case 'decline': declineRequest(); break;
    case 'remove':  removeFriend();   break;
    default:        jsonResponse(['error' => 'Invalid action'], 400);
}

// Returns all accepted friends with a "recent posts" activity indicator
function getFriends(): void {
    $userId = requireLogin();
    $db = getDB();

    $stmt = $db->prepare('
        SELECT u.id, u.name, u.avatar_path, u.created_at as member_since,
               f.created_at as friends_since
        FROM friendships f
        JOIN users u ON u.id = CASE
            WHEN f.requester_id = ? THEN f.addressee_id
            ELSE f.requester_id
        END
        WHERE (f.requester_id = ? OR f.addressee_id = ?) AND f.status = "accepted"
        ORDER BY u.name
    ');
    $stmt->execute([$userId, $userId, $userId]);
    $friends = $stmt->fetchAll();

    foreach ($friends as &$friend) {
        $stmt = $db->prepare('
            SELECT COUNT(*) as recent_posts FROM posts
            WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ');
        $stmt->execute([$friend['id']]);
        $friend['recent_posts'] = (int) $stmt->fetch()['recent_posts'];
    }

    jsonResponse(['friends' => $friends, 'count' => count($friends)]);
}

// Sends a friend request by email — handles re-requests after a decline
function sendRequest(): void {
    $userId = requireLogin();
    $data = getRequestBody();
    $db = getDB();

    $email = trim($data['email'] ?? '');
    if ($email === '') {
        jsonResponse(['error' => 'Email address is required'], 400);
    }

    $stmt = $db->prepare('SELECT id, name FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $target = $stmt->fetch();

    if (!$target) jsonResponse(['error' => 'No user found with that email'], 404);
    if ((int)$target['id'] === (int)$userId) jsonResponse(['error' => 'You cannot friend yourself'], 400);

    // Check both directions for an existing friendship
    $stmt = $db->prepare('
        SELECT id, status FROM friendships
        WHERE (requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?)
    ');
    $stmt->execute([$userId, $target['id'], $target['id'], $userId]);
    $existing = $stmt->fetch();

    if ($existing) {
        if ($existing['status'] === 'accepted') jsonResponse(['error' => 'You are already friends'], 409);
        if ($existing['status'] === 'pending')  jsonResponse(['error' => 'Friend request already pending'], 409);

        // Previously declined — allow a fresh request
        $db->prepare('UPDATE friendships SET status = "pending", requester_id = ?, addressee_id = ? WHERE id = ?')
           ->execute([$userId, $target['id'], $existing['id']]);
        jsonResponse(['success' => true, 'message' => 'Friend request sent']);
        return;
    }

    $db->prepare('INSERT INTO friendships (requester_id, addressee_id) VALUES (?, ?)')->execute([$userId, $target['id']]);
    jsonResponse(['success' => true, 'message' => 'Friend request sent to ' . $target['name']]);
}

// Returns incoming and outgoing pending requests
function getPending(): void {
    $userId = requireLogin();
    $db = getDB();

    $stmt = $db->prepare('
        SELECT f.id as request_id, u.id as user_id, u.name, u.avatar_path, f.created_at
        FROM friendships f JOIN users u ON u.id = f.requester_id
        WHERE f.addressee_id = ? AND f.status = "pending" ORDER BY f.created_at DESC
    ');
    $stmt->execute([$userId]);
    $incoming = $stmt->fetchAll();

    $stmt = $db->prepare('
        SELECT f.id as request_id, u.id as user_id, u.name, u.avatar_path, f.created_at
        FROM friendships f JOIN users u ON u.id = f.addressee_id
        WHERE f.requester_id = ? AND f.status = "pending" ORDER BY f.created_at DESC
    ');
    $stmt->execute([$userId]);
    $outgoing = $stmt->fetchAll();

    jsonResponse(['incoming' => $incoming, 'outgoing' => $outgoing]);
}

// Accepts a pending request and logs it for both users
function acceptRequest(): void {
    $userId = requireLogin();
    $requestId = (int) ($_GET['request_id'] ?? ($_POST['request_id'] ?? 0));
    if ($requestId <= 0) jsonResponse(['error' => 'request_id required'], 400);

    $db = getDB();
    $stmt = $db->prepare('SELECT id, requester_id FROM friendships WHERE id = ? AND addressee_id = ? AND status = "pending"');
    $stmt->execute([$requestId, $userId]);
    $req = $stmt->fetch();
    if (!$req) jsonResponse(['error' => 'Friend request not found'], 404);

    $db->prepare('UPDATE friendships SET status = "accepted" WHERE id = ?')->execute([$requestId]);

    $db->prepare('INSERT INTO activity_log (user_id, action_type, reference_id, description) VALUES (?, "friend_added", ?, "Made a new friend")')
       ->execute([$userId, $req['requester_id']]);
    $db->prepare('INSERT INTO activity_log (user_id, action_type, reference_id, description) VALUES (?, "friend_added", ?, "Made a new friend")')
       ->execute([$req['requester_id'], $userId]);

    jsonResponse(['success' => true]);
}

// Marks a pending request as declined
function declineRequest(): void {
    $userId = requireLogin();
    $requestId = (int) ($_GET['request_id'] ?? ($_POST['request_id'] ?? 0));
    if ($requestId <= 0) jsonResponse(['error' => 'request_id required'], 400);

    $db = getDB();
    $db->prepare('UPDATE friendships SET status = "declined" WHERE id = ? AND addressee_id = ? AND status = "pending"')
       ->execute([$requestId, $userId]);

    jsonResponse(['success' => true]);
}

// Removes an accepted friendship (works regardless of who sent the original request)
function removeFriend(): void {
    $userId = requireLogin();
    $friendId = (int) ($_GET['friend_id'] ?? 0);
    if ($friendId <= 0) jsonResponse(['error' => 'friend_id required'], 400);

    $db = getDB();
    $db->prepare('
        DELETE FROM friendships
        WHERE ((requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?))
          AND status = "accepted"
    ')->execute([$userId, $friendId, $friendId, $userId]);

    jsonResponse(['success' => true]);
}
