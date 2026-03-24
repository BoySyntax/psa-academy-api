<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$action = isset($_GET['action']) ? $_GET['action'] : '';

try {
    if (!$db) {
        throw new Exception('Database connection failed');
    }

    switch ($action) {
        case 'get':
            handleGet($db);
            break;
        case 'list':
            handleList($db);
            break;
        default:
            handleSave($db);
            break;
    }
} catch (Exception $e) {
    error_log("SATNA Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error: ' . $e->getMessage()]);
}

function handleGet($db) {
    $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    $year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID is required']);
        exit();
    }

    $query = "SELECT * FROM satna WHERE user_id = :user_id AND year = :year";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindParam(':year', $year, PDO::PARAM_INT);
    $stmt->execute();

    $satna = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($satna) {
        // Decode JSON fields
        $jsonFields = [
            'exemplifying_integrity', 'results_orientation', 'service_orientation',
            'teamwork_partnerships', 'policy_implementation', 'planning_organizing',
            'strategic_thinking', 'technical_knowledge'
        ];

        foreach ($jsonFields as $field) {
            if ($satna[$field]) {
                $satna[$field] = json_decode($satna[$field], true);
            }
        }

        echo json_encode(['success' => true, 'satna' => $satna]);
    } else {
        echo json_encode(['success' => false, 'message' => 'SATNA not found']);
    }
}

function handleList($db) {
    $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID is required']);
        exit();
    }

    $query = "SELECT id, year, date_accomplished, employee_signature FROM satna WHERE user_id = :user_id ORDER BY year DESC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->execute();

    $satnas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'satnas' => $satnas]);
}

function handleSave($db) {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
        exit();
    }

    $requiredFields = ['user_id', 'year', 'employee_name', 'position', 'office', 'service'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Field '$field' is required"]);
            exit();
        }
    }

    // Check if SATNA already exists for this user and year
    $checkQuery = "SELECT id FROM satna WHERE user_id = :user_id AND year = :year";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(':user_id', $data['user_id'], PDO::PARAM_INT);
    $checkStmt->bindParam(':year', $data['year'], PDO::PARAM_INT);
    $checkStmt->execute();

    $existingId = $checkStmt->fetchColumn();

    // Encode JSON fields
    $jsonFields = [
        'exemplifying_integrity', 'results_orientation', 'service_orientation',
        'teamwork_partnerships', 'policy_implementation', 'planning_organizing',
        'strategic_thinking', 'technical_knowledge'
    ];

    foreach ($jsonFields as $field) {
        if (isset($data[$field]) && is_array($data[$field])) {
            $data[$field] = json_encode($data[$field]);
        }
    }

    if ($existingId) {
        // Update existing SATNA
        $updateQuery = "UPDATE satna SET 
            employee_name = :employee_name,
            position = :position,
            office = :office,
            service = :service,
            period_covered = :period_covered,
            exemplifying_integrity = :exemplifying_integrity,
            results_orientation = :results_orientation,
            service_orientation = :service_orientation,
            teamwork_partnerships = :teamwork_partnerships,
            policy_implementation = :policy_implementation,
            planning_organizing = :planning_organizing,
            strategic_thinking = :strategic_thinking,
            technical_knowledge = :technical_knowledge,
            training_attended = :training_attended,
            training_recommendations = :training_recommendations,
            key_accomplishments = :key_accomplishments,
            challenges_encountered = :challenges_encountered,
            next_year_goals = :next_year_goals,
            career_aspirations = :career_aspirations,
            date_accomplished = :date_accomplished,
            updated_at = CURRENT_TIMESTAMP
            WHERE id = :id";

        $stmt = $db->prepare($updateQuery);
        $stmt->bindParam(':id', $existingId, PDO::PARAM_INT);
    } else {
        // Insert new SATNA
        $insertQuery = "INSERT INTO satna (
            user_id, year, employee_name, position, office, service, period_covered,
            exemplifying_integrity, results_orientation, service_orientation,
            teamwork_partnerships, policy_implementation, planning_organizing,
            strategic_thinking, technical_knowledge, training_attended,
            training_recommendations, key_accomplishments, challenges_encountered,
            next_year_goals, career_aspirations, date_accomplished,
            created_at, updated_at
        ) VALUES (
            :user_id, :year, :employee_name, :position, :office, :service, :period_covered,
            :exemplifying_integrity, :results_orientation, :service_orientation,
            :teamwork_partnerships, :policy_implementation, :planning_organizing,
            :strategic_thinking, :technical_knowledge, :training_attended,
            :training_recommendations, :key_accomplishments, :challenges_encountered,
            :next_year_goals, :career_aspirations, :date_accomplished,
            CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        )";

        $stmt = $db->prepare($insertQuery);
    }

    // Bind parameters
    $stmt->bindParam(':user_id', $data['user_id'], PDO::PARAM_INT);
    $stmt->bindParam(':year', $data['year'], PDO::PARAM_INT);
    $stmt->bindParam(':employee_name', $data['employee_name']);
    $stmt->bindParam(':position', $data['position']);
    $stmt->bindParam(':office', $data['office']);
    $stmt->bindParam(':service', $data['service']);
    $stmt->bindParam(':period_covered', $data['period_covered']);
    $stmt->bindParam(':exemplifying_integrity', $data['exemplifying_integrity']);
    $stmt->bindParam(':results_orientation', $data['results_orientation']);
    $stmt->bindParam(':service_orientation', $data['service_orientation']);
    $stmt->bindParam(':teamwork_partnerships', $data['teamwork_partnerships']);
    $stmt->bindParam(':policy_implementation', $data['policy_implementation']);
    $stmt->bindParam(':planning_organizing', $data['planning_organizing']);
    $stmt->bindParam(':strategic_thinking', $data['strategic_thinking']);
    $stmt->bindParam(':technical_knowledge', $data['technical_knowledge']);
    $stmt->bindParam(':training_attended', $data['training_attended']);
    $stmt->bindParam(':training_recommendations', $data['training_recommendations']);
    $stmt->bindParam(':key_accomplishments', $data['key_accomplishments']);
    $stmt->bindParam(':challenges_encountered', $data['challenges_encountered']);
    $stmt->bindParam(':next_year_goals', $data['next_year_goals']);
    $stmt->bindParam(':career_aspirations', $data['career_aspirations']);
    $stmt->bindParam(':date_accomplished', $data['date_accomplished']);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'SATNA saved successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save SATNA']);
    }
}
?>

