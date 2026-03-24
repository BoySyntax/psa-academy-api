<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$database = new Database();
$db = $database->getConnection();

switch ($method) {
    case 'GET':
        handleGet($db);
        break;
    case 'POST':
        handlePost($db);
        break;
    case 'DELETE':
        handleDelete($db);
        break;
    default:
        http_response_code(405);
        echo json_encode(['message' => 'Method not allowed']);
        break;
}

function handleGet($db) {
    try {
        if (isset($_GET['course_id'])) {
            $courseId = $_GET['course_id'];
            $query = "SELECT u.id, u.first_name, u.last_name, u.email, ct.assigned_at
                      FROM users u
                      INNER JOIN course_teachers ct ON u.id = ct.teacher_id
                      WHERE ct.course_id = :course_id
                      ORDER BY ct.assigned_at DESC";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':course_id', $courseId);
            $stmt->execute();
            
            $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            http_response_code(200);
            echo json_encode(['teachers' => $teachers]);
        } else {
            http_response_code(400);
            echo json_encode(['message' => 'Course ID is required']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Database error: ' . $e->getMessage()]);
    }
}

function handlePost($db) {
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['course_id']) || !isset($data['teacher_id'])) {
            http_response_code(400);
            echo json_encode(['message' => 'Course ID and Teacher ID are required']);
            return;
        }
        
        // Check if teacher is already assigned
        $checkQuery = "SELECT id FROM course_teachers WHERE course_id = :course_id AND teacher_id = :teacher_id";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':course_id', $data['course_id']);
        $checkStmt->bindParam(':teacher_id', $data['teacher_id']);
        $checkStmt->execute();
        
        if ($checkStmt->fetch()) {
            http_response_code(409);
            echo json_encode(['message' => 'Teacher is already assigned to this course']);
            return;
        }
        
        // Verify teacher exists and has teacher role
        $teacherQuery = "SELECT id FROM users WHERE id = :teacher_id AND user_type = 'teacher'";
        $teacherStmt = $db->prepare($teacherQuery);
        $teacherStmt->bindParam(':teacher_id', $data['teacher_id']);
        $teacherStmt->execute();
        
        if (!$teacherStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['message' => 'Teacher not found or user is not a teacher']);
            return;
        }
        
        $query = "INSERT INTO course_teachers (course_id, teacher_id) VALUES (:course_id, :teacher_id)";
        $stmt = $db->prepare($query);
        
        $stmt->bindParam(':course_id', $data['course_id']);
        $stmt->bindParam(':teacher_id', $data['teacher_id']);
        
        if ($stmt->execute()) {
            http_response_code(201);
            echo json_encode(['message' => 'Teacher assigned successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to assign teacher']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleDelete($db) {
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['course_id']) || !isset($data['teacher_id'])) {
            http_response_code(400);
            echo json_encode(['message' => 'Course ID and Teacher ID are required']);
            return;
        }
        
        $query = "DELETE FROM course_teachers WHERE course_id = :course_id AND teacher_id = :teacher_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':course_id', $data['course_id']);
        $stmt->bindParam(':teacher_id', $data['teacher_id']);
        
        if ($stmt->execute()) {
            if ($stmt->rowCount() > 0) {
                http_response_code(200);
                echo json_encode(['message' => 'Teacher removed successfully']);
            } else {
                http_response_code(404);
                echo json_encode(['message' => 'Assignment not found']);
            }
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to remove teacher']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Database error: ' . $e->getMessage()]);
    }
}

?>

