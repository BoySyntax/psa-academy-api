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

// Get student's enrollments
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $student_id = isset($_GET['student_id']) ? $_GET['student_id'] : null;
        
        if (!$student_id) {
            http_response_code(400);
            echo json_encode(['message' => 'Student ID is required']);
            exit();
        }
        
        $query = "SELECT 
                     e.*,
                     c.id as course_id,
                     c.course_code,
                     c.course_name,
                     c.description,
                     c.category,
                     c.duration_hours,
                     c.thumbnail_url,
                     t.id as teacher_id,
                     t.first_name as teacher_first_name,
                     t.last_name as teacher_last_name,
                     t.email as teacher_email
                   FROM course_enrollments e
                   JOIN courses c ON e.course_id = c.id
                   LEFT JOIN (
                       SELECT ct.course_id, ct.teacher_id
                       FROM course_teachers ct
                       INNER JOIN (
                           SELECT course_id, MAX(assigned_at) as latest_assigned_at
                           FROM course_teachers
                           GROUP BY course_id
                       ) latest_ct
                           ON latest_ct.course_id = ct.course_id
                          AND latest_ct.latest_assigned_at = ct.assigned_at
                   ) assigned_teacher ON assigned_teacher.course_id = c.id
                   LEFT JOIN users t ON t.id = assigned_teacher.teacher_id
                   WHERE e.student_id = :student_id
                   ORDER BY e.enrollment_date DESC";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->execute();
        
        $enrollments = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $enrollments[] = [
                'id' => $row['id'],
                'course_id' => $row['course_id'],
                'student_id' => $row['student_id'],
                'enrollment_date' => $row['enrollment_date'],
                'completion_date' => $row['completion_date'],
                'status' => $row['status'],
                'progress_percentage' => floatval($row['progress_percentage']),
                'course' => [
                    'id' => $row['course_id'],
                    'course_code' => $row['course_code'],
                    'course_name' => $row['course_name'],
                    'description' => $row['description'],
                    'category' => $row['category'],
                    'duration_hours' => $row['duration_hours'],
                    'thumbnail_url' => $row['thumbnail_url'],
                    'teacher' => $row['teacher_id'] ? [
                        'id' => $row['teacher_id'],
                        'first_name' => $row['teacher_first_name'],
                        'last_name' => $row['teacher_last_name'],
                        'email' => $row['teacher_email'],
                    ] : null,
                ]
            ];
        }
        
        http_response_code(200);
        echo json_encode(['enrollments' => $enrollments]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Database error: ' . $e->getMessage()]);
    }
}
?>

