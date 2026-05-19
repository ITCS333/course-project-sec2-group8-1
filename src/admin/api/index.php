<?php
/**
 * User Management API
 * ITCS333 Internet Software Development
 */

// ============================================================================
// HEADERS
// ============================================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================================
// DATABASE CONNECTION
// ============================================================================
require_once '../db/connection.php';
$db = getDBConnection();

// ============================================================================
// REQUEST PARSING
// ============================================================================
$method = $_SERVER['REQUEST_METHOD'];
$raw    = file_get_contents('php://input');
$data   = json_decode($raw, true) ?? [];

// Query string parameters
$id     = isset($_GET['id'])     ? (int) $_GET['id']        : null;
$action = isset($_GET['action']) ? trim($_GET['action'])     : null;
$search = isset($_GET['search']) ? trim($_GET['search'])     : null;
$sort   = isset($_GET['sort'])   ? trim($_GET['sort'])       : null;
$order  = isset($_GET['order'])  ? trim($_GET['order'])      : 'asc';


// ============================================================================
// CRUD FUNCTIONS
// ============================================================================

/**
 * GET all users (with optional search / sort).
 */
function getUsers($db) {
    global $search, $sort, $order;

    $allowedSort  = ['name', 'email', 'is_admin'];
    $allowedOrder = ['asc', 'desc'];

    $sql    = 'SELECT id, name, email, is_admin, created_at FROM users';
    $params = [];

    // Search filter
    if (!empty($search)) {
        $sql .= ' WHERE name LIKE :search OR email LIKE :search';
        $params[':search'] = '%' . $search . '%';
    }

    // Sorting
    if (!empty($sort) && in_array($sort, $allowedSort, true)) {
        $dir  = (in_array(strtolower($order), $allowedOrder, true))
                ? strtoupper($order)
                : 'ASC';
        $sql .= ' ORDER BY ' . $sort . ' ' . $dir;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendResponse($users, 200);
}

/**
 * GET a single user by id.
 */
function getUserById($db, $id) {
    $stmt = $db->prepare(
        'SELECT id, name, email, is_admin, created_at FROM users WHERE id = :id'
    );
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        sendResponse('User not found.', 404);
    }

    sendResponse($user, 200);
}

/**
 * POST — create a new user.
 */
function createUser($db, $data) {
    // Required fields
    if (empty($data['name']) || empty($data['email']) || empty($data['password'])) {
        sendResponse('Name, email, and password are required.', 400);
    }

    $name     = sanitizeInput($data['name']);
    $email    = sanitizeInput($data['email']);
    $password = trim($data['password']);

    // Validate email format
    if (!validateEmail($email)) {
        sendResponse('Invalid email address.', 400);
    }

    // Validate password length
    if (strlen($password) < 8) {
        sendResponse('Password must be at least 8 characters.', 400);
    }

    // Check for duplicate email
    $check = $db->prepare('SELECT id FROM users WHERE email = :email');
    $check->bindValue(':email', $email);
    $check->execute();
    if ($check->fetch()) {
        sendResponse('A user with this email already exists.', 409);
    }

    // Hash password
    $hashed   = password_hash($password, PASSWORD_DEFAULT);
    $is_admin = isset($data['is_admin']) && in_array((int)$data['is_admin'], [0, 1], true)
                ? (int) $data['is_admin']
                : 0;

    $stmt = $db->prepare(
        'INSERT INTO users (name, email, password, is_admin) VALUES (:name, :email, :password, :is_admin)'
    );
    $stmt->bindValue(':name',     $name);
    $stmt->bindValue(':email',    $email);
    $stmt->bindValue(':password', $hashed);
    $stmt->bindValue(':is_admin', $is_admin, PDO::PARAM_INT);

    if ($stmt->execute()) {
        sendResponse(['id' => (int) $db->lastInsertId()], 201);
    }

    sendResponse('Failed to create user.', 500);
}

/**
 * PUT — update an existing user (name, email, is_admin only).
 */
function updateUser($db, $data) {
    if (empty($data['id'])) {
        sendResponse('User id is required.', 400);
    }

    $id = (int) $data['id'];

    // Confirm user exists
    $check = $db->prepare('SELECT id FROM users WHERE id = :id');
    $check->bindValue(':id', $id, PDO::PARAM_INT);
    $check->execute();
    if (!$check->fetch()) {
        sendResponse('User not found.', 404);
    }

    // Build dynamic SET clause
    $fields = [];
    $params = [':id' => $id];

    if (isset($data['name']) && $data['name'] !== '') {
        $fields[]         = 'name = :name';
        $params[':name']  = sanitizeInput($data['name']);
    }

    if (isset($data['email']) && $data['email'] !== '') {
        $email = sanitizeInput($data['email']);
        if (!validateEmail($email)) {
            sendResponse('Invalid email address.', 400);
        }
        // Duplicate email check (exclude current user)
        $dup = $db->prepare('SELECT id FROM users WHERE email = :email AND id != :id');
        $dup->bindValue(':email', $email);
        $dup->bindValue(':id', $id, PDO::PARAM_INT);
        $dup->execute();
        if ($dup->fetch()) {
            sendResponse('This email is already in use by another account.', 409);
        }
        $fields[]          = 'email = :email';
        $params[':email']  = $email;
    }

    if (isset($data['is_admin']) && in_array((int)$data['is_admin'], [0, 1], true)) {
        $fields[]            = 'is_admin = :is_admin';
        $params[':is_admin'] = (int) $data['is_admin'];
    }

    if (empty($fields)) {
        sendResponse('No valid fields provided for update.', 400);
    }

    $sql  = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $stmt = $db->prepare($sql);

    if ($stmt->execute($params)) {
        sendResponse('User updated successfully.', 200);
    }

    sendResponse('Failed to update user.', 500);
}

/**
 * DELETE — remove a user by id.
 */
function deleteUser($db, $id) {
    if (empty($id)) {
        sendResponse('User id is required.', 400);
    }

    // Confirm user exists
    $check = $db->prepare('SELECT id FROM users WHERE id = :id');
    $check->bindValue(':id', $id, PDO::PARAM_INT);
    $check->execute();
    if (!$check->fetch()) {
        sendResponse('User not found.', 404);
    }

    $stmt = $db->prepare('DELETE FROM users WHERE id = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);

    if ($stmt->execute()) {
        sendResponse('User deleted successfully.', 200);
    }

    sendResponse('Failed to delete user.', 500);
}

/**
 * POST ?action=change_password — update a user's password.
 */
function changePassword($db, $data) {
    if (empty($data['id']) || empty($data['current_password']) || empty($data['new_password'])) {
        sendResponse('id, current_password, and new_password are required.', 400);
    }

    $id           = (int) $data['id'];
    $newPassword  = $data['new_password'];
    $currPassword = $data['current_password'];

    if (strlen($newPassword) < 8) {
        sendResponse('Password must be at least 8 characters.', 400);
    }

    // Fetch stored hash
    $stmt = $db->prepare('SELECT password FROM users WHERE id = :id');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        sendResponse('User not found.', 404);
    }

    // Verify current password
    if (!password_verify($currPassword, $row['password'])) {
        sendResponse('Current password is incorrect.', 401);
    }

    // Hash and store new password
    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
    $update = $db->prepare('UPDATE users SET password = :password WHERE id = :id');
    $update->bindValue(':password', $hashed);
    $update->bindValue(':id', $id, PDO::PARAM_INT);

    if ($update->execute()) {
        sendResponse('Password updated successfully.', 200);
    }

    sendResponse('Failed to update password.', 500);
}


// ============================================================================
// MAIN REQUEST ROUTER
// ============================================================================
try {

    if ($method === 'GET') {
        if (!empty($id)) {
            getUserById($db, $id);
        } else {
            getUsers($db);
        }

    } elseif ($method === 'POST') {
        if ($action === 'change_password') {
            changePassword($db, $data);
        } else {
            createUser($db, $data);
        }

    } elseif ($method === 'PUT') {
        updateUser($db, $data);

    } elseif ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : null;
        deleteUser($db, $id);

    } else {
        sendResponse('Method not allowed.', 405);
    }

} catch (PDOException $e) {
    error_log($e->getMessage());
    sendResponse('A database error occurred. Please try again later.', 500);

} catch (Exception $e) {
    sendResponse($e->getMessage(), 500);
}


// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Sends a JSON response and terminates execution.
 */
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);

    if ($statusCode < 400) {
        echo json_encode(['success' => true,  'data'    => $data]);
    } else {
        echo json_encode(['success' => false, 'message' => $data]);
    }

    exit;
}

/**
 * Validates an email address.
 */
function validateEmail($email) {
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Sanitizes a string input value.
 */
function sanitizeInput($data) {
    $data = trim($data);
    $data = strip_tags($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}
?>
