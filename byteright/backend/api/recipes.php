<?php
/**
 * ByteRight - Recipe API
 *
 * GET  /api/recipes.php?action=search&ingredients=eggs,tomato&diet=vegetarian&maxTime=20&maxCost=5
 * GET  /api/recipes.php?action=get&id=1
 * POST /api/recipes.php?action=save&recipe_id=1
 * POST /api/recipes.php?action=unsave&recipe_id=1
 * GET  /api/recipes.php?action=saved
 * GET  /api/recipes.php?action=random&count=5
 */

require_once __DIR__ . '/../config/database.php';
startSession();

$action = $_GET['action'] ?? 'search';

switch ($action) {
    case 'search':
        searchRecipes();
        break;
    case 'get':
        getRecipe();
        break;
    case 'save':
        saveRecipe();
        break;
    case 'unsave':
        unsaveRecipe();
        break;
    case 'saved':
        getSavedRecipes();
        break;
    case 'random':
        getRandomRecipes();
        break;
    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}

/**
 * Search recipes by ingredients, with optional diet/time/cost filters.
 * Tries Spoonacular API first, falls back to local DB.
 */
function searchRecipes(): void {
    $ingredients = trim($_GET['ingredients'] ?? '');
    $diet        = trim($_GET['diet'] ?? '');
    $maxTime     = (int) ($_GET['maxTime'] ?? 0);
    $maxCost     = (float) ($_GET['maxCost'] ?? 0);

    if ($ingredients === '') {
        jsonResponse(['error' => 'Please provide at least one ingredient'], 400);
    }

    // Try Spoonacular API first
    $apiResults = searchSpoonacular($ingredients, $diet, $maxTime);

    if ($apiResults !== null && count($apiResults) > 0) {
        jsonResponse([
            'source'  => 'api',
            'count'   => count($apiResults),
            'recipes' => $apiResults
        ]);
    }

    // Fallback to local database
    $localResults = searchLocalRecipes($ingredients, $diet, $maxTime, $maxCost);
    jsonResponse([
        'source'  => 'local',
        'count'   => count($localResults),
        'recipes' => $localResults
    ]);
}

/**
 * Search Spoonacular API for recipes by ingredients
 */
function searchSpoonacular(string $ingredients, string $diet, int $maxTime): ?array {
    $apiKey = SPOONACULAR_API_KEY;
    if ($apiKey === '') {
        return null; // No API key configured
    }

    $params = [
        'apiKey'             => $apiKey,
        'ingredients'        => $ingredients,
        'number'             => 10,
        'ranking'            => 1,           // maximize used ingredients
        'ignorePantry'       => true,
    ];

    $url = 'https://api.spoonacular.com/recipes/findByIngredients?' . http_build_query($params);

    $context = stream_context_create(['http' => ['timeout' => 5]]);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return null;
    }

    $results = [];
    foreach ($data as $item) {
        // Get full recipe info for each result
        $detailUrl = "https://api.spoonacular.com/recipes/{$item['id']}/information?apiKey={$apiKey}";
        $detail = @file_get_contents($detailUrl, false, $context);
        $info = $detail ? json_decode($detail, true) : null;

        $recipe = [
            'id'               => null,
            'spoonacular_id'   => $item['id'],
            'title'            => $item['title'],
            'image_url'        => $item['image'] ?? null,
            'used_ingredients' => array_map(fn($i) => $i['name'], $item['usedIngredients'] ?? []),
            'missed_ingredients' => array_map(fn($i) => $i['name'], $item['missedIngredients'] ?? []),
            'match_percentage' => 0,
        ];

        if ($info) {
            $recipe['prep_time']       = $info['preparationMinutes'] ?? 0;
            $recipe['cook_time']       = $info['readyInMinutes'] ?? 0;
            $recipe['servings']        = $info['servings'] ?? 2;
            $recipe['estimated_cost']  = round(($info['pricePerServing'] ?? 0) * ($info['servings'] ?? 2) / 100, 2);
            $recipe['difficulty']      = ($info['readyInMinutes'] ?? 30) <= 15 ? 'easy' : (($info['readyInMinutes'] ?? 30) <= 30 ? 'medium' : 'hard');
            $recipe['ingredients']     = array_map(fn($i) => $i['original'], $info['extendedIngredients'] ?? []);
            $recipe['instructions']    = $info['instructions'] ?? '';
            $recipe['tags']            = $info['diets'] ?? [];

            // Apply filters
            if ($diet !== '' && !in_array(strtolower($diet), array_map('strtolower', $recipe['tags']))) {
                continue;
            }
            if ($maxTime > 0 && $recipe['cook_time'] > $maxTime) {
                continue;
            }

            // Vegetarian/vegan: skip recipes containing egg in ingredients
            $apiIngStr = strtolower(implode(' ', $recipe['ingredients']));
            if (strtolower($diet) === 'vegetarian' || strtolower($diet) === 'vegan') {
                if (preg_match('/\beggs?\b/', $apiIngStr)) {
                    continue;
                }
            }
        }

        $total = count($recipe['used_ingredients']) + count($recipe['missed_ingredients']);
        $recipe['match_percentage'] = $total > 0
            ? round(count($recipe['used_ingredients']) / $total * 100)
            : 0;

        // Cache to local DB
        cacheApiRecipe($recipe);

        $results[] = $recipe;
    }

    // Sort by match percentage
    usort($results, fn($a, $b) => $b['match_percentage'] - $a['match_percentage']);

    return $results;
}

/**
 * Cache an API recipe into local database
 */
function cacheApiRecipe(array $recipe): void {
    if (empty($recipe['spoonacular_id'])) return;

    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM recipes WHERE spoonacular_id = ?');
    $stmt->execute([$recipe['spoonacular_id']]);

    if ($stmt->fetch()) return; // Already cached

    $stmt = $db->prepare('
        INSERT INTO recipes (title, description, ingredients, instructions, prep_time, cook_time,
                           servings, estimated_cost, difficulty, image_url, tags, source, spoonacular_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "api", ?)
    ');
    $stmt->execute([
        $recipe['title'],
        '',
        json_encode($recipe['ingredients'] ?? []),
        json_encode(is_string($recipe['instructions']) ? [$recipe['instructions']] : ($recipe['instructions'] ?? [])),
        $recipe['prep_time'] ?? 0,
        $recipe['cook_time'] ?? 0,
        $recipe['servings'] ?? 2,
        $recipe['estimated_cost'] ?? 0,
        $recipe['difficulty'] ?? 'easy',
        $recipe['image_url'] ?? null,
        json_encode($recipe['tags'] ?? []),
        $recipe['spoonacular_id'],
    ]);
}

/**
 * Search local recipe database by ingredients
 */
function searchLocalRecipes(string $ingredientStr, string $diet, int $maxTime, float $maxCost): array {
    $db = getDB();
    $ingredientList = array_map('trim', explode(',', strtolower($ingredientStr)));

    // Build query
    $sql = 'SELECT * FROM recipes WHERE 1=1';
    $params = [];

    // Time filter
    if ($maxTime > 0) {
        $sql .= ' AND (prep_time + cook_time) <= ?';
        $params[] = $maxTime;
    }

    // Cost filter
    if ($maxCost > 0) {
        $sql .= ' AND estimated_cost <= ?';
        $params[] = $maxCost;
    }

    // Diet filter via tags JSON
    if ($diet !== '') {
        $sql .= ' AND JSON_CONTAINS(tags, ?)';
        $params[] = json_encode(strtolower($diet));
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $recipes = $stmt->fetchAll();

    // Score by ingredient match
    $scored = [];
    $dietLower = strtolower($diet);
    foreach ($recipes as $recipe) {
        $recipeIngredients = json_decode($recipe['ingredients'], true) ?? [];
        $recipeIngStr = strtolower(implode(' ', $recipeIngredients));

        // Vegetarian/vegan: skip recipes containing egg in ingredients
        if ($dietLower === 'vegetarian' || $dietLower === 'vegan') {
            if (preg_match('/\beggs?\b/', $recipeIngStr)) {
                continue;
            }
        }
        // Vegan: also skip recipes containing dairy/meat products
        if ($dietLower === 'vegan') {
            if (preg_match('/\b(cheese|milk|butter|cream|yoghurt|honey|egg)\b/', $recipeIngStr)) {
                continue;
            }
        }

        $matched = 0;
        $usedIngredients = [];
        $missedIngredients = [];

        foreach ($ingredientList as $ing) {
            if (str_contains($recipeIngStr, $ing)) {
                $matched++;
                $usedIngredients[] = $ing;
            }
        }

        if ($matched === 0) continue; // Skip recipes with no ingredient match

        // Find missing key ingredients (skip pantry staples)
        $pantry = ['salt', 'pepper', 'oil', 'olive oil', 'butter', 'water'];
        foreach ($recipeIngredients as $ri) {
            $riLower = strtolower($ri);
            $found = false;
            foreach ($ingredientList as $ing) {
                if (str_contains($riLower, $ing)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $isPantry = false;
                foreach ($pantry as $p) {
                    if (str_contains($riLower, $p)) {
                        $isPantry = true;
                        break;
                    }
                }
                if (!$isPantry) {
                    $missedIngredients[] = $ri;
                }
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

    // Sort by match percentage descending
    usort($scored, fn($a, $b) => $b['match_percentage'] - $a['match_percentage']);

    return array_slice($scored, 0, 15);
}

function getRecipe(): void {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['error' => 'Recipe ID required'], 400);
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM recipes WHERE id = ?');
    $stmt->execute([$id]);
    $recipe = $stmt->fetch();

    if (!$recipe) {
        jsonResponse(['error' => 'Recipe not found'], 404);
    }

    $recipe['ingredients'] = json_decode($recipe['ingredients'], true);
    $recipe['instructions'] = json_decode($recipe['instructions'], true);
    $recipe['tags'] = json_decode($recipe['tags'], true);

    // Check if saved by current user
    $userId = getLoggedInUser();
    if ($userId !== false) {
        $stmt = $db->prepare('SELECT 1 FROM saved_recipes WHERE user_id = ? AND recipe_id = ?');
        $stmt->execute([$userId, $id]);
        $recipe['is_saved'] = (bool) $stmt->fetch();
    }

    jsonResponse($recipe);
}

function saveRecipe(): void {
    $userId = requireLogin();
    $recipeId = (int) ($_GET['recipe_id'] ?? ($_POST['recipe_id'] ?? 0));

    if ($recipeId <= 0) {
        jsonResponse(['error' => 'Recipe ID required'], 400);
    }

    $db = getDB();
    $stmt = $db->prepare('INSERT IGNORE INTO saved_recipes (user_id, recipe_id) VALUES (?, ?)');
    $stmt->execute([$userId, $recipeId]);

    // Log activity
    $db->prepare('INSERT INTO activity_log (user_id, action_type, reference_id, description) VALUES (?, "recipe_saved", ?, "Saved a recipe")')
       ->execute([$userId, $recipeId]);

    jsonResponse(['success' => true]);
}

function unsaveRecipe(): void {
    $userId = requireLogin();
    $recipeId = (int) ($_GET['recipe_id'] ?? ($_POST['recipe_id'] ?? 0));

    $db = getDB();
    $db->prepare('DELETE FROM saved_recipes WHERE user_id = ? AND recipe_id = ?')
       ->execute([$userId, $recipeId]);

    jsonResponse(['success' => true]);
}

function getSavedRecipes(): void {
    $userId = requireLogin();
    $db = getDB();

    $stmt = $db->prepare('
        SELECT r.*, sr.saved_at
        FROM recipes r
        JOIN saved_recipes sr ON sr.recipe_id = r.id
        WHERE sr.user_id = ?
        ORDER BY sr.saved_at DESC
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

function getRandomRecipes(): void {
    $count = min((int) ($_GET['count'] ?? 5), 20);
    $db = getDB();

    $stmt = $db->prepare('SELECT * FROM recipes ORDER BY RAND() LIMIT ?');
    $stmt->execute([$count]);
    $recipes = $stmt->fetchAll();

    foreach ($recipes as &$r) {
        $r['ingredients'] = json_decode($r['ingredients'], true);
        $r['instructions'] = json_decode($r['instructions'], true);
        $r['tags'] = json_decode($r['tags'], true);
    }

    jsonResponse($recipes);
}
