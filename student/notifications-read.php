<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Get the POST data
$data = json_decode(file_get_contents('php://input'), true);
$studentId = isset($data['student_id']) ? (int)$data['student_id'] : 0;
$enrollmentId = isset($data['enrollment_id']) ? (int)$data['enrollment_id'] : null;

if ($studentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Student ID is required']);
    exit();
}

try {
    if (!$db) {
        throw new Exception('Database connection failed');
    }

    // Set connection charset
    $db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    // Mark course enrollments as read
    if ($enrollmentId) {
        // Mark specific course enrollment as read
        $stmt = $db->prepare("UPDATE course_enrollments SET student_seen = 1 WHERE id = :enrollment_id AND student_id = :student_id");
        $stmt->bindParam(':enrollment_id', $enrollmentId, PDO::PARAM_INT);
        $stmt->bindParam(':student_id', $studentId, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        // Mark all course enrollments as read
        $stmt = $db->prepare("UPDATE course_enrollments SET student_seen = 1 WHERE student_id = :student_id AND status IN ('enrolled', 'rejected')");
        $stmt->bindParam(':student_id', $studentId, PDO::PARAM_INT);
        $stmt->execute();
    }

    // Mark IDP notifications as read
    $stmt = $db->prepare("UPDATE idp SET student_seen = 1 WHERE user_id = :student_id AND status IN ('approved', 'rejected')");
    $stmt->bindParam(':student_id', $studentId, PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode(['success' => true, 'message' => 'Notifications marked as read']);

} catch (Exception $e) {
    error_log("Error marking notifications as read: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error: ' . $e->getMessage()]);
}
?>
