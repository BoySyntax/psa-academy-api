<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

echo "PHP Version: " . phpversion() . "\n";
echo "PDO Drivers: " . print_r(PDO::getAvailableDrivers(), true) . "\n";

if (extension_loaded('pdo_mysql')) {
    echo "MySQL PDO Driver: LOADED ✓\n";
} else {
    echo "MySQL PDO Driver: NOT LOADED ✗\n";
}

try {
    require_once 'config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    
    if ($db) {
        echo "Database Connection: SUCCESS ✓\n";
    } else {
        echo "Database Connection: FAILED ✗\n";
    }
} catch (Exception $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
}
?>
