<?php
require_once '../config.php';

header('Content-Type: application/json');

try {
    $tables = ['course_enrollments', 'courses', 'users'];
    $schema = [];

    foreach ($tables as $t) {
        $cols = [];
        $res = $conn->query("SHOW COLUMNS FROM `$t`");
        if (!$res) {
            $schema[$t] = ['error' => $conn->error];
            continue;
        }
        while ($row = $res->fetch_assoc()) {
            $cols[] = $row;
        }
        $schema[$t] = $cols;
    }

    echo json_encode([
        'success' => true,
        'schema' => $schema,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}

$conn->close();
?>
