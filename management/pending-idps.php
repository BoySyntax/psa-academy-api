<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();

    $status = isset($_GET['status']) ? $_GET['status'] : 'pending';

    $sql = "SELECT 
                i.user_id,
                i.status,
                i.submitted_at,
                i.approved_by,
                i.approved_at,
                i.rejection_reason,
                i.management_message,
                u.first_name,
                u.middle_name,
                u.last_name,
                u.email,
                u.cellphone_number,
                u.profile_image_url,
                approver.first_name AS approver_first_name,
                approver.last_name AS approver_last_name,
                i.idp_data
            FROM idp i
            INNER JOIN users u ON i.user_id = u.id
            LEFT JOIN users approver ON i.approved_by = approver.id
            WHERE i.status = ?
            ORDER BY COALESCE(i.submitted_at, i.updated_at) DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute([$status]);

    $idps = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $idps[] = [
            'user_id' => (int)$row['user_id'],
            'status' => $row['status'],
            'submitted_at' => $row['submitted_at'],
            'approved_by' => $row['approved_by'],
            'approved_at' => $row['approved_at'],
            'rejection_reason' => $row['rejection_reason'],
            'management_message' => $row['management_message'],
            'student' => [
                'first_name' => $row['first_name'],
                'middle_name' => $row['middle_name'],
                'last_name' => $row['last_name'],
                'email' => $row['email'],
                'cellphone_number' => $row['cellphone_number'],
                'profile_image_url' => $row['profile_image_url'],
            ],
            'approver' => $row['approver_first_name'] ? [
                'first_name' => $row['approver_first_name'],
                'last_name' => $row['approver_last_name'],
            ] : null,
            'idp' => json_decode($row['idp_data'], true),
        ];
    }

    $stmt->closeCursor();

    echo json_encode([
        'success' => true,
        'idps' => $idps,
        'count' => count($idps),
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch IDPs: ' . $e->getMessage(),
    ]);
}
