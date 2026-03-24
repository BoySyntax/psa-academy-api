<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Get statistics
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header("Content-Type: application/json");
    
    try {
        // Get total students count
        $studentQuery = "SELECT COUNT(*) as total_students FROM users WHERE user_type = 'student'";
        $studentStmt = $db->prepare($studentQuery);
        $studentStmt->execute();
        $studentCount = $studentStmt->fetch(PDO::FETCH_ASSOC)['total_students'];
        
        // Get total teachers count
        $teacherQuery = "SELECT COUNT(*) as total_teachers FROM users WHERE user_type = 'teacher'";
        $teacherStmt = $db->prepare($teacherQuery);
        $teacherStmt->execute();
        $teacherCount = $teacherStmt->fetch(PDO::FETCH_ASSOC)['total_teachers'];
        
        // Get total courses count
        $courseQuery = "SELECT COUNT(*) as total_courses FROM courses";
        $courseStmt = $db->prepare($courseQuery);
        $courseStmt->execute();
        $courseCount = $courseStmt->fetch(PDO::FETCH_ASSOC)['total_courses'];
        
        // Get total enrollments count
        $enrollmentQuery = "SELECT COUNT(*) as total_enrollments FROM course_enrollments";
        $enrollmentStmt = $db->prepare($enrollmentQuery);
        $enrollmentStmt->execute();
        $enrollmentCount = $enrollmentStmt->fetch(PDO::FETCH_ASSOC)['total_enrollments'];
        
        echo json_encode([
            'success' => true,
            'statistics' => [
                'total_students' => (int)$studentCount,
                'total_teachers' => (int)$teacherCount,
                'total_courses' => (int)$courseCount,
                'total_enrollments' => (int)$enrollmentCount
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
}
?>

