<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

if (!$db) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

function parseCompetencies($raw) {
    $result = [
        'core' => '',
        'leadership' => '',
        'technical' => '',
    ];

    if (!$raw) {
        return $result;
    }

    if (preg_match('/^Core:\s*(.*?)\s*\|\s*Leadership:\s*(.*?)\s*\|\s*Technical:\s*(.*)$/', (string)$raw, $matches)) {
        $result['core'] = trim($matches[1] ?? '');
        $result['leadership'] = trim($matches[2] ?? '');
        $result['technical'] = trim($matches[3] ?? '');
        return $result;
    }

    $result['technical'] = trim((string)$raw);
    return $result;
}

try {
    switch ($method) {
        case 'GET':
            $planYear = isset($_GET['plan_year']) ? (int)$_GET['plan_year'] : (int)date('Y');

            $query = "SELECT
                cdp.id,
                cdp.plan_year,
                cdp.course_id,
                cdp.proposed_training_schedule,
                cdp.target_participants,
                cdp.estimated_participants,
                cdp.status_notes,
                cdp.created_by,
                cdp.created_at,
                cdp.updated_at,
                c.course_code,
                c.course_name,
                c.category,
                c.subcategory,
                c.description,
                c.max_students
            FROM capacity_development_plans cdp
            INNER JOIN courses c ON c.id = cdp.course_id
            WHERE cdp.plan_year = :plan_year
            ORDER BY c.course_name ASC";

            $stmt = $db->prepare($query);
            $stmt->bindValue(':plan_year', $planYear, PDO::PARAM_INT);
            $stmt->execute();

            $items = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $competencies = parseCompetencies($row['subcategory'] ?? '');
                $items[] = [
                    'id' => (int)$row['id'],
                    'plan_year' => (int)$row['plan_year'],
                    'course_id' => (int)$row['course_id'],
                    'proposed_training_schedule' => $row['proposed_training_schedule'],
                    'target_participants' => $row['target_participants'],
                    'estimated_participants' => isset($row['estimated_participants']) ? (int)$row['estimated_participants'] : null,
                    'status_notes' => $row['status_notes'],
                    'created_by' => $row['created_by'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                    'course' => [
                        'id' => (int)$row['course_id'],
                        'course_code' => $row['course_code'],
                        'course_name' => $row['course_name'],
                        'category' => $row['category'],
                        'subcategory' => $row['subcategory'],
                        'description' => $row['description'],
                        'max_students' => isset($row['max_students']) ? (int)$row['max_students'] : null,
                    ],
                    'competencies' => $competencies,
                ];
            }

            echo json_encode(['success' => true, 'items' => $items]);
            exit();

        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            $planYear = isset($data['plan_year']) ? (int)$data['plan_year'] : 0;
            $courseId = isset($data['course_id']) ? (int)$data['course_id'] : 0;
            $proposedTrainingSchedule = $data['proposed_training_schedule'] ?? null;
            $targetParticipants = $data['target_participants'] ?? null;
            $estimatedParticipants = isset($data['estimated_participants']) && $data['estimated_participants'] !== '' ? (int)$data['estimated_participants'] : null;
            $statusNotes = $data['status_notes'] ?? null;
            $createdBy = isset($data['created_by']) ? (int)$data['created_by'] : null;

            if ($planYear <= 0 || $courseId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'plan_year and course_id are required']);
                exit();
            }

            $query = "INSERT INTO capacity_development_plans (
                plan_year, course_id, proposed_training_schedule, target_participants,
                estimated_participants, status_notes, created_by
            ) VALUES (
                :plan_year, :course_id, :proposed_training_schedule, :target_participants,
                :estimated_participants, :status_notes, :created_by
            )";

            $stmt = $db->prepare($query);
            $stmt->bindValue(':plan_year', $planYear, PDO::PARAM_INT);
            $stmt->bindValue(':course_id', $courseId, PDO::PARAM_INT);
            $stmt->bindValue(':proposed_training_schedule', $proposedTrainingSchedule);
            $stmt->bindValue(':target_participants', $targetParticipants);
            $stmt->bindValue(':estimated_participants', $estimatedParticipants, $estimatedParticipants === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':status_notes', $statusNotes);
            $stmt->bindValue(':created_by', $createdBy, $createdBy === null ? PDO::PARAM_NULL : PDO::PARAM_INT);

            if (!$stmt->execute()) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to create CDP entry']);
                exit();
            }

            echo json_encode([
                'success' => true,
                'message' => 'CDP entry created successfully',
                'id' => (int)$db->lastInsertId(),
            ]);
            exit();

        case 'PUT':
            $data = json_decode(file_get_contents('php://input'), true);
            $id = isset($data['id']) ? (int)$data['id'] : 0;

            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'id is required']);
                exit();
            }

            $allowedFields = [
                'plan_year',
                'course_id',
                'proposed_training_schedule',
                'target_participants',
                'estimated_participants',
                'status_notes',
            ];

            $updates = [];
            $params = [':id' => $id];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[] = "$field = :$field";
                    $params[":$field"] = $data[$field] === '' ? null : $data[$field];
                }
            }

            if (empty($updates)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'No fields to update']);
                exit();
            }

            $query = "UPDATE capacity_development_plans SET " . implode(', ', $updates) . " WHERE id = :id";
            $stmt = $db->prepare($query);
            foreach ($params as $key => $value) {
                if (($key === ':plan_year' || $key === ':course_id' || $key === ':estimated_participants') && $value !== null) {
                    $stmt->bindValue($key, (int)$value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($key, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                }
            }

            if (!$stmt->execute()) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to update CDP entry']);
                exit();
            }

            echo json_encode(['success' => true, 'message' => 'CDP entry updated successfully']);
            exit();

        case 'DELETE':
            $data = json_decode(file_get_contents('php://input'), true);
            $id = isset($data['id']) ? (int)$data['id'] : 0;

            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'id is required']);
                exit();
            }

            $stmt = $db->prepare('DELETE FROM capacity_development_plans WHERE id = :id');
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);

            if (!$stmt->execute()) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to delete CDP entry']);
                exit();
            }

            echo json_encode(['success' => true, 'message' => 'CDP entry deleted successfully']);
            exit();
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
} catch (PDOException $e) {
    $message = $e->getCode() === '23000'
        ? 'A CDP entry for this course and year already exists.'
        : ('Database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}
?>

