<?php
declare(strict_types=1);
// Run with a Hostinger PHP cron job. There is deliberately no public HTTP trigger.
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require __DIR__.'/bootstrap.php';
require __DIR__.'/domain.php';
require __DIR__.'/mailer.php';
require __DIR__.'/lifecycle.php';
try {echo json_encode(run_trial_reminders(),JSON_THROW_ON_ERROR).PHP_EOL;}
catch(Throwable $error){fwrite(STDERR,"Trial reminders failed. Check database and SMTP configuration.\n");exit(1);}
