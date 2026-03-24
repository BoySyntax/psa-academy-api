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
                cli.user_id,
                cli.year,
                cli.submitted_at,
                cli.updated_at,
                cli.cli_data,
                u.first_name,
                u.middle_name,
                u.last_name,
                u.email,
                u.cellphone_number,
                u.profile_image_url
            FROM career_leverage_inventory cli
            INNER JOIN users u ON cli.user_id = u.id
            WHERE cli.year = ?
            ORDER BY COALESCE(cli.submitted_at, cli.updated_at) DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute([$year]);

    $submissions = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $submissions[] = [
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
            'cli' => json_decode($row['cli_data'], true),
        ];
    }

    echo json_encode([
        'success' => true,
        'submissions' => $submissions,
        'count' => count($submissions),
        'year' => $year,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch Career Leverage Inventory submissions: ' . $e->getMessage(),
    ]);
}
