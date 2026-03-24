<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');

ini_set('display_errors', '0');
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function respond($success, $submitted = false, $cli = null, $message = '') {
    $res = [
        'success' => $success,
        'submitted' => $submitted,
    ];
    if ($cli !== null) $res['cli'] = $cli;
    if ($message) $res['message'] = $message;
    echo json_encode($res);
    exit;
}

require_once '../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    if (!$conn) {
        respond(false, false, null, 'DB connection failed');
    }

    $currentYear = (int)date('Y');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $user_id = $_GET['user_id'] ?? null;
        $year = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;

        if (!$user_id) {
            respond(false, false, null, 'Missing user_id');
        }

        $stmt = $conn->prepare('SELECT cli_data, submitted_at, updated_at FROM career_leverage_inventory WHERE user_id = ? AND year = ? LIMIT 1');
        $stmt->execute([$user_id, $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $data = json_decode($row['cli_data'], true);
            if (!is_array($data)) $data = [];
            $data['_meta'] = [
                'year' => $year,
                'submitted_at' => $row['submitted_at'],
                'updated_at' => $row['updated_at'],
            ];
            respond(true, true, $data);
        }

        respond(true, false, null);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            respond(false, false, null, 'Invalid JSON payload');
        }

        if (!isset($input['user_id'])) {
            respond(false, false, null, 'Missing user_id in payload');
        }

        $user_id = $input['user_id'];
        $year = isset($input['year']) ? (int)$input['year'] : $currentYear;
        $input['year'] = $year;

        $json = json_encode($input);

        $sql = "INSERT INTO career_leverage_inventory (user_id, year, cli_data, submitted_at, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    cli_data = VALUES(cli_data),
                    submitted_at = NOW(),
                    updated_at = NOW()";

        $stmt = $conn->prepare($sql);
        if ($stmt->execute([$user_id, $year, $json])) {
            respond(true, true, $input, 'Career Leverage Inventory submitted');
        }

        respond(false, false, null, 'Failed to submit Career Leverage Inventory');
    }

    respond(false, false, null, 'Unsupported request method');
} catch (Throwable $e) {
    respond(false, false, null, $e->getMessage());
}
?>

