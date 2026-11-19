<?php require 'inc/bootstrap.php'; need('viewer'); $title = 'Help';
include 'inc/header.php'; ?>
<h1 class="h3 mb-3">Help</h1>
<div class="accordion" style="max-width:820px" id="hlp">
<?php $sections = [
 ['Item types', '<ul><li><b>Consumable</b>: counted up and down (paper, batteries). Use "Use" to take some, "Restock" to add.</li><li><b>Returnable</b>: quantity items that are signed out and come back (radios, tools in numbers).</li><li><b>Asset</b>: one individually tracked thing with its own history (a vehicle, a laptop).</li><li><b>Fixed item</b>: counted but never signed out (furniture, fixtures). Use Recount to confirm the number.</li></ul>'],
 ['Signing items out and in', 'Open the item (or scan its code) and choose Check out, then choose the person. Items out right now are on <b>Out now</b>. Return from the same page. Every action is logged and a mistaken one can be undone from the item history.'],
 ['Counting and alerts', 'Set a minimum on an item and it appears on <b>Attention</b> when stock falls below it. <b>Recount</b> lets you walk a location and enter what you actually find; differences are recorded as adjustments.'],
 ['Incidents', 'When something is issued for an event, create or pick an incident and enter its reference on the issue so supplies used can be reported afterwards.'],
 ['Categories and locations', 'Manage &gt; Categories. Drag one category onto another to merge them; items and subcategories move with it. Bulk edit (Items page) changes many items at once.'],
 ['Users and roles', 'Admin &gt; Users. Roles from lowest to highest: viewer, user, manager, admin. A user can be limited to certain locations. Turn on two-step sign-in or a passkey under your account.'],
 ['Backups and restoring', 'Admin &gt; Backup downloads or saves a database copy and can restore one. Turn on the daily automatic backup in Settings. An update or automatic deploy takes its own backup first.'],
 ['Updates', 'If the site deploys automatically from GitHub, nothing is needed. Otherwise use Admin &gt; Updates to check for and install a newer release. Admin &gt; Upgrade applies database changes if ever asked.'],
 ['Connecting other software (API)', 'Admin &gt; Integrations creates an access token for another program such as OpsHub. Each token has its own permissions and every change it makes is listed on that page.'],
 ['Something wrong?', 'Admin &gt; System health lists common problems (folders not writable, mail not configured, database out of date) with what to do about each.'],
]; foreach ($sections as $i => $s): ?>
<div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button<?=$i?' collapsed':''?>" type="button" data-bs-toggle="collapse" data-bs-target="#h<?=$i?>"><?=h($s[0])?></button></h2>
<div id="h<?=$i?>" class="accordion-collapse collapse<?=$i?'':' show'?>" data-bs-parent="#hlp"><div class="accordion-body"><?=$s[1]?></div></div></div>
<?php endforeach; ?></div>
<?php include 'inc/footer.php'; ?>
