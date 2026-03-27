<?php
/**
 * ByteRight - Quick Setup Script
 *
 * Run this once to create the database and tables:
 *   php backend/setup.php
 *
 * Or open in browser: http://localhost/backend/setup.php
 */

echo "=== ByteRight Database Setup ===\n\n";

// Database connection (without database name first)
$host = 'localhost';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Drop and recreate database for a clean setup
    $pdo->exec('DROP DATABASE IF EXISTS byteright');
    $pdo->exec('CREATE DATABASE byteright');
    $pdo->exec('USE byteright');

    echo "Database 'byteright' created fresh.\n";

    // Read and execute schema
    $schemaPath = __DIR__ . '/schema.sql';
    if (!file_exists($schemaPath)) {
        die("ERROR: schema.sql not found at $schemaPath\n");
    }

    $sql = file_get_contents($schemaPath);

    // Strip SQL comment lines (lines starting with --)
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    // Remove CREATE DATABASE / USE statements (already executed above)
    $sql = preg_replace('/CREATE DATABASE.*?;\s*/i', '', $sql);
    $sql = preg_replace('/USE\s+byteright\s*;\s*/i', '', $sql);

    $statements = array_filter(
        array_map('trim', preg_split('/;\s*\n/', $sql)),
        fn($s) => $s !== ''
    );

    $count = 0;
    $errors = 0;
    foreach ($statements as $stmt) {
        if (trim($stmt) === '') continue;
        try {
            $pdo->exec($stmt);
            $count++;
        } catch (PDOException $e) {
            $errors++;
            echo "WARNING: " . substr($e->getMessage(), 0, 120) . "\n";
        }
    }

    echo "\nSUCCESS: Executed $count SQL statements.\n";
    if ($errors > 0) echo "($errors warnings)\n";
    echo "Database 'byteright' is ready with all tables and seed data.\n\n";

    // Verify tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables created: " . implode(', ', $tables) . "\n";

    // Count seed recipes
    $recipeCount = $pdo->query("SELECT COUNT(*) FROM recipes")->fetchColumn();
    echo "Seed recipes loaded: $recipeCount\n\n";

    echo "=== Setup Complete ===\n";
    echo "Next steps:\n";
    echo "1. Add your Spoonacular API key to backend/config/database.php\n";
    echo "2. Start your PHP server: php -S localhost:8000\n";
    echo "3. Open: http://localhost/Byte-Right/frontend/byteright_login.html\n";

} catch (PDOException $e) {
    die("DATABASE ERROR: " . $e->getMessage() . "\n\nMake sure MySQL is running.\n");
}
