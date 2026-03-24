<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

// GET: check if evaluation already submitted
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header("Content-Type: application/json");

    $user_id = isset($_GET['user_id']) ? $_GET['user_id'] : null;
    $course_id = isset($_GET['course_id']) ? $_GET['course_id'] : null;

    if (!$user_id || !$course_id) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'user_id and course_id are required'
        ]);
        exit();
    }

    try {
        $query = "SELECT id FROM training_evaluations WHERE user_id = :user_id AND course_id = :course_id LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':course_id', $course_id);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'submitted' => !!$row,
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
    exit();
}

// POST: submit evaluation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header("Content-Type: application/json");

    $data = json_decode(file_get_contents("php://input"), true);

    $user_id = isset($data['user_id']) ? $data['user_id'] : null;
    $course_id = isset($data['course_id']) ? $data['course_id'] : null;
    $trainee_name = isset($data['trainee_name']) ? $data['trainee_name'] : null;
    $office_service_division = isset($data['office_service_division']) ? $data['office_service_division'] : null;
    $training_program = isset($data['training_program']) ? $data['training_program'] : null;
    $topic = isset($data['topic']) ? $data['topic'] : null;
    $date_of_conduct = isset($data['date_of_conduct']) ? $data['date_of_conduct'] : null;
    $venue = isset($data['venue']) ? $data['venue'] : null;
    $training_objectives = isset($data['training_objectives']) ? $data['training_objectives'] : null;

    if (!$user_id || !$course_id || !$training_program || !$date_of_conduct || !$venue) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing required fields'
        ]);
        exit();
    }

    try {
        $check = $db->prepare("SELECT id FROM training_evaluations WHERE user_id = :user_id AND course_id = :course_id LIMIT 1");
        $check->bindParam(':user_id', $user_id);
        $check->bindParam(':course_id', $course_id);
        $check->execute();
        if ($check->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Already submitted'
            ]);
            exit();
        }

        $query = "INSERT INTO training_evaluations (
                    user_id,
                    course_id,
                    trainee_name,
                    office_service_division,
                    training_program,
                    topic,
                    date_of_conduct,
                    venue,
                    training_objectives,
                    ratings_json,
                    yesno_json,
                    comments_1,
                    comments_2,
                    comments_3,
                    level2_q1,
                    level2_q2,
                    level3_q1,
                    level3_q2,
                    level3_q3,
                    evaluated_by,
                    evaluated_by_date,
                    received_by,
                    received_by_date,
                    created_at
                  ) VALUES (
                    :user_id,
                    :course_id,
                    :trainee_name,
                    :office_service_division,
                    :training_program,
                    :topic,
                    :date_of_conduct,
                    :venue,
                    :training_objectives,
                    :ratings_json,
                    :yesno_json,
                    :comments_1,
                    :comments_2,
                    :comments_3,
                    :level2_q1,
                    :level2_q2,
                    :level3_q1,
                    :level3_q2,
                    :level3_q3,
                    :evaluated_by,
                    :evaluated_by_date,
                    :received_by,
                    :received_by_date,
                    NOW()
                  )";

        $stmt = $db->prepare($query);
        $ratings_json = json_encode(isset($data['ratings']) ? $data['ratings'] : []);
        $yesno_json = json_encode(isset($data['yesno']) ? $data['yesno'] : []);
        $comments_1 = isset($data['comments_1']) ? $data['comments_1'] : null;
        $comments_2 = isset($data['comments_2']) ? $data['comments_2'] : null;
        $comments_3 = isset($data['comments_3']) ? $data['comments_3'] : null;
        $level2_q1 = isset($data['level2_q1']) ? $data['level2_q1'] : null;
        $level2_q2 = isset($data['level2_q2']) ? $data['level2_q2'] : null;
        $level3_q1 = isset($data['level3_q1']) ? $data['level3_q1'] : null;
        $level3_q2 = isset($data['level3_q2']) ? $data['level3_q2'] : null;
        $level3_q3 = isset($data['level3_q3']) ? $data['level3_q3'] : null;
        $evaluated_by = isset($data['evaluated_by']) ? $data['evaluated_by'] : null;
        $evaluated_by_date = isset($data['evaluated_by_date']) ? $data['evaluated_by_date'] : null;
        $received_by = isset($data['received_by']) ? $data['received_by'] : null;
        $received_by_date = isset($data['received_by_date']) ? $data['received_by_date'] : null;

        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':course_id', $course_id);
        $stmt->bindParam(':trainee_name', $trainee_name);
        $stmt->bindParam(':office_service_division', $office_service_division);
        $stmt->bindParam(':training_program', $training_program);
        $stmt->bindParam(':topic', $topic);
        $stmt->bindParam(':date_of_conduct', $date_of_conduct);
        $stmt->bindParam(':venue', $venue);
        $stmt->bindParam(':training_objectives', $training_objectives);
        $stmt->bindParam(':ratings_json', $ratings_json);
        $stmt->bindParam(':yesno_json', $yesno_json);
        $stmt->bindParam(':comments_1', $comments_1);
        $stmt->bindParam(':comments_2', $comments_2);
        $stmt->bindParam(':comments_3', $comments_3);
        $stmt->bindParam(':level2_q1', $level2_q1);
        $stmt->bindParam(':level2_q2', $level2_q2);
        $stmt->bindParam(':level3_q1', $level3_q1);
        $stmt->bindParam(':level3_q2', $level3_q2);
        $stmt->bindParam(':level3_q3', $level3_q3);
        $stmt->bindParam(':evaluated_by', $evaluated_by);
        $stmt->bindParam(':evaluated_by_date', $evaluated_by_date);
        $stmt->bindParam(':received_by', $received_by);
        $stmt->bindParam(':received_by_date', $received_by_date);

        if ($stmt->execute()) {
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Evaluation submitted'
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to submit evaluation'
            ]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
    exit();
}

http_response_code(405);
echo json_encode([
    'success' => false,
    'message' => 'Method not allowed'
]);
?>

