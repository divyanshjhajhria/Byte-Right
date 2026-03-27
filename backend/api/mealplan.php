<?php
// ByteRight — Meal Plan API (generate, view, update, delete weekly plans)

require_once __DIR__ . '/../config/database.php';
ob_start();
startSession();

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'generate': generateMealPlan(); break;
        case 'get':      getMealPlan();      break;
        case 'current':  getCurrentPlan();   break;
        case 'update':   updateMealSlot();   break;
        case 'delete':   deleteMealPlan();   break;
        default:         jsonResponse(['error' => 'Invalid action'], 400);
    }
} catch (\Throwable $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}

// Builds a full week of meals based on the user's budget, diet, and preferences
function generateMealPlan(): void {
    $userId = requireLogin();
    $data = getRequestBody();
    $db = getDB();

    $stmt = $db->prepare('SELECT weekly_budget, cooking_time_pref, meal_plan_pref, liked_ingredients, disliked_ingredients FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $prefs = $stmt->fetch();

    $budget = (float) ($data['budget'] ?? $prefs['weekly_budget'] ?? 30.00);
    $weekStart = $data['week_start'] ?? date('Y-m-d', strtotime('monday this week'));

    $likedIngredients = array_filter(array_map('trim', explode(',', strtolower($prefs['liked_ingredients'] ?? ''))));
    $dislikedIngredients = array_filter(array_map('trim', explode(',', strtolower($prefs['disliked_ingredients'] ?? ''))));

    $stmt = $db->prepare('
        SELECT dp.name FROM user_dietary_preferences udp
        JOIN dietary_preferences dp ON dp.id = udp.preference_id WHERE udp.user_id = ?
    ');
    $stmt->execute([$userId]);
    $dietaryPrefs = array_column($stmt->fetchAll(), 'name');

    // Try Spoonacular API first, fall back to local recipes
    $apiPlan = generateFromAPI($budget, $dietaryPrefs);

    if ($apiPlan !== null) {
        $planId = saveMealPlan($db, $userId, $weekStart, $budget, $apiPlan);
        jsonResponse(loadFullPlan($db, $planId));
        return;
    }

    $localPlan = generateFromLocal($db, $budget, $dietaryPrefs, $prefs['cooking_time_pref'] ?? 'any', $likedIngredients, $dislikedIngredients);
    $planId = saveMealPlan($db, $userId, $weekStart, $budget, $localPlan);

    $db->prepare('INSERT INTO activity_log (user_id, action_type, reference_id, description) VALUES (?, "plan_created", ?, "Generated a weekly meal plan")')
       ->execute([$userId, $planId]);

    jsonResponse(loadFullPlan($db, $planId));
}

// Tries to get a full week plan from the Spoonacular API
function generateFromAPI(float $budget, array $dietaryPrefs): ?array {
    $apiKey = SPOONACULAR_API_KEY;
    if ($apiKey === '') return null;

    $diet = '';
    if (in_array('Vegan', $dietaryPrefs)) $diet = 'vegan';
    elseif (in_array('Vegetarian', $dietaryPrefs)) $diet = 'vegetarian';

    $params = ['apiKey' => $apiKey, 'timeFrame' => 'week'];
    if ($diet !== '') $params['diet'] = $diet;

    $url = 'https://api.spoonacular.com/mealplanner/generate?' . http_build_query($params);
    $context = stream_context_create(['http' => ['timeout' => 10]]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) return null;

    $data = json_decode($response, true);
    if (!isset($data['week'])) return null;

    $plan = [];
    $dayNames = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    $mealTypes = ['breakfast', 'lunch', 'dinner'];

    foreach ($dayNames as $dayIndex => $dayName) {
        if (!isset($data['week'][$dayName])) continue;
        $meals = $data['week'][$dayName]['meals'] ?? [];

        foreach ($meals as $i => $meal) {
            $type = $mealTypes[$i] ?? 'snack';
            // Convert US cents → GBP
            $costGbp = round(($meal['pricePerServing'] ?? 0) / 100 * 0.80, 2);
            $plan[] = [
                'day_of_week'      => $dayIndex,
                'meal_type'        => $type,
                'custom_meal_name' => $meal['title'],
                'spoonacular_id'   => $meal['id'],
                'estimated_cost'   => $costGbp,
            ];
        }
    }

    return $plan;
}

// Picks recipes from the local DB that fit the user's budget, diet, and time constraints
function generateFromLocal(PDO $db, float $budget, array $dietaryPrefs, string $timePref, array $likedIngredients = [], array $dislikedIngredients = []): array {
    $maxTime = match($timePref) {
        'under15' => 15, 'under30' => 30, 'under60' => 60, default => 9999,
    };

    $sql = 'SELECT * FROM recipes WHERE (prep_time + cook_time) <= ?';
    $params = [$maxTime];

    foreach ($dietaryPrefs as $pref) {
        $sql .= ' AND JSON_CONTAINS(tags, ?)';
        $params[] = json_encode(strtolower(str_replace('-', '_', $pref)));
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $recipes = $stmt->fetchAll();

    // Skip egg-containing recipes for vegetarian/vegan users
    $isVeg = false;
    foreach ($dietaryPrefs as $pref) {
        if (in_array(strtolower($pref), ['vegetarian', 'vegan'])) { $isVeg = true; break; }
    }
    if ($isVeg) {
        $recipes = array_values(array_filter($recipes, fn($r) => !preg_match('/\beggs?\b/', strtolower($r['ingredients'] ?? ''))));
    }

    // Remove recipes with disliked ingredients
    if (!empty($dislikedIngredients)) {
        $recipes = array_values(array_filter($recipes, function($r) use ($dislikedIngredients) {
            $ing = strtolower($r['ingredients'] ?? '');
            foreach ($dislikedIngredients as $dislike) { if ($dislike !== '' && str_contains($ing, $dislike)) return false; }
            return true;
        }));
    }

    // Boost recipes containing liked ingredients
    foreach ($recipes as &$r) {
        $r['_like_score'] = 0;
        if (!empty($likedIngredients)) {
            $ing = strtolower($r['ingredients'] ?? '');
            foreach ($likedIngredients as $like) { if ($like !== '' && str_contains($ing, $like)) $r['_like_score']++; }
        }
    }
    unset($r);

    // Fallback chains: relax time → relax diet → all recipes
    if (empty($recipes)) {
        $fallbackSql = 'SELECT * FROM recipes WHERE 1=1';
        $fallbackParams = [];
        foreach ($dietaryPrefs as $pref) {
            $fallbackSql .= ' AND JSON_CONTAINS(tags, ?)';
            $fallbackParams[] = json_encode(strtolower(str_replace('-', '_', $pref)));
        }
        $stmt = $db->prepare($fallbackSql);
        $stmt->execute($fallbackParams);
        $recipes = $stmt->fetchAll();
    }
    if (empty($recipes)) {
        $recipes = $db->prepare('SELECT * FROM recipes')->execute() ? $db->query('SELECT * FROM recipes')->fetchAll() : [];
    }
    if (empty($recipes)) {
        jsonResponse(['error' => 'No recipes available. Please add recipes to the database first.'], 400);
    }

    // Split recipes into breakfast / lunch / dinner pools
    $breakfastRecipes = array_values(array_filter($recipes, fn($r) => str_contains($r['tags'] ?? '', 'breakfast')));
    $lunchRecipes     = array_values(array_filter($recipes, fn($r) => str_contains($r['tags'] ?? '', 'lunch')));
    $dinnerRecipes    = array_values(array_filter($recipes, fn($r) => str_contains($r['tags'] ?? '', 'dinner') || str_contains($r['tags'] ?? '', 'lunch')));

    // Pad small pools with quick/general recipes
    if (count($breakfastRecipes) < 4) {
        $quickRecipes = array_filter($recipes, fn($r) => str_contains($r['tags'] ?? '', 'quick') && !in_array($r['id'], array_column($breakfastRecipes, 'id')));
        $breakfastRecipes = array_values(array_merge($breakfastRecipes, array_values($quickRecipes)));
    }
    if (empty($breakfastRecipes)) $breakfastRecipes = $recipes;
    if (empty($lunchRecipes))     $lunchRecipes = $recipes;
    if (empty($dinnerRecipes))    $dinnerRecipes = $recipes;

    $dailyBudget = $budget / 7;
    $includeLunch = $dailyBudget >= 3;

    // Split the daily budget across meals
    if ($includeLunch) {
        $breakfastTarget = $dailyBudget * 0.25;
        $lunchTarget     = $dailyBudget * 0.30;
        $dinnerTarget    = $dailyBudget * 0.45;
    } else {
        $breakfastTarget = $dailyBudget * 0.35;
        $dinnerTarget    = $dailyBudget * 0.65;
        $lunchTarget     = 0;
    }

    // Sort each pool: liked ingredients first, then closest to budget target
    $sortByTarget = function(array &$pool, float $target) {
        usort($pool, function($a, $b) use ($target) {
            $likeDiff = ($b['_like_score'] ?? 0) - ($a['_like_score'] ?? 0);
            if ($likeDiff !== 0) return $likeDiff;
            return abs(($a['estimated_cost'] ?? 0) - $target) <=> abs(($b['estimated_cost'] ?? 0) - $target);
        });
    };

    $sortByTarget($breakfastRecipes, $breakfastTarget);
    $sortByTarget($dinnerRecipes, $dinnerTarget);
    if ($includeLunch) $sortByTarget($lunchRecipes, $lunchTarget);

    // Take the top candidates and shuffle for variety
    $shuffleTop = function(array $pool, int $n = 6): array {
        $top = array_slice($pool, 0, min($n, count($pool)));
        shuffle($top);
        return array_merge($top, array_slice($pool, min($n, count($pool))));
    };

    $breakfastPool = $shuffleTop($breakfastRecipes);
    $dinnerPool    = $shuffleTop($dinnerRecipes);
    $lunchPool     = $includeLunch ? $shuffleTop($lunchRecipes) : [];

    $recipeCostMap = [];
    foreach (array_merge($breakfastRecipes, $dinnerRecipes, $lunchRecipes) as $r) {
        $recipeCostMap[$r['id']] = (float) ($r['estimated_cost'] ?? 0);
    }

    // Assign meals for each day of the week
    $plan = [];
    for ($day = 0; $day < 7; $day++) {
        $plan[] = ['day_of_week' => $day, 'meal_type' => 'breakfast', 'recipe_id' => $breakfastPool[$day % count($breakfastPool)]['id']];
        if ($includeLunch && !empty($lunchPool)) {
            $plan[] = ['day_of_week' => $day, 'meal_type' => 'lunch', 'recipe_id' => $lunchPool[$day % count($lunchPool)]['id']];
        }
        $plan[] = ['day_of_week' => $day, 'meal_type' => 'dinner', 'recipe_id' => $dinnerPool[$day % count($dinnerPool)]['id']];
    }

    // If over budget, swap the most expensive meals for cheaper alternatives
    $totalCost = 0;
    foreach ($plan as $item) { $totalCost += $recipeCostMap[$item['recipe_id']] ?? 0; }

    if ($totalCost > $budget) {
        $cheapBreakfast = $breakfastRecipes;
        $cheapDinner = $dinnerRecipes;
        $cheapLunch = $lunchRecipes;
        usort($cheapBreakfast, fn($a, $b) => ($a['estimated_cost'] ?? 0) <=> ($b['estimated_cost'] ?? 0));
        usort($cheapDinner, fn($a, $b) => ($a['estimated_cost'] ?? 0) <=> ($b['estimated_cost'] ?? 0));
        usort($cheapLunch, fn($a, $b) => ($a['estimated_cost'] ?? 0) <=> ($b['estimated_cost'] ?? 0));

        $planWithCost = [];
        foreach ($plan as $i => $item) {
            $planWithCost[] = ['index' => $i, 'cost' => $recipeCostMap[$item['recipe_id']] ?? 0, 'type' => $item['meal_type']];
        }
        usort($planWithCost, fn($a, $b) => $b['cost'] <=> $a['cost']);

        foreach ($planWithCost as $entry) {
            if ($totalCost <= $budget) break;
            $idx = $entry['index'];
            $currentCost = $entry['cost'];
            $cheapPool = match($entry['type']) { 'breakfast' => $cheapBreakfast, 'lunch' => $cheapLunch, default => $cheapDinner };

            foreach ($cheapPool as $cheap) {
                $cheapCost = (float) ($cheap['estimated_cost'] ?? 0);
                if ($cheapCost < $currentCost) {
                    $totalCost = $totalCost - $currentCost + $cheapCost;
                    $plan[$idx]['recipe_id'] = $cheap['id'];
                    break;
                }
            }
        }
    }

    return $plan;
}

// Recalculates the total cost on a plan from its individual items
function recalculateMealPlanTotal(PDO $db, int $planId): float {
    $stmt = $db->prepare('
        SELECT mpi.recipe_id, mpi.estimated_cost AS item_cost, r.estimated_cost AS recipe_cost
        FROM meal_plan_items mpi LEFT JOIN recipes r ON r.id = mpi.recipe_id WHERE mpi.meal_plan_id = ?
    ');
    $stmt->execute([$planId]);

    $total = 0;
    foreach ($stmt->fetchAll() as $row) {
        $total += (float) ($row['recipe_cost'] ?? $row['item_cost'] ?? 0);
    }
    $total = round($total, 2);

    $db->prepare('UPDATE meal_plans SET total_estimated_cost = ? WHERE id = ?')->execute([$total, $planId]);
    return $total;
}

// Wraps the plan + items insert in a transaction
function saveMealPlan(PDO $db, int $userId, string $weekStart, float $budget, array $items): int {
    $db->beginTransaction();
    try {
        // Remove any existing plan for this week
        $stmt = $db->prepare('SELECT id FROM meal_plans WHERE user_id = ? AND week_start = ?');
        $stmt->execute([$userId, $weekStart]);
        $existing = $stmt->fetch();
        if ($existing) {
            $db->prepare('DELETE FROM meal_plans WHERE id = ?')->execute([$existing['id']]);
        }

        $stmt = $db->prepare('INSERT INTO meal_plans (user_id, week_start, budget_target, total_estimated_cost) VALUES (?, ?, ?, 0)');
        $stmt->execute([$userId, $weekStart, $budget]);
        $planId = (int) $db->lastInsertId();

        $stmt = $db->prepare('INSERT INTO meal_plan_items (meal_plan_id, day_of_week, meal_type, recipe_id, custom_meal_name, estimated_cost) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($items as $item) {
            $stmt->execute([
                $planId, $item['day_of_week'], $item['meal_type'],
                $item['recipe_id'] ?? null, $item['custom_meal_name'] ?? null, $item['estimated_cost'] ?? null,
            ]);
        }

        recalculateMealPlanTotal($db, $planId);
        $db->commit();
        return $planId;
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// Loads a plan with all its meal items and recipe details
function loadFullPlan(PDO $db, int $planId): array {
    $stmt = $db->prepare('SELECT * FROM meal_plans WHERE id = ?');
    $stmt->execute([$planId]);
    $plan = $stmt->fetch();
    if (!$plan) jsonResponse(['error' => 'Meal plan not found'], 404);

    $stmt = $db->prepare('
        SELECT mpi.*, r.title as recipe_title, r.prep_time, r.cook_time,
               r.estimated_cost as recipe_cost, r.difficulty, r.tags, r.ingredients as recipe_ingredients
        FROM meal_plan_items mpi LEFT JOIN recipes r ON r.id = mpi.recipe_id
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
    $plan['budget_remaining'] = round((float)($plan['budget_target'] ?? 0) - (float)($plan['total_estimated_cost'] ?? 0), 2);
    return $plan;
}

function getMealPlan(): void {
    $userId = requireLogin();
    $weekStart = $_GET['week'] ?? '';
    if ($weekStart === '') jsonResponse(['error' => 'week parameter required (YYYY-MM-DD)'], 400);

    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM meal_plans WHERE user_id = ? AND week_start = ?');
    $stmt->execute([$userId, $weekStart]);
    $plan = $stmt->fetch();
    if (!$plan) jsonResponse(['error' => 'No meal plan found for this week'], 404);

    jsonResponse(loadFullPlan($db, $plan['id']));
}

function getCurrentPlan(): void {
    $userId = requireLogin();
    $db = getDB();

    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $stmt = $db->prepare('SELECT id FROM meal_plans WHERE user_id = ? AND week_start = ?');
    $stmt->execute([$userId, $weekStart]);
    $plan = $stmt->fetch();
    if (!$plan) jsonResponse(['error' => 'No meal plan for current week', 'week_start' => $weekStart], 404);

    jsonResponse(loadFullPlan($db, $plan['id']));
}

function updateMealSlot(): void {
    $userId = requireLogin();
    $data = getRequestBody();
    $db = getDB();

    $itemId   = (int) ($data['item_id'] ?? 0);
    $recipeId = isset($data['recipe_id']) ? (int) $data['recipe_id'] : null;
    $customName = $data['custom_meal_name'] ?? null;

    if ($itemId <= 0) jsonResponse(['error' => 'item_id is required'], 400);

    $stmt = $db->prepare('
        SELECT mpi.id, mpi.meal_plan_id FROM meal_plan_items mpi
        JOIN meal_plans mp ON mp.id = mpi.meal_plan_id WHERE mpi.id = ? AND mp.user_id = ?
    ');
    $stmt->execute([$itemId, $userId]);
    $row = $stmt->fetch();
    if (!$row) jsonResponse(['error' => 'Not found or not authorized'], 403);

    $db->prepare('UPDATE meal_plan_items SET recipe_id = ?, custom_meal_name = ? WHERE id = ?')->execute([$recipeId, $customName, $itemId]);

    $newTotal = recalculateMealPlanTotal($db, (int) $row['meal_plan_id']);
    jsonResponse(['success' => true, 'total_estimated_cost' => $newTotal]);
}

function deleteMealPlan(): void {
    $userId = requireLogin();
    $planId = (int) ($_GET['id'] ?? 0);
    if ($planId <= 0) jsonResponse(['error' => 'Plan ID required'], 400);

    $db = getDB();
    $db->prepare('DELETE FROM meal_plans WHERE id = ? AND user_id = ?')->execute([$planId, $userId]);
    jsonResponse(['success' => true]);
}
