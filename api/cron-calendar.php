<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require __DIR__.'/bootstrap.php';require __DIR__.'/domain.php';require __DIR__.'/calendar.php';
try{echo json_encode(google_run_cron(),JSON_THROW_ON_ERROR).PHP_EOL;}catch(Throwable $e){fwrite(STDERR,"Calendar sync failed. Check calendar status in the app.\n");exit(1);}
