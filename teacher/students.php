<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Get students enrolled in teacher's courses
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header("Content-Type: application/json");
    
    $teacher_id = isset($_GET['teacher_id']) ? $_GET['teacher_id'] : null;
    
    if (!$teacher_id) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Teacher ID is required'
        ]);
        exit();
    }
    
    try {
        // Get all students enrolled in courses taught by this teacher
        $query = "SELECT 
                    u.id,
                    u.first_name,
                    u.last_name,
                    u.email,
                    c.course_name,
                    c.course_code,
                    ce.enrollment_date,
                    ce.status,
                    ce.progress_percentage
                  FROM users u
                  INNER JOIN course_enrollments ce ON u.id = ce.student_id
                  INNER JOIN courses c ON ce.course_id = c.id
                  INNER JOIN course_teachers ct ON c.id = ct.course_id
                  WHERE ct.teacher_id = :teacher_id
                  AND u.user_type = 'student'
                  ORDER BY ce.enrollment_date DESC, u.last_name ASC, u.first_name ASC";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':teacher_id', $teacher_id);
        $stmt->execute();
        
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'students' => $students,
            'count' => count($students)
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
}
?>

