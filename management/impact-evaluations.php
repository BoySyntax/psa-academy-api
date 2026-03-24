<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit();
    }

    // GET: list impact evaluations for management
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $status = isset($_GET['status']) ? $_GET['status'] : 'due';

        $baseSql = "SELECT
            te.id AS evaluation_id,
            te.user_id,
            te.course_id,
            te.trainee_name,
            te.office_service_division,
            te.training_program,
            te.training_objectives,
            te.level3_q1,
            te.level3_q2,
            te.level3_q3,
            te.evaluated_by,
            te.evaluated_by_date,
            te.received_by,
            te.received_by_date,
            ce.completion_date,
            DATE_ADD(ce.completion_date, INTERVAL 3 MONTH) AS due_date,
            c.course_code,
            c.course_name,
            u.first_name,
            u.middle_name,
            u.last_name,
            u.email
        FROM training_evaluations te
        INNER JOIN course_enrollments ce
            ON ce.student_id = te.user_id
           AND ce.course_id = te.course_id
        INNER JOIN courses c ON c.id = te.course_id
        INNER JOIN users u ON u.id = te.user_id
        WHERE ce.status = 'completed'
          AND ce.completion_date IS NOT NULL";

        // Completed means Level 3 already filled.
        $completedCond = "(te.level3_q1 IS NOT NULL AND TRIM(te.level3_q1) <> '')";
        $dueCond = "(DATE_ADD(ce.completion_date, INTERVAL 3 MONTH) <= CURDATE())";

        if ($status === 'completed') {
            $baseSql .= " AND $completedCond";
        } else if ($status === 'not_yet_due') {
            $baseSql .= " AND NOT $dueCond AND NOT $completedCond";
        } else if ($status === 'due') {
            $baseSql .= " AND $dueCond AND NOT $completedCond";
        } else if ($status === 'all') {
            // no extra filter
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid status']);
            exit();
        }

        $baseSql .= " ORDER BY due_date DESC, ce.completion_date DESC";

        $stmt = $db->prepare($baseSql);
        $stmt->execute();

        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $traineeName = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            $items[] = [
                'evaluation_id' => (int)$row['evaluation_id'],
                'user_id' => (int)$row['user_id'],
                'course_id' => (int)$row['course_id'],
                'trainee_name' => $row['trainee_name'] ? $row['trainee_name'] : $traineeName,
                'office_service_division' => $row['office_service_division'],
                'training_program' => $row['training_program'] ? $row['training_program'] : $row['course_name'],
                'training_objectives' => $row['training_objectives'],
                'completion_date' => $row['completion_date'],
                'due_date' => $row['due_date'],
                'course' => [
                    'course_code' => $row['course_code'],
                    'course_name' => $row['course_name'],
                ],
                'student' => [
                    'first_name' => $row['first_name'],
                    'middle_name' => $row['middle_name'],
                    'last_name' => $row['last_name'],
                    'email' => $row['email'],
                ],
                'level3' => [
                    'q1' => $row['level3_q1'],
                    'q2' => $row['level3_q2'],
                    'q3' => $row['level3_q3'],
                    'evaluated_by' => $row['evaluated_by'],
                    'evaluated_by_date' => $row['evaluated_by_date'],
                    'received_by' => $row['received_by'],
                    'received_by_date' => $row['received_by_date'],
                ]
            ];
        }

        echo json_encode([
            'success' => true,
            'items' => $items,
            'count' => count($items),
        ]);
        exit();
    }

    // POST: update Level 3 fields
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);

        $user_id = isset($data['user_id']) ? $data['user_id'] : null;
        $course_id = isset($data['course_id']) ? $data['course_id'] : null;

        $training_objectives = isset($data['training_objectives']) ? $data['training_objectives'] : null;

        $level3_q1 = isset($data['level3_q1']) ? $data['level3_q1'] : null;
        $level3_q2 = isset($data['level3_q2']) ? $data['level3_q2'] : null;
        $level3_q3 = isset($data['level3_q3']) ? $data['level3_q3'] : null;

        $evaluated_by = isset($data['evaluated_by']) ? $data['evaluated_by'] : null;
        $evaluated_by_date = isset($data['evaluated_by_date']) ? $data['evaluated_by_date'] : null;
        $received_by = isset($data['received_by']) ? $data['received_by'] : null;
        $received_by_date = isset($data['received_by_date']) ? $data['received_by_date'] : null;

        if (!$user_id || !$course_id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'user_id and course_id are required']);
            exit();
        }

        if (!$level3_q1 || !$level3_q2 || !$level3_q3) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Level 3 answers are required']);
            exit();
        }

        $check = $db->prepare('SELECT id FROM training_evaluations WHERE user_id = :user_id AND course_id = :course_id LIMIT 1');
        $check->bindParam(':user_id', $user_id);
        $check->bindParam(':course_id', $course_id);
        $check->execute();
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Evaluation record not found. Student must submit Level 1-2 first.']);
            exit();
        }

        $sql = "UPDATE training_evaluations
                SET training_objectives = :training_objectives,
                    level3_q1 = :level3_q1,
                    level3_q2 = :level3_q2,
                    level3_q3 = :level3_q3,
                    evaluated_by = :evaluated_by,
                    evaluated_by_date = :evaluated_by_date,
                    received_by = :received_by,
                    received_by_date = :received_by_date
                WHERE user_id = :user_id AND course_id = :course_id";

        $stmt = $db->prepare($sql);
        $stmt->bindParam(':training_objectives', $training_objectives);
        $stmt->bindParam(':level3_q1', $level3_q1);
        $stmt->bindParam(':level3_q2', $level3_q2);
        $stmt->bindParam(':level3_q3', $level3_q3);
        $stmt->bindParam(':evaluated_by', $evaluated_by);
        $stmt->bindParam(':evaluated_by_date', $evaluated_by_date);
        $stmt->bindParam(':received_by', $received_by);
        $stmt->bindParam(':received_by_date', $received_by_date);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':course_id', $course_id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Level 3 saved']);
            exit();
        }

        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save Level 3']);
        exit();
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit();
}
?>
