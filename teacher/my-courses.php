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
require_once '../config/url_helper.php';

$database = new Database();
$db = $database->getConnection();

// Get teacher's assigned courses
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $teacher_id = isset($_GET['teacher_id']) ? $_GET['teacher_id'] : null;
        
        if (!$teacher_id) {
            http_response_code(400);
            echo json_encode(['message' => 'Teacher ID is required']);
            exit();
        }
        
        $query = "SELECT 
                    c.*,
                    COUNT(DISTINCT ce.id) as enrolled_count,
                    COUNT(DISTINCT CASE WHEN ce.status = 'completed' THEN ce.id END) as completed_count
                  FROM courses c
                  INNER JOIN course_teachers ct ON c.id = ct.course_id
                  LEFT JOIN course_enrollments ce ON c.id = ce.course_id
                  WHERE ct.teacher_id = :teacher_id
                  GROUP BY c.id
                  ORDER BY c.created_at DESC";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':teacher_id', $teacher_id);
        $stmt->execute();
        
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($courses as &$course) {
            if (!empty($course['thumbnail_url'])) {
                $course['thumbnail_url'] = normalize_public_file_url($course['thumbnail_url']);
            }
        }
        
        http_response_code(200);
        echo json_encode([
            'courses' => $courses
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Error: ' . $e->getMessage()]);
    }
}
?>

