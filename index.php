<?php
// PSA Academy API - Main Entry Point
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

echo json_encode([
    "message" => "PSA Academy API is running",
    "version" => "1.0.0",
    "endpoints" => [
        "/student" => "Student management",
        "/teacher" => "Teacher management", 
        "/admin" => "Admin functions",
        "/management" => "Management functions"
    ]
]);
?>
