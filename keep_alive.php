<?php
/**
 * Keep-Alive Endpoint for Render Free Tier
 * Prevents instance from spinning down due to inactivity
 * Call this from a free monitoring service (UptimeRobot, Ping-O-Matic, etc.)
 */

// Simple response to keep instance awake
header('Content-Type: application/json');
http_response_code(200);

echo json_encode([
    'status' => 'alive',
    'timestamp' => date('Y-m-d H:i:s'),
    'uptime' => getenv('UPTIME') ?: 'unknown'
]);

// Log the keep-alive ping
error_log('[KEEP-ALIVE] ' . date('Y-m-d H:i:s') . ' - Instance is active');
exit;
?>
