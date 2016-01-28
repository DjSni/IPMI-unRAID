#!/usr/bin/php
<?php
##############################
######  DEAMON SECTION  ######
##############################
$cron         = FALSE;
$DEBUG        = FALSE;

# DO NOT TOUCH
set_time_limit(0);
$program_name = pathinfo(__FILE__, PATHINFO_FILENAME);
$lockfile     = "/var/run/${program_name}.pid";
$service_file = __FILE__;
openlog($program_name, LOG_PID | LOG_PERROR, LOG_LOCAL0);

$usage = <<<EOT
Usage: $program_name         = start the daemon
       $program_name -q      = quit the program if it is running

EOT;

function debug($m){
  global $program_name, $DEBUG;
  if($DEBUG){
    $STDERR = fopen('php://stderr', 'w+');
    fwrite($STDERR, $m."\n");
    fclose($STDERR);
  }
}

$background_args = "";
if(isset($argv)){
  for ($i=0; $i <= count($argv); $i++) { 
    switch ($argv[$i]) {
      case '-q':
        $quit = TRUE;
      break;
      case '--background':
        $background = TRUE;
      break;
      case '-c':
        $cron = $argv[$i+1];
        $background_args .= " ${argv[$i]} ${argv[$i+1]}";
      break;
      case '-d':
        $DEBUG = TRUE;
        $background_args .= " ${argv[$i]}";
      break;
      case '-h':
      case '--help':
      echo $usage;
      exit(0);
      # Especific switches:
    }
  }
}

# Deal with cron
if (isset($cron) && is_numeric($cron) && ! isset($quit)){
  exec("crontab -l 2>/dev/null", $crontab);
  $crontab = array_unique($crontab);
  if (! isset($quit)){
    $entry = sprintf("*/%s * * * *  ${service_file}${background_args} 1> /dev/null 2>&1", $cron);
    if (! preg_grep("#${service_file}#", $crontab)){
      $crontab[] = $entry;
      debug("\nCRONTAB\n".implode("\n", $crontab)."\n");
      file_put_contents("/tmp/${program_name}.cron", implode(PHP_EOL, $crontab));
      shell_exec("crontab /tmp/${program_name}.cron");
      unlink("/tmp/$program_name.cron");
    }
  }
  unset($crontab);
} else if (isset($quit)){
  exec("crontab -l 2>/dev/null", $crontab);
  $crontab = array_unique($crontab);
  if (preg_grep("#${service_file}#", $crontab)){
    $crontab = preg_grep("#${service_file}#", $crontab, PREG_GREP_INVERT);
      debug("\nCRONTAB\n".implode("\n", $crontab)."\n");
    file_put_contents("/tmp/${program_name}.cron", implode(PHP_EOL, $crontab));
    shell_exec("crontab /tmp/${program_name}.cron");
    unlink("/tmp/$program_name.cron");
  };
  unset($crontab);
}

if (is_file($lockfile)){
  $lock_pid = file($lockfile, FILE_IGNORE_NEW_LINES)[0];
  $pid_running=preg_replace("/\s+/", "", shell_exec("ps -p ${lock_pid}| grep ${lock_pid}"));
  if (! $pid_running){
    if (! isset($quit)){
      file_put_contents($lockfile, getmypid());
    } else {
      echo "${lock_pid} is not currently running";
      unlink($lockfile);
      exit(0);
    }
  } else {
    if (isset($quit)){
      syslog(LOG_INFO, "killing daemon with PID [${lock_pid}]");
      exec("kill $lock_pid");
      unlink($lockfile);
      if (function_exists('at_exit')) at_exit();
      exit(0);
    } else {
      echo "$program_name is already running [${lock_pid}]".PHP_EOL;
      exit(0);
    }
  }
} else {
  if(isset($quit)){
    echo "$program_name not currently running".PHP_EOL;
    exit(0);
  } else {
    file_put_contents($lockfile, getmypid());
  }
}

if(!isset($background)){
  exec("php $service_file --background $background_args 1>/dev/null ".($DEBUG ? "":"2>&1 ")."&");
  exit(0);
} else {
  syslog(LOG_INFO, "process started. To terminate it, type: $program_name -q");
}

##############################
#####  PROGRAM SECTION  ######
##############################
$plugin       = "dynamix.system.autofan";
$config_file  = "/boot/config/plugins/${plugin}/fan.conf";

function scan_dir($dir, $type = ""){
  $out = array();
  foreach (array_slice(scandir($dir), 2) as $entry){
    $sep   = (preg_match("/\/$/", $dir)) ? "" : "/";
    $out[] = $dir.$sep.$entry ;
  }
  return $out;
}

function get_highest_temp($hdds){
  $highest_temp="0";
  foreach ($hdds as $hdd) {
    if (shell_exec("hdparm -C ${hdd} 2>/dev/null| grep -c standby") == 0){
      $temp = preg_replace("/\s+/", "", shell_exec("smartctl -A ${hdd} 2>/dev/null| grep -m 1 -i Temperature_Celsius | awk '{print $10}'"));
      $highest_temp = ($temp > $highest_temp) ? $temp : $highest_temp;
    }
  } 
  debug("Highest temp is ${highest_temp}ºC");
  return $highest_temp;
}

function get_all_hdds(){
  $hdds = array();
  $flash = preg_replace("/\d$/", "", realpath("/dev/disk/by-label/UNRAID"));
  foreach (scan_dir("/dev/") as $dev) {
    if(preg_match("/[sh]d[a-z]+$/", $dev) && $dev != $flash) {
      $hdds[] = $dev;
    }
  }
  return $hdds;
}

$hdds = get_all_hdds();
while(TRUE){ while(TRUE){
####  DO YOUR STUFF HERE  ####

# Load config file or die
if(is_file($config_file)){
  $params  = (is_file($config_file)) ? parse_ini_file($config_file) : array();
  extract($params, EXTR_OVERWRITE);
} else {
  unlink($lockfile);
  exit(1);
}

# Wait probes to become ready
if (! is_file($PWM_CONTROLLER) || ! is_file($PWM_FAN)){
  sleep(15);
  continue;
}

# Set PWM_HIGH and PWM_OFF
$PWM_HIGH = 255;
$PWM_OFF = $PWM_LOW-5;

# Disable fan mininum RPM
$FAN_RPM_MIN = file(split("_", $PWM_FAN)[0]."_min", FILE_IGNORE_NEW_LINES)[0];
$D_FAN_RPM_MIN = ($FAN_RPM_MIN > 0) ? $FAN_RPM_MIN : FALSE;

# Enable speed change on fan
$PWM_MODE=file("${PWM_CONTROLLER}_enable", FILE_IGNORE_NEW_LINES)[0];
if($PWM_MODE != 1){
  $DEFAULT_PWM_MODE = $PWM_MODE;
  file_put_contents("${PWM_CONTROLLER}_enable", 1);
}

# Calculate size of increments.
$TEMP_INCREMENTS     = $TEMP_HIGH-$TEMP_LOW; 
$PWM_INCREMENTS      = round(($PWM_HIGH-$PWM_LOW)/$TEMP_INCREMENTS);
# Get current fan rpm and pwm
$CURRENT_PWM         = preg_replace("/\s*/", "", file_get_contents($PWM_CONTROLLER));
$CURRENT_RPM         = preg_replace("/\s*/", "", file_get_contents($PWM_FAN));
# Get current fan PWM percentage
$CURRENT_PERCENT_PWM = round(($CURRENT_PWM*100)/$PWM_HIGH);
$CURRENT_OUTPUT      = "${CURRENT_PWM} (${CURRENT_PERCENT_PWM}% @ ${CURRENT_RPM}rpm)";

# Calculate a new scenario
# Get highest drive temperature
$HIGHEST_TEMP       = get_highest_temp($hdds);
if ($HIGHEST_TEMP <= $TEMP_LOW){
  $NEW_PWM          = $PWM_OFF;
  $NEW_PERCENT_PWM  = 0;
} else if ($HIGHEST_TEMP >= $TEMP_HIGH){
  $NEW_PWM          = $PWM_HIGH;
  $NEW_PERCENT_PWM  = 100;
} else {
  $NEW_PWM          = (($HIGHEST_TEMP-$TEMP_LOW)*$PWM_INCREMENTS)+$PWM_LOW;
  $NEW_PERCENT_PWM  = round(($NEW_PWM*100)/$PWM_HIGH);
}

# Change the fan speed as needed
if ($CURRENT_PWM != $NEW_PWM){
  file_put_contents($PWM_CONTROLLER, $NEW_PWM);
  sleep(5);
  $NEW_RPM         = preg_replace("/\s*/", "", file_get_contents($PWM_FAN));
  $NEW_PERCENT_PWM = round(($NEW_PWM*100)/$PWM_HIGH);
  $NEW_OUTPUT      = "${NEW_PWM} (${NEW_PERCENT_PWM}% @ ${NEW_RPM}rpm)";
  syslog(LOG_INFO, "highest disk temp is ${HIGHEST_TEMP}ºC, adjusting fan PWM from: $CURRENT_OUTPUT to: $NEW_OUTPUT");
}

# PRINT VARIABLES DEBUG 
$defined_vars = get_defined_vars();
foreach (array("_GET","_POST","_COOKIE","_FILES","argv","argc","_SERVER") as $i) {unset($defined_vars[$i]);}
debug("\nDECLARED VARIABLES:\n".print_r($defined_vars, true));
unset($defined_vars);

$time1 = time();
$MD5 = shell_exec("md5sum $config_file|awk '{print $1}'");
$MD5 = md5_file($config_file);
for ($i=0; $i < $INTERVAL*6 ; $i++) { 
  sleep(10);
  if (md5_file($config_file) != $MD5){syslog(LOG_INFO, "config file updated, reloading."); $i=10000;}
}
debug("Sleeped ".(time()-$time1)." seconds.");

######  END OF SECTION  ######
};};?>

