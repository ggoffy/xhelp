<div id="xhelp_setowner">
    <form method="post" name="frmSetowner">
        <{securityToken}><{*//mb*}>
        <table style="width:100%;" border="1" cellpadding="0" cellspacing="2" class="outer">
            <tr>
                <th colspan="2"><{$smarty.const._XHELP_TEXT_SETOWNER}></th>
            </tr>
            <tr>
                <td class="head" width="20%"><label for="owner"><{$smarty.const._XHELP_TEXT_OWNER}></label></td>
                <td class="odd">
                    <select id="owner" name="owner">
                        <{html_options options=$xhelp_staff_ids}>
                    </select>
                </td>
            </tr>
            <tr>
                <td colspan="2" class="even" align="right">
                    <input type="submit" name="setowner" value="<{$smarty.const._XHELP_BUTTON_SET}>">
                    <input type="hidden" name="tickets" value="<{$xhelp_tickets}>">
                    <input type="hidden" name="op" value="setowner">
                </td>
            </tr>
        </table>

        <{include file='db:xhelp_batchTickets.tpl'}>

    </form>
</div>
