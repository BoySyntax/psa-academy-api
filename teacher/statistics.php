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

// Get teacher statistics
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header("Content-Type: application/json");
    
    // Get teacher_id from query parameter (you might pass this from frontend)
    $teacher_id = isset($_GET['teacher_id']) ? $_GET['teacher_id'] : null;
    
    try {
        // If teacher_id is provided, get teacher-specific stats
        if ($teacher_id) {
            // Get courses assigned to this teacher
            $courseQuery = "SELECT COUNT(*) as my_courses FROM course_teachers WHERE teacher_id = :teacher_id";
            $courseStmt = $db->prepare($courseQuery);
            $courseStmt->bindParam(':teacher_id', $teacher_id);
            $courseStmt->execute();
            $myCourses = $courseStmt->fetch(PDO::FETCH_ASSOC)['my_courses'];
            
            // Get total students enrolled in teacher's courses
            $studentQuery = "SELECT COUNT(DISTINCT ce.student_id) as total_students 
                           FROM course_enrollments ce 
                           JOIN course_teachers ct ON ce.course_id = ct.course_id 
                           WHERE ct.teacher_id = :teacher_id";
            $studentStmt = $db->prepare($studentQuery);
            $studentStmt->bindParam(':teacher_id', $teacher_id);
            $studentStmt->execute();
            $totalStudents = $studentStmt->fetch(PDO::FETCH_ASSOC)['total_students'];
            
            // Get pending assignments (lessons that need materials uploaded)
            $assignmentQuery = "SELECT COUNT(*) as pending_assignments 
                               FROM course_lessons cl 
                               JOIN course_modules cm ON cl.module_id = cm.id 
                               JOIN course_teachers ct ON cm.course_id = ct.course_id 
                               WHERE ct.teacher_id = :teacher_id 
                               AND cl.lesson_type IN ('assignment', 'quiz') 
                               AND NOT EXISTS (
                                   SELECT 1 FROM learning_materials lm 
                                   WHERE lm.lesson_id = cl.id
                               )";
            $assignmentStmt = $db->prepare($assignmentQuery);
            $assignmentStmt->bindParam(':teacher_id', $teacher_id);
            $assignmentStmt->execute();
            $pendingAssignments = $assignmentStmt->fetch(PDO::FETCH_ASSOC)['pending_assignments'];
        } else {
            // Default values if no teacher_id provided
            $myCourses = 0;
            $totalStudents = 0;
            $pendingAssignments = 0;
        }
        
        echo json_encode([
            'success' => true,
            'statistics' => [
                'my_courses' => (int)$myCourses,
                'total_students' => (int)$totalStudents,
                'pending_assignments' => (int)$pendingAssignments
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

