<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get filter parameter (default to 'pending')
    $status = isset($_GET['status']) ? $_GET['status'] : 'pending';
    
    // Fetch enrollments with student and course details
    $sql = "SELECT 
                ce.id as enrollment_id,
                ce.course_id,
                ce.student_id,
                ce.enrollment_date,
                ce.status,
                ce.approved_by,
                ce.approved_at,
                ce.rejection_reason,
                c.course_code,
                c.course_name,
                c.category,
                c.subcategory,
                c.duration_hours,
                u.id as student_uuid,
                u.first_name,
                u.last_name,
                u.middle_name,
                u.email,
                u.cellphone_number,
                u.profile_image_url,
                approver.first_name as approver_first_name,
                approver.last_name as approver_last_name
            FROM course_enrollments ce
            INNER JOIN courses c ON ce.course_id = c.id
            INNER JOIN users u ON ce.student_id = u.id
            LEFT JOIN users approver ON ce.approved_by = approver.id
            WHERE ce.status = ?
            ORDER BY ce.enrollment_date DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$status]);
    
    $enrollments = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $enrollments[] = [
            'enrollment_id' => (int)$row['enrollment_id'],
            'course_id' => (int)$row['course_id'],
            'student_id' => (int)$row['student_id'],
            'student_uuid' => $row['student_uuid'],
            'enrollment_date' => $row['enrollment_date'],
            'status' => $row['status'],
            'approved_by' => $row['approved_by'],
            'approved_at' => $row['approved_at'],
            'rejection_reason' => $row['rejection_reason'],
            'course' => [
                'course_code' => $row['course_code'],
                'course_name' => $row['course_name'],
                'category' => $row['category'],
                'subcategory' => $row['subcategory'],
                'duration_hours' => (int)$row['duration_hours']
            ],
            'student' => [
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'middle_name' => $row['middle_name'],
                'email' => $row['email'],
                'cellphone_number' => $row['cellphone_number'],
                'profile_image_url' => $row['profile_image_url']
            ],
            'approver' => $row['approver_first_name'] ? [
                'first_name' => $row['approver_first_name'],
                'last_name' => $row['approver_last_name']
            ] : null
        ];
    }
    
    $stmt->closeCursor();
    
    echo json_encode([
        'success' => true,
        'enrollments' => $enrollments,
        'count' => count($enrollments)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch enrollments: ' . $e->getMessage()
    ]);
}
