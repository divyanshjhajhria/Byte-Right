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
    "ALTER TABLE meal_plan_items ADD COLUMN estimated_cost DECIMAL(6,2) DEFAULT NULL",
    "ALTER TABLE recipes ADD COLUMN popularity_score INT DEFAULT 0",
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

// ============================================
// 8. CREATE DEMO MEAL PLAN FOR FIRST USER
// ============================================

$firstUserId = reset($userIds);
$existingPlan = $db->prepare('SELECT id FROM meal_plans WHERE user_id = ?');
$existingPlan->execute([$firstUserId]);

if ($existingPlan->fetch()) {
    echo "Meal plan already exists for first demo user, skipping\n";
} else {
    // Create a weekly meal plan
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $db->prepare('INSERT INTO meal_plans (user_id, week_start, budget_target, total_estimated_cost) VALUES (?, ?, 35.00, 0)')->execute([$firstUserId, $weekStart]);
    $planId = (int) $db->lastInsertId();

    // Pick recipes for breakfast and dinner across the week
    $breakfastRecipes = $db->query("SELECT id, title, estimated_cost, cook_time FROM recipes WHERE JSON_CONTAINS(tags, '\"breakfast\"') OR title LIKE '%oat%' OR title LIKE '%toast%' OR title LIKE '%pancake%' LIMIT 7")->fetchAll();
    $dinnerRecipes = $db->query("SELECT id, title, estimated_cost, cook_time FROM recipes WHERE JSON_CONTAINS(tags, '\"dinner\"') OR JSON_CONTAINS(tags, '\"lunch\"') OR estimated_cost > 2 LIMIT 7")->fetchAll();

    $stmtItem = $db->prepare('INSERT INTO meal_plan_items (meal_plan_id, day_of_week, meal_type, recipe_id, custom_meal_name, estimated_cost) VALUES (?, ?, ?, ?, ?, ?)');

    for ($day = 0; $day < 7; $day++) {
        // Breakfast
        $br = $breakfastRecipes[$day % count($breakfastRecipes)] ?? null;
        if ($br) {
            $stmtItem->execute([$planId, $day, 'breakfast', $br['id'], $br['title'], $br['estimated_cost'] ?: 1.50]);
        }
        // Dinner
        $dn = $dinnerRecipes[$day % count($dinnerRecipes)] ?? null;
        if ($dn) {
            $stmtItem->execute([$planId, $day, 'dinner', $dn['id'], $dn['title'], $dn['estimated_cost'] ?: 4.00]);
        }
    }
    // Recalculate total_estimated_cost from items
    $totalCost = $db->prepare('SELECT COALESCE(SUM(estimated_cost), 0) FROM meal_plan_items WHERE meal_plan_id = ?');
    $totalCost->execute([$planId]);
    $total = (float) $totalCost->fetchColumn();
    $db->prepare('UPDATE meal_plans SET total_estimated_cost = ? WHERE id = ?')->execute([$total, $planId]);

    echo "Created demo meal plan (ID $planId) with " . min(7, count($breakfastRecipes)) . " breakfasts and " . min(7, count($dinnerRecipes)) . " dinners (total £" . number_format($total, 2) . ")\n";
}

// ============================================
// 9. ADD EXTRA DIETARY PREFERENCES
// ============================================

$extraPrefs = ['Nut-Free', 'Pescatarian', 'Low-Carb', 'High-Protein'];
foreach ($extraPrefs as $pref) {
    try {
        $db->prepare('INSERT INTO dietary_preferences (name) VALUES (?)')->execute([$pref]);
        echo "Added dietary preference: $pref\n";
    } catch (PDOException $e) {
        // Already exists
    }
}

// Map all dietary preferences for frontend use
$allPrefs = $db->query('SELECT id, name FROM dietary_preferences ORDER BY id')->fetchAll();
echo "All dietary preferences: " . implode(', ', array_column($allPrefs, 'name')) . "\n";

// ============================================
// 10. ADD HALAL, KOSHER, PESCATARIAN, NUT-FREE RECIPES
// ============================================

$newRecipes = [
    [
        'title' => 'Chicken Shawarma Bowl',
        'description' => 'Aromatic spiced chicken with rice, hummus and fresh salad - halal friendly.',
        'ingredients' => '["2 chicken breasts","1 tsp cumin","1 tsp paprika","1 tsp turmeric","1 tsp coriander","2 tbsp olive oil","200g rice","1 cucumber","2 tomatoes","100g hummus","flatbread"]',
        'instructions' => '["Mix cumin, paprika, turmeric, coriander and oil.","Slice chicken and coat in spice mix.","Cook rice.","Pan fry chicken 5 mins each side.","Dice cucumber and tomatoes.","Assemble bowl: rice, chicken, salad, hummus.","Serve with warm flatbread."]',
        'prep_time' => 10, 'cook_time' => 15, 'servings' => 2, 'cost' => 3.50,
        'difficulty' => 'easy', 'tags' => '["dinner","lunch","halal","healthy","high_protein"]',
        'popularity' => 82,
    ],
    [
        'title' => 'Lamb Kofta with Rice',
        'description' => 'Spiced lamb kofta served with fluffy rice and yoghurt sauce.',
        'ingredients' => '["300g minced lamb","1 onion grated","2 cloves garlic","1 tsp cumin","1 tsp coriander","salt","pepper","200g rice","150g yoghurt","fresh mint","lemon"]',
        'instructions' => '["Mix lamb, grated onion, garlic, cumin, coriander, salt and pepper.","Shape into small oval kofta.","Grill or pan fry 4 mins each side.","Cook rice.","Mix yoghurt with mint and lemon juice.","Serve kofta on rice with yoghurt sauce."]',
        'prep_time' => 10, 'cook_time' => 15, 'servings' => 2, 'cost' => 4.50,
        'difficulty' => 'easy', 'tags' => '["dinner","halal","kosher","high_protein"]',
        'popularity' => 77,
    ],
    [
        'title' => 'Salmon Teriyaki',
        'description' => 'Glazed salmon fillets with sweet teriyaki sauce and steamed rice.',
        'ingredients' => '["2 salmon fillets","3 tbsp soy sauce","1 tbsp honey","1 tsp ginger","1 clove garlic","1 tbsp sesame oil","200g rice","steamed broccoli","sesame seeds"]',
        'instructions' => '["Mix soy sauce, honey, ginger, garlic and sesame oil.","Marinate salmon 10 mins.","Cook rice.","Pan fry salmon 4 mins each side, basting with sauce.","Steam broccoli.","Serve salmon on rice with broccoli, drizzle remaining sauce.","Top with sesame seeds."]',
        'prep_time' => 15, 'cook_time' => 12, 'servings' => 2, 'cost' => 5.50,
        'difficulty' => 'easy', 'tags' => '["dinner","pescatarian","healthy","nut_free","gluten_free"]',
        'popularity' => 84,
    ],
    [
        'title' => 'Prawn Stir-Fry',
        'description' => 'Quick and healthy prawn stir-fry with vegetables and noodles.',
        'ingredients' => '["200g king prawns","1 pepper","100g mange tout","2 spring onions","2 tbsp soy sauce","1 tbsp sweet chilli sauce","1 clove garlic","200g egg noodles","1 tbsp oil"]',
        'instructions' => '["Cook noodles per packet instructions.","Heat oil in wok over high heat.","Add prawns, cook 2 mins.","Add sliced pepper, mange tout and garlic.","Stir fry 3 mins.","Add soy and sweet chilli sauce.","Toss in drained noodles.","Top with sliced spring onions."]',
        'prep_time' => 5, 'cook_time' => 10, 'servings' => 2, 'cost' => 4.00,
        'difficulty' => 'easy', 'tags' => '["dinner","pescatarian","quick","nut_free"]',
        'popularity' => 70,
    ],
    [
        'title' => 'Matzo Ball Soup',
        'description' => 'Comforting chicken soup with light fluffy matzo balls.',
        'ingredients' => '["4 eggs","100g matzo meal","2 tbsp oil","1 tsp salt","1L chicken stock","2 carrots","2 celery sticks","1 onion","fresh dill"]',
        'instructions' => '["Beat eggs, mix in matzo meal, oil and salt.","Chill mixture 30 mins.","Dice carrots, celery and onion.","Simmer vegetables in chicken stock 15 mins.","Roll matzo mixture into small balls.","Drop into simmering soup.","Cook 20 mins until matzo balls float and are cooked through.","Garnish with fresh dill."]',
        'prep_time' => 15, 'cook_time' => 35, 'servings' => 4, 'cost' => 3.00,
        'difficulty' => 'medium', 'tags' => '["dinner","lunch","kosher","comfort","nut_free"]',
        'popularity' => 65,
    ],
    [
        'title' => 'Falafel Wrap',
        'description' => 'Crispy baked falafel in warm flatbread with tahini sauce.',
        'ingredients' => '["1 can chickpeas","1 onion","2 cloves garlic","1 tsp cumin","1 tsp coriander","2 tbsp flour","salt","pepper","flatbread","lettuce","tomato","tahini","lemon"]',
        'instructions' => '["Blend chickpeas, onion, garlic, cumin, coriander and flour.","Shape into small patties.","Bake at 200C for 20 mins, flip halfway.","Warm flatbread.","Mix tahini with lemon juice and water.","Assemble wrap: flatbread, lettuce, tomato, falafel.","Drizzle tahini sauce."]',
        'prep_time' => 10, 'cook_time' => 20, 'servings' => 2, 'cost' => 2.00,
        'difficulty' => 'easy', 'tags' => '["lunch","dinner","halal","kosher","vegan","vegetarian","budget","nut_free"]',
        'popularity' => 79,
    ],
    [
        'title' => 'Tuna Nicoise Salad',
        'description' => 'Classic French salad with tuna, eggs, olives and green beans.',
        'ingredients' => '["1 can tuna","2 eggs","100g green beans","10 cherry tomatoes","handful of olives","1 little gem lettuce","2 tbsp olive oil","1 tbsp red wine vinegar","salt","pepper"]',
        'instructions' => '["Boil eggs 8 mins, cool and halve.","Blanch green beans 3 mins.","Halve cherry tomatoes.","Arrange lettuce on plates.","Top with tuna, eggs, beans, tomatoes, olives.","Whisk olive oil, vinegar, salt and pepper.","Drizzle dressing over salad."]',
        'prep_time' => 10, 'cook_time' => 10, 'servings' => 2, 'cost' => 3.50,
        'difficulty' => 'easy', 'tags' => '["lunch","pescatarian","healthy","gluten_free","nut_free"]',
        'popularity' => 68,
    ],
    [
        'title' => 'Chickpea and Spinach Stew',
        'description' => 'Hearty one-pot stew packed with protein and greens.',
        'ingredients' => '["1 can chickpeas","200g spinach","1 can chopped tomatoes","1 onion","2 cloves garlic","1 tsp cumin","1 tsp paprika","1 tbsp olive oil","salt","pepper","bread to serve"]',
        'instructions' => '["Fry onion and garlic in olive oil.","Add cumin and paprika, stir 1 min.","Add tomatoes and drained chickpeas.","Simmer 15 mins.","Stir in spinach until wilted.","Season with salt and pepper.","Serve with crusty bread."]',
        'prep_time' => 5, 'cook_time' => 20, 'servings' => 2, 'cost' => 1.80,
        'difficulty' => 'easy', 'tags' => '["dinner","lunch","halal","kosher","vegan","vegetarian","budget","nut_free","healthy"]',
        'popularity' => 72,
    ],
];

$stmtRecipeCheck = $db->prepare('SELECT id FROM recipes WHERE title = ?');
$stmtRecipeInsert = $db->prepare('
    INSERT INTO recipes (title, description, ingredients, instructions, prep_time, cook_time, servings, estimated_cost, difficulty, tags, source, popularity_score)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "local", ?)
');

$recipeCount = 0;
foreach ($newRecipes as $r) {
    $stmtRecipeCheck->execute([$r['title']]);
    if ($stmtRecipeCheck->fetch()) continue;

    $stmtRecipeInsert->execute([
        $r['title'], $r['description'], $r['ingredients'], $r['instructions'],
        $r['prep_time'], $r['cook_time'], $r['servings'], $r['cost'],
        $r['difficulty'], $r['tags'], $r['popularity']
    ]);
    $recipeCount++;
}
echo "Added $recipeCount new recipes (halal, kosher, pescatarian, nut-free)\n";

// ============================================
// 11. BEFRIEND CURRENT USER WITH DEMO USERS
// ============================================

// Find any non-demo users (the real logged-in user) and befriend them with some demo users
$realUsers = $db->query('SELECT id FROM users WHERE email NOT LIKE "%manchester.ac.uk" LIMIT 5')->fetchAll(PDO::FETCH_COLUMN);
$demoIds = array_values($userIds);

foreach ($realUsers as $realId) {
    $friendsAdded = 0;
    foreach (array_slice($demoIds, 0, 5) as $demoId) {
        if ($realId == $demoId) continue;
        try {
            $stmtFriendCheck->execute([$realId, $demoId, $demoId, $realId]);
            if (!$stmtFriendCheck->fetch()) {
                $stmtFriendInsert->execute([$demoId, $realId, rand(1, 30)]);
                $friendsAdded++;
            }
        } catch (PDOException $e) { /* skip duplicates */ }
    }
    if ($friendsAdded > 0) echo "Added $friendsAdded demo friends for user ID $realId\n";
}

// ============================================
// 12. SET POPULARITY SCORES ON EXISTING RECIPES
// ============================================

$popularityMap = [
    'Chicken Stir-Fry' => 95, 'Spaghetti Bolognese' => 92, 'Vegetable Curry' => 90,
    'Overnight Oats' => 88, 'Chicken Fajitas' => 86, 'Pasta Carbonara' => 85,
    'Avocado Toast' => 83, 'Shakshuka' => 80, 'Thai Green Curry' => 78, 'Mushroom Risotto' => 75,
];
$stmtPop = $db->prepare('UPDATE recipes SET popularity_score = ? WHERE title = ? AND popularity_score = 0');
foreach ($popularityMap as $title => $score) {
    $stmtPop->execute([$score, $title]);
}
echo "Set popularity scores on featured recipes\n";

echo "\n=== Demo Data Seeding Complete ===\n";
echo "Demo user password: demo12345\n";
echo "You can log in as any demo user, e.g. sarah.mitchell@manchester.ac.uk\n";
