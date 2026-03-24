<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
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

if ($method === 'GET') {
    try {
        $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        $course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0;
        $teacher_id = isset($_GET['teacher_id']) ? intval($_GET['teacher_id']) : 0;

        if (!$user_id || !$course_id || !$teacher_id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'user_id, course_id, and teacher_id are required']);
            exit();
        }

        $query = "SELECT id, rating, comment, created_at, updated_at
                  FROM teacher_ratings
                  WHERE user_id = :user_id AND course_id = :course_id AND teacher_id = :teacher_id
                  LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
        $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
        $stmt->execute();

        $rating = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'submitted' => !!$rating,
            'rating' => $rating ? [
                'id' => intval($rating['id']),
                'rating' => intval($rating['rating']),
                'comment' => $rating['comment'],
                'created_at' => $rating['created_at'],
                'updated_at' => $rating['updated_at'],
            ] : null,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

if ($method === 'POST') {
    try {
        $data = json_decode(file_get_contents("php://input"), true);

        $user_id = isset($data['user_id']) ? intval($data['user_id']) : 0;
        $course_id = isset($data['course_id']) ? intval($data['course_id']) : 0;
        $teacher_id = isset($data['teacher_id']) ? intval($data['teacher_id']) : 0;
        $rating = isset($data['rating']) ? intval($data['rating']) : 0;
        $comment = isset($data['comment']) ? trim($data['comment']) : null;

        if (!$user_id || !$course_id || !$teacher_id || $rating < 1 || $rating > 5) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Valid user_id, course_id, teacher_id, and rating are required']);
            exit();
        }

        $enrollmentQuery = "SELECT id FROM course_enrollments
                            WHERE student_id = :user_id AND course_id = :course_id AND status = 'completed'
                            LIMIT 1";
        $enrollmentStmt = $db->prepare($enrollmentQuery);
        $enrollmentStmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $enrollmentStmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
        $enrollmentStmt->execute();

        if (!$enrollmentStmt->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only completed courses can be rated']);
            exit();
        }

        $teacherAssignmentQuery = "SELECT id FROM course_teachers WHERE course_id = :course_id AND teacher_id = :teacher_id LIMIT 1";
        $teacherAssignmentStmt = $db->prepare($teacherAssignmentQuery);
        $teacherAssignmentStmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
        $teacherAssignmentStmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
        $teacherAssignmentStmt->execute();

        if (!$teacherAssignmentStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Teacher is not assigned to this course']);
            exit();
        }

        // Enforce rate-once: reject if a rating already exists for this user/course/teacher
        $existingQuery = "SELECT id FROM teacher_ratings WHERE user_id = :user_id AND course_id = :course_id AND teacher_id = :teacher_id LIMIT 1";
        $existingStmt = $db->prepare($existingQuery);
        $existingStmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $existingStmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
        $existingStmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
        $existingStmt->execute();

        if ($existingStmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Teacher rating already submitted']);
            exit();
        }

        $query = "INSERT INTO teacher_ratings (user_id, course_id, teacher_id, rating, comment)
                  VALUES (:user_id, :course_id, :teacher_id, :rating, :comment)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':course_id', $course_id, PDO::PARAM_INT);
        $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
        $stmt->bindParam(':rating', $rating, PDO::PARAM_INT);
        $stmt->bindParam(':comment', $comment);
        $stmt->execute();

        echo json_encode([
            'success' => true,
            'message' => 'Teacher rating saved successfully',
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);

