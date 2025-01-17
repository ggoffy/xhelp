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

use Xmf\Request;
use XoopsModules\Xhelp;
use XoopsModules\Xhelp\EventService;

require_once __DIR__ . '/admin_header.php';

global $xoopsUser, $eventService;

if(!is_object($eventService)) {
    $eventService = new EventService();
}
$uid    = $xoopsUser->getVar('uid');
$helper = Xhelp\Helper::getInstance();
$deptID = 0;

if (Request::hasVar('deleteDept', 'REQUEST')) {
    if (Request::hasVar('deptid', 'REQUEST')) {
        $deptID = $_REQUEST['deptid'];
    } else {
        $helper->redirect('admin/department.php?op=manageDepartments', 3, _AM_XHELP_MESSAGE_NO_DEPT);
    }

    if (isset($_POST['ok'])) {
        /** @var \XoopsModules\Xhelp\DepartmentHandler $departmentHandler */
        /** @var \XoopsModules\Xhelp\DepartmentHandler $departmentHandler */
        $departmentHandler = $helper->getHandler('Department');
        /** @var \XoopsGroupPermHandler $grouppermHandler */
        $grouppermHandler = xoops_getHandler('groupperm');
        $dept             = $departmentHandler->get($deptID);

        $criteria = new \CriteriaCompo(new \Criteria('gperm_name', _XHELP_GROUP_PERM_DEPT));
        $criteria->add(new \Criteria('gperm_itemid', $deptID));
        $grouppermHandler->deleteAll($criteria);

        $deptCopy = $dept;

        if ($departmentHandler->delete($dept)) {
            $eventService->trigger('delete_department', [&$dept]);
            $message = _XHELP_MESSAGE_DEPT_DELETE;

            // Remove configOption for department
            /** @var \XoopsModules\Xhelp\ConfigOptionHandler $configOptionHandler */
            $configOptionHandler = $helper->getHandler('ConfigOption');
            $criteria            = new \CriteriaCompo(new \Criteria('confop_name', $deptCopy->getVar('department')));
            $criteria->add(new \Criteria('confop_value', $deptCopy->getVar('id')));
            $configOption = $configOptionHandler->getObjects($criteria);

            if (count($configOption) > 0) {
                if (!$configOptionHandler->delete($configOption[0])) {
                    $message = '';
                }
                unset($deptCopy);
            }

            // Change default department
            $depts  = $departmentHandler->getObjects();
            $aDepts = [];
            foreach ($depts as $dpt) {
                $aDepts[] = $dpt->getVar('id');
            }
            if (isset($aDepts[0])) {
                Xhelp\Utility::setMeta('default_department', $aDepts[0]);
            }
        } else {
            $message = _XHELP_MESSAGE_DEPT_DELETE_ERROR . $dept->getHtmlErrors();
        }
        $helper->redirect('admin/department.php?op=manageDepartments', 3, $message);
    } else {
        xoops_cp_header();
        //echo $oAdminButton->renderButtons('manDept');
        xoops_confirm(['deleteDept' => 1, 'deptid' => $deptID, 'ok' => 1], XHELP_BASE_URL . '/admin/delete.php', sprintf(_AM_XHELP_MSG_DEPT_DEL_CFRM, $deptID));
        xoops_cp_footer();
    }
} elseif (Request::hasVar('deleteStaff', 'REQUEST')) {
    if (Request::hasVar('uid', 'REQUEST')) {
        $staffid = \Xmf\Request::getInt('uid', 0, 'REQUEST');

        if (isset($_POST['ok'])) {
            /** @var \XoopsModules\Xhelp\StaffHandler $staffHandler */
            $staffHandler = $helper->getHandler('Staff');
            $staff        = $staffHandler->getByUid($staffid);

            if ($staffHandler->delete($staff)) {
                $eventService->trigger('delete_staff', [&$staff]);
                $message = _XHELP_MESSAGE_STAFF_DELETE;
            } else {
                $message = _XHELP_MESSAGE_STAFF_DELETE_ERROR . $staff->getHtmlErrors();
            }
            $helper->redirect('admin/staff.php?op=manageStaff', 3, $message);
        } else {
            xoops_cp_header();
            //echo $oAdminButton->renderButtons('manDept');
            xoops_confirm(['deleteStaff' => 1, 'uid' => $staffid, 'ok' => 1], XHELP_BASE_URL . '/admin/delete.php', sprintf(_AM_XHELP_MSG_STAFF_DEL_CFRM, $staffid));
            xoops_cp_footer();
        }
    }
}
