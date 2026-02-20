<?php
/**
 * ByteRight - Social Feed API
 *
 * GET    /api/social.php?action=feed&page=1         - Get social feed (friends' posts)
 * POST   /api/social.php?action=create              - Create a new post (supports image upload)
 * POST   /api/social.php?action=like&post_id=1      - Like a post
 * POST   /api/social.php?action=unlike&post_id=1    - Unlike a post
 * POST   /api/social.php?action=comment             - Add a comment
 * GET    /api/social.php?action=comments&post_id=1  - Get comments for a post
 * GET    /api/social.php?action=user_posts&user_id=1 - Get posts by a user
 * DELETE /api/social.php?action=delete&post_id=1    - Delete own post
 */

require_once __DIR__ . '/../config/database.php';
startSession();

$action = $_GET['action'] ?? 'feed';

switch ($action) {
    case 'feed':
        getFeed();
        break;
    case 'create':
        createPost();
        break;
    case 'like':
        likePost();
        break;
    case 'unlike':
        unlikePost();
        break;
    case 'comment':
        addComment();
        break;
    case 'comments':
        getComments();
        break;
    case 'user_posts':
        getUserPosts();
        break;
    case 'delete':
        deletePost();
        break;
    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}

function getFeed(): void {
    $userId = requireLogin();
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;
    $db = getDB();

    // Check if user has any friends
    $friendCheck = $db->prepare('
        SELECT COUNT(*) as cnt FROM friendships
        WHERE (requester_id = ? OR addressee_id = ?) AND status = "accepted"
    ');
    $friendCheck->execute([$userId, $userId]);
    $hasFriends = $friendCheck->fetch()['cnt'] > 0;

    if ($hasFriends) {
        // Get posts from friends and self
        $stmt = $db->prepare('
            SELECT p.*, u.name as author_name, u.avatar_path as author_avatar,
                   r.title as recipe_title, r.estimated_cost as recipe_cost,
                   r.cook_time as recipe_time,
                   (SELECT COUNT(*) FROM post_likes pl WHERE pl.post_id = p.id AND pl.user_id = ?) as liked_by_me
            FROM posts p
            JOIN users u ON u.id = p.user_id
            LEFT JOIN recipes r ON r.id = p.recipe_id
            WHERE p.user_id = ?
               OR p.user_id IN (
                   SELECT CASE
                       WHEN requester_id = ? THEN addressee_id
                       ELSE requester_id
                   END
                   FROM friendships
                   WHERE (requester_id = ? OR addressee_id = ?) AND status = "accepted"
               )
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?
        ');
        $stmt->execute([$userId, $userId, $userId, $userId, $userId, $limit, $offset]);
    } else {
        // No friends yet — show all community posts so the feed is not empty
        $stmt = $db->prepare('
            SELECT p.*, u.name as author_name, u.avatar_path as author_avatar,
                   r.title as recipe_title, r.estimated_cost as recipe_cost,
                   r.cook_time as recipe_time,
                   (SELECT COUNT(*) FROM post_likes pl WHERE pl.post_id = p.id AND pl.user_id = ?) as liked_by_me
            FROM posts p
            JOIN users u ON u.id = p.user_id
            LEFT JOIN recipes r ON r.id = p.recipe_id
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?
        ');
        $stmt->execute([$userId, $limit, $offset]);
    }
    $posts = $stmt->fetchAll();

    // Get total count for pagination
    if ($hasFriends) {
        $stmt = $db->prepare('
            SELECT COUNT(*) as total FROM posts p
            WHERE p.user_id = ?
               OR p.user_id IN (
                   SELECT CASE
                       WHEN requester_id = ? THEN addressee_id
                       ELSE requester_id
                   END
                   FROM friendships
                   WHERE (requester_id = ? OR addressee_id = ?) AND status = "accepted"
               )
        ');
        $stmt->execute([$userId, $userId, $userId, $userId]);
    } else {
        $stmt = $db->query('SELECT COUNT(*) as total FROM posts');
    }
    $total = $stmt->fetch()['total'];

    jsonResponse([
        'posts'        => $posts,
        'page'         => $page,
        'total_pages'  => ceil($total / $limit),
        'total_posts'  => (int) $total,
    ]);
}

function createPost(): void {
    $userId = requireLogin();
    $db = getDB();

    $content  = trim($_POST['content'] ?? '');
    $recipeId = isset($_POST['recipe_id']) && $_POST['recipe_id'] !== '' ? (int) $_POST['recipe_id'] : null;

    if ($content === '') {
        jsonResponse(['error' => 'Post content is required'], 400);
    }

    // Handle image upload
    $imagePath = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $imagePath = handleImageUpload($_FILES['image']);
        if ($imagePath === false) {
            jsonResponse(['error' => 'Image upload failed. Max 5MB, JPG/PNG/GIF only.'], 400);
        }
    }

    $stmt = $db->prepare('
        INSERT INTO posts (user_id, content, image_path, recipe_id)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([$userId, $content, $imagePath, $recipeId]);
    $postId = (int) $db->lastInsertId();

    // Log activity
    $db->prepare('INSERT INTO activity_log (user_id, action_type, reference_id, description) VALUES (?, "post_created", ?, "Shared a post")')
       ->execute([$userId, $postId]);

    jsonResponse([
        'success' => true,
        'post_id' => $postId,
        'image_path' => $imagePath,
    ], 201);
}

/**
 * Handle image file upload
 */
function handleImageUpload(array $file): string|false {
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    if (!in_array($file['type'], $allowed)) {
        return false;
    }
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return false;
    }

    $ext = match($file['type']) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        default      => 'jpg',
    };

    $filename = uniqid('post_', true) . '.' . $ext;
    $destDir = UPLOAD_DIR . 'posts/';

    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    $destPath = $destDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return false;
    }

    return 'uploads/posts/' . $filename;
}

function likePost(): void {
    $userId = requireLogin();
    $postId = (int) ($_GET['post_id'] ?? ($_POST['post_id'] ?? 0));

    if ($postId <= 0) {
        jsonResponse(['error' => 'post_id required'], 400);
    }

    $db = getDB();

    // Check if already liked
    $stmt = $db->prepare('SELECT 1 FROM post_likes WHERE user_id = ? AND post_id = ?');
    $stmt->execute([$userId, $postId]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'Already liked'], 409);
    }

    $db->prepare('INSERT INTO post_likes (user_id, post_id) VALUES (?, ?)')->execute([$userId, $postId]);
    $db->prepare('UPDATE posts SET likes_count = likes_count + 1 WHERE id = ?')->execute([$postId]);

    jsonResponse(['success' => true]);
}

function unlikePost(): void {
    $userId = requireLogin();
    $postId = (int) ($_GET['post_id'] ?? ($_POST['post_id'] ?? 0));

    if ($postId <= 0) {
        jsonResponse(['error' => 'post_id required'], 400);
    }

    $db = getDB();
    $stmt = $db->prepare('DELETE FROM post_likes WHERE user_id = ? AND post_id = ?');
    $stmt->execute([$userId, $postId]);

    if ($stmt->rowCount() > 0) {
        $db->prepare('UPDATE posts SET likes_count = GREATEST(likes_count - 1, 0) WHERE id = ?')->execute([$postId]);
    }

    jsonResponse(['success' => true]);
}

function addComment(): void {
    $userId = requireLogin();
    $data = getRequestBody();
    $db = getDB();

    $postId  = (int) ($data['post_id'] ?? 0);
    $content = trim($data['content'] ?? '');

    if ($postId <= 0 || $content === '') {
        jsonResponse(['error' => 'post_id and content required'], 400);
    }

    $stmt = $db->prepare('INSERT INTO post_comments (post_id, user_id, content) VALUES (?, ?, ?)');
    $stmt->execute([$postId, $userId, $content]);

    $db->prepare('UPDATE posts SET comments_count = comments_count + 1 WHERE id = ?')->execute([$postId]);

    jsonResponse([
        'success'    => true,
        'comment_id' => (int) $db->lastInsertId(),
    ], 201);
}

function getComments(): void {
    requireLogin();
    $postId = (int) ($_GET['post_id'] ?? 0);

    if ($postId <= 0) {
        jsonResponse(['error' => 'post_id required'], 400);
    }

    $db = getDB();
    $stmt = $db->prepare('
        SELECT pc.*, u.name as author_name, u.avatar_path as author_avatar
        FROM post_comments pc
        JOIN users u ON u.id = pc.user_id
        WHERE pc.post_id = ?
        ORDER BY pc.created_at ASC
    ');
    $stmt->execute([$postId]);

    jsonResponse($stmt->fetchAll());
}

function getUserPosts(): void {
    $userId = requireLogin();
    $targetUserId = (int) ($_GET['user_id'] ?? $userId);
    $db = getDB();

    $stmt = $db->prepare('
        SELECT p.*, u.name as author_name, u.avatar_path as author_avatar,
               r.title as recipe_title, r.estimated_cost as recipe_cost, r.cook_time as recipe_time,
               (SELECT COUNT(*) FROM post_likes pl WHERE pl.post_id = p.id AND pl.user_id = ?) as liked_by_me
        FROM posts p
        JOIN users u ON u.id = p.user_id
        LEFT JOIN recipes r ON r.id = p.recipe_id
        WHERE p.user_id = ?
        ORDER BY p.created_at DESC
        LIMIT 50
    ');
    $stmt->execute([$userId, $targetUserId]);

    jsonResponse($stmt->fetchAll());
}

function deletePost(): void {
    $userId = requireLogin();
    $postId = (int) ($_GET['post_id'] ?? 0);

    if ($postId <= 0) {
        jsonResponse(['error' => 'post_id required'], 400);
    }

    $db = getDB();

    // Verify ownership
    $stmt = $db->prepare('SELECT image_path FROM posts WHERE id = ? AND user_id = ?');
    $stmt->execute([$postId, $userId]);
    $post = $stmt->fetch();

    if (!$post) {
        jsonResponse(['error' => 'Post not found or not authorized'], 404);
    }

    // Delete image file if exists
    if ($post['image_path'] && file_exists(UPLOAD_DIR . '../' . $post['image_path'])) {
        unlink(UPLOAD_DIR . '../' . $post['image_path']);
    }

    $db->prepare('DELETE FROM posts WHERE id = ?')->execute([$postId]);

    jsonResponse(['success' => true]);
}
