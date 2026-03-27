<?php
// ByteRight — Recipe API (search, get, save, trending, budget picks)

require_once __DIR__ . '/../config/database.php';
ob_start();
startSession();

$action = $_GET['action'] ?? 'search';

try {
switch ($action) {
    case 'search':   searchRecipes();      break;
    case 'get':      getRecipe();          break;
    case 'save':     saveRecipe();         break;
    case 'unsave':   unsaveRecipe();       break;
    case 'saved':    getSavedRecipes();    break;
    case 'random':   getRandomRecipes();   break;
    case 'trending': getTrendingRecipes(); break;
    case 'budget':   getBudgetRecipes();   break;
    default:         jsonResponse(['error' => 'Invalid action'], 400);
}
} catch (\Throwable $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}

// Searches by ingredients — tries Spoonacular API first, falls back to local DB
function searchRecipes(): void {
    $ingredients = trim($_GET['ingredients'] ?? '');
    $diet        = trim($_GET['diet'] ?? '');
    $maxTime     = (int) ($_GET['maxTime'] ?? 0);
    $maxCost     = (float) ($_GET['maxCost'] ?? 0);

    if ($ingredients === '') jsonResponse(['error' => 'Please provide at least one ingredient'], 400);

    $apiResults = searchSpoonacular($ingredients, $diet, $maxTime, $maxCost);

    if ($apiResults !== null && count($apiResults) > 0) {
        jsonResponse(['source' => 'api', 'count' => count($apiResults), 'recipes' => $apiResults]);
    }

    $localResults = searchLocalRecipes($ingredients, $diet, $maxTime, $maxCost);
    jsonResponse(['source' => 'local', 'count' => count($localResults), 'recipes' => $localResults]);
}

// Calls the Spoonacular API to find recipes matching the given ingredients
function searchSpoonacular(string $ingredients, string $diet, int $maxTime, float $maxCost = 0): ?array {
    $apiKey = SPOONACULAR_API_KEY;
    if ($apiKey === '') return null;

    $params = [
        'apiKey'       => $apiKey,
        'ingredients'  => $ingredients,
        'number'       => 10,
        'ranking'      => 1,
        'ignorePantry' => true,
    ];

    $url = 'https://api.spoonacular.com/recipes/findByIngredients?' . http_build_query($params);
    $context = stream_context_create(['http' => ['timeout' => 5]]);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) return null;
    $data = json_decode($response, true);
    if (!is_array($data)) return null;

    $results = [];
    foreach ($data as $item) {
        $detailUrl = "https://api.spoonacular.com/recipes/{$item['id']}/information?apiKey={$apiKey}";
        $detail = @file_get_contents($detailUrl, false, $context);
        $info = $detail ? json_decode($detail, true) : null;

        $recipe = [
            'id'                 => null,
            'spoonacular_id'     => $item['id'],
            'title'              => $item['title'],
            'image_url'          => $item['image'] ?? null,
            'used_ingredients'   => array_map(fn($i) => $i['name'], $item['usedIngredients'] ?? []),
            'missed_ingredients' => array_map(fn($i) => $i['name'], $item['missedIngredients'] ?? []),
            'match_percentage'   => 0,
        ];

        if ($info) {
            $recipe['prep_time']      = $info['preparationMinutes'] ?? 0;
            $recipe['cook_time']      = $info['readyInMinutes'] ?? 0;
            $recipe['servings']       = $info['servings'] ?? 2;
            $recipe['estimated_cost'] = round(($info['pricePerServing'] ?? 0) * ($info['servings'] ?? 2) / 100, 2);
            $recipe['difficulty']     = ($info['readyInMinutes'] ?? 30) <= 15 ? 'easy' : (($info['readyInMinutes'] ?? 30) <= 30 ? 'medium' : 'hard');
            $recipe['ingredients']    = array_map(fn($i) => $i['original'], $info['extendedIngredients'] ?? []);
            $recipe['instructions']   = $info['instructions'] ?? '';
            $recipe['tags']           = $info['diets'] ?? [];

            // Apply user filters
            if ($diet !== '' && !in_array(strtolower($diet), array_map('strtolower', $recipe['tags']))) continue;
            if ($maxTime > 0 && $recipe['cook_time'] > $maxTime) continue;
            if ($maxCost > 0 && $recipe['estimated_cost'] > $maxCost) continue;

            // Skip egg-containing recipes for vegetarian/vegan
            $apiIngStr = strtolower(implode(' ', $recipe['ingredients']));
            if (in_array(strtolower($diet), ['vegetarian', 'vegan']) && preg_match('/\beggs?\b/', $apiIngStr)) continue;
        }

        $total = count($recipe['used_ingredients']) + count($recipe['missed_ingredients']);
        $recipe['match_percentage'] = $total > 0 ? round(count($recipe['used_ingredients']) / $total * 100) : 0;

        cacheApiRecipe($recipe);
        $results[] = $recipe;
    }

    usort($results, fn($a, $b) => $b['match_percentage'] - $a['match_percentage']);
    return $results;
}

// Saves an API recipe to the local DB so it's available offline
function cacheApiRecipe(array $recipe): void {
    if (empty($recipe['spoonacular_id'])) return;

    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM recipes WHERE spoonacular_id = ?');
    $stmt->execute([$recipe['spoonacular_id']]);
    if ($stmt->fetch()) return;

    $stmt = $db->prepare('
        INSERT INTO recipes (title, description, ingredients, instructions, prep_time, cook_time,
                           servings, estimated_cost, difficulty, image_url, tags, source, spoonacular_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "api", ?)
    ');
    $stmt->execute([
        $recipe['title'], '',
        json_encode($recipe['ingredients'] ?? []),
        json_encode(is_string($recipe['instructions']) ? [$recipe['instructions']] : ($recipe['instructions'] ?? [])),
        $recipe['prep_time'] ?? 0, $recipe['cook_time'] ?? 0,
        $recipe['servings'] ?? 2, $recipe['estimated_cost'] ?? 0,
        $recipe['difficulty'] ?? 'easy', $recipe['image_url'] ?? null,
        json_encode($recipe['tags'] ?? []), $recipe['spoonacular_id'],
    ]);
}

// Searches the local recipe database — scores results by how many ingredients match
function searchLocalRecipes(string $ingredientStr, string $diet, int $maxTime, float $maxCost): array {
    $db = getDB();
    $ingredientList = array_map('trim', explode(',', strtolower($ingredientStr)));

    $sql = 'SELECT * FROM recipes WHERE 1=1';
    $params = [];

    if ($maxTime > 0) { $sql .= ' AND (prep_time + cook_time) <= ?'; $params[] = $maxTime; }
    if ($maxCost > 0) { $sql .= ' AND estimated_cost <= ?'; $params[] = $maxCost; }
    if ($diet !== '')  { $sql .= ' AND JSON_CONTAINS(tags, ?)'; $params[] = json_encode(strtolower($diet)); }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $recipes = $stmt->fetchAll();

    // Load user's disliked ingredients to filter them out
    $userId = $_SESSION['user_id'] ?? null;
    $dislikedIngredients = [];
    if ($userId) {
        $uStmt = $db->prepare('SELECT disliked_ingredients FROM users WHERE id = ?');
        $uStmt->execute([$userId]);
        $uRow = $uStmt->fetch();
        if ($uRow && $uRow['disliked_ingredients']) {
            $dislikedIngredients = array_filter(array_map('trim', explode(',', strtolower($uRow['disliked_ingredients']))));
        }
    }

    $scored = [];
    $dietLower = strtolower($diet);

    foreach ($recipes as $recipe) {
        $recipeIngredients = json_decode($recipe['ingredients'], true) ?? [];
        $recipeIngStr = strtolower(implode(' ', $recipeIngredients));

        // Diet filtering — skip eggs for vegetarian, skip dairy/meat for vegan
        if (($dietLower === 'vegetarian' || $dietLower === 'vegan') && preg_match('/\beggs?\b/', $recipeIngStr)) continue;
        if ($dietLower === 'vegan' && preg_match('/\b(cheese|milk|butter|cream|yoghurt|honey|egg)\b/', $recipeIngStr)) continue;

        // Skip recipes with ingredients the user dislikes
        $hasDisliked = false;
        foreach ($dislikedIngredients as $dislike) {
            if ($dislike !== '' && str_contains($recipeIngStr, $dislike)) { $hasDisliked = true; break; }
        }
        if ($hasDisliked) continue;

        // Count how many of the user's ingredients appear in this recipe
        $matched = 0;
        $usedIngredients = [];
        foreach ($ingredientList as $ing) {
            if (str_contains($recipeIngStr, $ing)) { $matched++; $usedIngredients[] = $ing; }
        }
        if ($matched === 0) continue;

        // Figure out what the user is missing (ignoring pantry staples)
        $pantry = ['salt', 'pepper', 'oil', 'olive oil', 'butter', 'water'];
        $missedIngredients = [];
        foreach ($recipeIngredients as $ri) {
            $riLower = strtolower($ri);
            $found = false;
            foreach ($ingredientList as $ing) { if (str_contains($riLower, $ing)) { $found = true; break; } }
            if (!$found) {
                $isPantry = false;
                foreach ($pantry as $p) { if (str_contains($riLower, $p)) { $isPantry = true; break; } }
                if (!$isPantry) $missedIngredients[] = $ri;
            }
        }

        $total = count($usedIngredients) + count($missedIngredients);
        $matchPct = $total > 0 ? round($matched / $total * 100) : 0;

        $recipe['ingredients'] = $recipeIngredients;
        $recipe['instructions'] = json_decode($recipe['instructions'], true) ?? [];
        $recipe['tags'] = json_decode($recipe['tags'], true) ?? [];
        $recipe['used_ingredients'] = $usedIngredients;
        $recipe['missed_ingredients'] = $missedIngredients;
        $recipe['match_percentage'] = $matchPct;

        $scored[] = $recipe;
    }

    usort($scored, fn($a, $b) => $b['match_percentage'] - $a['match_percentage']);
    return array_slice($scored, 0, 15);
}

// Returns a single recipe with full details + whether the user has saved it
function getRecipe(): void {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) jsonResponse(['error' => 'Recipe ID required'], 400);

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM recipes WHERE id = ?');
    $stmt->execute([$id]);
    $recipe = $stmt->fetch();
    if (!$recipe) jsonResponse(['error' => 'Recipe not found'], 404);

    $recipe['ingredients'] = json_decode($recipe['ingredients'], true);
    $recipe['instructions'] = json_decode($recipe['instructions'], true);
    $recipe['tags'] = json_decode($recipe['tags'], true);

    $userId = getLoggedInUser();
    if ($userId !== false) {
        $stmt = $db->prepare('SELECT 1 FROM saved_recipes WHERE user_id = ? AND recipe_id = ?');
        $stmt->execute([$userId, $id]);
        $recipe['is_saved'] = (bool) $stmt->fetch();
    }

    jsonResponse($recipe);
}

// Bookmarks a recipe for the user
function saveRecipe(): void {
    $userId = requireLogin();
    $recipeId = (int) ($_GET['recipe_id'] ?? ($_POST['recipe_id'] ?? 0));
    if ($recipeId <= 0) jsonResponse(['error' => 'Recipe ID required'], 400);

    $db = getDB();
    $db->prepare('INSERT IGNORE INTO saved_recipes (user_id, recipe_id) VALUES (?, ?)')->execute([$userId, $recipeId]);

    $db->prepare('INSERT INTO activity_log (user_id, action_type, reference_id, description) VALUES (?, "recipe_saved", ?, "Saved a recipe")')
       ->execute([$userId, $recipeId]);

    jsonResponse(['success' => true]);
}

// Removes a recipe bookmark
function unsaveRecipe(): void {
    $userId = requireLogin();
    $recipeId = (int) ($_GET['recipe_id'] ?? ($_POST['recipe_id'] ?? 0));

    $db = getDB();
    $db->prepare('DELETE FROM saved_recipes WHERE user_id = ? AND recipe_id = ?')->execute([$userId, $recipeId]);
    jsonResponse(['success' => true]);
}

// Returns all recipes the user has saved
function getSavedRecipes(): void {
    $userId = requireLogin();
    $db = getDB();

    $stmt = $db->prepare('
        SELECT r.*, sr.saved_at FROM recipes r
        JOIN saved_recipes sr ON sr.recipe_id = r.id
        WHERE sr.user_id = ? ORDER BY sr.saved_at DESC
    ');
    $stmt->execute([$userId]);
    $recipes = $stmt->fetchAll();

    foreach ($recipes as &$r) {
        $r['ingredients'] = json_decode($r['ingredients'], true);
        $r['instructions'] = json_decode($r['instructions'], true);
        $r['tags'] = json_decode($r['tags'], true);
    }

    jsonResponse($recipes);
}

// Returns a handful of random recipes
function getRandomRecipes(): void {
    $count = min(max((int) ($_GET['count'] ?? 5), 1), 20);
    $db = getDB();

    $stmt = $db->query('SELECT * FROM recipes ORDER BY RAND() LIMIT ' . $count);
    $recipes = $stmt->fetchAll();

    foreach ($recipes as &$r) {
        $r['ingredients'] = json_decode($r['ingredients'], true);
        $r['instructions'] = json_decode($r['instructions'], true);
        $r['tags'] = json_decode($r['tags'], true);
    }

    jsonResponse($recipes);
}

// Returns the most popular recipes (by popularity_score), falls back to random
function getTrendingRecipes(): void {
    $count = min(max((int) ($_GET['count'] ?? 5), 1), 20);
    $db = getDB();

    try {
        $stmt = $db->query('SELECT * FROM recipes WHERE popularity_score > 0 ORDER BY popularity_score DESC LIMIT ' . $count);
        $recipes = $stmt->fetchAll();
    } catch (\Throwable $e) {
        $stmt = $db->query('SELECT * FROM recipes ORDER BY RAND() LIMIT ' . $count);
        $recipes = $stmt->fetchAll();
    }

    if (empty($recipes)) {
        $stmt = $db->query('SELECT * FROM recipes ORDER BY RAND() LIMIT ' . $count);
        $recipes = $stmt->fetchAll();
    }

    foreach ($recipes as &$r) {
        $r['ingredients'] = json_decode($r['ingredients'], true);
        $r['instructions'] = json_decode($r['instructions'], true);
        $r['tags'] = json_decode($r['tags'], true);
    }

    jsonResponse($recipes);
}

// Returns recipes under a given price (default £2.50), sorted cheapest first
function getBudgetRecipes(): void {
    $maxCost = (float) ($_GET['maxCost'] ?? 2.50);
    $count = min(max((int) ($_GET['count'] ?? 10), 1), 20);
    $db = getDB();

    $stmt = $db->prepare('SELECT * FROM recipes WHERE estimated_cost > 0 AND estimated_cost <= ? ORDER BY estimated_cost ASC LIMIT ' . $count);
    $stmt->execute([$maxCost]);
    $recipes = $stmt->fetchAll();

    foreach ($recipes as &$r) {
        $r['ingredients'] = json_decode($r['ingredients'], true);
        $r['instructions'] = json_decode($r['instructions'], true);
        $r['tags'] = json_decode($r['tags'], true);
    }

    jsonResponse($recipes);
}
