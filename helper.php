<?php
/** 
  * Helper Class for the DAVCal plugin
  * This helper does the actual work.
  * 
  */
  
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class helper_plugin_davcal extends DokuWiki_Plugin {
  
  protected $sqlite = null;
  protected $cachedValues = array();
  
  /**
    * Constructor to load the configuration and the SQLite plugin
    */
  public function helper_plugin_davcal() {
    $this->sqlite =& plugin_load('helper', 'sqlite');
    if(!$this->sqlite)
    {
        msg('This plugin requires the sqlite plugin. Please install it.');
        return;
    }
    
    if(!$this->sqlite->init('davcal', DOKU_PLUGIN.'davcal/db/'))
    {
        return;
    }
  }
  
  /**
   * Retrieve meta data for a given page
   * 
   * @param string $id optional The page ID
   * @return array The metadata
   */
  private function getMeta($id = null) {
    global $ID;
    global $INFO;

    if ($id === null) $id = $ID;

    if($ID === $id && $INFO['meta']) {
        $meta = $INFO['meta'];
    } else {
        $meta = p_get_metadata($id);
    }
    
    return $meta;
  }
  
  /**
   * Retrieve the meta data for a given page
   * 
   * @param string $id optional The page ID
   * @return array with meta data
   */
  public function getCalendarMetaForPage($id = null)
  {
      if(is_null($id))
      {
          global $ID;
          $id = $ID;
      }
      
      $meta = $this->getMeta($id);
      if(isset($meta['plugin_davcal']))
        return $meta['plugin_davcal'];
      else
        return array();
  }
  
  /**
   * Get all calendar pages used by a given page
   * based on the stored metadata
   * 
   * @param string $id optional The page id
   * @return mixed The pages as array or false
   */
  public function getCalendarPagesByMeta($id = null)
  {
      if(is_null($id))
      {
          global $ID;
          $id = $ID;
      }
      
      $meta = $this->getCalendarMetaForPage($id);
      if(isset($meta['id']))
      {
          return array_keys($meta['id']);
      }
      return false;
  }
  
  /**
   * Get a list of calendar names/pages/ids/colors
   * for an array of page ids
   * 
   * @param array $calendarPages The calendar pages to retrieve
   * @return array The list
   */
  public function getCalendarMapForIDs($calendarPages)
  {
      $data = array();
      foreach($calendarPages as $page)
      {
          $calid = $this->getCalendarIdForPage($page);
          if($calid !== false)
          {
            $settings = $this->getCalendarSettings($calid);
            $name = $settings['displayname'];
            $color = $settings['calendarcolor'];
            $data[] = array('name' => $name, 'page' => $page, 'calid' => $calid,
                            'color' => $color);
          }
      }
      return $data;
  }
  
  /**
   * Get the saved calendar color for a given page.
   * 
   * @param string $id optional The page ID
   * @return mixed The color on success, otherwise false
   */
  public function getCalendarColorForPage($id = null)
  {
      if(is_null($id))
      {
          global $ID;
          $id = $ID;
      }
      
      $calid = $this->getCalendarIdForPage($id);
      if($calid === false)
        return false;
      
      return $this->getCalendarColorForCalendar($calid);
  }
  
  /**
   * Get the saved calendar color for a given calendar ID.
   * 
   * @param string $id optional The calendar ID
   * @return mixed The color on success, otherwise false
   */
  public function getCalendarColorForCalendar($calid)
  {
      if(isset($this->cachedValues['calendarcolor'][$calid]))
        return $this->cachedValues['calendarcolor'][$calid];

      $row = $this->getCalendarSettings($calid);

      if(!isset($row['calendarcolor']))
        return false;
      
      $color = $row['calendarcolor'];
      $this->cachedValues['calendarcolor'][$calid] = $color;
      return $color;
  }
  
  /**
   * Set the calendar color for a given page.
   * 
   * @param string $color The color definition
   * @param string $id optional The page ID
   * @return boolean True on success, otherwise false
   */
  public function setCalendarColorForPage($color, $id = null)
  {
      if(is_null($id))
      {
          global $ID;
          $id = $ID;
      }
      $calid = $this->getCalendarIdForPage($id);
      if($calid === false)
        return false;
      
      $query = "UPDATE calendars SET calendarcolor=".$this->sqlite->quote_string($color).
               " WHERE id=".$this->sqlite->quote_string($calid);
      $res = $this->sqlite->query($query);
      if($res !== false)
      {
        $this->cachedValues['calendarcolor'][$calid] = $color;
        return true;
      }
      return false;
  }
  
  /**
   * Set the calendar name and description for a given page with a given
   * page id.
   * If the calendar doesn't exist, the calendar is created!
   * 
   * @param string  $name The name of the new calendar
   * @param string  $description The description of the new calendar
   * @param string  $id (optional) The ID of the page
   * @param string  $userid The userid of the creating user
   * 
   * @return boolean True on success, otherwise false.
   */
  public function setCalendarNameForPage($name, $description, $id = null, $userid = null)
  {
      if(is_null($id))
      {
          global $ID;
          $id = $ID;
      }
      if(is_null($userid))
        $userid = $_SERVER['REMOTE_USER'];
      $calid = $this->getCalendarIdForPage($id);
      if($calid === false)
        return $this->createCalendarForPage($name, $description, $id, $userid);
      
      $query = "UPDATE calendars SET displayname=".$this->sqlite->quote_string($name).", ".
               "description=".$this->sqlite->quote_string($description)." WHERE ".
               "id=".$this->sqlite->quote_string($calid);
      $res = $this->sqlite->query($query);
      if($res !== false)
        return true;
      return false;
  }
  
  /**
   * Save the personal settings to the SQLite database 'calendarsettings'.
   * 
   * @param array  $settings The settings array to store
   * @param string $userid (optional) The userid to store
   * 
   * @param boolean True on success, otherwise false
   */
  public function savePersonalSettings($settings, $userid = null)
  {
      if(is_null($userid))
          $userid = $_SERVER['REMOTE_USER'];
      $this->sqlite->query("BEGIN TRANSACTION");
      
      $query = "DELETE FROM calendarsettings WHERE userid=".$this->sqlite->quote_string($userid);
      $this->sqlite->query($query);
      
      foreach($settings as $key => $value)
      {
          $query = "INSERT INTO calendarsettings (userid, key, value) VALUES (".
                   $this->sqlite->quote_string($userid).", ".
                   $this->sqlite->quote_string($key).", ".
                   $this->sqlite->quote_string($value).")";
          $res = $this->sqlite->query($query);
          if($res === false)
              return false;
      }
      $this->sqlite->query("COMMIT TRANSACTION");
      $this->cachedValues['settings'][$userid] = $settings;
      return true;
  }
  
  /**
   * Retrieve the settings array for a given user id. 
   * Some sane defaults are returned, currently:
   * 
   *    timezone    => local
   *    weeknumbers => 0
   *    workweek    => 0
   * 
   * @param string $userid (optional) The user id to retrieve
   * 
   * @return array The settings array
   */
  public function getPersonalSettings($userid = null)
  {
      if(is_null($userid))
        $userid = $_SERVER['REMOTE_USER'];
      
      if(isset($this->cachedValues['settings'][$userid]))
        return $this->cachedValues['settings'][$userid];
      // Some sane default settings
      $settings = array(
        'timezone' => $this->getConf('timezone'),
        'weeknumbers' => $this->getConf('weeknumbers'),
        'workweek' => $this->getConf('workweek'),
        'monday' => $this->getConf('monday')
      );
      $query = "SELECT key, value FROM calendarsettings WHERE userid=".$this->sqlite->quote_string($userid);
      $res = $this->sqlite->query($query);
      $arr = $this->sqlite->res2arr($res);
      foreach($arr as $row)
      {
          $settings[$row['key']] = $row['value'];
      }
      $this->cachedValues['settings'][$userid] = $settings;
      return $settings;
  }
  
  /**
   * Retrieve the calendar ID based on a page ID from the SQLite table
   * 'pagetocalendarmapping'. 
   * 
   * @param string $id (optional) The page ID to retrieve the corresponding calendar
   * 
   * @return mixed the ID on success, otherwise false
   */
  public function getCalendarIdForPage($id = null)
  {
      if(is_null($id))
      {
          global $ID;
          $id = $ID;
      }
      
      if(isset($this->cachedValues['calid'][$id]))
        return $this->cachedValues['calid'][$id];
      
      $query = "SELECT calid FROM pagetocalendarmapping WHERE page=".$this->sqlite->quote_string($id);
      $res = $this->sqlite->query($query);
      $row = $this->sqlite->res2row($res);
      if(isset($row['calid']))
      {
        $calid = $row['calid'];
        $this->cachedValues['calid'] = $calid;
        return $calid;
      }
      return false;
  }
  
  /**
   * Retrieve the complete calendar id to page mapping.
   * This is necessary to be able to retrieve a list of
   * calendars for a given user and check the access rights.
   * 
   * @return array The mapping array
   */
  public function getCalendarIdToPageMapping()
  {
      $query = "SELECT calid, page FROM pagetocalendarmapping";
      $res = $this->sqlite->query($query);
      $arr = $this->sqlite->res2arr($res);
      return $arr;
  }
  
  /**
   * Retrieve all calendar IDs a given user has access to.
   * The user is specified by the principalUri, so the
   * user name is actually split from the URI component.
   * 
   * Access rights are checked against DokuWiki's ACL
   * and applied accordingly.
   * 
   * @param string $principalUri The principal URI to work on
   * 
   * @return array An associative array of calendar IDs
   */
  public function getCalendarIdsForUser($principalUri)
  {
      $user = explode('/', $principalUri);
      $user = end($user);
      $mapping = $this->getCalendarIdToPageMapping();
      $calids = array();
      foreach($mapping as $row)
      {
          $id = $row['calid'];
          $page = $row['page'];
          $acl = auth_quickaclcheck($page);
          if($acl >= AUTH_READ)
          {
              $write = $acl > AUTH_READ;
              $calids[$id] = array('readonly' => !$write);
          }
      }
      return $calids;
  }
  
  /**
   * Create a new calendar for a given page ID and set name and description
   * accordingly. Also update the pagetocalendarmapping table on success.
   * 
   * @param string $name The calendar's name
   * @param string $description The calendar's description
   * @param string $id (optional) The page ID to work on
   * @param string $userid (optional) The user ID that created the calendar
   * 
   * @return boolean True on success, otherwise false
   */
  public function createCalendarForPage($name, $description, $id = null, $userid = null)
  {
      if(is_null($id))
      {
          global $ID;
          $id = $ID;
      }
      if(is_null($userid))
          $userid = $_SERVER['REMOTE_USER'];
      $values = array('principals/'.$userid, 
                      $name,
                      str_replace(array('/', ' ', ':'), '_', $id), 
                      $description,
                      'VEVENT,VTODO',
                      0,
                      1);
      $query = "INSERT INTO calendars (principaluri, displayname, uri, description, components, transparent, synctoken) VALUES (".$this->sqlite->quote_and_join($values, ',').");";
      $res = $this->sqlite->query($query);
      if($res === false)
        return false;
      
      // Get the new calendar ID
      $query = "SELECT id FROM calendars WHERE principaluri=".$this->sqlite->quote_string($values[0])." AND ".
               "displayname=".$this->sqlite->quote_string($values[1])." AND ".
               "uri=".$this->sqlite->quote_string($values[2])." AND ".
               "description=".$this->sqlite->quote_string($values[3]);
      $res = $this->sqlite->query($query);
      $row = $this->sqlite->res2row($res);
      
      // Update the pagetocalendarmapping table with the new calendar ID
      if(isset($row['id']))
      {
          $values = array($id, $row['id']);
          $query = "INSERT INTO pagetocalendarmapping (page, calid) VALUES (".$this->sqlite->quote_and_join($values, ',').")";
          $res = $this->sqlite->query($query);
          return ($res !== false);
      }
      
      return false;
  }

  /**
   * Add a new iCal entry for a given page, i.e. a given calendar.
   * 
   * The parameter array needs to contain
   *   timezone         => The timezone of the entries
   *   detectedtz       => The timezone as detected by the browser
   *   eventfrom        => The event's start date
   *   eventfromtime    => The event's start time
   *   eventto          => The event's end date
   *   eventtotime      => The event's end time
   *   eventname        => The event's name
   *   eventdescription => The event's description
   * 
   * @param string $id The page ID to work on
   * @param string $user The user who created the calendar
   * @param string $params A parameter array with values to create
   * 
   * @return boolean True on success, otherwise false
   */
  public function addCalendarEntryToCalendarForPage($id, $user, $params)
  {
      $settings = $this->getPersonalSettings($user);
      if($settings['timezone'] !== '' && $settings['timezone'] !== 'local')
          $timezone = new \DateTimeZone($settings['timezone']);
      elseif($settings['timezone'] === 'local')
          $timezone = new \DateTimeZone($params['detectedtz']);
      else
          $timezone = new \DateTimeZone('UTC');
      
      // Retrieve dates from settings
      $startDate = explode('-', $params['eventfrom']);
      $startTime = explode(':', $params['eventfromtime']);
      $endDate = explode('-', $params['eventto']);
      $endTime = explode(':', $params['eventtotime']);
      
      // Load SabreDAV
      require_once('vendor/autoload.php');
      $vcalendar = new \Sabre\VObject\Component\VCalendar();
      
      // Add VCalendar, UID and Event Name
      $event = $vcalendar->add('VEVENT');
      $uuid = \Sabre\VObject\UUIDUtil::getUUID();
      $event->add('UID', $uuid);
      $event->summary = $params['eventname'];
      
      // Add a description if requested
      $description = $params['eventdescription'];
      if($description !== '')
        $event->add('DESCRIPTION', $description);
      
      // Create a timestamp for last modified, created and dtstamp values in UTC
      $dtStamp = new \DateTime(null, new \DateTimeZone('UTC'));
      $event->add('DTSTAMP', $dtStamp);
      $event->add('CREATED', $dtStamp);
      $event->add('LAST-MODIFIED', $dtStamp);
      
      // Adjust the start date, based on the given timezone information
      $dtStart = new \DateTime();
      $dtStart->setTimezone($timezone);            
      $dtStart->setDate(intval($startDate[0]), intval($startDate[1]), intval($startDate[2]));
      
      // Only add the time values if it's not an allday event
      if($params['allday'] != '1')
        $dtStart->setTime(intval($startTime[0]), intval($startTime[1]), 0);
      
      // Adjust the end date, based on the given timezone information
      $dtEnd = new \DateTime();
      $dtEnd->setTimezone($timezone);      
      $dtEnd->setDate(intval($endDate[0]), intval($endDate[1]), intval($endDate[2]));
      
      // Only add the time values if it's not an allday event
      if($params['allday'] != '1')
        $dtEnd->setTime(intval($endTime[0]), intval($endTime[1]), 0);
      
      // According to the VCal spec, we need to add a whole day here
      if($params['allday'] == '1')
          $dtEnd->add(new \DateInterval('P1D'));
      
      // Really add Start and End events
      $dtStartEv = $event->add('DTSTART', $dtStart);
      $dtEndEv = $event->add('DTEND', $dtEnd);
      
      // Adjust the DATE format for allday events
      if($params['allday'] == '1')
      {
          $dtStartEv['VALUE'] = 'DATE';
          $dtEndEv['VALUE'] = 'DATE';
      }
      
      // Actually add the values to the database
      $calid = $this->getCalendarIdForPage($id);
      $uri = uniqid('dokuwiki-').'.ics';
      $now = new DateTime();
      $eventStr = $vcalendar->serialize();
      
      $values = array($calid,
                      $uri,
                      $eventStr,
                      $now->getTimestamp(),
                      'VEVENT',
                      $event->DTSTART->getDateTime()->getTimeStamp(),
                      $event->DTEND->getDateTime()->getTimeStamp(),
                      strlen($eventStr),
                      md5($eventStr),
                      $uuid
      );
      
      $query = "INSERT INTO calendarobjects (calendarid, uri, calendardata, lastmodified, componenttype, firstoccurence, lastoccurence, size, etag, uid) VALUES (".$this->sqlite->quote_and_join($values, ',').")";
      $res = $this->sqlite->query($query);
      
      // If successfully, update the sync token database
      if($res !== false)
      {
          $this->updateSyncTokenLog($calid, $uri, 'added');
          return true;
      }
      return false;
  }

  /**
   * Retrieve the calendar settings of a given calendar id
   * 
   * @param string $calid The calendar ID
   * 
   * @return array The calendar settings array
   */
  public function getCalendarSettings($calid)
  {
      $query = "SELECT principaluri, calendarcolor, displayname, uri, description, components, transparent, synctoken FROM calendars WHERE id=".$this->sqlite->quote_string($calid);
      $res = $this->sqlite->query($query);
      $row = $this->sqlite->res2row($res);
      return $row;
  }

  /**
   * Retrieve all events that are within a given date range,
   * based on the timezone setting.
   * 
   * There is also support for retrieving recurring events,
   * using Sabre's VObject Iterator. Recurring events are represented
   * as individual calendar entries with the same UID.
   * 
   * @param string $id The page ID to work with
   * @param string $user The user ID to work with
   * @param string $startDate The start date as a string
   * @param string $endDate The end date as a string
   * 
   * @return array An array containing the calendar entries.
   */
  public function getEventsWithinDateRange($id, $user, $startDate, $endDate)
  {
      $settings = $this->getPersonalSettings($user);
      if($settings['timezone'] !== '' && $settings['timezone'] !== 'local')
          $timezone = new \DateTimeZone($settings['timezone']);
      else
          $timezone = new \DateTimeZone('UTC');
      $data = array();
      
      // Load SabreDAV
      require_once('vendor/autoload.php');
      $calid = $this->getCalendarIdForPage($id);
      $color = $this->getCalendarColorForCalendar($calid);
      $startTs = new \DateTime($startDate);
      $endTs = new \DateTime($endDate);
      
      // Retrieve matching calendar objects
      $query = "SELECT calendardata, componenttype, uid FROM calendarobjects WHERE calendarid=".
                $this->sqlite->quote_string($calid)." AND firstoccurence < ".
                $this->sqlite->quote_string($endTs->getTimestamp())." AND lastoccurence > ".
                $this->sqlite->quote_string($startTs->getTimestamp());
      $res = $this->sqlite->query($query);
      $arr = $this->sqlite->res2arr($res);
      
      // Parse individual calendar entries
      foreach($arr as $row)
      {
          if(isset($row['calendardata']))
          {
              $entry = array();
              $vcal = \Sabre\VObject\Reader::read($row['calendardata']);
              $recurrence = $vcal->VEVENT->RRULE;
              // If it is a recurring event, pass it through Sabre's EventIterator
              if($recurrence != null)
              {
                  $rEvents = new \Sabre\VObject\Recur\EventIterator(array($vcal->VEVENT));
                  $rEvents->rewind();
                  $done = false;
                  while($rEvents->valid() && !$done)
                  {
                      $event = $rEvents->getEventObject();
                      // If we are after the given time range, exit
                      if(($rEvents->getDtStart()->getTimestamp() > $endTs->getTimestamp()) &&
                         ($rEvents->getDtEnd()->getTimestamp() > $endTs->getTimestamp()))
                        $done = true;
                        
                      // If we are before the given time range, continue
                      if($rEvents->getDtEnd()->getTimestamp() < $startTs->getTimestamp())
                      {
                          $rEvents->next();
                          continue;
                      }
                      
                      // If we are within the given time range, parse the event
                      $data[] = $this->convertIcalDataToEntry($event, $id, $timezone, $row['uid'], $color, true);
                      $rEvents->next();
                  }
              }
              else
                $data[] = $this->convertIcalDataToEntry($vcal->VEVENT, $id, $timezone, $row['uid'], $color);
          }
      }
      return $data;
  }

  /**
   * Helper function that parses the iCal data of a VEVENT to a calendar entry.
   * 
   * @param \Sabre\VObject\VEvent $event The event to parse
   * @param \DateTimeZone $timezone The timezone object
   * @param string $uid The entry's UID
   * @param boolean $recurring (optional) Set to true to define a recurring event
   * 
   * @return array The parse calendar entry
   */
  private function convertIcalDataToEntry($event, $page, $timezone, $uid, $color, $recurring = false)
  {
      $entry = array();
      $start = $event->DTSTART;
      // Parse only if the start date/time is present
      if($start !== null)
      {
        $dtStart = $start->getDateTime();
        $dtStart->setTimezone($timezone);
        $entry['start'] = $dtStart->format(\DateTime::ATOM);
        if($start['VALUE'] == 'DATE')
          $entry['allDay'] = true;
        else
          $entry['allDay'] = false;
      }
      $end = $event->DTEND;
      // Parse onlyl if the end date/time is present
      if($end !== null)
      {
        $dtEnd = $end->getDateTime();
        $dtEnd->setTimezone($timezone);
        $entry['end'] = $dtEnd->format(\DateTime::ATOM);
      }
      $description = $event->DESCRIPTION;
      if($description !== null)
        $entry['description'] = (string)$description;
      else
        $entry['description'] = '';
      $entry['title'] = (string)$event->summary;
      $entry['id'] = $uid;
      $entry['page'] = $page;
      $entry['color'] = $color;
      $entry['recurring'] = $recurring;
      
      return $entry;
  }
  
  /**
   * Retrieve an event by its UID
   * 
   * @param string $uid The event's UID
   * 
   * @return mixed The table row with the given event
   */
  public function getEventWithUid($uid)
  {
      $query = "SELECT calendardata, calendarid, componenttype, uri FROM calendarobjects WHERE uid=".
                $this->sqlite->quote_string($uid);
      $res = $this->sqlite->query($query);
      $row = $this->sqlite->res2row($res);
      return $row;
  }
  
  /**
   * Retrieve all calendar events for a given calendar ID
   * 
   * @param string $calid The calendar's ID
   * 
   * @return array An array containing all calendar data
   */
  public function getAllCalendarEvents($calid)
  {
      $query = "SELECT calendardata, uid, componenttype, uri FROM calendarobjects WHERE calendarid=".
               $this->sqlite->quote_string($calid);
      $res = $this->sqlite->query($query);
      $arr = $this->sqlite->res2arr($res);
      return $arr;
  }
  
  /**
   * Edit a calendar entry for a page, given by its parameters.
   * The params array has the same format as @see addCalendarEntryForPage
   * 
   * @param string $id The page's ID to work on
   * @param string $user The user's ID to work on
   * @param array $params The parameter array for the edited calendar event
   * 
   * @return boolean True on success, otherwise false
   */
  public function editCalendarEntryForPage($id, $user, $params)
  {
      $settings = $this->getPersonalSettings($user);
      if($settings['timezone'] !== '' && $settings['timezone'] !== 'local')
          $timezone = new \DateTimeZone($settings['timezone']);
      elseif($settings['timezone'] === 'local')
          $timezone = new \DateTimeZone($params['detectedtz']);
      else
          $timezone = new \DateTimeZone('UTC');
          
      // Parse dates
      $startDate = explode('-', $params['eventfrom']);
      $startTime = explode(':', $params['eventfromtime']);
      $endDate = explode('-', $params['eventto']);
      $endTime = explode(':', $params['eventtotime']);
      
      // Retrieve the existing event based on the UID
      $uid = $params['uid'];
      $event = $this->getEventWithUid($uid);
      
      // Load SabreDAV
      require_once('vendor/autoload.php');
      if(!isset($event['calendardata']))
        return false;
      $uri = $event['uri'];
      $calid = $event['calendarid'];
      
      // Parse the existing event
      $vcal = \Sabre\VObject\Reader::read($event['calendardata']);
      $vevent = $vcal->VEVENT;
      
      // Set the new event values
      $vevent->summary = $params['eventname'];
      $dtStamp = new \DateTime(null, new \DateTimeZone('UTC'));
      $description = $params['eventdescription'];
      
      // Remove existing timestamps to overwrite them
      $vevent->remove('DESCRIPTION');      
      $vevent->remove('DTSTAMP');
      $vevent->remove('LAST-MODIFIED');
      
      // Add new time stamps and description
      $vevent->add('DTSTAMP', $dtStamp);
      $vevent->add('LAST-MODIFIED', $dtStamp);
      if($description !== '')
        $vevent->add('DESCRIPTION', $description);
      
      // Setup DTSTART      
      $dtStart = new \DateTime();
      $dtStart->setTimezone($timezone);      
      $dtStart->setDate(intval($startDate[0]), intval($startDate[1]), intval($startDate[2]));
      if($params['allday'] != '1')
        $dtStart->setTime(intval($startTime[0]), intval($startTime[1]), 0);
      
      // Setup DETEND
      $dtEnd = new \DateTime();
      $dtEnd->setTimezone($timezone);      
      $dtEnd->setDate(intval($endDate[0]), intval($endDate[1]), intval($endDate[2]));
      if($params['allday'] != '1')
        $dtEnd->setTime(intval($endTime[0]), intval($endTime[1]), 0);
      
      // According to the VCal spec, we need to add a whole day here
      if($params['allday'] == '1')
          $dtEnd->add(new \DateInterval('P1D'));
      $vevent->remove('DTSTART');
      $vevent->remove('DTEND');
      $dtStartEv = $vevent->add('DTSTART', $dtStart);
      $dtEndEv = $vevent->add('DTEND', $dtEnd);
      
      // Remove the time for allday events
      if($params['allday'] == '1')
      {
          $dtStartEv['VALUE'] = 'DATE';
          $dtEndEv['VALUE'] = 'DATE';
      }
      $now = new DateTime();
      $eventStr = $vcal->serialize();
      
      // Actually write to the database
      $query = "UPDATE calendarobjects SET calendardata=".$this->sqlite->quote_string($eventStr).
               ", lastmodified=".$this->sqlite->quote_string($now->getTimestamp()).
               ", firstoccurence=".$this->sqlite->quote_string($dtStart->getTimestamp()).
               ", lastoccurence=".$this->sqlite->quote_string($dtEnd->getTimestamp()).
               ", size=".strlen($eventStr).
               ", etag=".$this->sqlite->quote_string(md5($eventStr)).
               " WHERE uid=".$this->sqlite->quote_string($uid);
      $res = $this->sqlite->query($query);
      if($res !== false)
      {
          $this->updateSyncTokenLog($calid, $uri, 'modified');
          return true;
      }
      return false;
  }

  /**
   * Delete a calendar entry for a given page. Actually, the event is removed
   * based on the entry's UID, so that page ID is no used.
   * 
   * @param string $id The page's ID (unused)
   * @param array $params The parameter array to work with
   * 
   * @return boolean True
   */
  public function deleteCalendarEntryForPage($id, $params)
  {
      $uid = $params['uid'];
      $event = $this->getEventWithUid($uid);
      $calid = $event['calendarid'];
      $uri = $event['uri'];
      $query = "DELETE FROM calendarobjects WHERE uid=".$this->sqlite->quote_string($uid);
      $res = $this->sqlite->query($query);
      if($res !== false)
      {
          $this->updateSyncTokenLog($calid, $uri, 'deleted');
      }
      return true;
  }
  
  /**
   * Retrieve the current sync token for a calendar
   * 
   * @param string $calid The calendar id
   * 
   * @return mixed The synctoken or false
   */
  public function getSyncTokenForCalendar($calid)
  {
      $row = $this->getCalendarSettings($calid);
      if(isset($row['synctoken']))
          return $row['synctoken'];
      return false;
  }
  
  /**
   * Helper function to convert the operation name to 
   * an operation code as stored in the database
   * 
   * @param string $operationName The operation name
   * 
   * @return mixed The operation code or false
   */
  public function operationNameToOperation($operationName)
  {
      switch($operationName)
      {
          case 'added':
              return 1;
          break;
          case 'modified':
              return 2;
          break;
          case 'deleted':
              return 3;
          break;
      }
      return false;
  }
  
  /**
   * Update the sync token log based on the calendar id and the 
   * operation that was performed.
   * 
   * @param string $calid The calendar ID that was modified
   * @param string $uri The calendar URI that was modified
   * @param string $operation The operation that was performed
   * 
   * @return boolean True on success, otherwise false
   */
  private function updateSyncTokenLog($calid, $uri, $operation)
  {
      $currentToken = $this->getSyncTokenForCalendar($calid);
      $operationCode = $this->operationNameToOperation($operation);
      if(($operationCode === false) || ($currentToken === false))
          return false;
      $values = array($uri,
                      $currentToken,
                      $calid,
                      $operationCode
      );
      $query = "INSERT INTO calendarchanges (uri, synctoken, calendarid, operation) VALUES(".
               $this->sqlite->quote_and_join($values, ',').")";
      $res = $this->sqlite->query($query);
      if($res === false)
        return false;
      $currentToken++;
      $query = "UPDATE calendars SET synctoken=".$this->sqlite->quote_string($currentToken)." WHERE id=".
               $this->sqlite->quote_string($calid);
      $res = $this->sqlite->query($query);
      return ($res !== false);
  }
  
  /**
   * Return the sync URL for a given Page, i.e. a calendar
   * 
   * @param string $id The page's ID
   * @param string $user (optional) The user's ID
   * 
   * @return mixed The sync url or false
   */
  public function getSyncUrlForPage($id, $user = null)
  {
      if(is_null($user))
        $user = $_SERVER['REMOTE_USER'];
      
      $calid = $this->getCalendarIdForPage($id);
      if($calid === false)
        return false;
      
      $calsettings = $this->getCalendarSettings($calid);
      if(!isset($calsettings['uri']))
        return false;
      
      $syncurl = DOKU_URL.'lib/plugins/davcal/calendarserver.php/calendars/'.$user.'/'.$calsettings['uri'];
      return $syncurl; 
  }
  
  /**
   * Return the private calendar's URL for a given page
   * 
   * @param string $id the page ID
   * 
   * @return mixed The private URL or false
   */
  public function getPrivateURLForPage($id)
  {
      $calid = $this->getCalendarIdForPage($id);
      if($calid === false)
        return false;
      
      return $this->getPrivateURLForCalendar($calid);
  }
  
  /**
   * Return the private calendar's URL for a given calendar ID
   * 
   * @param string $calid The calendar's ID
   * 
   * @return mixed The private URL or false
   */
  public function getPrivateURLForCalendar($calid)
  {
      if(isset($this->cachedValues['privateurl'][$calid]))
        return $this->cachedValues['privateurl'][$calid];
      $query = "SELECT url FROM calendartoprivateurlmapping WHERE calid=".$this->sqlite->quote_string($calid);
      $res = $this->sqlite->query($query);
      $row = $this->sqlite->res2row($res);
      if(!isset($row['url']))
      {
          $url = uniqid("dokuwiki-").".ics";
          $values = array(
                $url,
                $calid
          );
          $query = "INSERT INTO calendartoprivateurlmapping (url, calid) VALUES(".
                $this->sqlite->quote_and_join($values, ", ").")";
          $res = $this->sqlite->query($query);
          if($res === false)
            return false;
      }
      else
      {
          $url = $row['url'];
      }
      
      $url = DOKU_URL.'lib/plugins/davcal/ics.php/'.$url;
      $this->cachedValues['privateurl'][$calid] = $url;
      return $url;
  }
  
  /**
   * Retrieve the calendar ID for a given private calendar URL
   * 
   * @param string $url The private URL
   * 
   * @return mixed The calendar ID or false
   */
  public function getCalendarForPrivateURL($url)
  {
      $query = "SELECT calid FROM calendartoprivateurlmapping WHERE url=".$this->sqlite->quote_string($url);
      $res = $this->sqlite->query($query);
      $row = $this->sqlite->res2row($res);
      if(!isset($row['calid']))
        return false;
      return $row['calid'];
  }
  
  /**
   * Return a given calendar as ICS feed, i.e. all events in one ICS file.
   * 
   * @param string $caldi The calendar ID to retrieve
   * 
   * @return mixed The calendar events as string or false
   */
  public function getCalendarAsICSFeed($calid)
  {
      $calSettings = $this->getCalendarSettings($calid);
      if($calSettings === false)
        return false;
      $events = $this->getAllCalendarEvents($calid);
      if($events === false)
        return false;
      
      $out = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//DAVCal//DAVCal for DokuWiki//EN\nCALSCALE:GREGORIAN\nX-WR-CALNAME:";
      $out .= $calSettings['displayname']."\n";
      foreach($events as $event)
      {
          $out .= rtrim($event['calendardata']);
          $out .= "\n";
      }
      $out .= "END:VCALENDAR\n";
      return $out;
  }
  
}
