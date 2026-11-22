<?php
declare(strict_types=1);
require __DIR__.'/../api/appointment-reminders.php';
function check(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);}
$s=['appointmentReminders'=>['threeDays'=>true,'oneDay'=>true,'sameDay'=>true,'sameDayTime'=>'08:00']];
foreach(['2027-03-28 08:00:00','2027-10-31 09:00:00'] as $date){
    $times=appointment_reminder_times(['starts_at'=>$date],$s);
    check($times['oneDay']->format('H:i')==='10:00','Local appointment hour survives DST');
    check($times['threeDays']->format('H:i')==='10:00','Three days follows local wall time');
    check($times['sameDay']->format('H:i')==='08:00','Same-day local time');
}
check(appointment_reminder_settings([])['oneDay']===true,'Default on');
check(appointment_reminder_settings([])['sameDay']===false,'Same day opt-in');
echo "PASS Brussels daylight saving, calendar-day timing and defaults\n";
