<?php
/**
 * ByteRight - Safe Setup / Repair Script
 *
 * What this version does:
 * - NEVER drops the database
 * - NEVER deletes existing users
 * - Creates the database if missing
 * - Executes schema.sql safely
 * - Ignores harmless "already exists" / duplicate seed errors
 * - Repairs missing columns on older databases
 * - Verifies core tables exist
 *
 * Run:
 *   php backend/setup.php
 * or open:
 *   http://localhost/Byte-Right/backend/setup.php
 */

declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

function out(string $text = ''): void
{
    echo $text . PHP_EOL;
}

function warning(string $text): void
{
    out("WARNING: " . $text);
}

function success(string $text): void
{
    out("SUCCESS: " . $text);
}

function info(string $text): void
{
    out($text);
}

function tableExists(PDO $pdo, string $dbName, string $tableName): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = ?
          AND table_name = ?
    ");
    $stmt->execute([$dbName, $tableName]);
    return (int)$stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $dbName, string $tableName, string $columnName): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = ?
          AND table_name = ?
          AND column_name = ?
    ");
    $stmt->execute([$dbName, $tableName, $columnName]);
    return (int)$stmt->fetchColumn() > 0;
}

function indexExists(PDO $pdo, string $dbName, string $tableName, string $indexName): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = ?
          AND table_name = ?
          AND index_name = ?
    ");
    $stmt->execute([$dbName, $tableName, $indexName]);
    return (int)$stmt->fetchColumn() > 0;
}

function foreignKeyExists(PDO $pdo, string $dbName, string $tableName, string $constraintName): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.table_constraints
        WHERE table_schema = ?
          AND table_name = ?
          AND constraint_type = 'FOREIGN KEY'
          AND constraint_name = ?
    ");
    $stmt->execute([$dbName, $tableName, $constraintName]);
    return (int)$stmt->fetchColumn() > 0;
}

function shouldIgnoreSqlError(PDOException $e): bool
{
    $message = strtolower($e->getMessage());

    $ignorablePhrases = [
        'already exists',
        'duplicate entry',
        'duplicate key name',
        'duplicate column name',
        'multiple primary key defined',
        'cannot add foreign key constraint', // often appears if table already partially exists; we repair separately
        'failed to open the referenced table', // repair handled later
    ];

    foreach ($ignorablePhrases as $phrase) {
        if (str_contains($message, $phrase)) {
            return true;
        }
    }

    return false;
}

function splitSqlStatements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inSingle = false;
    $inDouble = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $prev = $i > 0 ? $sql[$i - 1] : '';

        if ($char === "'" && !$inDouble && $prev !== '\\') {
            $inSingle = !$inSingle;
        } elseif ($char === '"' && !$inSingle && $prev !== '\\') {
            $inDouble = !$inDouble;
        }

        if ($char === ';' && !$inSingle && !$inDouble) {
            $trimmed = trim($buffer);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $trimmed = trim($buffer);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

function runStatement(PDO $pdo, string $sql, int &$executed, int &$warnings): void
{
    try {
        $pdo->exec($sql);
        $executed++;
    } catch (PDOException $e) {
        if (shouldIgnoreSqlError($e)) {
            $warnings++;
            warning(substr($e->getMessage(), 0, 180));
            return;
        }
        throw $e;
    }
}

out("=== ByteRight Safe Database Setup ===");
out();

$host = 'localhost';
$user = 'root';
$pass = '';
$dbName = 'byteright';

try {
    $pdo = new PDO(
        "mysql:host={$host};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // Create DB if missing
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbName}`");

    success("Database '{$dbName}' is available.");
    info("Existing users will be preserved.");
    out();

    $schemaPath = __DIR__ . '/schema.sql';
    if (!file_exists($schemaPath)) {
        throw new RuntimeException("schema.sql not found at {$schemaPath}");
    }

    $sql = file_get_contents($schemaPath);
    if ($sql === false) {
        throw new RuntimeException("Could not read schema.sql");
    }

    // Remove full-line comments that start with --
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);

    // Remove CREATE DATABASE / USE lines because we already handle that here
    $sql = preg_replace('/CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS\s+byteright\s*;/i', '', $sql);
    $sql = preg_replace('/CREATE\s+DATABASE\s+byteright\s*;/i', '', $sql);
    $sql = preg_replace('/USE\s+byteright\s*;/i', '', $sql);

    $statements = splitSqlStatements($sql);

    $executed = 0;
    $warnings = 0;

    foreach ($statements as $statement) {
        if (trim($statement) === '') {
            continue;
        }
        runStatement($pdo, $statement, $executed, $warnings);
    }

    out();
    info("=== Repairing / backfilling schema for older databases ===");

    // USERS repairs
    if (tableExists($pdo, $dbName, 'users')) {
        if (!columnExists($pdo, $dbName, 'users', 'university')) {
            runStatement($pdo, "ALTER TABLE users ADD COLUMN university VARCHAR(150) DEFAULT NULL AFTER password_hash", $executed, $warnings);
        }
        if (!columnExists($pdo, $dbName, 'users', 'avatar_path')) {
            runStatement($pdo, "ALTER TABLE users ADD COLUMN avatar_path VARCHAR(255) DEFAULT NULL AFTER university", $executed, $warnings);
        }
        if (!columnExists($pdo, $dbName, 'users', 'weekly_budget')) {
            runStatement($pdo, "ALTER TABLE users ADD COLUMN weekly_budget DECIMAL(6,2) DEFAULT 30.00 AFTER avatar_path", $executed, $warnings);
        }
        if (!columnExists($pdo, $dbName, 'users', 'cooking_time_pref')) {
            runStatement($pdo, "ALTER TABLE users ADD COLUMN cooking_time_pref ENUM('under15', 'under30', 'under60', 'any') DEFAULT 'under30' AFTER weekly_budget", $executed, $warnings);
        }
        if (!columnExists($pdo, $dbName, 'users', 'meal_plan_pref')) {
            runStatement($pdo, "ALTER TABLE users ADD COLUMN meal_plan_pref ENUM('balanced', 'high_protein', 'low_carb', 'budget') DEFAULT 'balanced' AFTER cooking_time_pref", $executed, $warnings);
        }
        if (!columnExists($pdo, $dbName, 'users', 'allergies')) {
            runStatement($pdo, "ALTER TABLE users ADD COLUMN allergies TEXT DEFAULT NULL AFTER meal_plan_pref", $executed, $warnings);
        }
        if (!columnExists($pdo, $dbName, 'users', 'liked_ingredients')) {
            runStatement($pdo, "ALTER TABLE users ADD COLUMN liked_ingredients TEXT DEFAULT NULL AFTER allergies", $executed, $warnings);
        }
        if (!columnExists($pdo, $dbName, 'users', 'disliked_ingredients')) {
            runStatement($pdo, "ALTER TABLE users ADD COLUMN disliked_ingredients TEXT DEFAULT NULL AFTER liked_ingredients", $executed, $warnings);
        }
        if (!columnExists($pdo, $dbName, 'users', 'created_at')) {
            runStatement($pdo, "ALTER TABLE users ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP", $executed, $warnings);
        }
        if (!columnExists($pdo, $dbName, 'users', 'updated_at')) {
            runStatement($pdo, "ALTER TABLE users ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP", $executed, $warnings);
        }
    }

    // RECIPES repairs
    if (tableExists($pdo, $dbName, 'recipes')) {
        if (!columnExists($pdo, $dbName, 'recipes', 'estimated_cost')) {
            runStatement($pdo, "ALTER TABLE recipes ADD COLUMN estimated_cost DECIMAL(6,2) DEFAULT 0.00 AFTER servings", $executed, $warnings);
        }
        if (!columnExists($pdo, $dbName, 'recipes', 'difficulty')) {
            runStatement($pdo, "ALTER TABLE recipes ADD COLUMN difficulty ENUM('easy', 'medium', 'hard') DEFAULT 'easy' AFTER estimated_cost", $executed, $warnings);
        }
        if (!columnExists($pdo, $dbName, 'recipes', 'image_url')) {
            runStatement($pdo, "ALTER TABLE recipes ADD COLUMN image_url VARCHAR(500) DEFAULT NULL AFTER difficulty", $executed, $warnings);
        }
        if (!columnExists($pdo, $dbName, 'recipes', 'tags')) {
            runStatement($pdo, "ALTER TABLE recipes ADD COLUMN tags JSON DEFAULT NULL AFTER image_url", $executed, $warnings);
        }
        if (!columnExists($pdo, $dbName, 'recipes', 'source')) {
            runStatement($pdo, "ALTER TABLE recipes ADD COLUMN source ENUM('api', 'local', 'user') DEFAULT 'local' AFTER tags", $executed, $warnings);
        }
        if (!columnExists($pdo, $dbName, 'recipes', 'spoonacular_id')) {
            runStatement($pdo, "ALTER TABLE recipes ADD COLUMN spoonacular_id INT DEFAULT NULL AFTER source", $executed, $warnings);
        }
        if (!columnExists($pdo, $dbName, 'recipes', 'popularity_score')) {
            runStatement($pdo, "ALTER TABLE recipes ADD COLUMN popularity_score INT DEFAULT 0 AFTER spoonacular_id", $executed, $warnings);
        }
        if (!columnExists($pdo, $dbName, 'recipes', 'created_at')) {
            runStatement($pdo, "ALTER TABLE recipes ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP", $executed, $warnings);
        }
        if (!indexExists($pdo, $dbName, 'recipes', 'idx_recipes_spoonacular')) {
            runStatement($pdo, "CREATE INDEX idx_recipes_spoonacular ON recipes(spoonacular_id)", $executed, $warnings);
        }
        if (!indexExists($pdo, $dbName, 'recipes', 'unique_recipe_title')) {
            runStatement($pdo, "ALTER TABLE recipes ADD UNIQUE KEY unique_recipe_title (title)", $executed, $warnings);
        }
    }

    // MEAL PLANS repairs
    if (tableExists($pdo, $dbName, 'meal_plans')) {
        if (!columnExists($pdo, $dbName, 'meal_plans', 'budget_target')) {
            runStatement($pdo, "ALTER TABLE meal_plans ADD COLUMN budget_target DECIMAL(6,2) DEFAULT NULL AFTER week_start", $executed, $warnings);
        }
        if (!columnExists($pdo, $dbName, 'meal_plans', 'total_estimated_cost')) {
            runStatement($pdo, "ALTER TABLE meal_plans ADD COLUMN total_estimated_cost DECIMAL(6,2) DEFAULT 0.00 AFTER budget_target", $executed, $warnings);
        }
        if (!indexExists($pdo, $dbName, 'meal_plans', 'unique_user_week')) {
            runStatement($pdo, "ALTER TABLE meal_plans ADD UNIQUE KEY unique_user_week (user_id, week_start)", $executed, $warnings);
        }
    }

    // MEAL PLAN ITEMS repairs
    if (tableExists($pdo, $dbName, 'meal_plan_items')) {
        if (!columnExists($pdo, $dbName, 'meal_plan_items', 'estimated_cost')) {
            runStatement($pdo, "ALTER TABLE meal_plan_items ADD COLUMN estimated_cost DECIMAL(6,2) DEFAULT NULL AFTER custom_meal_name", $executed, $warnings);
        }
    }

    // SHOPPING LISTS repairs
    if (tableExists($pdo, $dbName, 'shopping_lists')) {
        if (!columnExists($pdo, $dbName, 'shopping_lists', 'estimated_total')) {
            runStatement($pdo, "ALTER TABLE shopping_lists ADD COLUMN estimated_total DECIMAL(6,2) DEFAULT 0.00 AFTER name", $executed, $warnings);
        }
    }

    // SHOPPING LIST ITEMS repairs
    if (tableExists($pdo, $dbName, 'shopping_list_items')) {
        if (!columnExists($pdo, $dbName, 'shopping_list_items', 'estimated_price')) {
            runStatement($pdo, "ALTER TABLE shopping_list_items ADD COLUMN estimated_price DECIMAL(6,2) DEFAULT NULL AFTER category", $executed, $warnings);
        }
        if (!columnExists($pdo, $dbName, 'shopping_list_items', 'checked')) {
            runStatement($pdo, "ALTER TABLE shopping_list_items ADD COLUMN checked TINYINT(1) DEFAULT 0 AFTER estimated_price", $executed, $warnings);
        }
    }

    // POSTS repairs
    if (tableExists($pdo, $dbName, 'posts')) {
        if (!columnExists($pdo, $dbName, 'posts', 'image_path')) {
            runStatement($pdo, "ALTER TABLE posts ADD COLUMN image_path VARCHAR(255) DEFAULT NULL AFTER content", $executed, $warnings);
        }
        if (!columnExists($pdo, $dbName, 'posts', 'recipe_id')) {
            runStatement($pdo, "ALTER TABLE posts ADD COLUMN recipe_id INT DEFAULT NULL AFTER image_path", $executed, $warnings);
        }
        if (!columnExists($pdo, $dbName, 'posts', 'likes_count')) {
            runStatement($pdo, "ALTER TABLE posts ADD COLUMN likes_count INT DEFAULT 0 AFTER recipe_id", $executed, $warnings);
        }
        if (!columnExists($pdo, $dbName, 'posts', 'comments_count')) {
            runStatement($pdo, "ALTER TABLE posts ADD COLUMN comments_count INT DEFAULT 0 AFTER likes_count", $executed, $warnings);
        }
    }

    // FRIENDSHIPS repairs
    if (tableExists($pdo, $dbName, 'friendships')) {
        if (!columnExists($pdo, $dbName, 'friendships', 'updated_at')) {
            runStatement($pdo, "ALTER TABLE friendships ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP", $executed, $warnings);
        }
        if (!indexExists($pdo, $dbName, 'friendships', 'unique_friendship')) {
            runStatement($pdo, "ALTER TABLE friendships ADD UNIQUE KEY unique_friendship (requester_id, addressee_id)", $executed, $warnings);
        }
    }

    // FRIDGE ITEMS repairs
    if (tableExists($pdo, $dbName, 'fridge_items')) {
        if (!columnExists($pdo, $dbName, 'fridge_items', 'expiry_date')) {
            runStatement($pdo, "ALTER TABLE fridge_items ADD COLUMN expiry_date DATE DEFAULT NULL AFTER added_at", $executed, $warnings);
        }
    }

    // ACTIVITY LOG repairs
    if (tableExists($pdo, $dbName, 'activity_log')) {
        if (!columnExists($pdo, $dbName, 'activity_log', 'reference_id')) {
            runStatement($pdo, "ALTER TABLE activity_log ADD COLUMN reference_id INT DEFAULT NULL AFTER action_type", $executed, $warnings);
        }
        if (!columnExists($pdo, $dbName, 'activity_log', 'description')) {
            runStatement($pdo, "ALTER TABLE activity_log ADD COLUMN description VARCHAR(255) DEFAULT NULL AFTER reference_id", $executed, $warnings);
        }
    }

    out();
    info("=== Verifying required tables ===");

    $requiredTables = [
        'users',
        'dietary_preferences',
        'user_dietary_preferences',
        'recipes',
        'saved_recipes',
        'meal_plans',
        'meal_plan_items',
        'shopping_lists',
        'shopping_list_items',
        'posts',
        'post_likes',
        'post_comments',
        'friendships',
        'fridge_items',
        'activity_log',
    ];

    $missingTables = [];
    foreach ($requiredTables as $table) {
        if (!tableExists($pdo, $dbName, $table)) {
            $missingTables[] = $table;
        }
    }

    if (!empty($missingTables)) {
        warning("Some tables are still missing: " . implode(', ', $missingTables));
        warning("This usually means the existing database is from a very old/broken structure.");
        warning("In that case, manually export users, then do one full reset once.");
    } else {
        success("All core tables are present.");
    }

    out();

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    info("Tables available: " . implode(', ', $tables));

    $userCount = tableExists($pdo, $dbName, 'users')
        ? (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn()
        : 0;

    $recipeCount = tableExists($pdo, $dbName, 'recipes')
        ? (int)$pdo->query("SELECT COUNT(*) FROM recipes")->fetchColumn()
        : 0;

    info("Users currently in database: {$userCount}");
    info("Recipes currently in database: {$recipeCount}");

    if (tableExists($pdo, $dbName, 'recipes')) {
        $hasEstimatedCost = columnExists($pdo, $dbName, 'recipes', 'estimated_cost') ? 'YES' : 'NO';
        $hasImageUrl = columnExists($pdo, $dbName, 'recipes', 'image_url') ? 'YES' : 'NO';
        $hasPopularity = columnExists($pdo, $dbName, 'recipes', 'popularity_score') ? 'YES' : 'NO';

        info("recipes.estimated_cost exists: {$hasEstimatedCost}");
        info("recipes.image_url exists: {$hasImageUrl}");
        info("recipes.popularity_score exists: {$hasPopularity}");
    }

    out();
    success("Executed {$executed} SQL statements.");
    if ($warnings > 0) {
        warning("{$warnings} non-fatal warnings occurred during setup.");
    }

    out("=== Setup Complete ===");
    out("Recommended next steps:");
    out("1. Run seed_demo_data.php if you want demo recipe images / social seed content.");
    out("2. Existing users were preserved.");
    out("3. Open: http://localhost/Byte-Right/frontend/byteright_login.html");

} catch (Throwable $e) {
    out();
    out("DATABASE ERROR: " . $e->getMessage());
    out();
    out("Make sure MySQL is running and your credentials are correct.");
    exit(1);
}
