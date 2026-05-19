<?php
/**
 * Assignment Management API
 *
 * RESTful API for CRUD operations on course assignments and their
 * discussion comments. Uses PDO to interact with the MySQL database
 * defined in schema.sql.
 *
 * Database Tables (ground truth: schema.sql):
 *
 * Table: assignments
 *   id          INT UNSIGNED  PRIMARY KEY AUTO_INCREMENT
 *   title       VARCHAR(200)  NOT NULL
 *   description TEXT
 *   due_date    DATE          NOT NULL
 *   files       TEXT          — JSON-encoded array of file URL strings
 *   created_at  TIMESTAMP
 *   updated_at  TIMESTAMP     — updated automatically by MySQL ON UPDATE
 *
 * Table: comments_assignment
 *   id            INT UNSIGNED  PRIMARY KEY AUTO_INCREMENT
 *   assignment_id INT UNSIGNED  NOT NULL — FK → assignments.id (ON DELETE CASCADE)
 *   author        VARCHAR(100)  NOT NULL
 *   text          TEXT          NOT NULL
 *   created_at    TIMESTAMP
 *
 * HTTP Methods Supported:
 *   GET    — Retrieve assignment(s) or comments
 *   POST   — Create a new assignment or comment
 *   PUT    — Update an existing assignment
 *   DELETE — Delete an assignment (cascade removes its comments) or a comment
 *
 * URL scheme (all requests go to index.php):
 *
 *   Assignments:
 *     GET    ./api/index.php                  — list all assignments
 *     GET    ./api/index.php?id={id}           — get one assignment by integer id
 *     POST   ./api/index.php                  — create a new assignment
 *     PUT    ./api/index.php                  — update an assignment (id in JSON body)
 *     DELETE ./api/index.php?id={id}           — delete an assignment
 *
 *   Comments (action parameter selects the comments sub-resource):
 *     GET    ./api/index.php?action=comments&assignment_id={id}
 *                                             — list comments for an assignment
 *     POST   ./api/index.php?action=comment   — create a comment
 *     DELETE ./api/index.php?action=delete_comment&comment_id={id}
 *                                             — delete a single comment
 *
 * Query parameters for GET all assignments:
 *   search — filter rows where title LIKE or description LIKE the term
 *   sort   — column to sort by; allowed: title, due_date, created_at
 *            (default: due_date)
 *   order  — sort direction; allowed: asc, desc (default: asc)
 *
 * Response format: JSON
 *   Success: { "success": true,  "data": ... }
 *   Error:   { "success": false, "message": "..." }
 */

// ============================================================================
// HEADERS AND INITIALIZATION
// ============================================================================

// Set headers for JSON response and CORS
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include the shared database connection file
require_once __DIR__ . '/../../common/db.php';

// Get the PDO database connection
$db = getDBConnection();

// Read the HTTP request method
$method = $_SERVER['REQUEST_METHOD'];

// Read and decode the request body for POST and PUT requests
$rawData = file_get_contents('php://input');
$data    = json_decode($rawData, true) ?? [];

// Read query parameters
$action       = $_GET['action']        ?? null;  // 'comments', 'comment', 'delete_comment'
$id           = $_GET['id']            ?? null;  // integer assignment id
$assignmentId = $_GET['assignment_id'] ?? null;  // integer assignment id for comments queries
$commentId    = $_GET['comment_id']    ?? null;  // integer comment id


// ============================================================================
// ASSIGNMENT FUNCTIONS
// ============================================================================

/**
 * Get all assignments (with optional search and sort).
 * Method: GET (no ?id or ?action parameter).
 */
function getAllAssignments(PDO $db): void
{
    $query = "SELECT id, title, description, due_date, files, created_at, updated_at FROM assignments";
    $params = [];

    // Search filter handling
    if (!empty($_GET['search'])) {
        $searchTerm = trim($_GET['search']);
        $query .= " WHERE title LIKE :search OR description LIKE :search";
        $params[':search'] = '%' . $searchTerm . '%';
    }

    // Sorting validation whitelist
    $allowedSort = ['title', 'due_date', 'created_at'];
    $sort = isset($_GET['sort']) && in_array($_GET['sort'], $allowedSort, true) ? $_GET['sort'] : 'due_date';

    // Order direction validation whitelist
    $allowedOrder = ['asc', 'desc'];
    $order = isset($_GET['order']) && in_array(strtolower($_GET['order']), $allowedOrder, true) ? strtolower($_GET['order']) : 'asc';

    $query .= " ORDER BY {$sort} {$order}";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Decode files JSON payload for every row
    foreach ($assignments as &$row) {
        $row['files'] = json_decode($row['files'] ?? '', true) ?? [];
    }
    unset($row);

    sendResponse(['success' => true, 'data' => $assignments]);
}

/**
 * Get a single assignment by its integer primary key.
 * Method: GET with ?id={id}.
 */
function getAssignmentById(PDO $db, $id): void
{
    if ($id === null || !is_numeric($id)) {
        sendResponse(['success' => false, 'message' => 'Missing or invalid assignment ID.'], 400);
    }

    $stmt = $db->prepare("SELECT id, title, description, due_date, files, created_at, updated_at FROM assignments WHERE id = ?");
    $stmt->execute([(int)$id]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assignment) {
        sendResponse(['success' => false, 'message' => 'Assignment not found.'], 404);
    }

    $assignment['files'] = json_decode($assignment['files'] ?? '', true) ?? [];

    sendResponse(['success' => true, 'data' => $assignment]);
}

/**
 * Create a new assignment.
 * Method: POST (no ?action parameter).
 */
function createAssignment(PDO $db, array $data): void
{
    if (empty($data['title']) || empty($data['description']) || empty($data['due_date'])) {
        sendResponse(['success' => false, 'message' => 'Required fields missing (title, description, due_date).'], 400);
    }

    $title = sanitizeInput($data['title']);
    $description = sanitizeInput($data['description']);
    $dueDate = trim($data['due_date']);

    if (!validateDate($dueDate)) {
        sendResponse(['success' => false, 'message' => 'Invalid due_date format. Must be YYYY-MM-DD.'], 400);
    }

    // Handle optional files collection
    $filesJson = (isset($data['files']) && is_array($data['files'])) 
        ? json_encode($data['files']) 
        : json_encode([]);

    $stmt = $db->prepare("INSERT INTO assignments (title, description, due_date, files) VALUES (?, ?, ?, ?)");
    $stmt->execute([$title, $description, $dueDate, $filesJson]);

    if ($stmt->rowCount() > 0) {
        sendResponse([
            'success' => true, 
            'message' => 'Assignment created successfully.', 
            'id' => (int)$db->lastInsertId()
        ], 201);
    }

    sendResponse(['success' => false, 'message' => 'Failed to create assignment.'], 500);
}

/**
 * Update an existing assignment.
 * Method: PUT.
 */
function updateAssignment(PDO $db, array $data): void
{
    if (!isset($data['id']) || !is_numeric($data['id'])) {
        sendResponse(['success' => false, 'message' => 'Assignment id is required.'], 400);
    }

    $id = (int)$data['id'];

    // Ensure resource target exists
    $checkStmt = $db->prepare("SELECT id FROM assignments WHERE id = ?");
    $checkStmt->execute([$id]);
    if (!$checkStmt->fetch()) {
        sendResponse(['success' => false, 'message' => 'Assignment not found.'], 404);
    }

    $setClauses = [];
    $params = [];

    // Dynamically build mutations safely
    if (isset($data['title'])) {
        $setClauses[] = "title = ?";
        $params[] = sanitizeInput($data['title']);
    }

    if (isset($data['description'])) {
        $setClauses[] = "description = ?";
        $params[] = sanitizeInput($data['description']);
    }

    if (isset($data['due_date'])) {
        $dueDate = trim($data['due_date']);
        if (!validateDate($dueDate)) {
            sendResponse(['success' => false, 'message' => 'Invalid due_date format. Must be YYYY-MM-DD.'], 400);
        }
        $setClauses[] = "due_date = ?";
        $params[] = $dueDate;
    }

    if (isset($data['files'])) {
        if (!is_array($data['files'])) {
            sendResponse(['success' => false, 'message' => 'Files must be an array.'], 400);
        }
        $setClauses[] = "files = ?";
        $params[] = json_encode($data['files']);
    }

    if (empty($setClauses)) {
        sendResponse(['success' => false, 'message' => 'No updatable parameters provided.'], 400);
    }

    // Complete setup parameters with target id
    $params[] = $id;
    $sql = "UPDATE assignments SET " . implode(", ", $setClauses) . " WHERE id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    // Note: rowCount will be 0 if the data payload matches existing record states perfectly
    sendResponse(['success' => true, 'message' => 'Assignment updated successfully.']);
}

/**
 * Delete an assignment by integer id.
 * Method: DELETE with ?id={id}.
 */
function deleteAssignment(PDO $db, $id): void
{
    if ($id === null || !is_numeric($id)) {
        sendResponse(['success' => false, 'message' => 'Missing or invalid assignment ID.'], 400);
    }

    $id = (int)$id;

    $checkStmt = $db->prepare("SELECT id FROM assignments WHERE id = ?");
    $checkStmt->execute([$id]);
    if (!$checkStmt->fetch()) {
        sendResponse(['success' => false, 'message' => 'Assignment not found.'], 404);
    }

    $stmt = $db->prepare("DELETE FROM assignments WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() > 0) {
        sendResponse(['success' => true, 'message' => 'Assignment deleted successfully.']);
    }

    sendResponse(['success' => false, 'message' => 'Failed to delete assignment.'], 500);
}


// ============================================================================
// COMMENTS FUNCTIONS
// ============================================================================

/**
 * Get all comments for a specific assignment.
 * Method: GET with ?action=comments&assignment_id={id}.
 */
function getCommentsByAssignment(PDO $db, $assignmentId): void
{
    if ($assignmentId === null || !is_numeric($assignmentId)) {
        sendResponse(['success' => false, 'message' => 'Missing or invalid assignment_id.'], 400);
    }

    $stmt = $db->prepare("SELECT id, assignment_id, author, text, created_at FROM comments_assignment WHERE assignment_id = ? ORDER BY created_at ASC");
    $stmt->execute([(int)$assignmentId]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendResponse(['success' => true, 'data' => $comments]);
}

/**
 * Create a new comment.
 * Method: POST with ?action=comment.
 */
function createComment(PDO $db, array $data): void
{
    if (!isset($data['assignment_id']) || empty($data['author']) || empty($data['text'])) {
        sendResponse(['success' => false, 'message' => 'Required payload components missing.'], 400);
    }

    if (!is_numeric($data['assignment_id'])) {
        sendResponse(['success' => false, 'message' => 'Invalid assignment_id structure.'], 400);
    }

    $assignmentId = (int)$data['assignment_id'];
    $author = sanitizeInput($data['author']);
    $text = sanitizeInput($data['text']);

    if (empty($author) || empty($text)) {
        sendResponse(['success' => false, 'message' => 'Author and text elements cannot be blank.'], 400);
    }

    // Verify parent reference key
    $checkStmt = $db->prepare("SELECT id FROM assignments WHERE id = ?");
    $checkStmt->execute([$assignmentId]);
    if (!$checkStmt->fetch()) {
        sendResponse(['success' => false, 'message' => 'Target assignment does not exist.'], 404);
    }

    $stmt = $db->prepare("INSERT INTO comments_assignment (assignment_id, author, text) VALUES (?, ?, ?)");
    $stmt->execute([$assignmentId, $author, $text]);

    if ($stmt->rowCount() > 0) {
        $newId = (int)$db->lastInsertId();
        
        // Fetch fresh object to reflect database-level state structures (like created_at)
        $freshStmt = $db->prepare("SELECT id, assignment_id, author, text, created_at FROM comments_assignment WHERE id = ?");
        $freshStmt->execute([$newId]);
        $newComment = $freshStmt->fetch(PDO::FETCH_ASSOC);

        sendResponse([
            'success' => true,
            'message' => 'Comment added successfully.',
            'id' => $newId,
            'data' => $newComment
        ], 201);
    }

    sendResponse(['success' => false, 'message' => 'Failed to create comment.'], 500);
}

/**
 * Delete a single comment.
 * Method: DELETE with ?action=delete_comment&comment_id={id}.
 */
function deleteComment(PDO $db, $commentId): void
{
    if ($commentId === null || !is_numeric($commentId)) {
        sendResponse(['success' => false, 'message' => 'Missing or invalid comment_id.'], 400);
    }

    $commentId = (int)$commentId;

    $checkStmt = $db->prepare("SELECT id FROM comments_assignment WHERE id = ?");
    $checkStmt->execute([$commentId]);
    if (!$checkStmt->fetch()) {
        sendResponse(['success' => false, 'message' => 'Comment not found.'], 404);
    }

    $stmt = $db->prepare("DELETE FROM comments_assignment WHERE id = ?");
    $stmt->execute([$commentId]);

    if ($stmt->rowCount() > 0) {
        sendResponse(['success' => true, 'message' => 'Comment deleted successfully.']);
    }

    sendResponse(['success' => false, 'message' => 'Failed to delete comment.'], 500);
}


// ============================================================================
// MAIN REQUEST ROUTER
// ============================================================================

try {

    if ($method === 'GET') {

        if ($action === 'comments') {
            getCommentsByAssignment($db, $assignmentId);
        } elseif ($id !== null) {
            getAssignmentById($db, $id);
        } else {
            getAllAssignments($db);
        }

    } elseif ($method === 'POST') {

        if ($action === 'comment') {
            createComment($db, $data);
        } else {
            createAssignment($db, $data);
        }

    } elseif ($method === 'PUT') {

        updateAssignment($db, $data);

    } elseif ($method === 'DELETE') {

        if ($action === 'delete_comment') {
            deleteComment($db, $commentId);
        } else {
            deleteAssignment($db, $id);
        }

    } else {
        sendResponse(['success' => false, 'message' => 'Method Not Allowed.'], 405);
    }

} catch (PDOException $e) {
    error_log("Database Exception Layer Failure: " . $e->getMessage());
    sendResponse(['success' => false, 'message' => 'A database unexpected error occurred. Please try again later.'], 500);

} catch (Exception $e) {
    error_log("General Application Runtime Exception: " . $e->getMessage());
    sendResponse(['success' => false, 'message' => 'Internal Server Processing Error.'], 500);
}


// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Send a JSON response and stop execution.
 *
 * @param array $data         Must include a 'success' key.
 * @param int   $statusCode   HTTP status code (default 200).
 */
function sendResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Validate a date string against the "YYYY-MM-DD" format.
 *
 * @param  string $date
 * @return bool   True if valid, false otherwise.
 */
function validateDate(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

/**
 * Sanitize a string input.
 *
 * @param  string $data
 * @return string  Trimmed, tag-stripped, HTML-encoded string.
 */
function sanitizeInput(string $data): string
{
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}
