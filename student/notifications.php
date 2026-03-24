<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$studentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$onlyUnread = isset($_GET['only_unread']) ? (int)$_GET['only_unread'] : 0;

if ($studentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Student ID is required']);
    exit();
}

try {
    if (!$db) {
        throw new Exception('Database connection failed');
    }

    // Set the connection character set to avoid collation issues
    $db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    // Check if student_seen column exists in idp table
    $hasStudentSeenColumn = false;
    try {
        $stmt = $db->prepare("SELECT student_seen FROM idp LIMIT 1");
        $stmt->execute();
        $hasStudentSeenColumn = true;
    } catch (PDOException $e) {
        // Column doesn't exist
        $hasStudentSeenColumn = false;
    }

    // Get course notifications separately to avoid UNION collation issues
    $courseQuery = "SELECT 
        e.id AS enrollment_id, 
        e.course_id, 
        e.status, 
        e.approved_at, 
        e.student_seen, 
        e.management_message, 
        e.rejection_reason, 
        c.course_name, 
        c.course_code
        FROM course_enrollments e 
        JOIN courses c ON e.course_id = c.id 
        WHERE e.student_id = :student_id 
        AND e.status IN ('enrolled', 'rejected') 
        AND (e.management_message IS NOT NULL AND e.management_message != '')
        ORDER BY e.approved_at DESC";
    
    $stmt = $db->prepare($courseQuery);
    $stmt->bindParam(':student_id', $studentId, PDO::PARAM_INT);
    $stmt->execute();
    $courseNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get IDP notifications separately - handle both cases
    if ($hasStudentSeenColumn) {
        $idpQuery = "SELECT 
            NULL AS enrollment_id, 
            NULL AS course_id, 
            CASE WHEN i.status = 'approved' THEN 'idp_approved' ELSE 'idp_rejected' END AS status, 
            i.approved_at, 
            i.student_seen,  -- Use the column if it exists
            i.management_message, 
            i.rejection_reason, 
            NULL AS course_name, 
            NULL AS course_code
            FROM idp i 
            WHERE i.user_id = :student_id 
            AND i.status IN ('approved', 'rejected') 
            AND (i.management_message IS NOT NULL AND i.management_message != '')
            ORDER BY i.approved_at DESC";
    } else {
        // Fallback without student_seen column
        $idpQuery = "SELECT 
            NULL AS enrollment_id, 
            NULL AS course_id, 
            CASE WHEN i.status = 'approved' THEN 'idp_approved' ELSE 'idp_rejected' END AS status, 
            i.approved_at, 
            0 AS student_seen,  -- Always unread until column is added
            i.management_message, 
            i.rejection_reason, 
            NULL AS course_name, 
            NULL AS course_code
            FROM idp i 
            WHERE i.user_id = :student_id 
            AND i.status IN ('approved', 'rejected') 
            AND (i.management_message IS NOT NULL AND i.management_message != '')
            ORDER BY i.approved_at DESC";
    }
    
    $stmt = $db->prepare($idpQuery);
    $stmt->bindParam(':student_id', $studentId, PDO::PARAM_INT);
    $stmt->execute();
    $idpNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Combine and sort notifications in PHP instead of SQL UNION
    $allNotifications = [];
    
    // Process course notifications
    foreach ($courseNotifications as $notif) {
        $allNotifications[] = $notif;
    }
    
    // Process IDP notifications
    foreach ($idpNotifications as $notif) {
        $allNotifications[] = $notif;
    }
    
    // Sort by approved_at date (newest first)
    usort($allNotifications, function($a, $b) {
        $dateA = strtotime($a['approved_at']);
        $dateB = strtotime($b['approved_at']);
        return $dateB - $dateA; // Descending order
    });

    // Calculate unread count
    $unreadCount = 0;
    foreach ($allNotifications as $notif) {
        if ($notif['student_seen'] == 0) {
            $unreadCount++;
        }
    }

    // Limit to 50 most recent notifications
    $allNotifications = array_slice($allNotifications, 0, 50);

    echo json_encode([
        'success' => true,
        'notifications' => $allNotifications,
        'unread_count' => $unreadCount,
        'message' => 'Notifications retrieved successfully',
        'debug' => [
            'has_student_seen_column' => $hasStudentSeenColumn,
            'course_count' => count($courseNotifications),
            'idp_count' => count($idpNotifications)
        ]
    ]);

} catch (Exception $e) {
    error_log("Error fetching notifications: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error: ' . $e->getMessage()
    ]);
}
?>

