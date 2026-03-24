<?php
require_once '../config.php';

header('Content-Type: application/json');

// Get teacher ID from query parameter
$teacherId = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;

if ($teacherId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valid teacher ID required']);
    exit();
}

try {
    // Simple test query first
    $testQuery = "SELECT COUNT(*) as total FROM course_enrollments e JOIN courses c ON e.course_id = c.id WHERE c.teacher_id = ?";
    $stmt = $conn->prepare($testQuery);
    $stmt->bind_param("i", $teacherId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'teacher_id' => $teacherId,
        'total_enrollments' => (int)$row['total'],
        'message' => 'Test query successful'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

$stmt->close();
$conn->close();
?>
