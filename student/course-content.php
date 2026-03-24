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

// Get course content with modules and lessons
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $course_id = isset($_GET['course_id']) ? $_GET['course_id'] : null;
        $student_id = isset($_GET['student_id']) ? $_GET['student_id'] : null;
        
        if (!$course_id || !$student_id) {
            http_response_code(400);
            echo json_encode(['message' => 'Course ID and Student ID are required']);
            exit();
        }
        
        // Get course info
        $courseQuery = "SELECT * FROM courses WHERE id = :course_id";
        $courseStmt = $db->prepare($courseQuery);
        $courseStmt->bindParam(':course_id', $course_id);
        $courseStmt->execute();
        $course = $courseStmt->fetch(PDO::FETCH_ASSOC);

        if ($course && !empty($course['thumbnail_url'])) {
            $course['thumbnail_url'] = normalize_public_file_url($course['thumbnail_url']);
        }
        
        if (!$course) {
            http_response_code(404);
            echo json_encode(['message' => 'Course not found']);
            exit();
        }

        // Get assigned teacher (trainor) name (if any)
        $teacherQuery = "SELECT ct.teacher_id, u.first_name, u.last_name
                        FROM course_teachers ct
                        INNER JOIN users u ON u.id = ct.teacher_id
                        WHERE ct.course_id = :course_id
                        ORDER BY ct.assigned_at DESC
                        LIMIT 1";
        $teacherStmt = $db->prepare($teacherQuery);
        $teacherStmt->bindParam(':course_id', $course_id);
        $teacherStmt->execute();
        $teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);

        if ($teacher && isset($teacher['first_name']) && isset($teacher['last_name'])) {
            $course['assigned_teacher_id'] = $teacher['teacher_id'];
            $course['assigned_teacher_name'] = trim($teacher['first_name'] . ' ' . $teacher['last_name']);
        } else {
            // Fallback: if no explicit assignment exists, use the course creator if they are a teacher
            $fallbackTeacherQuery = "SELECT u.id as teacher_id, u.first_name, u.last_name
                                    FROM users u
                                    WHERE u.id = :created_by AND u.user_type = 'teacher'
                                    LIMIT 1";
            $fallbackTeacherStmt = $db->prepare($fallbackTeacherQuery);
            $fallbackTeacherStmt->bindParam(':created_by', $course['created_by']);
            $fallbackTeacherStmt->execute();
            $fallbackTeacher = $fallbackTeacherStmt->fetch(PDO::FETCH_ASSOC);

            if ($fallbackTeacher && isset($fallbackTeacher['first_name']) && isset($fallbackTeacher['last_name'])) {
                $course['assigned_teacher_id'] = $fallbackTeacher['teacher_id'];
                $course['assigned_teacher_name'] = trim($fallbackTeacher['first_name'] . ' ' . $fallbackTeacher['last_name']);
            } else {
                $course['assigned_teacher_id'] = null;
                $course['assigned_teacher_name'] = null;
            }
        }
        
        // Get modules
        $modulesQuery = "SELECT * FROM course_modules 
                         WHERE course_id = :course_id 
                         ORDER BY order_index ASC";
        $modulesStmt = $db->prepare($modulesQuery);
        $modulesStmt->bindParam(':course_id', $course_id);
        $modulesStmt->execute();
        
        $modules = [];
        while ($module = $modulesStmt->fetch(PDO::FETCH_ASSOC)) {
            // Get lessons for this module
            $lessonsQuery = "SELECT l.*,
                             p.status as progress_status,
                             p.progress_percentage as progress_percent,
                             p.time_spent_minutes,
                             p.started_at,
                             p.completed_at,
                             p.last_accessed
                             FROM course_lessons l
                             LEFT JOIN lesson_progress p ON l.id = p.lesson_id AND p.student_id = :student_id
                             WHERE l.module_id = :module_id
                             ORDER BY l.order_index ASC";
            $lessonsStmt = $db->prepare($lessonsQuery);
            $lessonsStmt->bindParam(':module_id', $module['id']);
            $lessonsStmt->bindParam(':student_id', $student_id);
            $lessonsStmt->execute();
            
            $lessons = [];
            while ($lesson = $lessonsStmt->fetch(PDO::FETCH_ASSOC)) {
                $lessonData = [
                    'id' => $lesson['id'],
                    'module_id' => $lesson['module_id'],
                    'lesson_title' => $lesson['lesson_title'],
                    'lesson_content' => $lesson['lesson_content'],
                    'lesson_type' => $lesson['lesson_type'],
                    'duration_minutes' => $lesson['duration_minutes'],
                    'order_index' => $lesson['order_index'],
                    'is_published' => (bool)$lesson['is_published']
                ];
                
                if ($lesson['progress_status']) {
                    $lessonData['progress'] = [
                        'status' => $lesson['progress_status'],
                        'progress_percentage' => floatval($lesson['progress_percent']),
                        'time_spent_minutes' => $lesson['time_spent_minutes'],
                        'started_at' => $lesson['started_at'],
                        'completed_at' => $lesson['completed_at'],
                        'last_accessed' => $lesson['last_accessed']
                    ];
                }
                
                $lessons[] = $lessonData;
            }
            
            $modules[] = [
                'id' => $module['id'],
                'course_id' => $module['course_id'],
                'module_name' => $module['module_name'],
                'description' => $module['description'],
                'order_index' => $module['order_index'],
                'lessons' => $lessons
            ];
        }
        
        http_response_code(200);
        echo json_encode([
            'course' => $course,
            'modules' => $modules
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['message' => 'Database error: ' . $e->getMessage()]);
    }
}
?>

