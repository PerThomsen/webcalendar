<?php
/**
 * Description:
 * This script is intended to be used outside of normal WebCalendar use,
 * as an RSS 2.0 feed to a RSS client.
 *
 * You must have "Enable RSS feed" set to "Yes"
 * in both System Settings and in the specific user's Preferences.
 *
 * Simply use the URL of this file as the feed address in the client.
 * For public user access:
 * http://xxxxx/aaa/rss.php
 * For any other user (where "joe" is the user login):
 * http:/xxxxxx/aaa/rss.php?user=joe
 *
 * By default (if you do not edit this file), events
 * will be loaded for either:
 *   - the next 30 days
 *   - the next 10 events
 *
 * Input parameters:
 * You can override settings by changing the URL parameters:
 *   - days: number of days ahead to look for events
 *   - cat_id: specify a category id to filter on
 *   - repeats: output all events including all repeat instances
 *       repeats=0 do not output repeating events (default)
 *       repeats=1 outputs repeating events
 *       repeats=2 outputs repeating events but suppresses display of
 *                 2nd & subsequent occurences of daily events
 *   - user: login name of calendar to display (instead of public user).
 *       You must have the following System Settings configured for this:
 *         Allow viewing other user's calendars: Yes
 *         Public access can view others: Yes
 *   - showdate: put the date and time (if specified in the title
 *       of the item) in the title
 *
 * Security:
 * $RSS_ENABLED must be set true
 * $USER_RSS_ENABLED must be set true unless this is for the public user
 * $USER_REMOTE_ACCESS can be set as follows in pref.php
 *      0 = Public entries only
 *      1 = Public & Confidential entries only
 *      2 = All entries are included in the feed *USE WITH CARE
 *
 * We do not include unapproved events in the RSS feed.
 *
 *
 * TODO
 * Add other RSS 2.0 options such as media.
 * Add <managingEditor>: dan@spam_me.com (Dan Deletekey)
 */

$debug = false;

include_once 'includes/translate.php';
require_once 'includes/classes/WebCalendar.class';
require_once 'includes/classes/Event.class';
require_once 'includes/classes/RptEvent.class';

$WebCalendar = new WebCalendar( __FILE__ );

include 'includes/formvars.php';
include 'includes/functions.php';
include 'includes/config.php';
include 'includes/dbi4php.php';

$WebCalendar->initializeFirstPhase();

include 'includes/' . $user_inc;

include_once 'includes/validate.php';
include 'includes/site_extras.php';

include_once 'includes/xcal.php';

$WebCalendar->initializeSecondPhase();

load_global_settings();

$WebCalendar->setLanguage();

if ( empty ( $RSS_ENABLED ) || $RSS_ENABLED != 'Y' ) {
  header ( 'Content-Type: text/plain' );
  echo print_not_auth();
  exit;
}
/* Configurable settings for this file. You may change the settings below to
 * change the default settings. These settings will likely move into the System
 * Settings in the web admin interface in a future release.
 */

// Show the date in the title and how to format it.
$date_in_title = false; //Can override with "rss.php?showdate=1|true".
$showdate = getValue ( 'showdate' );
if ( ! empty ( $showdate ) )
  $date_in_title = ( $showdate == 'true' || $showdate == 1 ? true : false );

$date_format = 'M jS'; //Aug 10th, 8/10
$time_format = 'g:ia'; //4:30pm, 16:30
$time_separator = ', '; //Aug 10th @ 4:30pm, Aug 10th, 4:30pm

// Default time window of events to load.
// Can override with "rss.php?days=60".
$numDays = 30;

// Max number of events to display.
// Can override with "rss.php?max=20".
$maxEvents = 10;

// Login of calendar user to use.
// '__public__' is the login name for the public user.
$username = '__public__';

// Allow the URL to override the user setting such as "rss.php?user=craig".
$allow_user_override = true;

// Load layers.
$load_layers = false;

// Load just a specified category (by its id).
// Leave blank to not filter on category (unless specified in URL).
// Can override in URL with "rss.php?cat_id=4".
$cat_id = '';

// Load all repeating events.
// Can override with "rss.php?repeats=1".
$allow_repeats = false;

// Load show only first occurence within the given time span of daily repeating events.
// Can override with "rss.php?repeats=2".
$show_daily_events_only_once = false;

// End configurable settings...

// Set for use elsewhere as a global.
$login = $username;

if ( $allow_user_override ) {
  $u = getValue ( 'user', '[A-Za-z0-9_\.=@,\-]+', true );
  if ( ! empty ( $u ) ) {
    if ( $u == 'public' )
      $u = '__public__';
    // We also set $login since some functions assume that it is set..
    $login = $username = $u;
  }
}

load_user_preferences();

  // public entries only
  $allow_access = ['P'];

// .
// Determine what remote access has been set up by user.
// This will only be used if $username is not __public__.
if ( isset ( $USER_REMOTE_ACCESS ) && $username != '__public__' ) {
  if ( $USER_REMOTE_ACCESS > 0 ) // plus confidential
    $allow_access[] = 'C';

  if ( $USER_REMOTE_ACCESS == 2 ) // plus private
    $allow_access[] = 'R';
}
user_load_variables ( $login, 'rss_' );
$creator = ( $username == '__public__' ) ? 'Public' : $rss_fullname;

if ( $username != '__public__' &&
  ( empty ( $USER_RSS_ENABLED ) || $USER_RSS_ENABLED != 'Y' ) ) {
  header ( 'Content-Type: text/plain' );
  echo print_not_auth();
  exit;
}

$cat_id = '';
if ( $CATEGORIES_ENABLED == 'Y' ) {
  $x = getValue ( 'cat_id', '-?[0-9]+', true );
  if ( ! empty ( $x ) ) {
    load_user_categories();
    $cat_id = $x;
    $category = $categories[$cat_id]['cat_name'];
  }
}

if ( $load_layers )
  load_user_layers ( $username );

// Calculate date range.
$date = getValue ( 'date', '-?[0-9]+', true );
if ( empty ( $date ) || strlen ( $date ) != 8 )
  // If no date specified, start with today.
  $date = date ( 'Ymd' );

$thisyear = substr ( $date, 0, 4 );
$thismonth = substr ( $date, 4, 2 );
$thisday = substr ( $date, 6, 2 );

$startTime = mktime ( 0, 0, 0, $thismonth, $thisday, $thisyear );

$x = getValue ( 'days', '-?[0-9]+', true );
if ( ! empty ( $x ) )
  $numDays = $x;

// Don't let a malicious user specify more than 365 days.
if ( $numDays > 365 )
  $numDays = 365;

$x = getValue ( 'max', '-?[0-9]+', true );
if ( ! empty ( $x ) )
  $maxEvents = $x;

// Don't let a malicious user specify more than 100 events.
if ( $maxEvents > 100 )
  $maxEvents = 100;

$x = getValue ( 'repeats', '-?[0-9]+', true );
if ( ! empty ( $x ) ) {
  $allow_repeats = $x;
  if ( $x == 2 )
    $show_daily_events_only_once = true;
}

$endTime = mktime ( 0, 0, 0, $thismonth, $thisday + $numDays -1, $thisyear );
$endDate = date ( 'Ymd', $endTime );

/* Pre-Load the repeated events for quicker access */
if ( $allow_repeats == true )
  $repeated_events = read_repeated_events ( $username, $startTime, $endTime, $cat_id );

/* Pre-load the non-repeating events for quicker access */
$events = read_events ( $username, $startTime, $endTime, $cat_id );

$charset = ( empty ( $LANGUAGE ) ? 'iso-8859-1' : translate ( 'charset' ) );
// This should work ok with RSS, may need to hardcode fallback value.
$lang = languageToAbbrev ( $LANGUAGE == 'Browser-defined' || $LANGUAGE == 'none'
  ? $lang : $LANGUAGE );
if ( $lang == 'en' )
  $lang = 'en-us'; //the RSS 2.0 default.

$appStr = generate_application_name();

// header ( 'Content-type: application/rss+xml');
header ( 'Content-type: text/xml' );
echo '<?xml version="1.0" encoding="' . $charset . '"?>
<?xml-stylesheet type="text/css" href="rss-style.css" ?>
<rss version="2.0" xml:lang="' . $lang . '">
  <channel>
    <title><![CDATA[' . $appStr . ']]></title>
    <link>' . $SERVER_URL . '</link>
    <description><![CDATA[' . $appStr . ']]></description>
    <language>' . $lang . '</language>
    <generator>:"http://www.k5n.us/webcalendar.php?v=' . $PROGRAM_VERSION
 . '"</generator>
    <image>
      <title><![CDATA[' . $appStr . ']]></title>
      <link>' . $SERVER_URL . '</link>
      <url>http://www.k5n.us/k5n_small.gif</url>
    </image>';

$endtimeYmd = date ( 'Ymd', $endTime );
$numEvents = 0;
$reventIds = [];
for ( $i = $startTime; date ( 'Ymd', $i ) <= $endtimeYmd && $numEvents < $maxEvents;
  $i += 86400 ) {
  $d = date ( 'Ymd', $i );
  $eventIds = [];
  $pubDate = gmdate ( 'D, d M Y', $i );

  $entries = get_entries ( $d, false );
  $rentries = get_repeating_entries ( $username, $d );
  $entrycnt = count ( $entries );
  $rentrycnt = count ( $rentries );
  if ( $debug )
    echo '

countentries==' . $entrycnt . ' ' . $rentrycnt . '

';

  if ( $entrycnt > 0 || $rentrycnt > 0 ) {
    for ( $j = 0; $j < $entrycnt && $numEvents < $maxEvents; $j++ ) {
      // Prevent non-Public events from feeding
      if ( in_array ( $entries[$j]->getAccess(), $allow_access ) ) {
        $eventIds[] = $entries[$j]->getID();
        $unixtime = $entries[$j]->getDateTimeTS();
        $dateinfo = ( $date_in_title ? date( $date_format, $unixtime )
          . ( $entries[$j]->isTimed() ? $time_separator
          . date( $time_format, $unixtime ) : '' ) . ' ' : '' );

        echo '
    <item>
      <title><![CDATA[' . $dateinfo . $entries[$j]->getName() . ']]></title>
      <link>' . $SERVER_URL . 'view_entry.php?id=' . $entries[$j]->getID()
         . '&amp;friendly=1&amp;rssuser=' . $login . '&amp;date=' . $d . '</link>
      <description><![CDATA[' . $entries[$j]->getDescription() . ']]></description>'
         . ( empty ( $category ) ? '' : '
      <category><![CDATA[' . $category . ']]></category>' )
        // . '<creator><![CDATA[' . $creator . ']]></creator>'
        /* RSS 2.0 date format Wed, 02 Oct 2002 13:00:00 GMT */. '
      <pubDate>' . gmdate ( 'D, d M Y H:i:s', $unixtime ) . ' GMT</pubDate>
      <guid>' . $SERVER_URL . 'view_entry.php?id=' . $entries[$j]->getID()
         . '&amp;friendly=1&amp;rssuser=' . $login . '&amp;date=' . $d . '</guid>
    </item>';
        $numEvents++;
      }
    }
    for ( $j = 0; $j < $rentrycnt && $numEvents < $maxEvents; $j++ ) {
      // To allow repeated daily entries to be suppressed. Step below is
      // necessary because 1st occurence of repeating events shows up in
      // $entries AND $rentries & we suppress display of it in $rentries.
      if ( in_array ( $rentries[$j]->getID(),
            $eventIds ) && $rentries[$j]->getrepeatType() == 'daily' )
        $reventIds[] = $rentries[$j]->getID();

      // Prevent non-Public events from feeding.
      // Prevent a repeating event from displaying if the original event has
      // already been displayed; prevent 2nd & later recurrence of daily events
      // from displaying if that option has been selected.
      if( ! in_array( $rentries[$j]->getID(), $eventIds )
          && ( ! $show_daily_events_only_once
            || ! in_array( $rentries[$j]->getID(), $reventIds ) )
          && ( in_array( $rentries[$j]->getAccess(), $allow_access ) ) ) {

        // Show repeating events only once.
        if ( $rentries[$j]->getrepeatType() == 'daily' )
          $reventIds[] = $rentries[$j]->getID();

        echo '
    <item>';
        $unixtime = $rentries[$j]->getDateTimeTS();
        // Constructing the TS for the current repeating event
        $unixtime = strtotime( date( 'D, d M Y ', $i )
         . date( 'H:i:s', $unixtime ) );
        $dateinfo = ( $date_in_title
          ? date ( $date_format, $unixtime )
           . ( $rentries[$j]->isTimed()
            ? $time_separator . date ( $time_format, $unixtime ) : '' ) . ' '
          : '' );

        echo '
      <title><![CDATA[' . $dateinfo . $rentries[$j]->getName() . ']]></title>
      <link>' . $SERVER_URL . "view_entry.php?id=" . $rentries[$j]->getID()
         . '&amp;friendly=1&amp;rssuser=' . $login . '&amp;date=' . $d . '</link>
      <description><![CDATA[' . $rentries[$j]->getDescription() . ']]></description>'
         . ( empty ( $category ) ? '' : '
      <category><![CDATA[' . $category . ']]></category>' )
        // . '<creator><![CDATA[' . $creator . ']]></creator>'
        . '
      <pubDate>' . gmdate ( 'D, d M Y H:i:s', $unixtime ) . ' GMT</pubDate>
      <guid>' . $SERVER_URL . 'view_entry.php?id=' . $rentries[$j]->getID()
         . '&amp;friendly=1&amp;rssuser=' . $login . '&amp;date=' . $d . '</guid>
    </item>';
        $numEvents++;
      }
    }
  }
}
echo '
  </channel>
</rss>';

// Clear login...just in case.
$login = '';
exit;

?>
