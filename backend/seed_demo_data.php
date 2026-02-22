<?php
/**
 * ByteRight - Seed Demo Data
 *
 * Creates fake users, friendships, and social posts for demonstration.
 * Run after setup.php:
 *   php backend/seed_demo_data.php
 */

require_once __DIR__ . '/config/database.php';

echo "=== ByteRight Demo Data Seeder ===\n\n";

$db = getDB();

// ============================================
// 0a. MIGRATIONS: Add new columns if they don't exist
// ============================================

echo "Running migrations...\n";

$migrations = [
    "ALTER TABLE users ADD COLUMN liked_ingredients TEXT DEFAULT NULL",
    "ALTER TABLE users ADD COLUMN disliked_ingredients TEXT DEFAULT NULL",
    "CREATE TABLE IF NOT EXISTS fridge_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        quantity VARCHAR(50) DEFAULT NULL,
        added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expiry_date DATE DEFAULT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )",
];

foreach ($migrations as $sql) {
    try {
        $db->exec($sql);
        echo "  OK: " . substr($sql, 0, 60) . "...\n";
    } catch (PDOException $e) {
        // Column/table already exists — skip
        if (str_contains($e->getMessage(), 'Duplicate column') || str_contains($e->getMessage(), 'already exists')) {
            echo "  Skip (already exists): " . substr($sql, 0, 60) . "...\n";
        } else {
            echo "  WARN: " . $e->getMessage() . "\n";
        }
    }
}

// Insert new breakfast recipes if missing
$newBreakfasts = [
    'Avocado Toast', 'Porridge with Honey and Banana', 'Fruit and Yoghurt Bowl',
    'Peanut Butter Banana Wrap', 'Smoothie Bowl'
];
foreach ($newBreakfasts as $title) {
    $check = $db->prepare('SELECT id FROM recipes WHERE title = ?');
    $check->execute([$title]);
    if (!$check->fetch()) {
        echo "  New recipe '$title' will be added on next setup.php run\n";
    }
}

echo "\n";

// ============================================
// 0b. FIX EXISTING RECIPES: Remove "vegetarian" tag from egg-containing recipes
// ============================================

echo "Fixing vegetarian tags on egg recipes...\n";
$stmt = $db->query('SELECT id, title, ingredients, tags FROM recipes');
$recipes = $stmt->fetchAll();
$fixCount = 0;

foreach ($recipes as $recipe) {
    $ingredients = strtolower($recipe['ingredients']);
    $tags = json_decode($recipe['tags'], true) ?? [];

    // If recipe contains eggs and is tagged vegetarian, remove the vegetarian tag
    if (preg_match('/\beggs?\b/', $ingredients) && in_array('vegetarian', $tags)) {
        $tags = array_values(array_filter($tags, fn($t) => $t !== 'vegetarian'));
        $db->prepare('UPDATE recipes SET tags = ? WHERE id = ?')
           ->execute([json_encode($tags), $recipe['id']]);
        echo "  Fixed: {$recipe['title']} (removed vegetarian tag)\n";
        $fixCount++;
    }
}
echo "Fixed $fixCount recipes\n\n";

// ============================================
// 1. CREATE DEMO USERS
// ============================================

$demoUsers = [
    ['name' => 'Sarah Mitchell',    'email' => 'sarah.mitchell@manchester.ac.uk',   'university' => 'University of Manchester'],
    ['name' => 'Marcus Chen',       'email' => 'marcus.chen@manchester.ac.uk',      'university' => 'University of Manchester'],
    ['name' => 'Emma Patel',        'email' => 'emma.patel@manchester.ac.uk',       'university' => 'University of Manchester'],
    ['name' => 'James Wilson',      'email' => 'james.wilson@manchester.ac.uk',     'university' => 'University of Manchester'],
    ['name' => 'Olivia Rodriguez',  'email' => 'olivia.rodriguez@manchester.ac.uk', 'university' => 'University of Manchester'],
    ['name' => 'Tom Williams',      'email' => 'tom.williams@manchester.ac.uk',     'university' => 'University of Manchester'],
    ['name' => 'Lisa Anderson',     'email' => 'lisa.anderson@manchester.ac.uk',    'university' => 'University of Manchester'],
    ['name' => 'Chris Martinez',    'email' => 'chris.martinez@manchester.ac.uk',   'university' => 'University of Manchester'],
];

$demoPassword = password_hash('demo12345', PASSWORD_DEFAULT);
$userIds = [];

$stmtCheck = $db->prepare('SELECT id FROM users WHERE email = ?');
$stmtInsert = $db->prepare('
    INSERT INTO users (name, email, password_hash, university, weekly_budget, cooking_time_pref)
    VALUES (?, ?, ?, ?, ?, ?)
');

foreach ($demoUsers as $u) {
    $stmtCheck->execute([$u['email']]);
    $existing = $stmtCheck->fetch();

    if ($existing) {
        $userIds[$u['name']] = (int) $existing['id'];
        echo "  User '{$u['name']}' already exists (ID {$existing['id']})\n";
    } else {
        $budget = rand(20, 50);
        $timePref = ['under15', 'under30', 'under60', 'any'][array_rand(['under15', 'under30', 'under60', 'any'])];
        $stmtInsert->execute([$u['name'], $u['email'], $demoPassword, $u['university'], $budget, $timePref]);
        $id = (int) $db->lastInsertId();
        $userIds[$u['name']] = $id;
        echo "  Created user '{$u['name']}' (ID $id)\n";
    }
}

echo "\n";

// ============================================
// 2. CREATE FRIENDSHIPS BETWEEN DEMO USERS
// ============================================

$friendshipPairs = [
    ['Sarah Mitchell', 'Marcus Chen'],
    ['Sarah Mitchell', 'Emma Patel'],
    ['Sarah Mitchell', 'James Wilson'],
    ['Marcus Chen', 'Emma Patel'],
    ['Marcus Chen', 'Olivia Rodriguez'],
    ['Emma Patel', 'James Wilson'],
    ['Emma Patel', 'Lisa Anderson'],
    ['James Wilson', 'Olivia Rodriguez'],
    ['Olivia Rodriguez', 'Tom Williams'],
    ['Tom Williams', 'Chris Martinez'],
];

$stmtFriendCheck = $db->prepare('
    SELECT id FROM friendships
    WHERE (requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?)
');
$stmtFriendInsert = $db->prepare('
    INSERT INTO friendships (requester_id, addressee_id, status, created_at)
    VALUES (?, ?, "accepted", DATE_SUB(NOW(), INTERVAL ? DAY))
');

$friendCount = 0;
foreach ($friendshipPairs as $pair) {
    $id1 = $userIds[$pair[0]] ?? null;
    $id2 = $userIds[$pair[1]] ?? null;
    if (!$id1 || !$id2) continue;

    $stmtFriendCheck->execute([$id1, $id2, $id2, $id1]);
    if ($stmtFriendCheck->fetch()) continue;

    $daysAgo = rand(7, 60);
    $stmtFriendInsert->execute([$id1, $id2, $daysAgo]);
    $friendCount++;
}
echo "Created $friendCount friendships\n\n";

// ============================================
// 3. CREATE SOCIAL POSTS
// ============================================

$demoPosts = [
    [
        'user' => 'Sarah Mitchell',
        'content' => 'Finally nailed the perfect veggie stir-fry! Used up all my leftover veggies and it came out amazing. High heat is your friend.',
        'days_ago' => 0,
        'likes' => 24,
    ],
    [
        'user' => 'Marcus Chen',
        'content' => 'Meal prep Sunday done! Made 5 portions of chickpea curry for under 15 pounds. My wallet and future-me are both happy.',
        'days_ago' => 0,
        'likes' => 42,
    ],
    [
        'user' => 'Emma Patel',
        'content' => 'Who else is obsessed with overnight oats? Game changer for busy mornings. Added banana, peanut butter and a drizzle of honey.',
        'days_ago' => 1,
        'likes' => 31,
    ],
    [
        'user' => 'James Wilson',
        'content' => 'Late night pasta experiment turned out better than expected. Sometimes the best recipes happen when you are just winging it with whatever is in the fridge.',
        'days_ago' => 2,
        'likes' => 18,
    ],
    [
        'user' => 'Olivia Rodriguez',
        'content' => 'First time making homemade pizza and I am never going back to takeaway. Way cheaper and honestly tastes better.',
        'days_ago' => 3,
        'likes' => 56,
    ],
    [
        'user' => 'Tom Williams',
        'content' => 'Tried the lentil daal recipe from ByteRight and it is now a weekly staple. So cheap and filling.',
        'days_ago' => 4,
        'likes' => 15,
    ],
    [
        'user' => 'Lisa Anderson',
        'content' => 'Budget tip: frozen veg is just as nutritious as fresh and costs a fraction. My stir-fries have never been cheaper.',
        'days_ago' => 5,
        'likes' => 29,
    ],
    [
        'user' => 'Chris Martinez',
        'content' => 'Made shakshuka for the first time. So simple and the spices make it incredible. Perfect for a quick dinner.',
        'days_ago' => 6,
        'likes' => 22,
    ],
    [
        'user' => 'Sarah Mitchell',
        'content' => 'Batch cooking is the best thing I have learned at uni. 2 hours on Sunday saves me so much time during the week.',
        'days_ago' => 7,
        'likes' => 35,
    ],
    [
        'user' => 'Marcus Chen',
        'content' => 'Jacket potato with beans and cheese. Under 2 pounds and took 10 minutes in the microwave. Student cooking at its finest.',
        'days_ago' => 8,
        'likes' => 19,
    ],
];

$stmtPostInsert = $db->prepare('
    INSERT INTO posts (user_id, content, likes_count, comments_count, created_at)
    VALUES (?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? DAY))
');

$postCount = 0;
$postIds = [];

// Check if posts already exist
$existingPostCount = $db->query('SELECT COUNT(*) FROM posts')->fetchColumn();
if ($existingPostCount > 0) {
    echo "Posts already exist ($existingPostCount), skipping post creation\n\n";
} else {
    foreach ($demoPosts as $post) {
        $uid = $userIds[$post['user']] ?? null;
        if (!$uid) continue;

        $commentCount = rand(2, 15);
        $stmtPostInsert->execute([$uid, $post['content'], $post['likes'], $commentCount, $post['days_ago']]);
        $postIds[] = (int) $db->lastInsertId();
        $postCount++;
    }
    echo "Created $postCount posts\n";

    // ============================================
    // 4. CREATE SOME LIKES ON POSTS
    // ============================================
    $stmtLike = $db->prepare('INSERT IGNORE INTO post_likes (user_id, post_id) VALUES (?, ?)');
    $likeCount = 0;
    $allUserIds = array_values($userIds);

    foreach ($postIds as $postId) {
        $numLikers = rand(2, min(5, count($allUserIds)));
        $likerPool = $allUserIds;
        shuffle($likerPool);

        for ($i = 0; $i < $numLikers; $i++) {
            $stmtLike->execute([$likerPool[$i], $postId]);
            $likeCount++;
        }
    }
    echo "Created $likeCount likes\n";

    // ============================================
    // 5. CREATE SOME COMMENTS ON POSTS
    // ============================================
    $commentTexts = [
        'Looks amazing!',
        'I need to try this recipe.',
        'How long did this take you?',
        'Great tip, thanks for sharing.',
        'This is exactly what I needed for this week.',
        'Adding this to my meal plan.',
        'My flatmates loved this one.',
        'So much better than takeaway.',
        'Saving this for later.',
        'What spices did you use?',
        'I made this yesterday, turned out great.',
        'Budget friendly and delicious.',
    ];

    $stmtComment = $db->prepare('
        INSERT INTO post_comments (post_id, user_id, content, created_at)
        VALUES (?, ?, ?, DATE_SUB(NOW(), INTERVAL ? HOUR))
    ');
    $commentCount = 0;

    foreach ($postIds as $postId) {
        $numComments = rand(1, 4);
        shuffle($allUserIds);

        for ($i = 0; $i < $numComments; $i++) {
            $text = $commentTexts[array_rand($commentTexts)];
            $hoursAgo = rand(1, 72);
            $stmtComment->execute([$postId, $allUserIds[$i % count($allUserIds)], $text, $hoursAgo]);
            $commentCount++;
        }
    }
    echo "Created $commentCount comments\n";
}

// ============================================
// 6. CREATE SOME SAVED RECIPES FOR DEMO USERS
// ============================================
$recipeIds = $db->query('SELECT id FROM recipes LIMIT 30')->fetchAll(PDO::FETCH_COLUMN);
$stmtSave = $db->prepare('INSERT IGNORE INTO saved_recipes (user_id, recipe_id) VALUES (?, ?)');
$saveCount = 0;

foreach ($userIds as $name => $uid) {
    $numSaves = rand(2, 6);
    $pool = $recipeIds;
    shuffle($pool);

    for ($i = 0; $i < min($numSaves, count($pool)); $i++) {
        $stmtSave->execute([$uid, $pool[$i]]);
        $saveCount++;
    }
}
echo "Created $saveCount saved recipes\n";

// ============================================
// 7. CREATE ACTIVITY LOG ENTRIES
// ============================================
$stmtActivity = $db->prepare('
    INSERT INTO activity_log (user_id, action_type, reference_id, description, created_at)
    VALUES (?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? DAY))
');
$activityCount = 0;

foreach ($userIds as $name => $uid) {
    $stmtActivity->execute([$uid, 'recipe_saved', null, "Saved a recipe to favourites", rand(1, 14)]);
    $stmtActivity->execute([$uid, 'post_created', null, "Shared a cooking post", rand(0, 7)]);
    $activityCount += 2;
}
echo "Created $activityCount activity log entries\n";

echo "\n=== Demo Data Seeding Complete ===\n";
echo "Demo user password: demo12345\n";
echo "You can log in as any demo user, e.g. sarah.mitchell@manchester.ac.uk\n";
