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

// Get available courses (not enrolled by student)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $student_id = isset($_GET['student_id']) ? $_GET['student_id'] : null;
        
        if (!$student_id) {
            http_response_code(400);
            echo json_encode(['message' => 'Student ID is required']);
            exit();
        }
        
        $query = "SELECT c.*
                  FROM courses c
                  WHERE c.status = 'published'
                  AND c.id NOT IN (
                      SELECT course_id 
                      FROM course_enrollments 
                      WHERE student_id = :student_id
                  )
                  ORDER BY c.created_at DESC";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->execute();
        
        $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($courses as &$course) {
            if (!empty($course['thumbnail_url'])) {
                $course['thumbnail_url'] = normalize_public_file_url($course['thumbnail_url']);
            }
        }
        
        http_response_code(200);
        echo json_encode(['courses' => $courses]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Database error: ' . $e->getMessage()]);
    }
}
?>

