<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, ngrok-skip-browser-warning');
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../config/url_helper.php';

// Cleanup orphaned chunks older than 2 hours to prevent disk bloat
function cleanupOldChunks() {
    $chunksDir = '../uploads/chunks/';
    if (!is_dir($chunksDir)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($chunksDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $cutoff = time() - 2 * 3600; // 2 hours ago
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getMTime() < $cutoff) {
            @unlink($file->getPathname());
        }
    }
    // Remove empty directories
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($chunksDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        if ($file->isDir() && !count(glob($file->getPathname() . '/*'))) {
            @rmdir($file->getPathname());
        }
    }
}
cleanupOldChunks();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['message' => 'Method not allowed']);
    exit();
}

$upload_id    = isset($_POST['upload_id'])    ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['upload_id']) : null;
$chunk_index  = isset($_POST['chunk_index'])  ? (int)$_POST['chunk_index']  : null;
$total_chunks = isset($_POST['total_chunks']) ? (int)$_POST['total_chunks'] : null;
$file_name    = isset($_POST['file_name'])    ? basename($_POST['file_name']) : null;

if (!$upload_id || $chunk_index === null || $total_chunks === null || !$file_name) {
    http_response_code(400);
    echo json_encode(['message' => 'Missing required fields: upload_id, chunk_index, total_chunks, file_name']);
    exit();
}

if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode([
        'message' => 'Chunk upload error',
        'error'   => $_FILES['chunk']['error'] ?? 'no chunk key',
    ]);
    exit();
}

// Save chunk into temp directory
$temp_dir = '../uploads/chunks/' . $upload_id . '/';
if (!file_exists($temp_dir)) {
    mkdir($temp_dir, 0777, true);
}

$chunk_path = $temp_dir . 'chunk_' . sprintf('%05d', $chunk_index);
if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunk_path)) {
    http_response_code(500);
    echo json_encode(['message' => 'Failed to save chunk ' . $chunk_index]);
    exit();
}

// Not the last chunk — acknowledge and wait for more
if ($chunk_index < $total_chunks - 1) {
    echo json_encode(['received' => true, 'chunk_index' => $chunk_index]);
    exit();
}

// ---- Last chunk received: assemble the file ----

$lesson_id     = isset($_POST['lesson_id'])     ? $_POST['lesson_id']     : null;
$course_id     = isset($_POST['course_id'])     ? $_POST['course_id']     : null;
$teacher_id    = isset($_POST['teacher_id'])    ? $_POST['teacher_id']    : null;
$material_name = isset($_POST['material_name']) ? $_POST['material_name'] : null;
$description   = isset($_POST['description'])   ? $_POST['description']   : '';

if (!$lesson_id || !$teacher_id) {
    http_response_code(400);
    echo json_encode(['message' => 'lesson_id and teacher_id are required on the final chunk']);
    exit();
}

$file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
$allowed  = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'zip', 'mp4', 'avi', 'mov', 'mp3', 'jpg', 'jpeg', 'png'];

if (!in_array($file_ext, $allowed)) {
    for ($i = 0; $i < $total_chunks; $i++) {
        @unlink($temp_dir . 'chunk_' . sprintf('%05d', $i));
    }
    @rmdir($temp_dir);
    http_response_code(400);
    echo json_encode(['message' => 'File type not allowed. Allowed: ' . implode(', ', $allowed)]);
    exit();
}

$materials_dir = '../uploads/materials/';
if (!file_exists($materials_dir)) {
    mkdir($materials_dir, 0777, true);
}

$unique_name = uniqid('mat_', true) . '.' . $file_ext;
$final_path  = $materials_dir . $unique_name;

$out = fopen($final_path, 'wb');
if (!$out) {
    http_response_code(500);
    echo json_encode(['message' => 'Failed to create output file']);
    exit();
}

for ($i = 0; $i < $total_chunks; $i++) {
    $part = $temp_dir . 'chunk_' . sprintf('%05d', $i);
    if (!file_exists($part)) {
        fclose($out);
        @unlink($final_path);
        http_response_code(400);
        echo json_encode(['message' => 'Missing chunk ' . $i . '. Please retry the upload.']);
        exit();
    }
    $in = fopen($part, 'rb');
    stream_copy_to_stream($in, $out);
    fclose($in);
    unlink($part);
}
fclose($out);
@rmdir($temp_dir);

$actual_size = filesize($final_path);

$material_type = 'document';
if ($file_ext === 'pdf') {
    $material_type = 'pdf';
} elseif (in_array($file_ext, ['mp4', 'avi', 'mov', 'mp3'])) {
    $material_type = 'video';
} elseif (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif'])) {
    $material_type = 'image';
}

$file_url     = make_local_file_url('uploads/materials/' . $unique_name);
$display_name = $material_name ? $material_name : $file_name;

$database = new Database();
$db       = $database->getConnection();

$query = "INSERT INTO learning_materials
          (lesson_id, course_id, material_name, material_type, file_url, file_size, description, uploaded_by)
          VALUES (:lesson_id, :course_id, :material_name, :material_type, :file_url, :file_size, :description, :uploaded_by)";

$stmt = $db->prepare($query);
$stmt->bindParam(':lesson_id',     $lesson_id);
$stmt->bindParam(':course_id',     $course_id);
$stmt->bindParam(':material_name', $display_name);
$stmt->bindParam(':material_type', $material_type);
$stmt->bindParam(':file_url',      $file_url);
$stmt->bindParam(':file_size',     $actual_size);
$stmt->bindParam(':description',   $description);
$stmt->bindParam(':uploaded_by',   $teacher_id);

if ($stmt->execute()) {
    http_response_code(201);
    echo json_encode([
        'message'  => 'Material uploaded successfully',
        'material' => [
            'id'            => $db->lastInsertId(),
            'material_name' => $display_name,
            'material_type' => $material_type,
            'file_url'      => normalize_public_file_url($file_url),
            'file_size'     => $actual_size,
        ],
    ]);
} else {
    @unlink($final_path);
    http_response_code(500);
    echo json_encode(['message' => 'Failed to save material info to database']);
}
?>
