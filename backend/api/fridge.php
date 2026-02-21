<?php
/**
 * ByteRight - Fridge Inventory API
 *
 * GET    /api/fridge.php?action=list       - List all fridge items
 * POST   /api/fridge.php?action=add        - Add an item
 * POST   /api/fridge.php?action=update     - Update an item
 * DELETE /api/fridge.php?action=remove&id=1 - Remove an item
 * POST   /api/fridge.php?action=clear      - Clear all items
 */

require_once __DIR__ . '/../config/database.php';
startSession();

$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'list':
        listItems();
        break;
    case 'add':
        addItem();
        break;
    case 'update':
        updateItem();
        break;
    case 'remove':
        removeItem();
        break;
    case 'clear':
        clearItems();
        break;
    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}

function listItems(): void {
    $userId = requireLogin();
    $db = getDB();

    $stmt = $db->prepare('
        SELECT id, name, quantity, added_at, expiry_date
        FROM fridge_items
        WHERE user_id = ?
        ORDER BY name ASC
    ');
    $stmt->execute([$userId]);
    $items = $stmt->fetchAll();

    jsonResponse([
        'items' => $items,
        'count' => count($items),
    ]);
}

function addItem(): void {
    $userId = requireLogin();
    $data = getRequestBody();
    $db = getDB();

    $name = trim($data['name'] ?? '');
    $quantity = trim($data['quantity'] ?? '');
    $expiryDate = $data['expiry_date'] ?? null;

    if ($name === '') {
        jsonResponse(['error' => 'Item name is required'], 400);
    }

    // Check for duplicate
    $stmt = $db->prepare('SELECT id FROM fridge_items WHERE user_id = ? AND LOWER(name) = LOWER(?)');
    $stmt->execute([$userId, $name]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'Item already in your fridge'], 409);
    }

    $stmt = $db->prepare('
        INSERT INTO fridge_items (user_id, name, quantity, expiry_date)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([$userId, $name, $quantity ?: null, $expiryDate ?: null]);

    jsonResponse(['success' => true, 'id' => (int) $db->lastInsertId()]);
}

function updateItem(): void {
    $userId = requireLogin();
    $data = getRequestBody();
    $db = getDB();

    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['error' => 'Item ID required'], 400);
    }

    $sets = [];
    $params = [];

    if (isset($data['name'])) {
        $sets[] = 'name = ?';
        $params[] = trim($data['name']);
    }
    if (isset($data['quantity'])) {
        $sets[] = 'quantity = ?';
        $params[] = trim($data['quantity']);
    }
    if (array_key_exists('expiry_date', $data)) {
        $sets[] = 'expiry_date = ?';
        $params[] = $data['expiry_date'] ?: null;
    }

    if (empty($sets)) {
        jsonResponse(['error' => 'No fields to update'], 400);
    }

    $params[] = $id;
    $params[] = $userId;

    $sql = 'UPDATE fridge_items SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?';
    $db->prepare($sql)->execute($params);

    jsonResponse(['success' => true]);
}

function removeItem(): void {
    $userId = requireLogin();
    $id = (int) ($_GET['id'] ?? 0);

    if ($id <= 0) {
        jsonResponse(['error' => 'Item ID required'], 400);
    }

    $db = getDB();
    $db->prepare('DELETE FROM fridge_items WHERE id = ? AND user_id = ?')
       ->execute([$id, $userId]);

    jsonResponse(['success' => true]);
}

function clearItems(): void {
    $userId = requireLogin();
    $db = getDB();
    $db->prepare('DELETE FROM fridge_items WHERE user_id = ?')->execute([$userId]);
    jsonResponse(['success' => true]);
}
