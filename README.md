# ical4openhab
The script returns the date and title of the event in an ical file that starts or ends next. For OpenHAB 2. 

## How to use
- is the php command-line interface installed on your system? (try "man php" in console)
- Store the php files of this project somewhere (e.g., /home/pi/ical)
- Write the url to the ics (either local or http) to the ical.php file
- Try: php /home/pi/ical/ical.php DTSTART
- Try again: php /home/pi/ical/ical.php DTEND

## How it works
The script opens an ical file on the file system or downloads a ical file from the internet (not every time, only if the downloaded file is older than $refresh_seconds seconds (can be set in ical.php). Then it scans the events using the Ics-Parser and searches the one event which starts (DTSTART) or ends (DTEND) next. It returns the date, time and title of this event. If the event starts or ends in the minute of executing this script, "NOW! " and the event title is returned.

## Using in OpenHAB
You need two things (using the Exec binding), two items and two rules. The things call the php script every minute. The result (the next event that starts or ends) is stored in the two items iCalStart and iCalEnd. The rules are fired when the items change its states. This is the case when a new event is added to the calendar which starts/ends before the former event, or if the event starts/ends now ("NOW! " prefix), or if its start or end is just over. Then the next event is shown.

things/ical.things

    Thing exec:command:icalstart [command="php /home/pi/ical/ical.php DTSTART", interval=60, timeout=30]
    Thing exec:command:icalend [command="php /home/pi/ical/ical.php DTEND", interval=60, timeout=30]

items/ical.items

    String iCalStart "iCalStart [%s]" (All) {channel="exec:command:icalstart:output"} 
    String iCalEnd "iCalEnd [%s]" (All) {channel="exec:command:icalend:output"}

rules/ical.rules

    rule "Do something when an event starts"
    when
        Item iCalStart changed
    then
        if(iCalStart.state.toString.substring(0,5)=="NOW! ") {
            var List<String> events = iCalStart.state.toString.substring(5).split('&&&')
            for(String event : events) {
                logInfo("iCalStart", event)
                if(event=="Bathroom") {
                    sendCommand(Bathroom_4_SetTemperature, 21)
                }
            }
        }    
    end
    
    
    rule "Do something when an event ends"
    when
            Item iCalEnd changed
    then
        if(iCalStart.state.toString.substring(0,5)=="NOW! ") {
            var List<String> events = iCalStart.state.toString.substring(5).split('&&&')
            for(String event : events) {
                logInfo("iCalStart", event)
                if(event=="Bathroom") {
                    sendCommand(Bathroom_4_SetTemperature, 17)
                }
            }
        }    
    end

The events in the calendar have to use scene names, which are defined in the rules, e.g. "Bathroom". More scenes can simply be added to the rules:

    if(event=="Rollershutter") {
        sendCommand(Rollershutter, UP)
    }

It's okay, if a scene is only in the "start" section. Then nothing is done when the event ends.

## Connecting to a Google Calendar
Click on the little arrow on your calendar in the calendar list on the left. Click settings and then the ical button at "Private Address". There you find the URL to an ical containing all events of that calendar.

## Differences to the gcal binding
- This script is not a binding. It uses the exec binding
- You can use all icals, not only from Google Calendar. Even the trash ical, the holiday ical, ...
- It does not use the API. It uses the calendar in a read-only fashion.
- It does not allow arbitrary events, only predefined scenes.

## Offset Option
- If you want to see the "NOW! " of the event one our before the events starts and ends, set $offset = -3600. Its the time in seconds to add to the event timestamp, usually negative, otherwise the event is shown with a delay.

## Future Work
- Support daily, monthly and yearly events. Currently only one-time events and weekly events are supported. The weekly events can have arbirary many exceptions and they can repeat n times or untial a specific date. Weekly events must be every week (not every two weeks etc.). If you want to make a daily event, create a weekly one and set the days to MO,TU,WE,TH,FR,SA,SU ;-)
- Support timezones

## Ics-Parser
ical4openhab uses the Ics-Parser (http://code.google.com/p/ics-parser/); it is a bit modified in this project to support multiple exceptions of repeating events, UTC-timezone events, etc. Maybe, more modifications, especially for time zones must be done. 
