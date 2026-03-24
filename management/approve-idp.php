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

    if (!isset($data['user_id']) || !isset($data['action']) || !isset($data['management_user_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit();
    }

    $userId = (int)$data['user_id'];
    $action = $data['action'];
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
        echo json_encode(['success' => false, 'message' => 'Unauthorized: Only management users can approve IDPs']);
        exit();
    }
    $verifyStmt->closeCursor();

    // Check if IDP exists and is pending
    $checkStmt = $db->prepare("SELECT status FROM idp WHERE user_id = ?");
    $checkStmt->execute([$userId]);
    $idpRow = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$idpRow) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'IDP not found']);
        exit();
    }
    if ($idpRow['status'] !== 'pending') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'IDP is not pending']);
        exit();
    }
    $checkStmt->closeCursor();

    if ($action === 'approve') {
        $newStatus = 'approved';
        $messageToStore = $managementMessage ? $managementMessage : 'Your IDP has been approved.';
        $sql = "UPDATE idp
                SET status = ?,
                    approved_by = ?,
                    approved_at = NOW(),
                    rejection_reason = NULL,
                    management_message = ?
                WHERE user_id = ?";
        $stmt = $db->prepare($sql);
        $ok = $stmt->execute([$newStatus, $managementUserId, $messageToStore, $userId]);
    } else {
        $newStatus = 'rejected';
        $messageToStore = $managementMessage ? $managementMessage : ($rejectionReason ? $rejectionReason : 'Your IDP was rejected.');
        $sql = "UPDATE idp
                SET status = ?,
                    approved_by = ?,
                    approved_at = NOW(),
                    rejection_reason = ?,
                    management_message = ?
                WHERE user_id = ?";
        $stmt = $db->prepare($sql);
        $ok = $stmt->execute([$newStatus, $managementUserId, $rejectionReason, $messageToStore, $userId]);
    }

    if ($ok) {
        $stmt->closeCursor();

        echo json_encode([
            'success' => true,
            'message' => $action === 'approve' ? 'IDP approved successfully' : 'IDP rejected',
            'user_id' => $userId,
            'new_status' => $newStatus
        ]);
    } else {
        throw new Exception('Failed to update IDP');
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to process IDP: ' . $e->getMessage(),
    ]);
}
