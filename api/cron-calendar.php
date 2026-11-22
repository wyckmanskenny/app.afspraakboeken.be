<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require __DIR__.'/bootstrap.php';require __DIR__.'/domain.php';require __DIR__.'/mailer.php';require __DIR__.'/calendar.php';
// Mail runs independently, even when a calendar provider fails.
$results=[];$failed=false;
foreach(['reminders'=>'run_appointment_reminders','google'=>'google_run_cron','outlook'=>'outlook_run_cron'] as $name=>$run){
    try{$results[$name]=$run();}catch(Throwable $e){$results[$name]=['error'=>true];$failed=true;fwrite(STDERR,'Scheduled task failed: '.$name.". Check the app status.\n");}
}
echo json_encode($results,JSON_THROW_ON_ERROR).PHP_EOL;if($failed)exit(1);
