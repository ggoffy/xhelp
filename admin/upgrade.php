<?php

use XoopsModules\Xhelp;

require_once __DIR__ . '/admin_header.php';
require_once XOOPS_ROOT_PATH . '/class/pagenav.php';

/** @var Xhelp\Helper $helper */
$helper = Xhelp\Helper::getInstance();

global $xoopsModule;
$module_id = $xoopsModule->getVar('mid');

$op = 'default';

if (\Xmf\Request::hasVar('op', 'REQUEST')) {
    $op = $_REQUEST['op'];
}

switch ($op) {
    case 'checkTables':
        checkTables();
        break;
    case 'upgradeDB':
        upgradeDB();
        break;
    default:
        redirect_header(XHELP_BASE_URL . '/admin/index.php');
        break;
}

/**
 * @param $oldName
 * @param $newName
 * @return bool
 */
function _renameTable($oldName, $newName)
{
    global $xoopsDB;
    $qry = _runQuery(sprintf('ALTER TABLE %s RENAME %s', $xoopsDB->prefix($oldName), $xoopsDB->prefix($newName)), sprintf(_AM_XHELP_MSG_RENAME_TABLE, $oldName, $newName), sprintf(_AM_XHELP_MSG_RENAME_TABLE_ERR, $oldName));

    return $qry;
}

/**
 * @param $query
 * @param $goodmsg
 * @param $badmsg
 * @return bool
 */
function _runQuery($query, $goodmsg, $badmsg)
{
    global $xoopsDB;
    $ret = $xoopsDB->query($query);
    if (!$ret) {
        echo "<li class='err'>$badmsg</li>";

        return false;
    }

    echo "<li class='ok'>$goodmsg</li>";

    return true;
}

function checkTables()
{
    global $xoopsModule;
    xoops_cp_header();
    //echo $oAdminButton->renderButtons('');
    $adminObject = \Xmf\Module\Admin::getInstance();
    $adminObject->displayNavigation('upgrade.php?op=checkTables');
    //1. Determine previous release
    if (!Xhelp\Utility::tableExists('xhelp_meta')) {
        $ver = '0.5';
    } else {
        if (!$ver = Xhelp\Utility::getMeta('version')) {
            echo('Unable to determine previous version.');
        }
    }

    $currentVer = round($xoopsModule->getVar('version') / 100, 2);

    printf('<h2>' . _AM_XHELP_CURRENTVER . '</h2>', $currentVer);
    printf('<h2>' . _AM_XHELP_DBVER . '</h2>', $ver);

    if ($ver == $currentVer) {
        //No updates are necessary
        echo '<div>' . _AM_XHELP_DB_NOUPDATE . '</div>';
    } elseif ($ver < $currentVer) {
        //Needs to upgrade
        echo '<div>' . _AM_XHELP_DB_NEEDUPDATE . '</div>';
        echo '<form method="post" action="upgrade.php"><input type="hidden" name="op" value="upgradeDB"><input type="submit" value="' . _AM_XHELP_UPDATE_NOW . "\" onclick='_openProgressWindow();'></form>";
    } else {
        //Tried to downgrade
        echo '<div>' . _AM_XHELP_DB_NEEDINSTALL . '</div>';
    }

    require_once __DIR__ . '/admin_footer.php';
}

echo "<script type='text/javascript'>
function _openProgressWindow()
{
    newwindow = openWithSelfMain('upgradeProgress.php','progress','430','100', true);
}
    </script>";

function upgradeDB()
{
    global $xoopsModule;
    $xoopsDB = \XoopsDatabaseFactory::getDatabaseConnection();
    //1. Determine previous release
    //   *** Update this in sql/mysql.sql for each release **
    if (!Xhelp\Utility::tableExists('xhelp_meta')) {
        $ver = '0.5';
    } else {
        if (!$ver = Xhelp\Utility::getMeta('version')) {
            exit(_AM_XHELP_VERSION_ERR);
        }
    }

    $staffHandler  = new Xhelp\StaffHandler($GLOBALS['xoopsDB']);
    $memberHandler = new Xhelp\MembershipHandler($GLOBALS['xoopsDB']);
    $ticketHandler = new Xhelp\TicketHandler($GLOBALS['xoopsDB']);
    $hXoopsMember  = xoops_getHandler('member');
    $hRole         = new Xhelp\RoleHandler($GLOBALS['xoopsDB']);

    $mid = $xoopsModule->getVar('mid');

    xoops_cp_header();
    //echo $oAdminButton->renderButtons('');
    $adminObject = \Xmf\Module\Admin::getInstance();
    $adminObject->displayNavigation(basename(__FILE__));

    echo '<h2>' . _AM_XHELP_UPDATE_DB . '</h2>';
    $ret = true;
    //2. Do All Upgrades necessary to make current
    //   Break statements are omitted on purpose
    switch ($ver) {
        case '0.5':
            set_time_limit(60);
            printf('<h3>' . _AM_XHELP_UPDATE_TO . '</h3>', '0.6');
            echo '<ul>';
            //Create meta table
            $ret = $ret
                   && _runQuery(
                       sprintf("CREATE TABLE %s (metakey VARCHAR(50) NOT NULL DEFAULT '', metavalue VARCHAR(255) NOT NULL DEFAULT '', PRIMARY KEY (metakey)) ENGINE=MyISAM;", $xoopsDB->prefix('xhelp_meta')),
                       sprintf(_AM_XHELP_MSG_ADDTABLE, 'xhelp_meta'),
                       sprintf(_AM_XHELP_MSG_ADDTABLE_ERR, 'xhelp_meta')
                   );

            //Insert Current Version into table
            $qry = $xoopsDB->query(sprintf("INSERT INTO `%s` VALUES('version', %s)", $xoopsDB->prefix('xhelp_meta'), $xoopsDB->quoteString($ver)));

            //Update xhelp_responses table
            $ret = $ret
                   && _runQuery(sprintf("ALTER TABLE %s ADD private INT(11) NOT NULL DEFAULT '0'", $xoopsDB->prefix('xhelp_responses')), sprintf(_AM_XHELP_MSG_MODIFYTABLE, 'xhelp_responses'), sprintf(_AM_XHELP_MSG_MODIFYTABLE_ERR, 'xhelp_responses'));

            //Retrieve uid's of all staff members
            $qry = $xoopsDB->query('SELECT uid FROM ' . $xoopsDB->prefix('xhelp_staff') . ' ORDER BY uid');

            //Get email addresses in user profile
            $staff = [];
            while (false !== ($arr = $xoopsDB->fetchArray($qry))) {
                $staff[$arr['uid']] = '';
            }
            $xoopsDB->freeRecordSet($qry);

            $query = 'SELECT uid, email FROM ' . $xoopsDB->prefix('users') . ' WHERE uid IN (' . implode(array_keys($staff), ',') . ')';
            $qry   = $xoopsDB->query($query);
            while (false !== ($arr = $xoopsDB->fetchArray($qry))) {
                $staff[$arr['uid']] = $arr['email'];
            }
            $xoopsDB->freeRecordSet($qry);

            //Update xhelp_staff table
            $ret = $ret
                   && _runQuery(sprintf("ALTER TABLE %s ADD email VARCHAR(255) NOT NULL DEFAULT '' AFTER uid, ADD notify INT(11) NOT NULL DEFAULT '0'", $xoopsDB->prefix('xhelp_staff')), sprintf(_AM_XHELP_MSG_MODIFYTABLE, 'xhelp_staff'), sprintf(_AM_XHELP_MSG_MODIFYTABLE_ERR, 'xhelp_staff'));

            //Update existing staff records
            $staff_tbl = $xoopsDB->prefix('xhelp_staff');
            $notif_tbl = $xoopsDB->prefix('xoopsnotifications');
            $email_tpl = $xoopsModule->getInfo('_email_tpl');
            foreach ($staff as $uid => $email) {
                //get notifications for current user
                $usernotif = 0;
                $qry       = $xoopsDB->query(sprintf("SELECT DISTINCT not_category, not_event FROM `%s` WHERE not_uid = %u AND not_category='dept' AND not_modid=%u", $notif_tbl, $uid, $mid));
                while (false !== ($arr = $xoopsDB->fetchArray($qry))) {
                    //Look for current event information in $email_tpl
                    foreach ($email_tpl as $tpl) {
                        if (($tpl['name'] == $arr['not_event']) && ($tpl['category'] == $arr['not_category'])) {
                            $usernotif |= 2 ** $tpl['bit_value'];
                            break;
                        }
                    }
                }

                //Update xhelp_staff with user notifications & email address
                $ret = $ret
                       && _runQuery(sprintf('UPDATE `%s` SET email = %s, notify = %u WHERE uid = %u', $staff_tbl, $xoopsDB->quoteString($email), $usernotif, $uid), sprintf(_AM_XHELP_MSG_UPDATESTAFF, $uid), sprintf(_AM_XHELP_MSG_UPDATESTAFF_ERR, $uid));
            }
            echo '</ul>';
        // no break
        case '0.6':
            set_time_limit(60);
            //Do DB updates to make 0.7
            printf('<h3>' . _AM_XHELP_UPDATE_TO . '</h3>', '0.7');

            echo '<ul>';
            // change table names to lowercase
            $ret = $ret && _renameTable('xhelp_logMessages', 'xhelp_logmessages');
            $ret = $ret && _renameTable('xhelp_responseTemplates', 'xhelp_responsetemplates');
            $ret = $ret && _renameTable('xhelp_jStaffDept', 'xhelp_jstaffdept');
            $ret = $ret && _renameTable('xhelp_staffReview', 'xhelp_staffreview');
            $ret = $ret && _renameTable('xhelp_emailTpl', 'xhelp_emailtpl');

            // Remove unused table - xhelp_emailtpl
            $ret = $ret
                   && _runQuery(sprintf('DROP TABLE %s', $xoopsDB->prefix('xhelp_emailtpl')), sprintf(_AM_XHELP_MSG_REMOVE_TABLE, 'xhelp_emailtpl'), sprintf(_AM_XHELP_MSG_NOT_REMOVE_TABLE, 'xhelp_emailtpl'));

            // xhelp_staff table - permTimestamp
            $ret = $ret
                   && _runQuery(sprintf("ALTER TABLE %s ADD permTimestamp INT(11) NOT NULL DEFAULT '0'", $xoopsDB->prefix('xhelp_staff')), sprintf(_AM_XHELP_MSG_MODIFYTABLE, 'xhelp_staff'), sprintf(_AM_XHELP_MSG_MODIFYTABLE_ERR, 'xhelp_staff'));

            //Update xhelp_tickets table
            $ret = $ret
                   && _runQuery(sprintf("ALTER TABLE %s MODIFY SUBJECT VARCHAR(100) NOT NULL DEFAULT ''", $xoopsDB->prefix('xhelp_tickets')), sprintf(_AM_XHELP_MSG_MODIFYTABLE, 'xhelp_tickets'), sprintf(_AM_XHELP_MSG_MODIFYTABLE_ERR, 'xhelp_tickets'));

            $ret = $ret
                   && _runQuery(
                       sprintf(
                           "ALTER TABLE %s ADD (serverid INT(11) DEFAULT NULL,
                                                             emailHash VARCHAR(100) DEFAULT NULL,
                                                             email VARCHAR(100) DEFAULT NULL,
                                                             overdueTime INT(11) NOT NULL DEFAULT '0',
                                                             KEY emailHash (emailHash))",
                           $xoopsDB->prefix('xhelp_tickets')
                       ),
                       sprintf(_AM_XHELP_MSG_MODIFYTABLE, 'xhelp_tickets'),
                       sprintf(_AM_XHELP_MSG_MODIFYTABLE_ERR, 'xhelp_tickets')
                   );

            // Create xhelp_department_mailbox table
            $ret = $ret
                   && _runQuery(
                       sprintf(
                           'CREATE TABLE %s (id INT(11) NOT NULL AUTO_INCREMENT,
                                                          departmentid INT(11) DEFAULT NULL,
                                                          emailaddress VARCHAR(255) DEFAULT NULL,
                                                          SERVER VARCHAR(50) DEFAULT NULL,
                                                          serverport INT(11) DEFAULT NULL,
                                                          username VARCHAR(50) DEFAULT NULL,
                                                          PASSWORD VARCHAR(50) DEFAULT NULL,
                                                          priority TINYINT(4) DEFAULT NULL,
                                                          mboxtype INT(11) NOT NULL DEFAULT 1,
                                                          PRIMARY KEY  (id),
                                                          UNIQUE KEY id (id),
                                                          KEY departmentid (departmentid),
                                                          KEY emailaddress (emailaddress),
                                                          KEY mboxtype (mboxtype)
                                                         )ENGINE=MyISAM;',
                           $xoopsDB->prefix('xhelp_department_mailbox')
                       ),
                       sprintf(_AM_XHELP_MSG_ADDTABLE, 'xhelp_department_mailbox'),
                       sprintf(_AM_XHELP_MSG_ADDTABLE_ERR, 'xhelp_department_mailbox')
                   );

            // Create xhelp_mailevent table
            $ret = $ret
                   && _runQuery(
                       sprintf(
                           "CREATE TABLE %s (id INT(11) NOT NULL AUTO_INCREMENT,
                                                           mbox_id INT(11) NOT NULL DEFAULT '0',
                                                           event_desc TEXT,
                                                           event_class INT(11) NOT NULL DEFAULT '0',
                                                           posted INT(11) NOT NULL DEFAULT '0',
                                                           PRIMARY KEY(id)
                                                          )ENGINE=MyISAM;",
                           $xoopsDB->prefix('xhelp_mailevent')
                       ),
                       sprintf(_AM_XHELP_MSG_ADDTABLE, 'xhelp_mailevent'),
                       sprintf(_AM_XHELP_MSG_ADDTABLE_ERR, 'xhelp_mailevent')
                   );

            // Create xhelp_roles table
            $ret = $ret
                   && _runQuery(
                       sprintf(
                           "CREATE TABLE %s (id INT(11) NOT NULL AUTO_INCREMENT,
                                                          name VARCHAR(35) NOT NULL DEFAULT '',
                                                          description MEDIUMTEXT,
                                                          tasks INT(11) NOT NULL DEFAULT '0',
                                                          PRIMARY KEY(id)
                                                         )ENGINE=MyISAM;",
                           $xoopsDB->prefix('xhelp_roles')
                       ),
                       sprintf(_AM_XHELP_MSG_ADDTABLE, 'xhelp_roles'),
                       sprintf(_AM_XHELP_MSG_ADDTABLE_ERR, 'xhelp_roles')
                   );

            // Create xhelp_staffroles table
            $ret = $ret
                   && _runQuery(
                       sprintf(
                           "CREATE TABLE %s (uid INT(11) NOT NULL DEFAULT '0',
                                                         roleid INT(11) NOT NULL DEFAULT '0',
                                                         deptid INT(11) NOT NULL DEFAULT '0',
                                                         PRIMARY KEY(uid, roleid, deptid)
                                                        )ENGINE=MyISAM;",
                           $xoopsDB->prefix('xhelp_staffroles')
                       ),
                       sprintf(_AM_XHELP_MSG_ADDTABLE, 'xhelp_staffroles'),
                       sprintf(_AM_XHELP_MSG_ADDTABLE_ERR, 'xhelp_staffroles')
                   );

            // Add default roles to db
            if (!$hasRoles = Xhelp\Utility::createRoles()) {
                echo '<li>' . _AM_XHELP_MESSAGE_DEF_ROLES_ERROR . '</li>';
            } else {
                echo '<li>' . _AM_XHELP_MESSAGE_DEF_ROLES . '</li>';
            }

            // Set all staff members to have admin permission role
            $staff = $staffHandler->getObjects();
            if ($staff) {
                foreach ($staff as $stf) {
                    $uid   = $stf->getVar('uid');
                    $depts = $memberHandler->membershipByStaff($uid, true);
                    if ($staffHandler->addStaffRole($uid, 1, 0)) {
                        echo '<li>' . sprintf(_AM_XHELP_MSG_GLOBAL_PERMS, $uid) . '</li>';
                    }

                    foreach ($depts as $dept) {
                        $deptid = $dept->getVar('id');
                        if ($staffHandler->addStaffRole($uid, 1, $deptid)) {    // Departmental permissions
                            echo '<li>' . sprintf(_AM_XHELP_MSG_UPD_PERMS, $uid, $dept->getVar('department')) . '</li>';
                        }
                    }

                    $stf->setVar('permTimestamp', time());        // Set initial value for permTimestamp field
                    if (!$staffHandler->insert($stf)) {
                        echo '<li>' . sprintf(_AM_XHELP_MSG_UPDATESTAFF_ERR, $uid) . '</li>';
                    } else {
                        echo '<li>' . sprintf(_AM_XHELP_MSG_UPDATESTAFF, $uid) . '</li>';
                    }
                }
            }
            echo '</ul>';

        // no break
        case '0.7':
            set_time_limit(60);
            //Do DB updates to make 0.71
            printf('<h3>' . _AM_XHELP_UPDATE_TO . '</h3>', '0.71');

            echo '<ul>';
            echo '</ul>';

        // no break
        case '0.71':
            set_time_limit(60);
            //Do DB updates to make 0.75
            printf('<h3>' . _AM_XHELP_UPDATE_TO . '</h3>', '0.75');

            echo '<ul>';

            //Changes for php5 compabibility
            $ret = $ret
                   && _runQuery(sprintf("ALTER TABLE %s MODIFY lastUpdated INT(11) NOT NULL DEFAULT '0'", $xoopsDB->prefix('xhelp_logmessages')), sprintf(_AM_XHELP_MSG_MODIFYTABLE, 'xhelp_logmessages'), sprintf(_AM_XHELP_MSG_MODIFYTABLE_ERR, 'xhelp_logmessages'));
            $ret = $ret
                   && _runQuery(sprintf("ALTER TABLE %s MODIFY department INT(11) NOT NULL DEFAULT '0'", $xoopsDB->prefix('xhelp_jstaffdept')), sprintf(_AM_XHELP_MSG_MODIFYTABLE, 'xhelp_jstaffdept'), sprintf(_AM_XHELP_MSG_MODIFYTABLE_ERR, 'xhelp_jstaffdept'));

            // Create table for email template information
            $ret = $ret
                   && _runQuery(
                       sprintf(
                           "CREATE TABLE %s (notif_id INT(11) NOT NULL DEFAULT '0',
                                                           staff_setting INT(11) NOT NULL DEFAULT '0',
                                                           user_setting INT(11) NOT NULL DEFAULT '0',
                                                           staff_options MEDIUMTEXT NOT NULL,
                                                           PRIMARY KEY (notif_id)
                                                          )ENGINE=MyISAM;",
                           $xoopsDB->prefix('xhelp_notifications')
                       ),
                       sprintf(_AM_XHELP_MSG_ADDTABLE, 'xhelp_notifications'),
                       sprintf(_AM_XHELP_MSG_ADDTABLE_ERR, 'xhelp_notifications')
                   );

            // Add xhelp_status table
            $ret = $ret
                   && _runQuery(
                       sprintf(
                           "CREATE TABLE %s (id INT(11) NOT NULL AUTO_INCREMENT,
                                                           state INT(11) NOT NULL DEFAULT '0',
                                                           description VARCHAR(50) NOT NULL DEFAULT '',
                                                           PRIMARY KEY(id),
                                                           KEY state (state)
                                                          )ENGINE=MyISAM;",
                           $xoopsDB->prefix('xhelp_status')
                       ),
                       sprintf(_AM_XHELP_MSG_ADDTABLE, 'xhelp_status'),
                       sprintf(_AM_XHELP_MSG_ADDTABLE_ERR, 'xhelp_status')
                   );

            // Give default statuses for upgrade
            $hStatus       = new Xhelp\StatusHandler($GLOBALS['xoopsDB']);
            $startStatuses = [_XHELP_STATUS0 => 1, _XHELP_STATUS1 => 1, _XHELP_STATUS2 => 2];

            $count = 1;
            set_time_limit(60);
            foreach ($startStatuses as $desc => $state) {
                $newStatus = $hStatus->create();
                $newStatus->setVar('id', $count);
                $newStatus->setVar('description', $desc);
                $newStatus->setVar('state', $state);
                if (!$hStatus->insert($newStatus)) {
                    echo '<li>' . sprintf(_AM_XHELP_MSG_ADD_STATUS_ERR, $desc) . '</li>';
                } else {
                    echo '<li>' . sprintf(_AM_XHELP_MSG_ADD_STATUS, $desc) . '</li>';
                }
                ++$count;
            }

            // Change old status values to new status values
            $oldStatuses = [2 => 3, 1 => 2, 0 => 1];

            foreach ($oldStatuses as $cStatus => $newStatus) {
                $crit    = new \Criteria('status', $cStatus);
                $success = $ticketHandler->updateAll('status', $newStatus, $crit);
            }
            if ($success) {
                echo '<li>' . _AM_XHELP_MSG_CHANGED_STATUS . '</li>';
            } else {
                echo '<li>' . _AM_XHELP_MSG_CHANGED_STATUS_ERR . '</li>';
            }

            // Add xhelp_ticket_submit_emails table
            $ret = $ret
                   && _runQuery(
                       sprintf(
                           "CREATE TABLE %s (ticketid INT(11) NOT NULL DEFAULT '0',
                                                           uid INT(11) NOT NULL DEFAULT '0',
                                                           email VARCHAR(100) NOT NULL DEFAULT '',
                                                           suppress INT(11) NOT NULL DEFAULT '0',
                                                           PRIMARY KEY(ticketid, email)
                                                          )ENGINE=MyISAM;",
                           $xoopsDB->prefix('xhelp_ticket_submit_emails')
                       ),
                       sprintf(_AM_XHELP_MSG_ADDTABLE, 'xhelp_ticket_submit_emails'),
                       sprintf(_AM_XHELP_MSG_ADDTABLE_ERR, 'xhelp_ticket_submit_emails')
                   );

            // Add records to xhelp_ticket_submit_emails for existing tickets
            $count     = $ticketHandler->getCount();
            $batchsize = 100;

            $crit = new \Criteria('', '');
            $crit->setLimit($batchsize);
            $i = 0;

            while ($i <= $count) {
                set_time_limit(60);
                $crit->setStart($i);
                $tickets = $ticketHandler->getObjects($crit);

                $all_users = [];
                foreach ($tickets as $ticket) {
                    $all_users[$ticket->getVar('uid')] = $ticket->getVar('uid');
                }

                $crit  = new \Criteria('uid', '(' . implode(array_keys($all_users), ',') . ')', 'IN');
                $users = $hXoopsMember->getUsers($crit, true);

                foreach ($users as $user) {
                    $all_users[$user->getVar('uid')] = $user->getVar('email');
                }
                unset($users);

                foreach ($tickets as $ticket) {
                    set_time_limit(60);
                    $ticket_uid = $ticket->getVar('uid');
                    if (array_key_exists($ticket_uid, $all_users)) {
                        $ticket_email = $all_users[$ticket_uid];
                        $success      = $ticket->addSubmitter($ticket_email, $ticket_uid);
                    }
                }
                unset($tickets);
                //increment
                $i += $batchsize;
            }

            set_time_limit(60);
            // Update xhelp_roles Admin record with new value (2047)
            $crit        = new \Criteria('tasks', 511);
            $admin_roles = $hRole->getObjects($crit);

            foreach ($admin_roles as $role) {
                $role->setVar('tasks', 2047);
                if ($hRole->insert($role)) {
                    echo '<li>' . sprintf(_AM_XHELP_MSG_UPDATE_ROLE, $role->getVar('name')) . '</li>';
                } else {
                    echo '<li>' . sprintf(_AM_XHELP_MSG_UPDATE_ROLE_ERR, $role->getVar('name')) . '</li>';
                }
            }

            set_time_limit(60);
            $ret = $ret
                   && _runQuery(
                       sprintf(
                           'ALTER TABLE %s ADD (active INT(11) NOT NULL DEFAULT 1,
                                                          KEY ACTIVE (ACTIVE))',
                           $xoopsDB->prefix('xhelp_department_mailbox')
                       ),
                       sprintf(_AM_XHELP_MSG_MODIFYTABLE, 'xhelp_department_mailbox'),
                       sprintf(_AM_XHELP_MSG_MODIFYTABLE_ERR, 'xhelp_department_mailbox')
                   );

            // Add xhelp_saved_searches table
            $ret = $ret
                   && _runQuery(
                       sprintf(
                           "CREATE TABLE %s (id INT(11) NOT NULL AUTO_INCREMENT,
                                                           uid INT(11) NOT NULL DEFAULT '0',
                                                           name VARCHAR(50) NOT NULL DEFAULT '',
                                                           search MEDIUMTEXT NOT NULL,
                                                           pagenav_vars MEDIUMTEXT NOT NULL,
                                                           PRIMARY KEY(id)
                                                          )ENGINE=MyISAM;",
                           $xoopsDB->prefix('xhelp_saved_searches')
                       ),
                       sprintf(_AM_XHELP_MSG_ADDTABLE, 'xhelp_saved_searches'),
                       sprintf(_AM_XHELP_MSG_ADDTABLE_ERR, 'xhelp_saved_searches')
                   );

            set_time_limit(60);
            $ret = $ret
                   && _runQuery(
                       sprintf(
                           "CREATE TABLE %s (fieldid INT(11) NOT NULL DEFAULT '0',
                                                           deptid INT(11) NOT NULL DEFAULT '0',
                                                           PRIMARY KEY  (fieldid, deptid)
                                                          )ENGINE=MyISAM;",
                           $xoopsDB->prefix('xhelp_ticket_field_departments')
                       ),
                       sprintf(_AM_XHELP_MSG_ADDTABLE, 'xhelp_ticket_field_departments'),
                       sprintf(_AM_XHELP_MSG_ADDTABLE_ERR, 'xhelp_ticket_field_departments')
                   );

            $ret = $ret
                   && _runQuery(
                       sprintf(
                           "CREATE TABLE %s (ticketid INT(11) NOT NULL DEFAULT '0',
                                                           PRIMARY KEY  (ticketid)
                                                          )ENGINE=MyISAM;",
                           $xoopsDB->prefix('xhelp_ticket_values')
                       ),
                       sprintf(_AM_XHELP_MSG_ADDTABLE, 'xhelp_ticket_values'),
                       sprintf(_AM_XHELP_MSG_ADDTABLE_ERR, 'xhelp_ticket_values')
                   );

            set_time_limit(60);
            $ret = $ret
                   && _runQuery(
                       sprintf(
                           "CREATE TABLE %s (id INT(11) NOT NULL AUTO_INCREMENT,
                                                           NAME VARCHAR(64) NOT NULL DEFAULT '',
                                                           description TINYTEXT NOT NULL,
                                                           fieldname VARCHAR(64) NOT NULL DEFAULT '',
                                                           controltype INT(11) NOT NULL DEFAULT '0',
                                                           datatype VARCHAR(64) NOT NULL DEFAULT '',
                                                           required TINYINT(1) NOT NULL DEFAULT '0',
                                                           fieldlength INT(11) NOT NULL DEFAULT '0',
                                                           weight INT(11) NOT NULL DEFAULT '0',
                                                           fieldvalues MEDIUMTEXT NOT NULL,
                                                           defaultvalue VARCHAR(100) NOT NULL DEFAULT '',
                                                           VALIDATION MEDIUMTEXT NOT NULL,
                                                           PRIMARY KEY (id),
                                                           KEY weight (weight)
                                                          )ENGINE=MyISAM;",
                           $xoopsDB->prefix('xhelp_ticket_fields')
                       ),
                       sprintf(_AM_XHELP_MSG_ADDTABLE, 'xhelp_ticket_fields'),
                       sprintf(_AM_XHELP_MSG_ADDTABLE_ERR, 'xhelp_ticket_fields')
                   );

            set_time_limit(60);
            // Add notifications to new table
            set_time_limit(60);
            $hasNotifications = Xhelp\Utility::createNotifications();

            // Make all departments visible to all groups
            $hasDeptVisibility = xhelpCreateDepartmentVisibility();

            // Update staff permTimestamp
            $staffHandler->updateAll('permTimestamp', time());

            set_time_limit(60);
            //Update xhelp_tickets table
            set_time_limit(60);
            $ret = $ret
                   && _runQuery(sprintf("ALTER TABLE %s MODIFY SUBJECT VARCHAR(255) NOT NULL DEFAULT ''", $xoopsDB->prefix('xhelp_tickets')), sprintf(_AM_XHELP_MSG_MODIFYTABLE, 'xhelp_tickets'), sprintf(_AM_XHELP_MSG_MODIFYTABLE_ERR, 'xhelp_tickets'));

        // no break
        case '0.75':
            set_time_limit(60);
            // Set default department
            //            $xoopsModuleConfig = Xhelp\Utility::getModuleConfig();
            if (null !== $helper->getConfig('xhelp_defaultDept') && 0 != $helper->getConfig('xhelp_defaultDept')) {
                $ret = Xhelp\Utility::setMeta('default_department', $helper->getConfig('xhelp_defaultDept'));
            } else {
                $hDepartments = new Xhelp\DepartmentHandler($GLOBALS['xoopsDB']);
                $depts        = $hDepartments->getObjects();
                $aDepts       = [];
                foreach ($depts as $dpt) {
                    $aDepts[] = $dpt->getVar('id');
                }
                $ret = Xhelp\Utility::setMeta('default_department', $aDepts[0]);
            }

            $qry = $xoopsDB->query(sprintf('ALTER TABLE %s DROP PRIMARY KEY', $xoopsDB->prefix('xhelp_ticket_submit_emails')));
            $ret = $ret
                   && _runQuery(sprintf('ALTER TABLE %s ADD PRIMARY KEY(ticketid, uid, email)', $xoopsDB->prefix('xhelp_ticket_submit_emails')), sprintf(_AM_XHELP_MSG_MODIFYTABLE, 'xhelp_ticket_submit_emails'), sprintf(_AM_XHELP_MSG_MODIFYTABLE_ERR, 'xhelp_ticket_submit_emails'));

            $ret = $ret
                   && _runQuery(sprintf("ALTER TABLE %s MODIFY department INT(11) NOT NULL DEFAULT '0'", $xoopsDB->prefix('xhelp_jstaffdept')), sprintf(_AM_XHELP_MSG_MODIFYTABLE, 'xhelp_jstaffdept'), sprintf(_AM_XHELP_MSG_MODIFYTABLE_ERR, 'xhelp_jstaffdept'));

            echo '<li>' . _AM_XHELP_MSG_CHANGED_DEFAULT_DEPT . '</li>';

            // Add field to xhelp_saved_searches to determine if custom fields table is needed
            $ret = $ret
                   && _runQuery(sprintf("ALTER TABLE %s ADD (hasCustFields INT(11) NOT NULL DEFAULT '0')", $xoopsDB->prefix('xhelp_saved_searches')), sprintf(_AM_XHELP_MSG_MODIFYTABLE, 'xhelp_saved_searches'), sprintf(_AM_XHELP_MSG_MODIFYTABLE_ERR, 'xhelp_saved_searches'));

            // Take existing saved searches and add 'query' field
            $hSavedSearch  = new Xhelp\SavedSearchHandler($GLOBALS['xoopsDB']);
            $savedSearches = $hSavedSearch->getObjects();

            foreach ($savedSearches as $savedSearch) {
                set_time_limit(60);
                $crit = unserialize($savedSearch->getVar('search'));
                if (is_object($crit)) {
                    $savedSearch->setVar('query', $crit->render());

                    if ($hSavedSearch->insert($savedSearch)) {
                        echo '<li>' . sprintf(_AM_XHELP_MSG_UPDATE_SEARCH, $savedSearch->getVar('id')) . '</li>';
                    } else {
                        echo '<li>' . sprintf(_AM_XHELP_MSG_UPDATE_SEARCH_ERR, $savedSearch->getVar('id')) . '</li>';
                    }
                }
            }
            unset($savedSearches);

            // Add ticket list table
            set_time_limit(60);
            $ret = $ret
                   && _runQuery(
                       sprintf(
                           "CREATE TABLE %s (id INT(11) NOT NULL AUTO_INCREMENT,
                                                           uid INT(11) NOT NULL DEFAULT '0',
                                                           searchid INT(11) NOT NULL DEFAULT '0',
                                                           weight INT(11) NOT NULL DEFAULT '0',
                                                           PRIMARY KEY (id),
                                                           KEY ticketList (uid, searchid)
                                                          )ENGINE=MyISAM;",
                           $xoopsDB->prefix('xhelp_ticket_lists')
                       ),
                       sprintf(_AM_XHELP_MSG_ADDTABLE, 'xhelp_ticket_lists'),
                       sprintf(_AM_XHELP_MSG_ADDTABLE_ERR, 'xhelp_ticket_lists')
                   );

            // Add global ticket lists for staff members
            Xhelp\Utility::createDefaultTicketLists();

            set_time_limit(60);
            // Update xhelp_roles Admin record with new value (4095)
            $crit        = new \Criteria('tasks', 2047);
            $admin_roles = $hRole->getObjects($crit);

            foreach ($admin_roles as $role) {
                $role->setVar('tasks', 4095);
                if ($hRole->insert($role)) {
                    echo '<li>' . sprintf(_AM_XHELP_MSG_UPDATE_ROLE, $role->getVar('name')) . '</li>';
                } else {
                    echo '<li>' . sprintf(_AM_XHELP_MSG_UPDATE_ROLE_ERR, $role->getVar('name')) . '</li>';
                }
            }

        // no break
        case '0.77':
            // No schema changes for 0.78

        case '0.78':

            echo '</ul>';
    }

    $newversion = round($xoopsModule->getVar('version') / 100, 2);
    //if successful, update xhelp_meta table with new ver
    if ($ret) {
        printf(_AM_XHELP_UPDATE_OK, $newversion);
        $ret = Xhelp\Utility::setMeta('version', $newversion);
    } else {
        printf(_AM_XHELP_UPDATE_ERR, $newversion);
    }

    require_once __DIR__ . '/admin_footer.php';
}

if ('upgradeDB' === $op) {
    echo "<script language='JavaScript' type='text/javascript'>
window.onload=function() {
    var objWindow=window.open('about:blank', 'progress', '');
    objWindow.close();
}
</script>";
}
