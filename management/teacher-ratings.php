<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    $query = "SELECT 
                tr.id,
                tr.user_id,
                tr.course_id,
                tr.teacher_id,
                tr.rating,
                tr.comment,
                tr.created_at,
                tr.updated_at,
                c.course_code,
                c.course_name,
                c.category,
                stu.first_name AS student_first_name,
                stu.last_name AS student_last_name,
                stu.middle_name AS student_middle_name,
                stu.email AS student_email,
                tea.first_name AS teacher_first_name,
                tea.last_name AS teacher_last_name,
                tea.email AS teacher_email,
                tea.profile_image_url AS teacher_profile_image_url,
                tea.present_position AS teacher_present_position,
                tea.office AS teacher_office,
                tea.division_province AS teacher_division_province,
                first_assignment.first_assigned_at AS teacher_first_assigned_at
              FROM teacher_ratings tr
              INNER JOIN courses c ON c.id = tr.course_id
              INNER JOIN users stu ON stu.id = tr.user_id
              INNER JOIN users tea ON tea.id = tr.teacher_id
              LEFT JOIN (
                SELECT teacher_id, MIN(assigned_at) AS first_assigned_at
                FROM course_teachers
                GROUP BY teacher_id
              ) first_assignment ON first_assignment.teacher_id = tr.teacher_id
              ORDER BY tr.updated_at DESC";

    $stmt = $db->prepare($query);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ratings = [];
    $teacherSummaries = [];

    foreach ($rows as $row) {
        $ratings[] = [
            'id' => intval($row['id']),
            'user_id' => intval($row['user_id']),
            'course_id' => intval($row['course_id']),
            'teacher_id' => intval($row['teacher_id']),
            'rating' => intval($row['rating']),
            'comment' => $row['comment'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'course' => [
                'course_code' => $row['course_code'],
                'course_name' => $row['course_name'],
                'category' => $row['category'],
            ],
            'student' => [
                'first_name' => $row['student_first_name'],
                'middle_name' => $row['student_middle_name'],
                'last_name' => $row['student_last_name'],
                'email' => $row['student_email'],
            ],
            'teacher' => [
                'first_name' => $row['teacher_first_name'],
                'last_name' => $row['teacher_last_name'],
                'email' => $row['teacher_email'],
                'profile_image_url' => $row['teacher_profile_image_url'],
                'present_position' => $row['teacher_present_position'],
                'office' => $row['teacher_office'],
                'division_province' => $row['teacher_division_province'],
                'trainer_since' => $row['teacher_first_assigned_at'] ? intval(date('Y', strtotime($row['teacher_first_assigned_at']))) : null,
            ],
        ];

        $summaryKey = $row['teacher_id'] . '::' . $row['course_id'];
        if (!isset($teacherSummaries[$summaryKey])) {
            $teacherSummaries[$summaryKey] = [
                'teacher_id' => intval($row['teacher_id']),
                'course_id' => intval($row['course_id']),
                'teacher' => [
                    'first_name' => $row['teacher_first_name'],
                    'last_name' => $row['teacher_last_name'],
                    'email' => $row['teacher_email'],
                    'profile_image_url' => $row['teacher_profile_image_url'],
                    'present_position' => $row['teacher_present_position'],
                    'office' => $row['teacher_office'],
                    'division_province' => $row['teacher_division_province'],
                    'trainer_since' => $row['teacher_first_assigned_at'] ? intval(date('Y', strtotime($row['teacher_first_assigned_at']))) : null,
                ],
                'course' => [
                    'course_code' => $row['course_code'],
                    'course_name' => $row['course_name'],
                    'category' => $row['category'],
                ],
                'average_rating' => 0,
                'rating_count' => 0,
                'rating_total' => 0,
            ];
        }

        $teacherSummaries[$summaryKey]['rating_count'] += 1;
        $teacherSummaries[$summaryKey]['rating_total'] += intval($row['rating']);
    }

    foreach ($teacherSummaries as &$summary) {
        $summary['average_rating'] = $summary['rating_count'] > 0
            ? round($summary['rating_total'] / $summary['rating_count'], 2)
            : 0;
        unset($summary['rating_total']);
    }
    unset($summary);

    echo json_encode([
        'success' => true,
        'ratings' => $ratings,
        'summaries' => array_values($teacherSummaries),
        'count' => count($ratings),
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
