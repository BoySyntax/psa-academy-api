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

// Enroll student in course
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        
        $student_id = isset($data['student_id']) ? $data['student_id'] : null;
        $course_id = isset($data['course_id']) ? $data['course_id'] : null;
        
        if (!$student_id || !$course_id) {
            http_response_code(400);
            echo json_encode(['message' => 'Student ID and Course ID are required']);
            exit();
        }
        
        // Check if already enrolled
        $checkQuery = "SELECT id FROM course_enrollments 
                       WHERE student_id = :student_id AND course_id = :course_id";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->bindParam(':student_id', $student_id);
        $checkStmt->bindParam(':course_id', $course_id);
        $checkStmt->execute();
        
        if ($checkStmt->fetch()) {
            http_response_code(409);
            echo json_encode(['message' => 'Already enrolled in this course']);
            exit();
        }
        
        // Check course capacity
        $capacityQuery = "SELECT c.max_students, COUNT(e.id) as enrolled_count
                          FROM courses c
                          LEFT JOIN course_enrollments e ON c.id = e.course_id
                          WHERE c.id = :course_id
                          GROUP BY c.id";
        $capacityStmt = $db->prepare($capacityQuery);
        $capacityStmt->bindParam(':course_id', $course_id);
        $capacityStmt->execute();
        $capacity = $capacityStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($capacity && $capacity['max_students'] && $capacity['enrolled_count'] >= $capacity['max_students']) {
            http_response_code(400);
            echo json_encode(['message' => 'Course is full']);
            exit();
        }
        
        // Submit enrollment application (pending approval)
        $insertQuery = "INSERT INTO course_enrollments (course_id, student_id, status, progress_percentage)
                        VALUES (:course_id, :student_id, 'pending', 0.00)";
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->bindParam(':course_id', $course_id);
        $insertStmt->bindParam(':student_id', $student_id);
        
        if ($insertStmt->execute()) {
            http_response_code(201);
            echo json_encode(['message' => 'Enrollment application submitted successfully. Awaiting management approval.']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Failed to submit enrollment application']);
        }
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Database error: ' . $e->getMessage()]);
    }
}
?>

