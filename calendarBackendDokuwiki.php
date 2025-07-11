<?php

use \Sabre\VObject;
use \Sabre\CalDAV;
use \Sabre\DAV;
use \Sabre\DAV\Exception\Forbidden;
/**
 * PDO CalDAV backend for DokuWiki - based on Sabre's CalDAV backend
 *
 * This backend is used to store calendar-data in a PDO database, such as
 * sqlite or MySQL
 *
 * @copyright Copyright (C) 2007-2015 fruux GmbH (https://fruux.com/).
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class DokuWikiSabreCalendarBackend extends \Sabre\CalDAV\Backend\AbstractBackend 
{


    /**
     * DokuWiki PlugIn Helper
     */
    protected $hlp = null;
    /**

    /**
     * List of CalDAV properties, and how they map to database fieldnames
     * Add your own properties by simply adding on to this array.
     *
     * Note that only string-based properties are supported here.
     *
     * @var array
     */
    public $propertyMap = array(
        '{DAV:}displayname'                                   => 'displayname',
        '{urn:ietf:params:xml:ns:caldav}calendar-description' => 'description',
        '{urn:ietf:params:xml:ns:caldav}calendar-timezone'    => 'timezone',
        //'{http://apple.com/ns/ical/}calendar-order'           => 'calendarorder',
        //'{http://apple.com/ns/ical/}calendar-color'           => 'calendarcolor',
    );

    /**
     * Creates the backend
     *
     * @param \PDO $pdo
     */
    function __construct(&$hlp) 
    {

        $this->hlp = $hlp;

    }

    /**
     * Returns a list of calendars for a principal.
     *
     * Every project is an array with the following keys:
     *  * id, a unique id that will be used by other functions to modify the
     *    calendar. This can be the same as the uri or a database key.
     *  * uri. This is just the 'base uri' or 'filename' of the calendar.
     *  * principaluri. The owner of the calendar. Almost always the same as
     *    principalUri passed to this method.
     *
     * Furthermore it can contain webdav properties in clark notation. A very
     * common one is '{DAV:}displayname'.
     *
     * Many clients also require:
     * {urn:ietf:params:xml:ns:caldav}supported-calendar-component-set
     * For this property, you can just return an instance of
     * Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet.
     *
     * If you return {http://sabredav.org/ns}read-only and set the value to 1,
     * ACL will automatically be put in read-only mode.
     *
     * @param string $principalUri
     * @return array
     */
    function getCalendarsForUser($principalUri) 
    {
        \dokuwiki\Logger::debug('DAVCAL', 'getCalendarsForUser called for: '.$principalUri, __FILE__, __LINE__);
        $fields = array_values($this->propertyMap);
        $fields[] = 'id';
        $fields[] = 'uri';
        $fields[] = 'synctoken';
        $fields[] = 'components';
        $fields[] = 'principaluri';
        $fields[] = 'transparent';

        $idInfo = $this->hlp->getCalendarIdsForUser($principalUri);
        $calendars = array();
        foreach($idInfo as $id => $data)
        {
            $row = $this->hlp->getCalendarSettings($id);

            // Skip this calendar if settings couldn't be retrieved
            if (!$row) {
                \dokuwiki\Logger::debug('DAVCAL', 'Skipping calendar '.$id.' - settings not found', __FILE__, __LINE__);
                continue;
            }

            $components = array();
            if ($row['components']) 
            {
                $components = explode(',', $row['components']);
            }

            $calendar = array(
                'id'                                                                 => $row['id'],
                'uri'                                                                => $row['uri'],
                'principaluri'                                                       => $principalUri,//Overwrite principaluri from database, we actually don't need it.
                '{' . CalDAV\Plugin::NS_CALENDARSERVER . '}getctag'                  => 'http://sabre.io/ns/sync/' . ($row['synctoken'] ? $row['synctoken'] : '0'),
                '{http://sabredav.org/ns}sync-token'                                 => $row['synctoken'] ? $row['synctoken'] : '0',
                '{' . CalDAV\Plugin::NS_CALDAV . '}supported-calendar-component-set' => new CalDAV\Xml\Property\SupportedCalendarComponentSet($components),
                //'{' . CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp'         => new CalDAV\Xml\Property\ScheduleCalendarTransp($row['transparent'] ? 'transparent' : 'opaque'),
            );
            if(isset($idInfo[$id]['readonly']) && $idInfo[$id]['readonly'] === true)
                $calendar['{http://sabredav.org/ns}read-only'] = '1';


            foreach ($this->propertyMap as $xmlName => $dbName) 
            {
                $calendar[$xmlName] = $row[$dbName];
            }

            $calendars[] = $calendar;

        }
        \dokuwiki\Logger::debug('DAVCAL', 'Calendars returned: '.count($calendars), __FILE__, __LINE__);
        return $calendars;

    }

    /**
     * Creates a new calendar for a principal.
     *
     * If the creation was a success, an id must be returned that can be used
     * to reference this calendar in other methods, such as updateCalendar.
     *
     * @param string $principalUri
     * @param string $calendarUri
     * @param array $properties
     * @return string
     */
    function createCalendar($principalUri, $calendarUri, array $properties) 
    {
        \dokuwiki\Logger::debug('DAVCAL', 'createCalendar called, returning false', __FILE__, __LINE__);
        return false;
    }

    /**
     * Updates properties for a calendar.
     *
     * The list of mutations is stored in a Sabre\DAV\PropPatch object.
     * To do the actual updates, you must tell this object which properties
     * you're going to process with the handle() method.
     *
     * Calling the handle method is like telling the PropPatch object "I
     * promise I can handle updating this property".
     *
     * Read the PropPatch documenation for more info and examples.
     *
     * @param string $calendarId
     * @param \Sabre\DAV\PropPatch $propPatch
     * @return void
     */
    function updateCalendar($calendarId, \Sabre\DAV\PropPatch $propPatch) 
    {
        \dokuwiki\Logger::debug('DAVCAL', 'updateCalendar for calendarId '.$calendarId, __FILE__, __LINE__);
        $supportedProperties = array_keys($this->propertyMap);

        $propPatch->handle($supportedProperties, function($mutations) use ($calendarId) 
        {
            foreach ($mutations as $propertyName => $propertyValue) 
            {

                switch ($propertyName) 
                {
                    case '{DAV:}displayname' :
                        \dokuwiki\Logger::debug('DAVCAL', 'updateCalendarName', __FILE__, __LINE__);
                        $this->hlp->updateCalendarName($calendarId, $propertyValue);
                        break;
                    case '{urn:ietf:params:xml:ns:caldav}calendar-description':
                        \dokuwiki\Logger::debug('DAVCAL', 'updateCalendarDescription', __FILE__, __LINE__);
                        $this->hlp->updateCalendarDescription($calendarId, $propertyValue);
                        break;
                    case '{urn:ietf:params:xml:ns:caldav}calendar-timezone':
                        \dokuwiki\Logger::debug('DAVCAL', 'updateCalendarTimezone', __FILE__, __LINE__);
                        $this->hlp->updateCalendarTimezone($calendarId, $propertyValue);
                        break;
                    default :
                        break;
                }

            }
            return true;

        });

    }

    /**
     * Delete a calendar and all it's objects
     *
     * @param string $calendarId
     * @return void
     */
    function deleteCalendar($calendarId) 
    {
        \dokuwiki\Logger::debug('DAVCAL', 'deleteCalendar called, returning false', __FILE__, __LINE__);
        return;
    }

    /**
     * Returns all calendar objects within a calendar.
     *
     * Every item contains an array with the following keys:
     *   * calendardata - The iCalendar-compatible calendar data
     *   * uri - a unique key which will be used to construct the uri. This can
     *     be any arbitrary string, but making sure it ends with '.ics' is a
     *     good idea. This is only the basename, or filename, not the full
     *     path.
     *   * lastmodified - a timestamp of the last modification time
     *   * etag - An arbitrary string, surrounded by double-quotes. (e.g.:
     *   '  "abcdef"')
     *   * size - The size of the calendar objects, in bytes.
     *   * component - optional, a string containing the type of object, such
     *     as 'vevent' or 'vtodo'. If specified, this will be used to populate
     *     the Content-Type header.
     *
     * Note that the etag is optional, but it's highly encouraged to return for
     * speed reasons.
     *
     * The calendardata is also optional. If it's not returned
     * 'getCalendarObject' will be called later, which *is* expected to return
     * calendardata.
     *
     * If neither etag or size are specified, the calendardata will be
     * used/fetched to determine these numbers. If both are specified the
     * amount of times this is needed is reduced by a great degree.
     *
     * @param string $calendarId
     * @return array
     */
    function getCalendarObjects($calendarId) 
    {
        \dokuwiki\Logger::debug('DAVCAL', 'getCalendarObjects for calendarId '.$calendarId, __FILE__, __LINE__);
        $arr = $this->hlp->getCalendarObjects($calendarId);
        $result = array();
        foreach ($arr as $row) 
        {
            $result[] = array(
                'id'           => $row['id'],
                'uri'          => $row['uri'],
                'lastmodified' => $row['lastmodified'],
                'etag'         => '"' . $row['etag'] . '"',
                'calendarid'   => $row['calendarid'],
                'size'         => (int)$row['size'],
                'component'    => strtolower($row['componenttype']),
            );
        }
        \dokuwiki\Logger::debug('DAVCAL', 'Calendar objects returned: '.count($result), __FILE__, __LINE__);
        return $result;

    }

    /**
     * Returns information from a single calendar object, based on it's object
     * uri.
     *
     * The object uri is only the basename, or filename and not a full path.
     *
     * The returned array must have the same keys as getCalendarObjects. The
     * 'calendardata' object is required here though, while it's not required
     * for getCalendarObjects.
     *
     * This method must return null if the object did not exist.
     *
     * @param string $calendarId
     * @param string $objectUri
     * @return array|null
     */
    function getCalendarObject($calendarId, $objectUri) 
    {
        \dokuwiki\Logger::debug('DAVCAL', 'getCalendarObject for calendarId '.$calendarId.' and objectUri '.$objectUri, __FILE__, __LINE__);
        $row = $this->hlp->getCalendarObjectByUri($calendarId, $objectUri);
        \dokuwiki\Logger::debug('DAVCAL', 'Calendar object row: '.($row ? 'found' : 'not found'), __FILE__, __LINE__);
        if (!$row) 
            return null;

        return array(
            'id'            => $row['id'],
            'uri'           => $row['uri'],
            'lastmodified'  => $row['lastmodified'],
            'etag'          => '"' . $row['etag'] . '"',
            'calendarid'    => $row['calendarid'],
            'size'          => (int)$row['size'],
            'calendardata'  => $row['calendardata'],
            'component'     => strtolower($row['componenttype']),
         );

    }

    /**
     * Returns a list of calendar objects.
     *
     * This method should work identical to getCalendarObject, but instead
     * return all the calendar objects in the list as an array.
     *
     * If the backend supports this, it may allow for some speed-ups.
     *
     * @param mixed $calendarId
     * @param array $uris
     * @return array
     */
    function getMultipleCalendarObjects($calendarId, array $uris) 
    {
        \dokuwiki\Logger::debug('DAVCAL', 'getMultipleCalendarObjects for calendarId '.$calendarId, __FILE__, __LINE__);
        \dokuwiki\Logger::debug('DAVCAL', 'URIs requested: '.count($uris), __FILE__, __LINE__);
        $arr = $this->hlp->getMultipleCalendarObjectsByUri($calendarId, $uris);

        $result = array();
        foreach($arr as $row) 
        {

            $result[] = array(
                'id'           => $row['id'],
                'uri'          => $row['uri'],
                'lastmodified' => $row['lastmodified'],
                'etag'         => '"' . $row['etag'] . '"',
                'calendarid'   => $row['calendarid'],
                'size'         => (int)$row['size'],
                'calendardata' => $row['calendardata'],
                'component'    => strtolower($row['componenttype']),
            );

        }
        \dokuwiki\Logger::debug('DAVCAL', 'Multiple calendar objects returned: '.count($result), __FILE__, __LINE__);
        return $result;

    }


    /**
     * Creates a new calendar object.
     *
     * The object uri is only the basename, or filename and not a full path.
     *
     * It is possible return an etag from this function, which will be used in
     * the response to this PUT request. Note that the ETag must be surrounded
     * by double-quotes.
     *
     * However, you should only really return this ETag if you don't mangle the
     * calendar-data. If the result of a subsequent GET to this object is not
     * the exact same as this request body, you should omit the ETag.
     *
     * @param mixed $calendarId
     * @param string $objectUri
     * @param string $calendarData
     * @return string|null
     */
    function createCalendarObject($calendarId, $objectUri, $calendarData) 
    {
        \dokuwiki\Logger::debug('DAVCAL', 'createCalendarObject for calendarId '.$calendarId.' and objectUri '.$objectUri, __FILE__, __LINE__);
        \dokuwiki\Logger::debug('DAVCAL', 'Calendar data length: '.strlen($calendarData), __FILE__, __LINE__);
        $etag = $this->hlp->addCalendarEntryToCalendarByICS($calendarId, $objectUri, $calendarData);
        \dokuwiki\Logger::debug('DAVCAL', 'ETag generated: '.$etag, __FILE__, __LINE__);
        
        return '"' . $etag . '"';
    }

    /**
     * Updates an existing calendarobject, based on it's uri.
     *
     * The object uri is only the basename, or filename and not a full path.
     *
     * It is possible return an etag from this function, which will be used in
     * the response to this PUT request. Note that the ETag must be surrounded
     * by double-quotes.
     *
     * However, you should only really return this ETag if you don't mangle the
     * calendar-data. If the result of a subsequent GET to this object is not
     * the exact same as this request body, you should omit the ETag.
     *
     * @param mixed $calendarId
     * @param string $objectUri
     * @param string $calendarData
     * @return string|null
     */
    function updateCalendarObject($calendarId, $objectUri, $calendarData) 
    {
        \dokuwiki\Logger::debug('DAVCAL', 'updateCalendarObject for calendarId '.$calendarId.' and objectUri '.$objectUri, __FILE__, __LINE__);
        \dokuwiki\Logger::debug('DAVCAL', 'Calendar data length: '.strlen($calendarData), __FILE__, __LINE__);
        $etag = $this->hlp->editCalendarEntryToCalendarByICS($calendarId, $objectUri, $calendarData);
        \dokuwiki\Logger::debug('DAVCAL', 'ETag generated: '.$etag, __FILE__, __LINE__);
        return '"' . $etag. '"';

    }



    /**
     * Deletes an existing calendar object.
     *
     * The object uri is only the basename, or filename and not a full path.
     *
     * @param string $calendarId
     * @param string $objectUri
     * @return void
     */
    function deleteCalendarObject($calendarId, $objectUri) 
    {
        \dokuwiki\Logger::debug('DAVCAL', 'deleteCalendarObject for calendarId '.$calendarId.' and objectUri '.$objectUri, __FILE__, __LINE__);
        $this->hlp->deleteCalendarEntryForCalendarByUri($calendarId, $objectUri);

    }

    /**
     * Performs a calendar-query on the contents of this calendar.
     *
     * The calendar-query is defined in RFC4791 : CalDAV. Using the
     * calendar-query it is possible for a client to request a specific set of
     * object, based on contents of iCalendar properties, date-ranges and
     * iCalendar component types (VTODO, VEVENT).
     *
     * This method should just return a list of (relative) urls that match this
     * query.
     *
     * The list of filters are specified as an array. The exact array is
     * documented by \Sabre\CalDAV\CalendarQueryParser.
     *
     * Note that it is extremely likely that getCalendarObject for every path
     * returned from this method will be called almost immediately after. You
     * may want to anticipate this to speed up these requests.
     *
     * This method provides a default implementation, which parses *all* the
     * iCalendar objects in the specified calendar.
     *
     * This default may well be good enough for personal use, and calendars
     * that aren't very large. But if you anticipate high usage, big calendars
     * or high loads, you are strongly adviced to optimize certain paths.
     *
     * The best way to do so is override this method and to optimize
     * specifically for 'common filters'.
     *
     * Requests that are extremely common are:
     *   * requests for just VEVENTS
     *   * requests for just VTODO
     *   * requests with a time-range-filter on a VEVENT.
     *
     * ..and combinations of these requests. It may not be worth it to try to
     * handle every possible situation and just rely on the (relatively
     * easy to use) CalendarQueryValidator to handle the rest.
     *
     * Note that especially time-range-filters may be difficult to parse. A
     * time-range filter specified on a VEVENT must for instance also handle
     * recurrence rules correctly.
     * A good example of how to interprete all these filters can also simply
     * be found in \Sabre\CalDAV\CalendarQueryFilter. This class is as correct
     * as possible, so it gives you a good idea on what type of stuff you need
     * to think of.
     *
     * This specific implementation (for the PDO) backend optimizes filters on
     * specific components, and VEVENT time-ranges.
     *
     * @param string $calendarId
     * @param array $filters
     * @return array
     */
    function calendarQuery($calendarId, array $filters) 
    {
        \dokuwiki\Logger::debug('DAVCAL', 'calendarQuery for calendarId '.$calendarId, __FILE__, __LINE__);
        \dokuwiki\Logger::debug('DAVCAL', 'Filters count: '.count($filters), __FILE__, __LINE__);
        $result = $this->hlp->calendarQuery($calendarId, $filters);
        \dokuwiki\Logger::debug('DAVCAL', 'Query results: '.count($result), __FILE__, __LINE__);
        return $result;
    }

    /**
     * Searches through all of a users calendars and calendar objects to find
     * an object with a specific UID.
     *
     * This method should return the path to this object, relative to the
     * calendar home, so this path usually only contains two parts:
     *
     * calendarpath/objectpath.ics
     *
     * If the uid is not found, return null.
     *
     * This method should only consider * objects that the principal owns, so
     * any calendars owned by other principals that also appear in this
     * collection should be ignored.
     *
     * @param string $principalUri
     * @param string $uid
     * @return string|null
     */
    function getCalendarObjectByUID($principalUri, $uid) 
    {
        \dokuwiki\Logger::debug('DAVCAL', 'getCalendarObjectByUID for principalUri '.$principalUri.' and uid '.$uid, __FILE__, __LINE__);
        $calids = array_keys($this->hlp->getCalendarIsForUser($principalUri));
        $event = $this->hlp->getEventWithUid($uid);
        
        if(in_array($event['calendarid'], $calids))
        {
            $settings = $this->hlp->getCalendarSettings($event['calendarid']);
            return $settings['uri'] . '/' . $event['uri'];
        }
        return null;
    }

    /**
     * The getChanges method returns all the changes that have happened, since
     * the specified syncToken in the specified calendar.
     *
     * This function should return an array, such as the following:
     *
     * [
     *   'syncToken' => 'The current synctoken',
     *   'added'   => [
     *      'new.txt',
     *   ],
     *   'modified'   => [
     *      'modified.txt',
     *   ],
     *   'deleted' => [
     *      'foo.php.bak',
     *      'old.txt'
     *   ]
     * ];
     *
     * The returned syncToken property should reflect the *current* syncToken
     * of the calendar, as reported in the {http://sabredav.org/ns}sync-token
     * property this is needed here too, to ensure the operation is atomic.
     *
     * If the $syncToken argument is specified as null, this is an initial
     * sync, and all members should be reported.
     *
     * The modified property is an array of nodenames that have changed since
     * the last token.
     *
     * The deleted property is an array with nodenames, that have been deleted
     * from collection.
     *
     * The $syncLevel argument is basically the 'depth' of the report. If it's
     * 1, you only have to report changes that happened only directly in
     * immediate descendants. If it's 2, it should also include changes from
     * the nodes below the child collections. (grandchildren)
     *
     * The $limit argument allows a client to specify how many results should
     * be returned at most. If the limit is not specified, it should be treated
     * as infinite.
     *
     * If the limit (infinite or not) is higher than you're willing to return,
     * you should throw a Sabre\DAV\Exception\TooMuchMatches() exception.
     *
     * If the syncToken is expired (due to data cleanup) or unknown, you must
     * return null.
     *
     * The limit is 'suggestive'. You are free to ignore it.
     *
     * @param string $calendarId
     * @param string $syncToken
     * @param int $syncLevel
     * @param int $limit
     * @return array
     */
    function getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit = null) 
    {
        \dokuwiki\Logger::debug('DAVCAL', 'getChangesForCalendar for calendarId '.$calendarId.' and syncToken '.$syncToken.' and syncLevel '.$syncLevel, __FILE__, __LINE__);
        $result = $this->hlp->getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit);
        \dokuwiki\Logger::debug('DAVCAL', 'Changes result: '.($result ? 'found' : 'not found'), __FILE__, __LINE__);
        return $result;
    }

    /**
     * Returns a list of subscriptions for a principal.
     *
     * Every subscription is an array with the following keys:
     *  * id, a unique id that will be used by other functions to modify the
     *    subscription. This can be the same as the uri or a database key.
     *  * uri. This is just the 'base uri' or 'filename' of the subscription.
     *  * principaluri. The owner of the subscription. Almost always the same as
     *    principalUri passed to this method.
     *  * source. Url to the actual feed
     *
     * Furthermore, all the subscription info must be returned too:
     *
     * 1. {DAV:}displayname
     * 2. {http://apple.com/ns/ical/}refreshrate
     * 3. {http://calendarserver.org/ns/}subscribed-strip-todos (omit if todos
     *    should not be stripped).
     * 4. {http://calendarserver.org/ns/}subscribed-strip-alarms (omit if alarms
     *    should not be stripped).
     * 5. {http://calendarserver.org/ns/}subscribed-strip-attachments (omit if
     *    attachments should not be stripped).
     * 7. {http://apple.com/ns/ical/}calendar-color
     * 8. {http://apple.com/ns/ical/}calendar-order
     * 9. {urn:ietf:params:xml:ns:caldav}supported-calendar-component-set
     *    (should just be an instance of
     *    Sabre\CalDAV\Property\SupportedCalendarComponentSet, with a bunch of
     *    default components).
     *
     * @param string $principalUri
     * @return array
     */
    function getSubscriptionsForUser($principalUri) 
    {
        \dokuwiki\Logger::debug('DAVCAL', 'getSubscriptionsForUser with principalUri '.$principalUri.', returning empty array()', __FILE__, __LINE__);
        return array();

    }

    /**
     * Creates a new subscription for a principal.
     *
     * If the creation was a success, an id must be returned that can be used to reference
     * this subscription in other methods, such as updateSubscription.
     *
     * @param string $principalUri
     * @param string $uri
     * @param array $properties
     * @return mixed
     */
    function createSubscription($principalUri, $uri, array $properties) 
    {
        \dokuwiki\Logger::debug('DAVCAL', 'createSubscription for principalUri '.$principalUri.' and uri '.$uri.', returning null', __FILE__, __LINE__);
        return null;

    }

    /**
     * Updates a subscription
     *
     * The list of mutations is stored in a Sabre\DAV\PropPatch object.
     * To do the actual updates, you must tell this object which properties
     * you're going to process with the handle() method.
     *
     * Calling the handle method is like telling the PropPatch object "I
     * promise I can handle updating this property".
     *
     * Read the PropPatch documenation for more info and examples.
     *
     * @param mixed $subscriptionId
     * @param \Sabre\DAV\PropPatch $propPatch
     * @return void
     */
    function updateSubscription($subscriptionId, DAV\PropPatch $propPatch) 
    {
        \dokuwiki\Logger::debug('DAVCAL', 'updateSubscription with subscriptionId '.$subscriptionId.', returning false', __FILE__, __LINE__);
        return;
    }

    /**
     * Deletes a subscription
     *
     * @param mixed $subscriptionId
     * @return void
     */
    function deleteSubscription($subscriptionId) 
    {
        \dokuwiki\Logger::debug('DAVCAL', 'deleteSubscription with subscriptionId '.$subscriptionId.', returning', __FILE__, __LINE__);
        return;

    }

    /**
     * Returns a single scheduling object.
     *
     * The returned array should contain the following elements:
     *   * uri - A unique basename for the object. This will be used to
     *           construct a full uri.
     *   * calendardata - The iCalendar object
     *   * lastmodified - The last modification date. Can be an int for a unix
     *                    timestamp, or a PHP DateTime object.
     *   * etag - A unique token that must change if the object changed.
     *   * size - The size of the object, in bytes.
     *
     * @param string $principalUri
     * @param string $objectUri
     * @return array
     */
    function getSchedulingObject($principalUri, $objectUri) 
    {
        \dokuwiki\Logger::debug('DAVCAL', 'getSchedulingObject with principalUri '.$principalUri.' and objectUri '.$objectUri.', returning null', __FILE__, __LINE__);
        return null;

    }

    /**
     * Returns all scheduling objects for the inbox collection.
     *
     * These objects should be returned as an array. Every item in the array
     * should follow the same structure as returned from getSchedulingObject.
     *
     * The main difference is that 'calendardata' is optional.
     *
     * @param string $principalUri
     * @return array
     */
    function getSchedulingObjects($principalUri) 
    {
        \dokuwiki\Logger::debug('DAVCAL', 'getSchedulingObjects for principalUri '.$principalUri.', returning null', __FILE__, __LINE__);
        return null;

    }

    /**
     * Deletes a scheduling object
     *
     * @param string $principalUri
     * @param string $objectUri
     * @return void
     */
    function deleteSchedulingObject($principalUri, $objectUri) 
    {
        \dokuwiki\Logger::debug('DAVCAL', 'deleteSchedulingObject for principalUri '.$principalUri.' and objectUri '.$objectUri.', returning', __FILE__, __LINE__);
        return;
    }

    /**
     * Creates a new scheduling object. This should land in a users' inbox.
     *
     * @param string $principalUri
     * @param string $objectUri
     * @param string $objectData
     * @return void
     */
    function createSchedulingObject($principalUri, $objectUri, $objectData) 
    {
        \dokuwiki\Logger::debug('DAVCAL', 'createSchedulingObject with principalUri '.$principalUri.' and objectUri '.$objectUri.', returning', __FILE__, __LINE__);
        return;

    }

}
