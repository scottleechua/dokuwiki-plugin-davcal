<?php

/**
 * DoukWiki DAVCal PlugIn - ICS support server
 */

if(!defined('DOKU_INC')) define('DOKU_INC', dirname(__FILE__).'/../../../');
if (!defined('DOKU_DISABLE_GZIP_OUTPUT')) define('DOKU_DISABLE_GZIP_OUTPUT', 1);
require_once(DOKU_INC.'inc/init.php');
session_write_close(); //close session

global $conf;
if($conf['allowdebug'])
    \dokuwiki\Logger::debug('DAVCAL', 'ICS server initialized', __FILE__, __LINE__);

$path = explode('/', $_SERVER['REQUEST_URI']);
$icsFile = end($path);

// Load the helper plugin
$hlp = null;
$hlp =& plugin_load('helper', 'davcal');

if(is_null($hlp))
{
    if($conf['allowdebug'])
        \dokuwiki\Logger::error('DAVCAL', 'Error loading helper plugin', __FILE__, __LINE__);
    die('Error loading helper plugin');
}

if($hlp->getConfig('disable_ics') === 1)
{
    if($conf['allowdebug'])
        \dokuwiki\Logger::debug('DAVCAL', 'ICS synchronisation is disabled', __FILE__, __LINE__);
    die("ICS synchronisation is disabled");
}

// Check if this is an aggregated calendar URL
if(strpos($icsFile, 'dokuwiki-aggregated-') === 0)
{
    // This is an aggregated calendar - handle it specially
    $stream = $hlp->getAggregatedCalendarAsICSFeed($icsFile);
    if($stream === false)
    {
        if($conf['allowdebug'])
            \dokuwiki\Logger::error('DAVCAL', 'No aggregated calendar with this name known: '.$icsFile, __FILE__, __LINE__);
        die("No aggregated calendar with this name known.");
    }
}
else
{
    // Regular single calendar
    // Retrieve calendar ID based on private URI
    $calid = $hlp->getCalendarForPrivateURL($icsFile);

    if($calid === false)
    {
        if($conf['allowdebug'])
            \dokuwiki\Logger::error('DAVCAL', 'No calendar with this name known: '.$icsFile, __FILE__, __LINE__);
        die("No calendar with this name known.");
    }

    // Retrieve calendar contents and serve
    $stream = $hlp->getCalendarAsICSFeed($calid);
}

header("Content-Type: text/calendar");
header("Content-Transfer-Encoding: Binary");
header("Content-disposition: attachment; filename=\"calendar.ics\"");
echo $stream;