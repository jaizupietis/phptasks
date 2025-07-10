<?php
define('SECURE_ACCESS', true);
require_once 'config/config.php';

echo "=== TIMEZONE FIX SCRIPT ===\n";

// Set PHP timezone
date_default_timezone_set('Europe/Riga');
echo "✓ PHP timezone set to Europe/Riga\n";

echo "Current PHP time: " . date('Y-m-d H:i:s T') . "\n";
echo "Current timezone: " . date_default_timezone_get() . "\n";

try {
    $db = Database::getInstance();
    
    // Set MySQL timezone
    $db->query("SET time_zone = 'Europe/Riga'");
    echo "✓ MySQL timezone set to Europe/Riga\n";
    
    // Get MySQL timezone
    $mysql_tz = $db->fetch("SELECT @@session.time_zone as tz, NOW() as current_time");
    echo "MySQL timezone: " . $mysql_tz['tz'] . "\n";
    echo "MySQL time: " . $mysql_tz['current_time'] . "\n";
    
    echo "\n✓ Timezone configuration completed!\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
?>
