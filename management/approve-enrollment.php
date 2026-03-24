<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['enrollment_id']) || !isset($data['action']) || !isset($data['management_user_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit();
    }
    
    $enrollmentId = (int)$data['enrollment_id'];
    $action = $data['action']; // 'approve' or 'reject'
    $managementUserId = (int)$data['management_user_id'];
    $rejectionReason = isset($data['rejection_reason']) ? $data['rejection_reason'] : null;
    $managementMessage = isset($data['management_message']) ? $data['management_message'] : null;
    
    if (!in_array($action, ['approve', 'reject'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit();
    }
    
    $database = new Database();
    $db = $database->getConnection();
    
    // Verify the management user exists and has correct role
    $verifyStmt = $db->prepare("SELECT user_type FROM users WHERE id = ?");
    $verifyStmt->execute([$managementUserId]);
    $userRow = $verifyStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userRow) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid management user']);
        exit();
    }
    if ($userRow['user_type'] !== 'management' && $userRow['user_type'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized: Only management users can approve enrollments']);
        exit();
    }
    $verifyStmt->closeCursor();
    
    // Check if enrollment exists and is pending
    $checkStmt = $db->prepare("SELECT status FROM course_enrollments WHERE id = ?");
    $checkStmt->execute([$enrollmentId]);
    $enrollmentRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$enrollmentRow) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Enrollment not found']);
        exit();
    }
    if ($enrollmentRow['status'] !== 'pending') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Enrollment is not pending']);
        exit();
    }
    $checkStmt->closeCursor();
    
    // Update enrollment status
    if ($action === 'approve') {
        $newStatus = 'enrolled';
        $messageToStore = $managementMessage ? $managementMessage : 'Your enrollment has been approved.';
        $sql = "UPDATE course_enrollments 
                SET status = ?, 
                    approved_by = ?, 
                    approved_at = NOW(),
                    rejection_reason = NULL,
                    management_message = ?,
                    student_seen = 0
                WHERE id = ?";
        $stmt = $db->prepare($sql);
        $ok = $stmt->execute([$newStatus, $managementUserId, $messageToStore, $enrollmentId]);
    } else {
        $newStatus = 'rejected';
        $messageToStore = $managementMessage ? $managementMessage : ($rejectionReason ? $rejectionReason : 'Your enrollment was rejected.');
        $sql = "UPDATE course_enrollments 
                SET status = ?, 
                    approved_by = ?, 
                    approved_at = NOW(),
                    rejection_reason = ?,
                    management_message = ?,
                    student_seen = 0
                WHERE id = ?";
        $stmt = $db->prepare($sql);
        $ok = $stmt->execute([$newStatus, $managementUserId, $rejectionReason, $messageToStore, $enrollmentId]);
    }
    
    if ($ok) {
        $stmt->closeCursor();
        
        echo json_encode([
            'success' => true,
            'message' => $action === 'approve' ? 'Enrollment approved successfully' : 'Enrollment rejected',
            'enrollment_id' => $enrollmentId,
            'new_status' => $newStatus
        ]);
    } else {
        throw new Exception('Failed to update enrollment');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to process enrollment: ' . $e->getMessage()
    ]);
}
