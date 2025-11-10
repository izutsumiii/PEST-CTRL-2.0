<?php
/**
 * AJAX endpoint to update session activity
 * Called by client-side JavaScript to keep session alive
 */
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

// Update last activity time
$_SESSION['last_activity'] = time();

echo json_encode([
    'success' => true,
    'last_activity' => $_SESSION['last_activity'],
    'time_remaining' => (20 * 60) - (time() - $_SESSION['last_activity'])
]);

