<?php
/**
 * ByteRight - Shopping List API
 *
 * POST /api/shopping.php?action=generate&meal_plan_id=1  - Generate from meal plan
 * GET  /api/shopping.php?action=get&id=1                  - Get a shopping list
 * GET  /api/shopping.php?action=current                    - Get list for current week
 * POST /api/shopping.php?action=check                      - Toggle item checked
 * POST /api/shopping.php?action=add_item                   - Add custom item
 * DELETE /api/shopping.php?action=remove_item&item_id=1   - Remove item
 */

require_once __DIR__ . '/../config/database.php';
startSession();

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'generate':
        generateShoppingList();
        break;
    case 'get':
        getShoppingList();
        break;
    case 'current':
        getCurrentList();
        break;
    case 'check':
        toggleItemCheck();
        break;
    case 'add_item':
        addItem();
        break;
    case 'remove_item':
        removeItem();
        break;
    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}

/**
 * Generate a shopping list from a meal plan by aggregating all recipe ingredients
 */
function generateShoppingList(): void {
    $userId = requireLogin();
    $mealPlanId = (int) ($_GET['meal_plan_id'] ?? ($_POST['meal_plan_id'] ?? 0));
    $db = getDB();

    if ($mealPlanId <= 0) {
        jsonResponse(['error' => 'meal_plan_id is required'], 400);
    }

    // Verify ownership
    $stmt = $db->prepare('SELECT id FROM meal_plans WHERE id = ? AND user_id = ?');
    $stmt->execute([$mealPlanId, $userId]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Meal plan not found'], 404);
    }

    // Get all recipe ingredients from the plan
    $stmt = $db->prepare('
        SELECT r.ingredients, r.estimated_cost, r.servings
        FROM meal_plan_items mpi
        JOIN recipes r ON r.id = mpi.recipe_id
        WHERE mpi.meal_plan_id = ?
    ');
    $stmt->execute([$mealPlanId]);
    $meals = $stmt->fetchAll();

    // Aggregate ingredients
    $ingredientMap = [];
    $totalCost = 0;

    foreach ($meals as $meal) {
        $ingredients = json_decode($meal['ingredients'], true) ?? [];
        $totalCost += ($meal['estimated_cost'] ?? 0);

        foreach ($ingredients as $ing) {
            $parsed = parseIngredient($ing);
            $key = strtolower($parsed['name']);

            if (isset($ingredientMap[$key])) {
                $existingQty  = $ingredientMap[$key]['quantity'];
                $newQty       = $parsed['quantity'];
                $existingUnit = strtolower($ingredientMap[$key]['unit']);
                $newUnit      = strtolower($parsed['unit']);

                if (is_numeric($existingQty) && is_numeric($newQty) && $existingUnit === $newUnit) {
                    // Same unit — add them together
                    $ingredientMap[$key]['quantity'] = (string)($existingQty + $newQty);
                } elseif ($newQty !== '' && $newQty !== $existingQty) {
                    // Different units — show both
                    $ingredientMap[$key]['quantity'] = $existingQty . ' + ' . $newQty . ($newUnit ? ' ' . $parsed['unit'] : '');
                }
            } else {
                $ingredientMap[$key] = $parsed;
            }
        }
    }

    // Delete existing shopping list for this plan
    $stmt = $db->prepare('SELECT id FROM shopping_lists WHERE meal_plan_id = ? AND user_id = ?');
    $stmt->execute([$mealPlanId, $userId]);
    $existing = $stmt->fetch();
    if ($existing) {
        $db->prepare('DELETE FROM shopping_lists WHERE id = ?')->execute([$existing['id']]);
    }

    // Create new shopping list
    $stmt = $db->prepare('
        INSERT INTO shopping_lists (user_id, meal_plan_id, name, estimated_total)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([$userId, $mealPlanId, 'Week Shopping List', $totalCost]);
    $listId = (int) $db->lastInsertId();

    // Insert items
    $insertStmt = $db->prepare('
        INSERT INTO shopping_list_items (shopping_list_id, ingredient_name, quantity, unit, category, estimated_price)
        VALUES (?, ?, ?, ?, ?, ?)
    ');

    foreach ($ingredientMap as $item) {
        $category = categorizeIngredient($item['name']);
        $price = estimatePrice($item['name']);
        $insertStmt->execute([
            $listId,
            $item['name'],
            $item['quantity'],
            $item['unit'],
            $category,
            $price,
        ]);
    }

    // Return the full list
    jsonResponse(loadShoppingList($db, $listId));
}

/**
 * Parse an ingredient string into name, quantity, unit
 */
function parseIngredient(string $raw): array {
    $raw = trim($raw);

    // Common patterns: "200g pasta", "2 eggs", "1 tbsp oil", "salt"
    $pattern = '/^([\d\/\.]+)?\s*(g|kg|ml|l|tbsp|tsp|cup|cups|handful|pinch|can|cans|slice|slices|clove|cloves|pack|packs)?\s*(?:of\s+)?(.+)$/i';

    if (preg_match($pattern, $raw, $m)) {
        $quantityStr = trim($m[1] ?? '');
        $quantity = '';
        if ($quantityStr !== '') {
            if (str_contains($quantityStr, '/')) {
                [$num, $den] = explode('/', $quantityStr, 2);
                $quantity = $den != 0 ? (string) round((float)$num / (float)$den, 2) : $quantityStr;
            } else {
                $quantity = $quantityStr;
            }
        }
        return [
            'quantity' => $quantity,
            'unit'     => trim($m[2] ?? ''),
            'name'     => trim($m[3] ?? $raw),
        ];
    }

    return ['quantity' => '', 'unit' => '', 'name' => $raw];
}

/**
 * Categorize an ingredient into shopping sections
 */
function categorizeIngredient(string $name): string {
    $name = strtolower($name);

    $fresh = ['tomato', 'onion', 'garlic', 'pepper', 'carrot', 'potato', 'lettuce',
              'cucumber', 'banana', 'lemon', 'lime', 'avocado', 'mushroom', 'courgette',
              'broccoli', 'spinach', 'berries', 'spring onion', 'coriander', 'basil',
              'dill', 'parsley', 'ginger', 'sweet potato', 'green beans'];

    $fridge = ['milk', 'cream', 'cheese', 'butter', 'yoghurt', 'yogurt', 'egg', 'eggs',
               'chicken', 'beef', 'lamb', 'pork', 'salmon', 'fish', 'bacon', 'ham',
               'sour cream', 'creme fraiche', 'feta', 'parmesan', 'mozzarella',
               'tuna', 'frozen', 'ice'];

    foreach ($fresh as $f) {
        if (str_contains($name, $f)) return 'fresh_produce';
    }
    foreach ($fridge as $f) {
        if (str_contains($name, $f)) return 'fridge_freezer';
    }

    return 'store_cupboard';
}

/**
 * Rough price estimate for an ingredient (GBP)
 */
function estimatePrice(string $name): float {
    $name = strtolower($name);

    $prices = [
        'chicken' => 3.00, 'salmon' => 4.00, 'beef' => 3.50, 'lamb' => 4.00,
        'fish'    => 3.50, 'bacon'  => 2.00, 'egg'  => 1.50, 'eggs' => 1.50,
        'milk'    => 1.10, 'cheese' => 2.00, 'butter' => 1.50, 'bread' => 1.00,
        'pasta'   => 0.80, 'rice'   => 1.00, 'noodle' => 0.60, 'tortilla' => 1.20,
        'tomato'  => 0.50, 'onion'  => 0.30, 'potato' => 0.80, 'carrot' => 0.40,
        'pepper'  => 0.60, 'garlic' => 0.40, 'mushroom' => 0.90, 'avocado' => 1.00,
        'lemon'   => 0.30, 'banana' => 0.15, 'coconut milk' => 1.00,
        'olive oil' => 2.50, 'soy sauce' => 1.50, 'honey' => 2.00,
        'can'     => 0.60, 'beans'  => 0.60, 'chickpea' => 0.60, 'lentil' => 0.80,
        'oats'    => 0.80, 'flour'  => 0.65, 'sugar'  => 0.65,
    ];

    foreach ($prices as $key => $price) {
        if (str_contains($name, $key)) return $price;
    }

    return 0.50; // Default estimate
}

function getShoppingList(): void {
    $userId = requireLogin();
    $listId = (int) ($_GET['id'] ?? 0);

    if ($listId <= 0) {
        jsonResponse(['error' => 'Shopping list ID required'], 400);
    }

    $db = getDB();

    // Verify ownership
    $stmt = $db->prepare('SELECT id FROM shopping_lists WHERE id = ? AND user_id = ?');
    $stmt->execute([$listId, $userId]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Shopping list not found'], 404);
    }

    jsonResponse(loadShoppingList($db, $listId));
}

function getCurrentList(): void {
    $userId = requireLogin();
    $db = getDB();

    $stmt = $db->prepare('
        SELECT sl.* FROM shopping_lists sl
        JOIN meal_plans mp ON mp.id = sl.meal_plan_id
        WHERE sl.user_id = ?
        ORDER BY sl.created_at DESC LIMIT 1
    ');
    $stmt->execute([$userId]);
    $list = $stmt->fetch();

    if (!$list) {
        jsonResponse(['error' => 'No shopping list found'], 404);
    }

    jsonResponse(loadShoppingList($db, $list['id']));
}

function loadShoppingList(PDO $db, int $listId): array {
    $stmt = $db->prepare('SELECT * FROM shopping_lists WHERE id = ?');
    $stmt->execute([$listId]);
    $list = $stmt->fetch();

    $stmt = $db->prepare('SELECT * FROM shopping_list_items WHERE shopping_list_id = ? ORDER BY category, ingredient_name');
    $stmt->execute([$listId]);
    $items = $stmt->fetchAll();

    // Group by category
    $grouped = [
        'fresh_produce'  => [],
        'fridge_freezer' => [],
        'store_cupboard' => [],
        'other'          => [],
    ];
    $totalEstimate = 0;

    foreach ($items as $item) {
        $cat = $item['category'] ?? 'other';
        $grouped[$cat][] = $item;
        $totalEstimate += ($item['estimated_price'] ?? 0);
    }

    $list['items'] = $items;
    $list['items_grouped'] = $grouped;
    $list['calculated_total'] = round($totalEstimate, 2);

    return $list;
}

function toggleItemCheck(): void {
    $userId = requireLogin();
    $data = getRequestBody();
    $itemId = (int) ($data['item_id'] ?? 0);

    if ($itemId <= 0) {
        jsonResponse(['error' => 'item_id required'], 400);
    }

    $db = getDB();

    // Verify ownership
    $stmt = $db->prepare('
        SELECT sli.id, sli.checked FROM shopping_list_items sli
        JOIN shopping_lists sl ON sl.id = sli.shopping_list_id
        WHERE sli.id = ? AND sl.user_id = ?
    ');
    $stmt->execute([$itemId, $userId]);
    $item = $stmt->fetch();

    if (!$item) {
        jsonResponse(['error' => 'Item not found'], 404);
    }

    $newChecked = $item['checked'] ? 0 : 1;
    $db->prepare('UPDATE shopping_list_items SET checked = ? WHERE id = ?')
       ->execute([$newChecked, $itemId]);

    jsonResponse(['success' => true, 'checked' => (bool) $newChecked]);
}

function addItem(): void {
    $userId = requireLogin();
    $data = getRequestBody();
    $db = getDB();

    $listId = (int) ($data['shopping_list_id'] ?? 0);
    $name   = trim($data['ingredient_name'] ?? '');

    if ($listId <= 0 || $name === '') {
        jsonResponse(['error' => 'shopping_list_id and ingredient_name required'], 400);
    }

    // Verify ownership
    $stmt = $db->prepare('SELECT id FROM shopping_lists WHERE id = ? AND user_id = ?');
    $stmt->execute([$listId, $userId]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Shopping list not found'], 404);
    }

    $category = categorizeIngredient($name);
    $price = estimatePrice($name);

    $stmt = $db->prepare('
        INSERT INTO shopping_list_items (shopping_list_id, ingredient_name, quantity, category, estimated_price)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$listId, $name, $data['quantity'] ?? '', $category, $price]);

    jsonResponse(['success' => true, 'item_id' => (int) $db->lastInsertId()]);
}

function removeItem(): void {
    $userId = requireLogin();
    $itemId = (int) ($_GET['item_id'] ?? 0);

    if ($itemId <= 0) {
        jsonResponse(['error' => 'item_id required'], 400);
    }

    $db = getDB();

    // Verify ownership
    $stmt = $db->prepare('
        SELECT sli.id FROM shopping_list_items sli
        JOIN shopping_lists sl ON sl.id = sli.shopping_list_id
        WHERE sli.id = ? AND sl.user_id = ?
    ');
    $stmt->execute([$itemId, $userId]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Item not found'], 404);
    }

    $db->prepare('DELETE FROM shopping_list_items WHERE id = ?')->execute([$itemId]);
    jsonResponse(['success' => true]);
}
