<?php
/**
 * This script reads a local or an online iCal file and show_source
 * the date and time of the next event that starts (DTSTART) or ends (DTEND).
 * If an events starts or ends in the minute of execution, "NOW! " is shown.
 * Another output is the name of the event.
 *
 * PHP Version 5
 *
 * @category Parser
 * @package  openhab
 * @author   Johannes Schildgen
 * @license  http://www.opensource.org/licenses/mit-license.php  MIT License
 * @version  SVN: <svn_id>
 * @link     https://github.com/jschildgen/ical4openhab
 * @example  php /path/to/ical.php DTSTART
 */

 /** the time in seconds in which the ical file is re-downloaded from the web **/
 $refresh_seconds = 900;
 /** the url of the ical file, either local (/path/to/file.ics) or from the web (http://...) **/
 $ical_url = "http://your/calendar/url/here.ics";
 /** the events are shown $offset seconds after the events actually starts; e.g., $offset = -3600; to show an event one hour before it starts **/
 $offset = 0;
 $debug = false;


/**************************/

if(count($argv) < 2 || !($argv[1] == 'DTSTART' || $argv[1] == 'DTEND')) {
  echo "Usage: php /path/to/ical.php DTSTART|DTEND";
  die();
}

$now = time()-59; /* otherwise an event at 8:00 would be over, if it's now 8:00:01 ;-) */

require_once('/home/pi/ical/class.iCalReader.php');

if($argv[1] == 'DTEND') {
  /* wait for the DTSTART script to refresh the ics file */
  $refresh_seconds += 60;
}

if(substr($ical_url,0,4) == "http") {
  if(!file_exists('/tmp/ical.ics') || ($argv[1] == 'DTSTART' && filectime('/tmp/ical.ics') < $now-$refresh_seconds)) {
    if(file_exists('/tmp/ical.ics')) { unlink("/tmp/ical.ics"); }
    file_put_contents("/tmp/ical.ics", file_get_contents($ical_url));
  }
  $ical_url = '/tmp/ical.ics';
}



$ical = new ical($ical_url);

$next_events = array();

//print_r($ical->events()); die();

foreach($ical->events() as $event) {
  //print_r($event); continue;
  debug("===> ".$event["SUMMARY"]."      (".$event["UID"].")");

  $time = $ical->iCalDateToUnixTimestamp($event[$argv[1]])+$offset;

    /* a one-time event */
    if(!isset($event['RRULE'])) {
      if($time < $now) {
        debug("schon vorbei: $time < ".$now);
        continue;
      } else {
        $event["NEXT"] = $time;
        $next_events[] = $event;
        debug("one-time event: ".date("d.m.Y H:i",$time));
        continue;
      }
    }

    /* frequence */
    $freq_str = str_between("FREQ=",";",$event['RRULE']);
    if($freq_str == "WEEKLY") { $freq = 7; }
    else { continue; }                            /* not supported */
    debug("freq: $freq");

    /* weekdays */
    $byday = array();
    if(strpos($event['RRULE'],";BYDAY=") !== FALSE) {
      $byday_str = explode(",",str_between(";BYDAY=",";",$event['RRULE']));
      foreach($byday_str as $byday_str_element) {
        switch ($byday_str_element) {
          case 'SU': $byday[] = 0; break;
          case 'MO': $byday[] = 1; break;
          case 'TU': $byday[] = 2; break;
          case 'WE': $byday[] = 3; break;
          case 'TH': $byday[] = 4; break;
          case 'FR': $byday[] = 5; break;
          case 'SA': $byday[] = 6; break;
        }
      }
      /* the event ends on another day as it starts => adjust weekday */
      if($argv[1] == 'DTEND') {
        $tmp_enddate = date("Y-m-d", $ical->iCalDateToUnixTimestamp($event["DTEND"])+$offset);
        $tmp_startdate = date("Y-m-d", $ical->iCalDateToUnixTimestamp($event["DTSTART"])+$offset);
        $adjust_days = round((strtotime("$tmp_enddate 12:00")-strtotime("$tmp_startdate 12:00"))/86400);
        debug("adjust_days: ".$adjust_days);
        foreach($byday as $k=>$v) { $byday[$k] += $adjust_days; while($byday[$k] > 6) { $byday[$k]-=7; } }
      }
      debug("byday: ".implode(",",$byday));
    }

    /* interval (every n weeks/days/...) */
    // NOT YET SUPPORTED!
    $interval = 1;
    if(strpos($event['RRULE'],";INTERVAL=") !== FALSE) {
      $interval = 0+str_between(";INTERVAL=",";",$event['RRULE']);
      debug("interval: ".$interval);
    }

    /* exceptions */
    $exceptions = array();
    $exceptions_date = array();

    if(isset($event['EXDATE'])) {
      $exceptions_ical = explode(",",$event['EXDATE']);
      foreach($exceptions_ical as $exception) {
        if($exception=="") { continue; }
        $exceptions[$ical->iCalDateToUnixTimestamp($exception)] = true;
        //$exceptions_date[] = date("d.m.Y H:i",$ical->iCalDateToUnixTimestamp($exception));
      }
    }
    //debug("exceptions: ".implode(",",$exceptions_date));

    /* an n-times repeated event */
    if(strpos($event['RRULE'],";COUNT=") !== FALSE) {
      $count = str_between(";COUNT=",";",$event['RRULE']);
      debug("count: $count");
      for($counted = 0; $counted<$count; $time = strtotime(date("Y-m-d H:i",$time)." + 1 days")) {
        if(in_array(date("w",$time),$byday)) {
          $counted++;
        }
        if($time >= $now && !array_key_exists($event['DTSTART'],$exceptions) && in_array(date("w",$time),$byday)) {
          $event["NEXT"] = $time;
          $next_events[] = $event;
          debug("next: ".date("d.m.Y H:i",$time));
          break;
        }
      }
      continue;
    }

    /* an event repeated until a specific day */
    if(strpos($event['RRULE'],";UNTIL=") !== FALSE) {
      $until = $ical->iCalDateToUnixTimestamp(str_between(";UNTIL=",";",$event['RRULE']));
      debug("until: ".date("d.m.Y H:i",$until));
      if($until < $now) {
        /* will not happen anymore */
        continue;
      } else {
        for($time = $time; $time <= $until; $time = strtotime(date("Y-m-d H:i",$time)." + 1 days")) {
          if($time >= $now) {
            if(array_key_exists($event['DTSTART'],$exceptions)) { continue; }
            if(!in_array(date("w",$time),$byday)) { continue; }
            $event["NEXT"] = $time;
            $next_events[] = $event;
            debug("next: ".date("d.m.Y H:i",$time));
            break;
          }
        }
        continue;
      }
    }

    /* an event repeated forever */
    for($time = $time; true; $time = strtotime(date("Y-m-d H:i",$time)." + 1 days")) {
      if($time >= $now) {
        if(array_key_exists($event['DTSTART'],$exceptions)) { continue; }
        if(!in_array(date("w",$time),$byday)) { continue; }
        $event["NEXT"] = $time;
        $next_events[] = $event;
        debug("next: ".date("d.m.Y H:i",$time));
        break;
      }
    }
    continue;
}

if(count($next_events)==0) {
  echo "No upcoming events";
  die();
}

$next_event = null;
$next_event_time = null;
for($i = 0; $i < count($next_events); $i++) {
  if($next_event_time == null || $next_events[$i]['NEXT'] < $next_event_time) {
    $next_event_time = $next_events[$i]['NEXT'];
    $next_event = $next_events[$i]["SUMMARY"];
  } elseif($next_events[$i]['NEXT'] == $next_event_time) {
    /* two events at the same time */
    $next_event .= "&&&".$next_events[$i]["SUMMARY"];
  }
}

$next_event_datetime = date("Y-m-d H:i", $next_event_time);
echo ($next_event_datetime==date("Y-m-d H:i")?"NOW!":$next_event_datetime)." ".$next_event;

function debug($str) {
  global $debug;
  if(!$debug) { return; }
  echo $str."\n";
}

/**
* Returns the text between $a and $z in $string
* if $a == null: from beginning
* if $b == null: to the end
*/
function str_between($a, $z, $string){
  if($a == null) {
    $t = $string;
  } else {
    $startpos = strpos($string, $a);
    if($startpos === FALSE) { return $t = $string; }
    $t = substr($string, $startpos+strlen($a));
  }

  if($z == null) { return $t; }
  $endpos = strpos($t, $z);
  if($endpos === FALSE) { return $t; }
  $t = substr($t, 0, $endpos);
 return $t;
}

