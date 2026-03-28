<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

function out(string $message = ''): void
{
    echo $message . PHP_EOL;
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

function shouldIgnoreSqlError(PDOException $e): bool
{
    $message = strtolower($e->getMessage());

    $ignorable = [
        'already exists',
        'duplicate entry',
        'duplicate column name',
        'duplicate key name',
        'multiple primary key defined',
    ];

    foreach ($ignorable as $phrase) {
        if (str_contains($message, $phrase)) {
            return true;
        }
    }

    return false;
}

function execSafe(PDO $pdo, string $sql, int &$executed, int &$warnings): void
{
    try {
        $pdo->exec($sql);
        $executed++;
    } catch (PDOException $e) {
        if (shouldIgnoreSqlError($e)) {
            $warnings++;
            out("WARNING: " . substr($e->getMessage(), 0, 180));
            return;
        }
        throw $e;
    }
}

out("=== ByteRight Setup (Preserve User + Content Data) ===");
out();

$host = 'localhost';
$user = 'root';
$pass = '';
$dbName = 'byteright';

try {
    $pdo = new PDO(
        "mysql:host=$host;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbName`");

    out("Database '$dbName' is ready.");
    out("Preserving users, posts, saved recipes, meal plans, shopping lists, friendships, fridge items, and activity log.");
    out();

    /**
     * Drop only static/catalog tables that are safer to rebuild.
     * Keep user-generated / social tables intact.
     */
    $tablesToDrop = [
        'user_dietary_preferences',
        'dietary_preferences',
        'recipes',
    ];

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    foreach ($tablesToDrop as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `$table`");
        out("Dropped table if exists: $table");
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    out();

    /**
     * Repair USERS table
     */
    if (tableExists($pdo, $dbName, 'users')) {
        out("Repairing users table...");

        if (!columnExists($pdo, $dbName, 'users', 'university')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN university VARCHAR(150) DEFAULT NULL AFTER password_hash");
            out("Added users.university");
        }
        if (!columnExists($pdo, $dbName, 'users', 'avatar_path')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN avatar_path VARCHAR(255) DEFAULT NULL AFTER university");
            out("Added users.avatar_path");
        }
        if (!columnExists($pdo, $dbName, 'users', 'weekly_budget')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN weekly_budget DECIMAL(6,2) DEFAULT 30.00 AFTER avatar_path");
            out("Added users.weekly_budget");
        }
        if (!columnExists($pdo, $dbName, 'users', 'cooking_time_pref')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN cooking_time_pref ENUM('under15', 'under30', 'under60', 'any') DEFAULT 'under30' AFTER weekly_budget");
            out("Added users.cooking_time_pref");
        }
        if (!columnExists($pdo, $dbName, 'users', 'meal_plan_pref')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN meal_plan_pref ENUM('balanced', 'high_protein', 'low_carb', 'budget') DEFAULT 'balanced' AFTER cooking_time_pref");
            out("Added users.meal_plan_pref");
        }
        if (!columnExists($pdo, $dbName, 'users', 'allergies')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN allergies TEXT DEFAULT NULL AFTER meal_plan_pref");
            out("Added users.allergies");
        }
        if (!columnExists($pdo, $dbName, 'users', 'liked_ingredients')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN liked_ingredients TEXT DEFAULT NULL AFTER allergies");
            out("Added users.liked_ingredients");
        }
        if (!columnExists($pdo, $dbName, 'users', 'disliked_ingredients')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN disliked_ingredients TEXT DEFAULT NULL AFTER liked_ingredients");
            out("Added users.disliked_ingredients");
        }
        if (!columnExists($pdo, $dbName, 'users', 'created_at')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
            out("Added users.created_at");
        }
        if (!columnExists($pdo, $dbName, 'users', 'updated_at')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            out("Added users.updated_at");
        }
    }

    /**
     * Read and execute schema.sql
     */
    $schemaPath = __DIR__ . '/schema.sql';
    if (!file_exists($schemaPath)) {
        throw new RuntimeException("schema.sql not found at: $schemaPath");
    }

    $sql = file_get_contents($schemaPath);
    if ($sql === false) {
        throw new RuntimeException("Could not read schema.sql");
    }

    // Remove full-line -- comments
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);

    // Remove CREATE DATABASE / USE lines
    $sql = preg_replace('/CREATE\s+DATABASE\s+IF\s+NOT\s+EXISTS\s+byteright\s*;/i', '', $sql);
    $sql = preg_replace('/CREATE\s+DATABASE\s+byteright\s*;/i', '', $sql);
    $sql = preg_replace('/USE\s+byteright\s*;/i', '', $sql);

    $statements = splitSqlStatements($sql);

    $executed = 0;
    $warnings = 0;

    out("Executing schema.sql...");
    foreach ($statements as $statement) {
        $trimmed = trim($statement);
        if ($trimmed === '') {
            continue;
        }

        execSafe($pdo, $trimmed, $executed, $warnings);
    }

    out();

    /**
     * Critical repairs
     */
    out("Running critical repairs...");

    if (tableExists($pdo, $dbName, 'recipes')) {
        if (!columnExists($pdo, $dbName, 'recipes', 'estimated_cost')) {
            $pdo->exec("ALTER TABLE recipes ADD COLUMN estimated_cost DECIMAL(6,2) DEFAULT 0.00 AFTER servings");
            out("Added recipes.estimated_cost");
        }
        if (!columnExists($pdo, $dbName, 'recipes', 'difficulty')) {
            $pdo->exec("ALTER TABLE recipes ADD COLUMN difficulty ENUM('easy', 'medium', 'hard') DEFAULT 'easy' AFTER estimated_cost");
            out("Added recipes.difficulty");
        }
        if (!columnExists($pdo, $dbName, 'recipes', 'image_url')) {
            $pdo->exec("ALTER TABLE recipes ADD COLUMN image_url VARCHAR(500) DEFAULT NULL AFTER difficulty");
            out("Added recipes.image_url");
        }
        if (!columnExists($pdo, $dbName, 'recipes', 'tags')) {
            $pdo->exec("ALTER TABLE recipes ADD COLUMN tags JSON DEFAULT NULL AFTER image_url");
            out("Added recipes.tags");
        }
        if (!columnExists($pdo, $dbName, 'recipes', 'source')) {
            $pdo->exec("ALTER TABLE recipes ADD COLUMN source ENUM('api', 'local', 'user') DEFAULT 'local' AFTER tags");
            out("Added recipes.source");
        }
        if (!columnExists($pdo, $dbName, 'recipes', 'spoonacular_id')) {
            $pdo->exec("ALTER TABLE recipes ADD COLUMN spoonacular_id INT DEFAULT NULL AFTER source");
            out("Added recipes.spoonacular_id");
        }
        if (!columnExists($pdo, $dbName, 'recipes', 'popularity_score')) {
            $pdo->exec("ALTER TABLE recipes ADD COLUMN popularity_score INT DEFAULT 0 AFTER spoonacular_id");
            out("Added recipes.popularity_score");
        }
        if (!indexExists($pdo, $dbName, 'recipes', 'unique_recipe_title')) {
            $pdo->exec("ALTER TABLE recipes ADD UNIQUE KEY unique_recipe_title (title)");
            out("Added unique_recipe_title");
        }
    }

    if (tableExists($pdo, $dbName, 'meal_plans')) {
        if (!columnExists($pdo, $dbName, 'meal_plans', 'budget_target')) {
            $pdo->exec("ALTER TABLE meal_plans ADD COLUMN budget_target DECIMAL(6,2) DEFAULT NULL AFTER week_start");
            out("Added meal_plans.budget_target");
        }
        if (!columnExists($pdo, $dbName, 'meal_plans', 'total_estimated_cost')) {
            $pdo->exec("ALTER TABLE meal_plans ADD COLUMN total_estimated_cost DECIMAL(6,2) DEFAULT 0.00 AFTER budget_target");
            out("Added meal_plans.total_estimated_cost");
        }
    }

    if (tableExists($pdo, $dbName, 'meal_plan_items')) {
        if (!columnExists($pdo, $dbName, 'meal_plan_items', 'estimated_cost')) {
            $pdo->exec("ALTER TABLE meal_plan_items ADD COLUMN estimated_cost DECIMAL(6,2) DEFAULT NULL AFTER custom_meal_name");
            out("Added meal_plan_items.estimated_cost");
        }
    }

    if (tableExists($pdo, $dbName, 'shopping_lists')) {
        if (!columnExists($pdo, $dbName, 'shopping_lists', 'estimated_total')) {
            $pdo->exec("ALTER TABLE shopping_lists ADD COLUMN estimated_total DECIMAL(6,2) DEFAULT 0.00 AFTER name");
            out("Added shopping_lists.estimated_total");
        }
    }

    if (tableExists($pdo, $dbName, 'shopping_list_items')) {
        if (!columnExists($pdo, $dbName, 'shopping_list_items', 'estimated_price')) {
            $pdo->exec("ALTER TABLE shopping_list_items ADD COLUMN estimated_price DECIMAL(6,2) DEFAULT NULL AFTER category");
            out("Added shopping_list_items.estimated_price");
        }
        if (!columnExists($pdo, $dbName, 'shopping_list_items', 'checked')) {
            $pdo->exec("ALTER TABLE shopping_list_items ADD COLUMN checked TINYINT(1) DEFAULT 0 AFTER estimated_price");
            out("Added shopping_list_items.checked");
        }
    }

    if (tableExists($pdo, $dbName, 'posts')) {
        if (!columnExists($pdo, $dbName, 'posts', 'image_path')) {
            $pdo->exec("ALTER TABLE posts ADD COLUMN image_path VARCHAR(255) DEFAULT NULL AFTER content");
            out("Added posts.image_path");
        }
        if (!columnExists($pdo, $dbName, 'posts', 'recipe_id')) {
            $pdo->exec("ALTER TABLE posts ADD COLUMN recipe_id INT DEFAULT NULL AFTER image_path");
            out("Added posts.recipe_id");
        }
        if (!columnExists($pdo, $dbName, 'posts', 'likes_count')) {
            $pdo->exec("ALTER TABLE posts ADD COLUMN likes_count INT DEFAULT 0 AFTER recipe_id");
            out("Added posts.likes_count");
        }
        if (!columnExists($pdo, $dbName, 'posts', 'comments_count')) {
            $pdo->exec("ALTER TABLE posts ADD COLUMN comments_count INT DEFAULT 0 AFTER likes_count");
            out("Added posts.comments_count");
        }
    }

    /**
     * Default test login
     */
    if (tableExists($pdo, $dbName, 'users')) {
        $email = 'divyansh.jhajhria0@gmail.com';
        $plainPassword = '12345678';

        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $existingUser = $stmt->fetch();

        if (!$existingUser) {
            $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);

            $insertColumns = ['name', 'email', 'password_hash'];
            $insertValues = ['Divyansh Jhajhria', $email, $passwordHash];

            $optionalColumns = [
                'university' => 'University of Manchester',
                'weekly_budget' => 35.00,
                'cooking_time_pref' => 'under30',
                'meal_plan_pref' => 'balanced',
                'allergies' => null,
                'liked_ingredients' => null,
                'disliked_ingredients' => null,
            ];

            foreach ($optionalColumns as $column => $value) {
                if (columnExists($pdo, $dbName, 'users', $column)) {
                    $insertColumns[] = $column;
                    $insertValues[] = $value;
                }
            }

            $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
            $columnsSql = implode(', ', $insertColumns);

            $insertSql = "INSERT INTO users ($columnsSql) VALUES ($placeholders)";
            $insertStmt = $pdo->prepare($insertSql);
            $insertStmt->execute($insertValues);

            out("Created default test login:");
            out("  Email: divyansh.jhajhria0@gmail.com");
            out("  Password: 12345678");
        } else {
            out("Default test login already exists.");
        }
    }

    /**
     * Summary
     */
    $usersCount = tableExists($pdo, $dbName, 'users')
        ? (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn()
        : 0;

    $recipesCount = tableExists($pdo, $dbName, 'recipes')
        ? (int)$pdo->query("SELECT COUNT(*) FROM recipes")->fetchColumn()
        : 0;

    out();
    out("=== Setup Summary ===");
    out("Users in database: $usersCount");
    out("Recipes in database: $recipesCount");
    out("SQL statements executed: $executed");
    out("Warnings: $warnings");

    if (tableExists($pdo, $dbName, 'recipes')) {
        out("recipes.estimated_cost exists: " . (columnExists($pdo, $dbName, 'recipes', 'estimated_cost') ? 'YES' : 'NO'));
        out("recipes.image_url exists: " . (columnExists($pdo, $dbName, 'recipes', 'image_url') ? 'YES' : 'NO'));
        out("recipes.popularity_score exists: " . (columnExists($pdo, $dbName, 'recipes', 'popularity_score') ? 'YES' : 'NO'));
    }

    out();
    out("=== Setup Complete ===");
    out("Note: if homepage images are still blank, run seed_demo_data.php as well.");

} catch (Throwable $e) {
    out();
    out("DATABASE ERROR: " . $e->getMessage());
    out("Make sure MySQL is running and the credentials are correct.");
    exit(1);
}