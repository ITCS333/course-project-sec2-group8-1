<?php
/**
 * Weekly Course Breakdown API
 *
 * RESTful API for CRUD operations on weekly course content and discussion
 * comments. Uses PDO to interact with the MySQL database defined in
 * schema.sql.
 *
 * Database Tables (ground truth: schema.sql):
 *
 * Table: weeks
 *   id          INT UNSIGNED  PRIMARY KEY AUTO_INCREMENT
 *   title       VARCHAR(200)  NOT NULL
 *   start_date  DATE          NOT NULL
 *   description TEXT
 *   links       TEXT          — JSON-encoded array of URL strings
 *   created_at  TIMESTAMP
 *   updated_at  TIMESTAMP
 *
 * Table: comments_week
 *   id          INT UNSIGNED  PRIMARY KEY AUTO_INCREMENT
 *   week_id     INT UNSIGNED  NOT NULL   — FK → weeks.id (ON DELETE CASCADE)
 *   author      VARCHAR(100)  NOT NULL
 *   text        TEXT          NOT NULL
 *   created_at  TIMESTAMP
 *
 * HTTP Methods Supported:
 *   GET    — Retrieve week(s) or comments
 *   POST   — Create a new week or comment
 *   PUT    — Update an existing week
 *   DELETE — Delete a week (cascade removes its comments) or a single comment
 *
 * URL scheme (all requests go to index.php):
 *
 *   Weeks:
 *     GET    ./api/index.php                  — list all weeks
 *     GET    ./api/index.php?id={id}           — get one week by integer id
 *     POST   ./api/index.php                  — create a new week
 *     PUT    ./api/index.php                  — update a week (id in JSON body)
 *     DELETE ./api/index.php?id={id}           — delete a week
 *
 *   Comments (action parameter selects the comments sub-resource):
 *     GET    ./api/index.php?action=comments&week_id={id}
 *                                             — list comments for a week
 *     POST   ./api/index.php?action=comment   — create a comment
 *     DELETE ./api/index.php?action=delete_comment&comment_id={id}
 *                                             — delete a single comment
 *
 * Query parameters for GET all weeks:
 *   search — filter rows where title LIKE or description LIKE the term
 *   sort   — column to sort by; allowed: title, start_date (default: start_date)
 *   order  — sort direction; allowed: asc, desc (default: asc)
 *
 * Response format: JSON
 *   Success: { "success": true,  "data": ... }
 *   Error:   { "success": false, "message": "..." }
 */
// ============================================================================
// HEADERS AND INITIALIZATION
// ============================================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include local db shim if present (tests provide one), otherwise shared connector
if (file_exists(__DIR__ . '/db.php')) {
    require_once __DIR__ . '/db.php';
} else {
    require_once __DIR__ . '/../../common/db.php';
}

$db = getDBConnection();
$method = $_SERVER['REQUEST_METHOD'];
$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true) ?? [];

$action    = $_GET['action']     ?? null;
$id        = $_GET['id']         ?? null;
$weekId    = $_GET['week_id']    ?? null;
$commentId = $_GET['comment_id'] ?? null;

function sendResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}
// ============================================================================
// WEEKS / COMMENTS IMPLEMENTATION
// ============================================================================

function getAllWeeks(PDO $db): void
{
    $search = $_GET['search'] ?? null;
    $sort   = $_GET['sort']   ?? 'start_date';
    $order  = $_GET['order']  ?? 'asc';

    $allowedSort = ['title', 'start_date'];
    if (!in_array($sort, $allowedSort, true)) {
        $sort = 'start_date';
    }
    $order = strtolower($order) === 'desc' ? 'DESC' : 'ASC';

    $sql = 'SELECT id, title, start_date, description, links, created_at FROM weeks';
    $params = [];
    if ($search !== null && trim($search) !== '') {
        $sql .= ' WHERE title LIKE :search OR description LIKE :search';
        $params[':search'] = '%' . $search . '%';
    }

    $sql .= " ORDER BY {$sort} {$order}";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $weeks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($weeks as &$row) {
        $row['links'] = json_decode($row['links'], true) ?? [];
    }
    sendResponse(['success' => true, 'data' => $weeks]);
}


function getWeekById(PDO $db, $id): void
{
    if ($id === null || !is_numeric($id)) {
        sendResponse(['success' => false, 'message' => 'Invalid id'], 400);
    }
    $id = (int)$id;
    $stmt = $db->prepare('SELECT id, title, start_date, description, links, created_at FROM weeks WHERE id = ?');
    $stmt->execute([$id]);
    $week = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$week) {
        sendResponse(['success' => false, 'message' => 'Week not found'], 404);
    }
    $week['links'] = json_decode($week['links'], true) ?? [];
    sendResponse(['success' => true, 'data' => $week]);
}


function createWeek(PDO $db, array $data): void
{
    $title = isset($data['title']) ? trim((string)$data['title']) : '';
    $start_date = isset($data['start_date']) ? trim((string)$data['start_date']) : '';
    $description = isset($data['description']) ? trim((string)$data['description']) : '';
    $links = $data['links'] ?? [];
    if ($title === '' || $start_date === '') {
        sendResponse(['success' => false, 'message' => 'Missing required fields'], 400);
    }
    $d = DateTime::createFromFormat('Y-m-d', $start_date);
    if (!$d || $d->format('Y-m-d') !== $start_date) {
        sendResponse(['success' => false, 'message' => 'Invalid start_date format'], 400);
    }
    $linksJson = is_array($links) ? json_encode($links) : json_encode([]);
    $stmt = $db->prepare('INSERT INTO weeks (title, start_date, description, links) VALUES (?, ?, ?, ?)');
    $ok = $stmt->execute([$title, $start_date, $description, $linksJson]);
    if ($ok && $stmt->rowCount() > 0) {
        $id = (int)$db->lastInsertId();
        sendResponse(['success' => true, 'message' => 'Week created', 'id' => $id], 201);
    }
    sendResponse(['success' => false, 'message' => 'Failed to create week'], 500);
}


function updateWeek(PDO $db, array $data): void
{
    if (!isset($data['id']) || !is_numeric($data['id'])) {
        sendResponse(['success' => false, 'message' => 'Missing id'], 400);
    }
    $id = (int)$data['id'];
    $stmt = $db->prepare('SELECT id FROM weeks WHERE id = ?');
    $stmt->execute([$id]);
    $exists = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$exists) {
        sendResponse(['success' => false, 'message' => 'Week not found'], 404);
    }
    $fields = [];
    $params = [];
    if (array_key_exists('title', $data)) {
        $fields[] = 'title = ?';
        $params[] = trim((string)$data['title']);
    }
    if (array_key_exists('start_date', $data)) {
        $sd = trim((string)$data['start_date']);
        $d = DateTime::createFromFormat('Y-m-d', $sd);
        if (!$d || $d->format('Y-m-d') !== $sd) {
            sendResponse(['success' => false, 'message' => 'Invalid start_date format'], 400);
        }
        $fields[] = 'start_date = ?';
        $params[] = $sd;
    }
    if (array_key_exists('description', $data)) {
        $fields[] = 'description = ?';
        $params[] = trim((string)$data['description']);
    }
    if (array_key_exists('links', $data)) {
        $linksJson = is_array($data['links']) ? json_encode($data['links']) : json_encode([]);
        $fields[] = 'links = ?';
        $params[] = $linksJson;
    }
    if (empty($fields)) {
        sendResponse(['success' => false, 'message' => 'No fields to update'], 400);
    }
    $sql = 'UPDATE weeks SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $params[] = $id;
    $stmt = $db->prepare($sql);
    $ok = $stmt->execute($params);
    if ($ok) {
        sendResponse(['success' => true, 'message' => 'Week updated']);
    }
    sendResponse(['success' => false, 'message' => 'Failed to update week'], 500);
}


function deleteWeek(PDO $db, $id): void
{
    if ($id === null || !is_numeric($id)) {
        sendResponse(['success' => false, 'message' => 'Invalid id'], 400);
    }
    $id = (int)$id;
    $stmt = $db->prepare('SELECT id FROM weeks WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        sendResponse(['success' => false, 'message' => 'Week not found'], 404);
    }
    $del = $db->prepare('DELETE FROM weeks WHERE id = ?');
    $del->execute([$id]);
    if ($del->rowCount() > 0) {
        sendResponse(['success' => true, 'message' => 'Week deleted']);
    }
    sendResponse(['success' => false, 'message' => 'Failed to delete week'], 500);
}


function getCommentsByWeek(PDO $db, $weekId): void
{
    if ($weekId === null || !is_numeric($weekId)) {
        sendResponse(['success' => false, 'message' => 'Invalid week_id'], 400);
    }
    $weekId = (int)$weekId;
    $stmt = $db->prepare('SELECT id, week_id, author, text, created_at FROM comments_week WHERE week_id = ? ORDER BY created_at ASC');
    $stmt->execute([$weekId]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    sendResponse(['success' => true, 'data' => $comments]);
}


function createComment(PDO $db, array $data): void
{
    $week_id = $data['week_id'] ?? null;
    $author = isset($data['author']) ? trim((string)$data['author']) : '';
    $text = isset($data['text']) ? trim((string)$data['text']) : '';
    if ($week_id === null || $author === '' || $text === '') {
        sendResponse(['success' => false, 'message' => 'Missing fields'], 400);
    }
    if (!is_numeric($week_id)) {
        sendResponse(['success' => false, 'message' => 'Invalid week_id'], 400);
    }
    $week_id = (int)$week_id;
    $s = $db->prepare('SELECT id FROM weeks WHERE id = ?');
    $s->execute([$week_id]);
    if (!$s->fetch(PDO::FETCH_ASSOC)) {
        sendResponse(['success' => false, 'message' => 'Week not found'], 404);
    }
    $ins = $db->prepare('INSERT INTO comments_week (week_id, author, text) VALUES (?, ?, ?)');
    $ok = $ins->execute([$week_id, $author, $text]);
    if ($ok && $ins->rowCount() > 0) {
        $id = (int)$db->lastInsertId();
        $stmt = $db->prepare('SELECT id, week_id, author, text, created_at FROM comments_week WHERE id = ?');
        $stmt->execute([$id]);
        $comment = $stmt->fetch(PDO::FETCH_ASSOC);
        sendResponse(['success' => true, 'message' => 'Comment created', 'id' => $id, 'data' => $comment], 201);
    }
    sendResponse(['success' => false, 'message' => 'Failed to create comment'], 500);
}


function deleteComment(PDO $db, $commentId): void
{
    if ($commentId === null || !is_numeric($commentId)) {
        sendResponse(['success' => false, 'message' => 'Invalid comment_id'], 400);
    }
    $commentId = (int)$commentId;
    $s = $db->prepare('SELECT id FROM comments_week WHERE id = ?');
    $s->execute([$commentId]);
    if (!$s->fetch(PDO::FETCH_ASSOC)) {
        sendResponse(['success' => false, 'message' => 'Comment not found'], 404);
    }
    $del = $db->prepare('DELETE FROM comments_week WHERE id = ?');
    $del->execute([$commentId]);
    if ($del->rowCount() > 0) {
        sendResponse(['success' => true, 'message' => 'Comment deleted']);
    }
    sendResponse(['success' => false, 'message' => 'Failed to delete comment'], 500);
}


// Main router
try {
    if ($method === 'GET') {
        if ($action === 'comments') {
            getCommentsByWeek($db, $weekId);
        } elseif ($id !== null) {
            getWeekById($db, $id);
        } else {
            getAllWeeks($db);
        }
    } elseif ($method === 'POST') {
        if ($action === 'comment') {
            createComment($db, $data);
        } else {
            createWeek($db, $data);
        }
    } elseif ($method === 'PUT') {
        updateWeek($db, $data);
    } elseif ($method === 'DELETE') {
        if ($action === 'delete_comment') {
            deleteComment($db, $commentId);
        } else {
            deleteWeek($db, $id);
        }
    } else {
        sendResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
} catch (PDOException $e) {
    error_log('PDOException in weekly api: ' . $e->getMessage());
    sendResponse(['success' => false, 'message' => 'Internal server error'], 500);
} catch (Exception $e) {
    error_log('Exception in weekly api: ' . $e->getMessage());
    sendResponse(['success' => false, 'message' => 'Internal server error'], 500);
}
