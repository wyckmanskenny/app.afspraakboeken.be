<?php
declare(strict_types=1);
// Optional daily Hostinger cron job. Never callable through the web.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php';
try {
    query('DELETE FROM ab_sessions WHERE expires_at<UTC_TIMESTAMP() OR last_seen<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)');
    query('DELETE FROM ab_rate_limits WHERE expires_at<UTC_TIMESTAMP()');
    query('DELETE FROM ab_invites WHERE expires_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)');
    echo "Expired authentication records removed. No customer records were changed.\n";
} catch (Throwable $e) { fwrite(STDERR, "Maintenance could not connect. Check the private server configuration.\n"); exit(1); }
