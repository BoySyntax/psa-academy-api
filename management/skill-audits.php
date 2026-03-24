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

    $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

    $sql = "SELECT
                sa.user_id,
                sa.year,
                sa.submitted_at,
                sa.updated_at,
                sa.audit_data,
                u.first_name,
                u.middle_name,
                u.last_name,
                u.email,
                u.cellphone_number,
                u.profile_image_url
            FROM skill_audit sa
            INNER JOIN users u ON sa.user_id = u.id
            WHERE sa.year = ?
            ORDER BY COALESCE(sa.submitted_at, sa.updated_at) DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute([$year]);

    $audits = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $audits[] = [
            'user_id' => (int)$row['user_id'],
            'year' => (int)$row['year'],
            'submitted_at' => $row['submitted_at'],
            'updated_at' => $row['updated_at'],
            'student' => [
                'first_name' => $row['first_name'],
                'middle_name' => $row['middle_name'],
                'last_name' => $row['last_name'],
                'email' => $row['email'],
                'cellphone_number' => $row['cellphone_number'],
                'profile_image_url' => $row['profile_image_url'],
            ],
            'audit' => json_decode($row['audit_data'], true),
        ];
    }

    $stmt->closeCursor();

    echo json_encode([
        'success' => true,
        'audits' => $audits,
        'count' => count($audits),
        'year' => $year,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch Skill Audits: ' . $e->getMessage(),
    ]);
}
