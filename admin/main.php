<?php declare(strict_types=1);

/*
 * You may not change or alter any portion of this comment or credits
 * of supporting developers from this source code or any supporting source code
 * which is considered copyrighted (c) material of the original comment or credit authors.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */

/**
 * @copyright    {@link https://xoops.org/ XOOPS Project}
 * @license      {@link https://www.gnu.org/licenses/gpl-2.0.html GNU GPL 2 or later}
 * @author       Brian Wahoff <ackbarr@xoops.org>
 * @author       Eric Juden <ericj@epcusa.com>
 * @author       XOOPS Development Team
 */

use Xmf\Module\Admin;
use Xmf\Request;
use XoopsModules\Xhelp;

require_once __DIR__ . '/admin_header.php';
xoops_load('XoopsPagenav');
define('MAX_STAFF_RESPONSETIME', 5);
define('MAX_STAFF_CALLSCLOSED', 5);

global $_GET, $xoopsModule;
$module_id = $xoopsModule->getVar('mid');

$helper = Xhelp\Helper::getInstance();

$op = 'default';

if (Request::hasVar('op', 'REQUEST')) {
    $op = $_REQUEST['op'];
}

switch ($op) {
    case 'about':
        about();
        break;
    case 'mailEvents':
        mailEvents();
        break;
    case 'searchMailEvents':
        searchMailEvents();
        break;
    //    case 'blocks':
    //        require_once __DIR__ . '/myblocksadmin.php';
    //        break;
    case 'createdir':
        createdir();
        break;
    case 'setperm':
        setperm();
        break;
    case 'manageFields':
        manageFields();
        break;
    default:
        xhelp_default();
        break;
}

function modifyTicketFields()
{
    //xoops_cp_header();
    //echo "not created yet";
    xoops_cp_footer();
}

/**
 * @param array $mailEvents
 * @param array $mailboxes
 */
function displayEvents(array $mailEvents, array $mailboxes)
{
    echo "<table width='100%' cellspacing='1' class='outer'>";
    if (count($mailEvents) > 0) {
        echo "<tr><th colspan='4'>" . _AM_XHELP_TEXT_MAIL_EVENTS . '</th></tr>';
        echo "<tr class='head'><td>" . _AM_XHELP_TEXT_MAILBOX . '</td>
                              <td>' . _AM_XHELP_TEXT_EVENT_CLASS . '</td>
                              <td>' . _AM_XHELP_TEXT_DESCRIPTION . '</td>
                              <td>' . _AM_XHELP_TEXT_TIME . '</td>
             </tr>';

        $class = 'odd';
        foreach ($mailEvents as $event) {
            echo "<tr class='" . $class . "'><td>" . $mailboxes[$event->getVar('mbox_id')]->getVar('emailaddress') . '</td>
                      <td>' . Xhelp\Utility::getEventClass($event->getVar('event_class')) . '</td>
                      <td>' . $event->getVar('event_desc') . '</td>
                      <td>' . $event->posted() . '</td>
                  </tr>';
            $class = ('odd' === $class) ? 'even' : 'odd';
        }
    } else {
        echo '<tr><th>' . _AM_XHELP_TEXT_MAIL_EVENTS . '</th></tr>';
        echo "<tr><td class='odd'>" . _AM_XHELP_NO_EVENTS . '</td></tr>';
    }
    echo '</table><br>';
    echo "<a href='main.php?op=searchMailEvents'>" . _AM_XHELP_SEARCH_EVENTS . '</a>';
}

function mailEvents()
{
    $helper = Xhelp\Helper::getInstance();
    // Will display the last 50 mail events
    /** @var \XoopsModules\Xhelp\MailEventHandler $mailEventHandler */
    $mailEventHandler = $helper->getHandler('MailEvent');
    /** @var \XoopsModules\Xhelp\DepartmentMailBoxHandler $departmentMailBoxHandler */
    $departmentMailBoxHandler = $helper->getHandler('DepartmentMailBox');
    $mailboxes                = $departmentMailBoxHandler->getObjects(null, true);

    $criteria = new \Criteria('', '');
    $criteria->setLimit(50);
    $criteria->setOrder('DESC');
    $criteria->setSort('posted');
    $mailEvents = $mailEventHandler->getObjects($criteria);

    xoops_cp_header();
    //echo $oAdminButton->renderButtons('mailEvents');
    $adminObject = Admin::getInstance();
    $adminObject->displayNavigation(basename(__FILE__));

    displayEvents($mailEvents, $mailboxes);

    require_once __DIR__ . '/admin_footer.php';
}

function searchMailEvents()
{
    xoops_cp_header();
    $helper = Xhelp\Helper::getInstance();
    //echo $oAdminButton->renderButtons('mailEvents');
    $adminObject = Admin::getInstance();
    $adminObject->displayNavigation(basename(__FILE__));

    if (isset($_POST['searchEvents'])) {
        /** @var \XoopsModules\Xhelp\MailEventHandler $mailEventHandler */
        $mailEventHandler = $helper->getHandler('MailEvent');
        /** @var \XoopsModules\Xhelp\DepartmentMailBoxHandler $departmentMailBoxHandler */
        $departmentMailBoxHandler = $helper->getHandler('DepartmentMailBox');
        $mailboxes                = $departmentMailBoxHandler->getObjects(null, true);

        $begin_date = explode('-', $_POST['begin_date']);
        $end_date   = explode('-', $_POST['end_date']);
        $begin_hour = xhelpChangeHour($_POST['begin_mode'], $_POST['begin_hour']);
        $end_hour   = xhelpChangeHour($_POST['end_mode'], $_POST['end_hour']);

        // Get timestamps to search by
        $begin_time = mktime($begin_hour, Request::getInt('begin_minute', 0, 'POST'), (int)$begin_date[1], (int)$begin_date[2], (int)$begin_date[0]);
        $end_time   = mktime($end_hour, Request::getInt('end_minute', 0, 'POST'), 0, (int)$end_date[1], (int)$end_date[2], (int)$end_date[0]);

        $criteria = new \CriteriaCompo(new \Criteria('posted', (string)$begin_time, '>='));
        $criteria->add(new \Criteria('posted', (string)$end_time, '<='));
        if ('' != $_POST['email']) {
            $email = \Xmf\Request::getString('email', '', 'POST');
            $criteria->add(new \Criteria('emailaddress', "%$email%", 'LIKE', 'd'));
        }
        if ('' != $_POST['description']) {
            $description = \Xmf\Request::getString('description', '', 'POST');
            $criteria->add(new \Criteria('event_desc', "%$description%", 'LIKE'));
        }
        $criteria->setOrder('DESC');
        $criteria->setSort('posted');
        if (isset($email)) {
            $mailEvents = $mailEventHandler->getObjectsJoin($criteria);
        } else {
            $mailEvents = $mailEventHandler->getObjects($criteria);
        }

        displayEvents($mailEvents, $mailboxes);

        require_once __DIR__ . '/admin_footer.php';
    } else {
        $stylePath = XHELP_ASSETS_PATH . '/js/calendar/calendarjs.php';
        require_once $stylePath;
        echo '<link rel="stylesheet" type="text/css" media="all" href="' . $stylePath . '"><!--[if lt IE 7]><script src="iepngfix.js" language="JavaScript" type="text/javascript"></script><![endif]-->';

        echo "<form method='post' action='" . XHELP_ADMIN_URL . "/main.php?op=searchMailEvents'>";

        echo "<table width='100%' cellspacing='1' class='outer'>";
        echo "<tr><th colspan='2'>" . _AM_XHELP_SEARCH_EVENTS . '</th></tr>';
        echo "<tr><td width='20%' class='head'>" . _AM_XHELP_TEXT_MAILBOX . "</td>
                  <td class='even'><input type='text' size='55' name='email' class='formButton'></td></tr>";
        echo "<tr><td class='head'>" . _AM_XHELP_TEXT_DESCRIPTION . "</td>
                  <td class='even'><input type='text' size='55' name='description' class='formButton'></td></tr>";
        echo "<tr><td class='head'>" . _AM_XHELP_SEARCH_BEGINEGINDATE . "</td>
                  <td class='even'><input type='text' name='begin_date' id='begin_date' size='10' maxlength='10' value='" . formatTimestamp(time(), 'mysql') . "'>
                                  <a href='' onclick='return showCalendar(\"begin_date\");'><img src='" . XHELP_IMAGE_URL . "/calendar.png' alt='Calendar image' name='calendar' style='vertical-align:bottom;border:0;background:transparent;'></a>&nbsp;";
        xhelpDrawHourSelect('begin_hour', '12');
        xhelpDrawMinuteSelect('begin_minute');
        xhelpDrawModeSelect('begin_mode');
        echo "<tr><td class='head'>" . _AM_XHELP_SEARCH_ENDDATE . "</td>
                  <td class='even'><input type='text' name='end_date' id='end_date' size='10' maxlength='10' value='" . formatTimestamp(time(), 'mysql') . "'>
                                  <a href='' onclick='return showCalendar(\"end_date\");'><img src='" . XHELP_IMAGE_URL . "/calendar.png' alt='Calendar image' name='calendar' style='vertical-align:bottom;border:0;background:transparent;'></a>&nbsp;";
        xhelpDrawHourSelect('end_hour', '12');
        xhelpDrawMinuteSelect('end_minute');
        xhelpDrawModeSelect('end_mode');
        echo "<tr><td class='foot' colspan='2'><input type='submit' name='searchEvents' value='" . _AM_XHELP_BUTTON_SEARCH . "'></td></tr>";
        echo '</table>';
        echo '</form>';

        require_once __DIR__ . '/admin_footer.php';
    }
}

/**
 * changes hour to am/pm
 *
 * @param int $mode , 1-am, 2-pm
 * @param int $hour hour of the day
 *
 * @return int in 24 hour mode
 */
function xhelpChangeHour(int $mode, int $hour): int
{
    $mode = $mode;
    $hour = $hour;

    if (2 == $mode) {
        $hour += 12;

        return $hour;
    }

    return $hour;
}

/**
 * @param string $name
 * @param string $lSelect
 */
function xhelpDrawHourSelect(string $name, string $lSelect = '-1')
{
    echo "<select name='" . $name . "'>";
    for ($i = 1; $i <= 12; ++$i) {
        if ($lSelect == $i) {
            $selected = 'selected';
        } else {
            $selected = '';
        }
        echo "<option value='" . $i . "'" . $selected . '>' . $i . '</option>';
    }
    echo '</select>';
}

/**
 * @param string $name
 */
function xhelpDrawMinuteSelect(string $name)
{
    $lSum = 0;

    echo "<select name='" . $name . "'>";
    for ($i = 0; $lSum <= 50; ++$i) {
        if (0 == $i) {
            echo "<option value='00' selected>00</option>";
        } else {
            $lSum += 5;
            echo "<option value='" . $lSum . "'>" . $lSum . '</option>';
        }
    }
    echo '</select>';
}

/**
 * @param string $name
 * @param string $sSelect
 */
function xhelpDrawModeSelect(string $name, string $sSelect = 'AM')
{
    echo "<select name='" . $name . "'>";
    if ('AM' === $sSelect) {
        echo "<option value='1' selected>AM</option>";
        echo "<option value='2'>PM</option>";
    } else {
        echo "<option value='1'>AM</option>";
        echo "<option value='2' selected>PM</option>";
    }
}

function xhelp_default()
{
    $helper = Xhelp\Helper::getInstance();

    xoops_cp_header();
    //echo $oAdminButton->renderButtons('index');
    $adminObject = Admin::getInstance();
    $adminObject->displayNavigation(basename(__FILE__));

    $displayName = $helper->getConfig('xhelp_displayName');    // Determines if username or real name is displayed

    $stylePath = XHELP_BASE_URL . '/assets/css/xhelp.css';
    echo '<link rel="stylesheet" type="text/css" media="all" href="' . $stylePath . '"><!--[if if lt IE 7]><script src="iepngfix.js" language="JavaScript" type="text/javascript"></script><![endif]-->';

    global $xoopsUser, $xoopsDB;
    /** @var \XoopsModules\Xhelp\TicketHandler $ticketHandler */
    $ticketHandler = $helper->getHandler('Ticket');
    /** @var \XoopsModules\Xhelp\StatusHandler $statusHandler */
    $statusHandler = $helper->getHandler('Status');

    $criteria = new \Criteria('', '');
    $criteria->setSort('description');
    $criteria->setOrder('ASC');
    $statuses    = $statusHandler->getObjects($criteria);
    $table_class = ['odd', 'even'];
    echo "<table border='0' width='100%'>";
    echo "<tr><td width='50%' valign='top'>";
    echo "<div id='ticketInfo'>";
    echo "<table border='0' width='95%' cellspacing='1' class='outer'>
          <tr><th colspan='2'>" . _AM_XHELP_TEXT_TICKET_INFO . '</th></tr>';
    $class        = 'odd';
    $totalTickets = 0;
    foreach ($statuses as $status) {
        $criteria     = new \Criteria('status', $status->getVar('id'));
        $numTickets   = $ticketHandler->getCount($criteria);
        $totalTickets += $numTickets;

        echo "<tr class='" . $class . "'><td>" . $status->getVar('description') . '</td><td>' . $numTickets . '</td></tr>';
        if ('odd' === $class) {
            $class = 'even';
        } else {
            $class = 'odd';
        }
    }
    echo "<tr class='foot'><td>" . _AM_XHELP_TEXT_TOTAL_TICKETS . '</td><td>' . $totalTickets . '</td></tr>';
    echo '</table></div><br>';

    /** @var \XoopsModules\Xhelp\StaffHandler $staffHandler */
    $staffHandler = $helper->getHandler('Staff');
    /** @var \XoopsModules\Xhelp\ResponseHandler $responseHandler */
    $responseHandler = $helper->getHandler('Response');
    echo "</td><td valign='top'>";    // Outer table
    echo "<div id='timeSpent'>";      // Start inner top-left cell
    echo "<table border='0' width='100%' cellspacing='1' class='outer'>
          <tr><th colspan='2'>" . _AM_XHELP_TEXT_RESPONSE_TIME . '</th></tr>';

    $sql = sprintf('SELECT u.uid, u.uname, u.name, (s.responseTime / s.ticketsResponded) AS AvgResponseTime FROM `%s` u INNER JOIN %s s ON u.uid = s.uid WHERE ticketsResponded > 0 ORDER BY AvgResponseTime', $xoopsDB->prefix('users'), $xoopsDB->prefix('xhelp_staff'));
    $ret = $xoopsDB->query($sql, MAX_STAFF_RESPONSETIME);
    $i   = 0;
    while ([$uid, $uname, $name, $avgResponseTime] = $xoopsDB->fetchRow($ret)) {
        $class = $table_class[$i % 2];
        echo "<tr class='$class'><td>" . Xhelp\Utility::getDisplayName($displayName, $name, $uname) . "</td><td align='right'>" . Xhelp\Utility::formatTime((int)$avgResponseTime) . '</td></tr>';
        ++$i;
    }
    echo '</table></div><br>';              // End inner top-left cell
    echo "</td></tr><tr><td valign='top'>"; // End first, start second cell

    //Get Calls Closed block
    $sql = sprintf('SELECT SUM(callsClosed) FROM `%s`', $xoopsDB->prefix('xhelp_staff'));
    $ret = $xoopsDB->query($sql);
    if ([$totalStaffClosed] = $xoopsDB->fetchRow($ret)) {
        if ($totalStaffClosed) {
            $sql = sprintf('SELECT u.uid, u.uname, u.name, s.callsClosed FROM `%s` u INNER JOIN %s s ON u.uid = s.uid WHERE s.callsClosed > 0 ORDER BY s.callsClosed DESC', $xoopsDB->prefix('users'), $xoopsDB->prefix('xhelp_staff'));
            $ret = $xoopsDB->query($sql, MAX_STAFF_CALLSCLOSED);
            echo "<div id='callsClosed'>";
            echo "<table border='0' width='95%' cellspacing='1' class='outer'>
                  <tr><th colspan='2'>" . _AM_XHELP_TEXT_TOP_CLOSERS . '</th></tr>';
            $i = 0;
            while ([$uid, $uname, $name, $callsClosed] = $xoopsDB->fetchRow($ret)) {
                $class = $table_class[$i % 2];
                echo "<tr class='$class'><td>" . Xhelp\Utility::getDisplayName($displayName, $name, $uname) . "</td><td align='right'>" . $callsClosed . ' (' . round(($callsClosed / $totalStaffClosed) * 100, 2) . '%)</td></tr>';
                ++$i;
            }
            echo '</table></div><br>';     // End inner table top row
            echo "</td><td valign='top'>"; // End top row of outer table

            $sql = sprintf('SELECT u.uid, u.uname, u.name, (s.responseTime / s.ticketsResponded) AS AvgResponseTime FROM `%s` u INNER JOIN %s s ON u.uid = s.uid WHERE ticketsResponded > 0 ORDER BY AvgResponseTime DESC', $xoopsDB->prefix('users'), $xoopsDB->prefix('xhelp_staff'));
            $ret = $xoopsDB->query($sql, MAX_STAFF_RESPONSETIME);
            echo "<div id='leastCallsClosed'>";
            echo "<table border='0' width='100%' cellspacing='1' class='outer'>
                  <tr><th colspan='2'>" . _AM_XHELP_TEXT_RESPONSE_TIME_SLOW . '</th></tr>';
            $i = 0;
            while ([$uid, $uname, $name, $avgResponseTime] = $xoopsDB->fetchRow($ret)) {
                $class = $table_class[$i % 2];
                echo "<tr class='$class'><td>" . Xhelp\Utility::getDisplayName($displayName, $name, $uname) . "</td><td align='right'>" . Xhelp\Utility::formatTime($avgResponseTime) . '</td></tr>';
                ++$i;
            }
            echo '</table></div>';  // End first cell, second row of inner table
        }
    }
    echo '</td></tr></table><br>';   // End second cell, second row of inner table

    $criteria = new \Criteria('state', '2', '<>', 's');
    $criteria->setSort('priority');
    $criteria->setOrder('ASC');
    $criteria->setLimit(10);
    $highPriority     = $ticketHandler->getObjects($criteria);
    $has_highPriority = (count($highPriority) > 0);
    if ($has_highPriority) {
        echo "<div id='highPriority'>";
        echo "<table border='0' width='100%' cellspacing='1' class='outer'>
              <tr><th colspan='8'>" . _AM_XHELP_TEXT_HIGH_PRIORITY . '</th></tr>';
        echo "<tr class='head'><td>"
             . _AM_XHELP_TEXT_PRIORITY
             . '</td><td>'
             . _AM_XHELP_TEXT_ELAPSED
             . '</td><td>'
             . _AM_XHELP_TEXT_STATUS
             . '</td><td>'
             . _AM_XHELP_TEXT_SUBJECT
             . '</td><td>'
             . _AM_XHELP_TEXT_DEPARTMENT
             . '</td><td>'
             . _AM_XHELP_TEXT_OWNER
             . '</td><td>'
             . _AM_XHELP_TEXT_LAST_UPDATED
             . '</td><td>'
             . _AM_XHELP_TEXT_LOGGED_BY
             . '</td></tr>';
        $i = 0;
        foreach ($highPriority as $ticket) {
            if ($ticket->isOverdue()) {
                $class = $table_class[$i % 2] . ' overdue';
            } else {
                $class = $table_class[$i % 2];
            }
            $priority_url = "<img src='" . XHELP_IMAGE_URL . '/priority' . $ticket->getVar('priority') . ".png' alt='" . $ticket->getVar('priority') . "'>";
            $subject_url  = sprintf("<a href='" . XHELP_BASE_URL . '/ticket.php?id=' . $ticket->getVar('id') . "' target='_BLANK'>%s</a>", $ticket->getVar('subject'));
            $dept         = $ticket->getDepartment();
            if ($dept) {
                $dept_url = sprintf("<a href='" . XHELP_BASE_URL . '/index.php?op=staffViewAll&amp;dept=' . $dept->getVar('id') . "' target='_BLANK'>%s</a>", $dept->getVar('department'));
            } else {
                $dept_url = _AM_XHELP_TEXT_NO_DEPT;
            }
            if (0 != $ticket->getVar('ownership')) {
                $owner_url = sprintf("<a href='" . XOOPS_URL . '/userinfo.php?uid=' . $ticket->getVar('uid') . "' target='_BLANK'>%s</a>", Xhelp\Utility::getUsername($ticket->getVar('ownership'), $displayName));
            } else {
                $owner_url = _AM_XHELP_TEXT_NO_OWNER;
            }
            $user_url = sprintf("<a href='" . XOOPS_URL . '/userinfo.php?uid=' . $ticket->getVar('uid') . "' target='_BLANK'>%s</a>", Xhelp\Utility::getUsername($ticket->getVar('uid'), $displayName));
            echo "<tr class='$class'><td>" . $priority_url . '</td>
                         <td>' . $ticket->elapsed() . '</td>
                         <td>' . Xhelp\Utility::getStatus($ticket->getVar('status')) . '</td>
                         <td>' . $subject_url . '</td>
                         <td>' . $dept_url . '</td>
                         <td>' . $owner_url . ' </td>
                         <td>' . $ticket->lastUpdated() . '</td>
                         <td>' . $user_url . '</td>
                     </tr>';
            ++$i;
        }
        echo '</table></div>';
    }

    pathConfiguration();

    require_once __DIR__ . '/admin_footer.php';
}

function pathConfiguration()
{
    global $xoopsModule, $xoopsConfig;

    // Upload and Images Folders

    $paths                              = [];
    $paths[_AM_XHELP_PATH_TICKETATTACH] = XHELP_UPLOAD_PATH;
    $paths[_AM_XHELP_PATH_EMAILTPL]     = XHELP_BASE_PATH . "/language/{$xoopsConfig['language']}";

    echo '<h3>' . _AM_XHELP_PATH_CONFIG . '</h3>';
    echo "<table width='100%' class='outer' cellspacing='1' cellpadding='3' border='0' ><tr>";
    echo "<td class='bg3'><b>" . _AM_XHELP_TEXT_DESCRIPTION . '</b></td>';
    echo "<td class='bg3'><b>" . _AM_XHELP_TEXT_PATH . '</b></td>';
    echo "<td class='bg3' align='center'><b>" . _AM_XHELP_TEXT_STATUS . '</b></td></tr>';

    foreach ($paths as $desc => $path) {
        echo "<tr><td class='odd'>$desc</td>";
        echo "<td class='odd'>$path</td>";
        echo "<td class='even' class='center;'>" . xhelp_admin_getPathStatus($path) . '</td></tr>';
    }

    echo '</table>';
    echo '<br>';

    echo '</div>';
}

function about()
{
    xoops_cp_header();
    //echo $oAdminButton->renderButtons();
    $adminObject = Admin::getInstance();
    $adminObject->displayNavigation(basename(__FILE__));

    require_once XHELP_ADMIN_PATH . '/about.php';
}

function createdir()
{
    $path   = $_GET['path'];
    $res    = xhelp_admin_mkdir($path);
    $helper = Xhelp\Helper::getInstance();

    $msg = $res ? _AM_XHELP_PATH_CREATED : _AM_XHELP_PATH_NOTCREATED;
    $helper->redirect('admin/index.php', 2, $msg . ': ' . $path);
}

function setperm()
{
    $helper = Xhelp\Helper::getInstance();
    $path   = $_GET['path'];
    $res    = xhelp_admin_chmod($path, 0777);
    $msg    = ($res ? _AM_XHELP_PATH_PERMSET : _AM_XHELP_PATH_NOTPERMSET);
    $helper->redirect('admin/index.php', 2, $msg . ': ' . $path);
}
