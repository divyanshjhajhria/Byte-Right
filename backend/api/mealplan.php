<?php
/**
 * ByteRight - Meal Plan API
 *
 * POST /api/mealplan.php?action=generate    - Auto-generate a weekly meal plan
 * GET  /api/mealplan.php?action=get&week=2025-01-06  - Get plan for a week
 * POST /api/mealplan.php?action=update      - Update a specific meal slot
 * GET  /api/mealplan.php?action=current     - Get current week's plan
 * DELETE /api/mealplan.php?action=delete&id=1 - Delete a meal plan
 */

require_once __DIR__ . '/../config/database.php';
startSession();

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'generate':
        generateMealPlan();
        break;
    case 'get':
        getMealPlan();
        break;
    case 'current':
        getCurrentPlan();
        break;
    case 'update':
        updateMealSlot();
        break;
    case 'delete':
        deleteMealPlan();
        break;
    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}

/**
 * Auto-generate a weekly meal plan based on user preferences and budget
 */
function generateMealPlan(): void {
    $userId = requireLogin();
    $data = getRequestBody();
    $db = getDB();

    // Get user preferences
    $stmt = $db->prepare('SELECT weekly_budget, cooking_time_pref, meal_plan_pref, liked_ingredients, disliked_ingredients FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $prefs = $stmt->fetch();

    $budget = (float) ($data['budget'] ?? $prefs['weekly_budget'] ?? 30.00);
    $weekStart = $data['week_start'] ?? date('Y-m-d', strtotime('monday this week'));

    // Parse likes/dislikes
    $likedIngredients = array_filter(array_map('trim', explode(',', strtolower($prefs['liked_ingredients'] ?? ''))));
    $dislikedIngredients = array_filter(array_map('trim', explode(',', strtolower($prefs['disliked_ingredients'] ?? ''))));

    // Get user dietary restrictions
    $stmt = $db->prepare('
        SELECT dp.name FROM user_dietary_preferences udp
        JOIN dietary_preferences dp ON dp.id = udp.preference_id
        WHERE udp.user_id = ?
    ');
    $stmt->execute([$userId]);
    $dietaryPrefs = array_column($stmt->fetchAll(), 'name');

    // Try Spoonacular meal plan API first
    $apiPlan = generateFromAPI($budget, $dietaryPrefs);

    if ($apiPlan !== null) {
        $planId = saveMealPlan($db, $userId, $weekStart, $budget, $apiPlan);
        jsonResponse(loadFullPlan($db, $planId));
        return;
    }

    // Fallback: generate from local recipes
    $localPlan = generateFromLocal($db, $budget, $dietaryPrefs, $prefs['cooking_time_pref'] ?? 'any', $likedIngredients, $dislikedIngredients);
    $planId = saveMealPlan($db, $userId, $weekStart, $budget, $localPlan);

    // Log activity
    $db->prepare('INSERT INTO activity_log (user_id, action_type, reference_id, description) VALUES (?, "plan_created", ?, "Generated a weekly meal plan")')
       ->execute([$userId, $planId]);

    jsonResponse(loadFullPlan($db, $planId));
}

/**
 * Try to generate a meal plan from Spoonacular API
 */
function generateFromAPI(float $budget, array $dietaryPrefs): ?array {
    $apiKey = SPOONACULAR_API_KEY;
    if ($apiKey === '') return null;

    $diet = '';
    if (in_array('Vegan', $dietaryPrefs)) $diet = 'vegan';
    elseif (in_array('Vegetarian', $dietaryPrefs)) $diet = 'vegetarian';

    // Daily budget in USD (rough GBP to USD conversion)
    $dailyBudget = round($budget * 1.25 / 7, 2);

    $params = [
        'apiKey'    => $apiKey,
        'timeFrame' => 'week',
    ];
    if ($diet !== '') $params['diet'] = $diet;

    $url = 'https://api.spoonacular.com/mealplanner/generate?' . http_build_query($params);
    $context = stream_context_create(['http' => ['timeout' => 10]]);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) return null;

    $data = json_decode($response, true);
    if (!isset($data['week'])) return null;

    $plan = [];
    $dayNames = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    foreach ($dayNames as $dayIndex => $dayName) {
        if (!isset($data['week'][$dayName])) continue;
        $meals = $data['week'][$dayName]['meals'] ?? [];

        // First meal -> breakfast, second -> lunch, third -> dinner
        $mealTypes = ['breakfast', 'lunch', 'dinner'];
        foreach ($meals as $i => $meal) {
            $type = $mealTypes[$i] ?? 'snack';
            $plan[] = [
                'day_of_week'      => $dayIndex,
                'meal_type'        => $type,
                'custom_meal_name' => $meal['title'],
                'spoonacular_id'   => $meal['id'],
                'estimated_cost'   => 0,
            ];
        }
    }

    return $plan;
}

/**
 * Generate meal plan from local recipe database
 */
function generateFromLocal(PDO $db, float $budget, array $dietaryPrefs, string $timePref, array $likedIngredients = [], array $dislikedIngredients = []): array {
    // Determine time filter
    $maxTime = match($timePref) {
        'under15' => 15,
        'under30' => 30,
        'under60' => 60,
        default   => 9999,
    };

    // Build query for eligible recipes
    $sql = 'SELECT * FROM recipes WHERE (prep_time + cook_time) <= ?';
    $params = [$maxTime];

    // Filter by diet tags
    foreach ($dietaryPrefs as $pref) {
        $tag = strtolower(str_replace('-', '_', $pref));
        $sql .= ' AND JSON_CONTAINS(tags, ?)';
        $params[] = json_encode($tag);
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $recipes = $stmt->fetchAll();

    // Exclude egg-containing recipes for vegetarian/vegan
    $isVeg = false;
    foreach ($dietaryPrefs as $pref) {
        $lower = strtolower($pref);
        if ($lower === 'vegetarian' || $lower === 'vegan') { $isVeg = true; break; }
    }
    if ($isVeg) {
        $recipes = array_values(array_filter($recipes, function($r) {
            return !preg_match('/\beggs?\b/', strtolower($r['ingredients'] ?? ''));
        }));
    }

    // Filter out recipes containing disliked ingredients
    if (!empty($dislikedIngredients)) {
        $recipes = array_values(array_filter($recipes, function($r) use ($dislikedIngredients) {
            $ing = strtolower($r['ingredients'] ?? '');
            foreach ($dislikedIngredients as $dislike) {
                if ($dislike !== '' && str_contains($ing, $dislike)) return false;
            }
            return true;
        }));
    }

    // Score recipes by liked ingredients (higher = more liked items)
    foreach ($recipes as &$r) {
        $r['_like_score'] = 0;
        if (!empty($likedIngredients)) {
            $ing = strtolower($r['ingredients'] ?? '');
            foreach ($likedIngredients as $like) {
                if ($like !== '' && str_contains($ing, $like)) $r['_like_score']++;
            }
        }
    }
    unset($r);

    if (empty($recipes)) {
        $stmt = $db->prepare('SELECT * FROM recipes');
        $stmt->execute();
        $recipes = $stmt->fetchAll();
    }

    // Separate by tags for variety
    $breakfastRecipes = array_values(array_filter($recipes, fn($r) => str_contains($r['tags'] ?? '', 'breakfast')));
    $lunchRecipes = array_values(array_filter($recipes, fn($r) => str_contains($r['tags'] ?? '', 'lunch')));
    $dinnerRecipes = array_values(array_filter($recipes, fn($r) =>
        str_contains($r['tags'] ?? '', 'dinner') || str_contains($r['tags'] ?? '', 'lunch')
    ));

    // If too few breakfast recipes, supplement with quick/light recipes from general pool
    if (count($breakfastRecipes) < 4) {
        $quickRecipes = array_filter($recipes, fn($r) =>
            str_contains($r['tags'] ?? '', 'quick') && !in_array($r['id'], array_column($breakfastRecipes, 'id'))
        );
        $breakfastRecipes = array_values(array_merge($breakfastRecipes, array_values($quickRecipes)));
    }
    if (empty($breakfastRecipes)) $breakfastRecipes = $recipes;
    if (empty($lunchRecipes)) $lunchRecipes = $recipes;
    if (empty($dinnerRecipes)) $dinnerRecipes = $recipes;

    $dailyBudget = $budget / 7;

    // Include lunch when budget allows (daily > £6)
    $includeLunch = $dailyBudget >= 6;

    // Budget allocation per meal type
    if ($includeLunch) {
        $breakfastTarget = $dailyBudget * 0.25;
        $lunchTarget = $dailyBudget * 0.30;
        $dinnerTarget = $dailyBudget * 0.45;
    } else {
        $breakfastTarget = $dailyBudget * 0.35;
        $dinnerTarget = $dailyBudget * 0.65;
        $lunchTarget = 0;
    }

    // Sort each pool by liked-ingredient score first, then by closeness to budget target
    $sortByTarget = function(array &$pool, float $target) {
        usort($pool, function($a, $b) use ($target) {
            // Higher like score first
            $likeDiff = ($b['_like_score'] ?? 0) - ($a['_like_score'] ?? 0);
            if ($likeDiff !== 0) return $likeDiff;
            // Then closest to budget target
            return abs(($a['estimated_cost'] ?? 0) - $target) <=> abs(($b['estimated_cost'] ?? 0) - $target);
        });
    };

    $sortByTarget($breakfastRecipes, $breakfastTarget);
    $sortByTarget($dinnerRecipes, $dinnerTarget);
    if ($includeLunch) $sortByTarget($lunchRecipes, $lunchTarget);

    // Take top 6 best-fit candidates and shuffle for variety
    $shuffleTop = function(array $pool, int $n = 6): array {
        $top = array_slice($pool, 0, min($n, count($pool)));
        shuffle($top);
        return array_merge($top, array_slice($pool, min($n, count($pool))));
    };

    $breakfastPool = $shuffleTop($breakfastRecipes);
    $dinnerPool = $shuffleTop($dinnerRecipes);
    $lunchPool = $includeLunch ? $shuffleTop($lunchRecipes) : [];

    $plan = [];

    for ($day = 0; $day < 7; $day++) {
        $plan[] = [
            'day_of_week' => $day,
            'meal_type'   => 'breakfast',
            'recipe_id'   => $breakfastPool[$day % count($breakfastPool)]['id'],
        ];

        if ($includeLunch && !empty($lunchPool)) {
            $plan[] = [
                'day_of_week' => $day,
                'meal_type'   => 'lunch',
                'recipe_id'   => $lunchPool[$day % count($lunchPool)]['id'],
            ];
        }

        $plan[] = [
            'day_of_week' => $day,
            'meal_type'   => 'dinner',
            'recipe_id'   => $dinnerPool[$day % count($dinnerPool)]['id'],
        ];
    }

    return $plan;
}

/**
 * Save the generated plan to database
 */
function saveMealPlan(PDO $db, int $userId, string $weekStart, float $budget, array $items): int {
    // Delete existing plan for this week
    $stmt = $db->prepare('SELECT id FROM meal_plans WHERE user_id = ? AND week_start = ?');
    $stmt->execute([$userId, $weekStart]);
    $existing = $stmt->fetch();
    if ($existing) {
        $db->prepare('DELETE FROM meal_plans WHERE id = ?')->execute([$existing['id']]);
    }

    // Calculate total cost
    $totalCost = 0;
    foreach ($items as $item) {
        if (isset($item['recipe_id'])) {
            $stmt = $db->prepare('SELECT estimated_cost FROM recipes WHERE id = ?');
            $stmt->execute([$item['recipe_id']]);
            $r = $stmt->fetch();
            $totalCost += ($r['estimated_cost'] ?? 0);
        }
    }

    $stmt = $db->prepare('
        INSERT INTO meal_plans (user_id, week_start, budget_target, total_estimated_cost)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([$userId, $weekStart, $budget, $totalCost]);
    $planId = (int) $db->lastInsertId();

    // Insert items
    $stmt = $db->prepare('
        INSERT INTO meal_plan_items (meal_plan_id, day_of_week, meal_type, recipe_id, custom_meal_name)
        VALUES (?, ?, ?, ?, ?)
    ');
    foreach ($items as $item) {
        $stmt->execute([
            $planId,
            $item['day_of_week'],
            $item['meal_type'],
            $item['recipe_id'] ?? null,
            $item['custom_meal_name'] ?? null,
        ]);
    }

    return $planId;
}

/**
 * Load a full meal plan with recipe details
 */
function loadFullPlan(PDO $db, int $planId): array {
    $stmt = $db->prepare('SELECT * FROM meal_plans WHERE id = ?');
    $stmt->execute([$planId]);
    $plan = $stmt->fetch();

    if (!$plan) {
        jsonResponse(['error' => 'Meal plan not found'], 404);
    }

    $stmt = $db->prepare('
        SELECT mpi.*, r.title as recipe_title, r.prep_time, r.cook_time,
               r.estimated_cost, r.difficulty, r.tags, r.ingredients as recipe_ingredients
        FROM meal_plan_items mpi
        LEFT JOIN recipes r ON r.id = mpi.recipe_id
        ORDER BY mpi.day_of_week, FIELD(mpi.meal_type, "breakfast", "lunch", "dinner", "snack")
    ');
    $stmt->execute();
    // Need to filter by meal_plan_id
    $stmt = $db->prepare('
        SELECT mpi.*, r.title as recipe_title, r.prep_time, r.cook_time,
               r.estimated_cost, r.difficulty, r.tags, r.ingredients as recipe_ingredients
        FROM meal_plan_items mpi
        LEFT JOIN recipes r ON r.id = mpi.recipe_id
        WHERE mpi.meal_plan_id = ?
        ORDER BY mpi.day_of_week, FIELD(mpi.meal_type, "breakfast", "lunch", "dinner", "snack")
    ');
    $stmt->execute([$planId]);
    $items = $stmt->fetchAll();

    foreach ($items as &$item) {
        if ($item['tags']) $item['tags'] = json_decode($item['tags'], true);
        if ($item['recipe_ingredients']) $item['recipe_ingredients'] = json_decode($item['recipe_ingredients'], true);
    }

    $plan['items'] = $items;
    return $plan;
}

function getMealPlan(): void {
    $userId = requireLogin();
    $weekStart = $_GET['week'] ?? '';

    if ($weekStart === '') {
        jsonResponse(['error' => 'week parameter required (YYYY-MM-DD)'], 400);
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM meal_plans WHERE user_id = ? AND week_start = ?');
    $stmt->execute([$userId, $weekStart]);
    $plan = $stmt->fetch();

    if (!$plan) {
        jsonResponse(['error' => 'No meal plan found for this week'], 404);
    }

    jsonResponse(loadFullPlan($db, $plan['id']));
}

function getCurrentPlan(): void {
    $userId = requireLogin();
    $db = getDB();

    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $stmt = $db->prepare('SELECT id FROM meal_plans WHERE user_id = ? AND week_start = ?');
    $stmt->execute([$userId, $weekStart]);
    $plan = $stmt->fetch();

    if (!$plan) {
        jsonResponse(['error' => 'No meal plan for current week', 'week_start' => $weekStart], 404);
    }

    jsonResponse(loadFullPlan($db, $plan['id']));
}

function updateMealSlot(): void {
    $userId = requireLogin();
    $data = getRequestBody();
    $db = getDB();

    $itemId   = (int) ($data['item_id'] ?? 0);
    $recipeId = isset($data['recipe_id']) ? (int) $data['recipe_id'] : null;
    $customName = $data['custom_meal_name'] ?? null;

    if ($itemId <= 0) {
        jsonResponse(['error' => 'item_id is required'], 400);
    }

    // Verify ownership
    $stmt = $db->prepare('
        SELECT mpi.id FROM meal_plan_items mpi
        JOIN meal_plans mp ON mp.id = mpi.meal_plan_id
        WHERE mpi.id = ? AND mp.user_id = ?
    ');
    $stmt->execute([$itemId, $userId]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Not found or not authorized'], 403);
    }

    $stmt = $db->prepare('UPDATE meal_plan_items SET recipe_id = ?, custom_meal_name = ? WHERE id = ?');
    $stmt->execute([$recipeId, $customName, $itemId]);

    jsonResponse(['success' => true]);
}

function deleteMealPlan(): void {
    $userId = requireLogin();
    $planId = (int) ($_GET['id'] ?? 0);

    if ($planId <= 0) {
        jsonResponse(['error' => 'Plan ID required'], 400);
    }

    $db = getDB();
    $stmt = $db->prepare('DELETE FROM meal_plans WHERE id = ? AND user_id = ?');
    $stmt->execute([$planId, $userId]);

    jsonResponse(['success' => true]);
}
