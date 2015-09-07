<?php

if(!defined('DOKU_INC')) define('DOKU_INC', dirname(__FILE__).'/../../../');
if (!defined('DOKU_DISABLE_GZIP_OUTPUT')) define('DOKU_DISABLE_GZIP_OUTPUT', 1);
require_once(DOKU_INC.'inc/init.php');
session_write_close(); //close session

require_once (DOKU_INC.'inc/fetch.functions.php');

global $conf;

$baseUri = DOKU_BASE.'lib/plugins/davcal/'.basename(__FILE__).'/';
$sqlFile = $conf['metadir'].'/davcal.sqlite3';

if(!file_exists($sqlFile))
{
    die('SQL File doesn\'t exist');
}

/*

CalendarServer example

This server features CalDAV support

*/

// settings
// date_default_timezone_set('Canada/Eastern');

// If you want to run the SabreDAV server in a custom location (using mod_rewrite for instance)
// You can override the baseUri here.
// $baseUri = '/';

/* Database */
$pdo = new PDO('sqlite:'.$sqlFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

//Mapping PHP errors to exceptions
function exception_error_handler($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}
//set_error_handler("exception_error_handler");

// Files we need
require_once 'vendor/autoload.php';
require_once('authBackendDokuwiki.php');
require_once('principalBackendDokuwiki.php');
require_once('calendarBackendDokuwiki.php');

// Backends
$authBackend = new DokuWikiSabreAuthBackend();
$calendarBackend = new DokuWikiSabreCalendarBackend($pdo); //Sabre\CalDAV\Backend\PDO($pdo);
$principalBackend = new DokuWikiSabrePrincipalBackend();

// Directory structure
$tree = [
    new Sabre\CalDAV\Principal\Collection($principalBackend),
    new Sabre\CalDAV\CalendarRoot($principalBackend, $calendarBackend),
];

$server = new Sabre\DAV\Server($tree);

if (isset($baseUri))
    $server->setBaseUri($baseUri);

/* Server Plugins */
$authPlugin = new Sabre\DAV\Auth\Plugin($authBackend);
$server->addPlugin($authPlugin);

$aclPlugin = new Sabre\DAVACL\Plugin();
$server->addPlugin($aclPlugin);

/* CalDAV support */
$caldavPlugin = new Sabre\CalDAV\Plugin();
$server->addPlugin($caldavPlugin);

/* Calendar subscription support */
//$server->addPlugin(
//    new Sabre\CalDAV\Subscriptions\Plugin()
//);

/* Calendar scheduling support */
//$server->addPlugin(
//    new Sabre\CalDAV\Schedule\Plugin()
//);

/* WebDAV-Sync plugin */
$server->addPlugin(new Sabre\DAV\Sync\Plugin());

// Support for html frontend
$browser = new Sabre\DAV\Browser\Plugin();
$server->addPlugin($browser);

// And off we go!
$server->exec();
