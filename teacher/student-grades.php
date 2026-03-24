<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

// Get teacher ID from query parameter
$teacherId = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;

if ($teacherId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Valid teacher ID required']);
    exit();
}

try {
    if (!$db) {
        throw new Exception('Database connection failed');
    }

    $query = "
        SELECT
            ce.id AS enrollment_id,
            ce.student_id,
            CONCAT(u.first_name, ' ', u.last_name) AS student_name,
            c.id AS course_id,
            c.course_name,
            c.course_code,
            ce.status AS enrollment_status,
            ce.completion_date,
            COALESCE(pre.best_score, 0) AS pre_test_score,
            COALESCE(post.best_score, 0) AS post_test_score
        FROM course_enrollments ce
        JOIN users u ON u.id = ce.student_id
        JOIN courses c ON c.id = ce.course_id
        JOIN course_teachers ct ON ct.course_id = c.id

        LEFT JOIN (
            SELECT
                ce2.id AS enrollment_id,
                MAX(sta.score) AS best_score
            FROM course_enrollments ce2
            JOIN courses c2 ON c2.id = ce2.course_id
            JOIN course_modules cm2 ON cm2.course_id = c2.id
            JOIN module_tests mt2 ON mt2.module_id = cm2.id AND mt2.test_type = 'pre_test'
            JOIN student_test_attempts sta ON sta.test_id = mt2.id
                AND sta.student_id = ce2.student_id
                AND sta.completed_at IS NOT NULL
            GROUP BY ce2.id
        ) pre ON pre.enrollment_id = ce.id

        LEFT JOIN (
            SELECT
                ce3.id AS enrollment_id,
                MAX(sta.score) AS best_score
            FROM course_enrollments ce3
            JOIN courses c3 ON c3.id = ce3.course_id
            JOIN course_modules cm3 ON cm3.course_id = c3.id
            JOIN module_tests mt3 ON mt3.module_id = cm3.id AND mt3.test_type = 'post_test'
            JOIN student_test_attempts sta ON sta.test_id = mt3.id
                AND sta.student_id = ce3.student_id
                AND sta.completed_at IS NOT NULL
            GROUP BY ce3.id
        ) post ON post.enrollment_id = ce.id

        WHERE ct.teacher_id = :teacher_id
          AND u.user_type = 'student'
          AND ce.status IN ('enrolled', 'in_progress', 'completed')
        ORDER BY ce.completion_date DESC, ce.enrollment_date DESC, c.course_name, u.last_name, u.first_name
        LIMIT 200
    ";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':teacher_id', $teacherId, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grades = [];
    foreach ($rows as $row) {
        $preScore = (int)($row['pre_test_score'] ?? 0);
        $postScore = (int)($row['post_test_score'] ?? 0);
        $improvement = ($preScore > 0 && $postScore > 0) ? ($postScore - $preScore) : 0;

        $completionDate = $row['completion_date'] ? date('Y-m-d', strtotime($row['completion_date'])) : '';

        $grades[] = [
            'id' => (int)$row['enrollment_id'],
            'studentId' => (int)$row['student_id'],
            'studentName' => $row['student_name'],
            'courseName' => $row['course_name'],
            'courseCode' => $row['course_code'],
            'preTestScore' => $preScore,
            'postTestScore' => $postScore,
            'improvement' => $improvement,
            'completedAt' => $completionDate,
            'status' => $row['enrollment_status']
        ];
    }

    echo json_encode([
        'success' => true,
        'grades' => $grades,
        'message' => 'Student grades retrieved successfully'
    ]);

} catch (Exception $e) {
    error_log("Error fetching student grades: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error: ' . $e->getMessage()
    ]);
}
?>

