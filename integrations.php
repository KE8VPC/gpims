<?php require 'inc/bootstrap.php'; need('admin'); $title = 'Integrations (API)';
[$inst, $gen] = api_ids(); $newTok = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $a = $_POST['action'] ?? ''; $id = (int)($_POST['id'] ?? 0);
  $mkTok = function($iid){ $t = 'gpi_'.bin2hex(random_bytes(24)); q("INSERT INTO api_tokens(integration_id,token_hash,hint) VALUES(?,?,?)", [$iid, hash('sha256', $t), substr($t, -4)]); return $t; };
  if ($a === 'create') {
    $nm = trim($_POST['name'] ?? ''); $sc = array_values(array_intersect(['read', 'read_people', 'write'], (array)($_POST['scopes'] ?? [])));
    if ($nm === '' || mb_strlen($nm) > 80) flash('Give the integration a name (up to 80 characters).', 'danger');
    elseif (q("SELECT 1 FROM api_integrations WHERE name=?", [$nm])->fetch()) flash('An integration with that name already exists.', 'danger');
    elseif (in_array('read_people', $sc, true) && !in_array('read', $sc, true)) flash("'See people names' needs 'Read' as well.", 'danger');
    elseif (!$sc) flash('Pick at least one permission.', 'danger');
    else { q("INSERT INTO api_integrations(name,scopes) VALUES(?,?)", [$nm, implode(',', $sc)]); $iid = (int)$GLOBALS['pdo']->lastInsertId(); $_SESSION['new_api_token'] = [$nm, $mkTok($iid)]; audit('api_integration_add', "$nm (".implode(',', $sc).')'); }
  } elseif ($i = q("SELECT * FROM api_integrations WHERE id=?", [$id])->fetch()) {
    if ($a === 'token') { $_SESSION['new_api_token'] = [$i['name'], $mkTok($id)]; audit('api_token_add', $i['name']); }
    elseif ($a === 'revoke') { q("UPDATE api_tokens SET revoked_at=NOW() WHERE id=? AND integration_id=? AND revoked_at IS NULL", [(int)$_POST['tid'], $id]); audit('api_token_revoke', $i['name']); flash('Token revoked.'); }
    elseif ($a === 'rotate') { q("UPDATE api_tokens SET revoked_at=NOW() WHERE integration_id=? AND revoked_at IS NULL", [$id]); $_SESSION['new_api_token'] = [$i['name'], $mkTok($id)]; audit('api_token_rotate', $i['name']); }
    elseif ($a === 'toggle') { q("UPDATE api_integrations SET active=1-active WHERE id=?", [$id]); audit('api_integration_toggle', $i['name']); flash($i['active'] ? 'Switched off. Its tokens stop working immediately.' : 'Switched on.'); }
    elseif ($a === 'scopes') { $sc = array_values(array_intersect(['read', 'read_people', 'write'], (array)($_POST['scopes'] ?? []))); if (in_array('read_people', $sc, true) && !in_array('read', $sc, true)) $sc[] = 'read'; q("UPDATE api_integrations SET scopes=? WHERE id=?", [implode(',', $sc), $id]); audit('api_scopes', $i['name'].': '.implode(',', $sc)); flash('Permissions saved.'); }
    elseif ($a === 'delete') { q("DELETE FROM api_integrations WHERE id=?", [$id]); audit('api_integration_delete', $i['name']); flash('Integration deleted. Its saved write results are kept until they expire.'); }
  }
  redirect('integrations.php');
}
if (!empty($_SESSION['new_api_token'])) { $newTok = $_SESSION['new_api_token']; unset($_SESSION['new_api_token']); }
$ints = q("SELECT * FROM api_integrations ORDER BY name")->fetchAll();
$base = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? '').rtrim(dirname($_SERVER['SCRIPT_NAME']), '/').'/api/v1/';
$sl = ['read' => 'Read stock, items and alerts', 'read_people' => 'See people names (who holds what)', 'write' => 'Change stock and check items out or in'];
include 'inc/header.php'; ?>
<h1 class="h3 mb-2">Integrations (API)</h1>
<p class="text-muted small" style="max-width:760px">Lets another system, such as OpsHub, read inventory and record use, restock, counts and check-outs. Each integration has its own permissions and access keys. Everything it does is written to the audit log under its name. Keys only work over HTTPS.</p>
<div class="card mb-3" style="max-width:760px"><div class="card-body small">
<div>Address for the other system: <code class="user-select-all"><?=h($base)?></code></div>
<div>Installation ID: <code class="user-select-all"><?=h($inst)?></code> <span class="text-muted">(never changes)</span></div>
<div>Data generation: <code class="user-select-all"><?=h($gen)?></code> <span class="text-muted">(changes whenever a backup is restored, so connected systems know to re-check)</span></div></div></div>
<?php if ($newTok): ?><div class="alert alert-warning" style="max-width:760px"><strong>Access key for “<?=h($newTok[0])?>”.</strong> Copy it now. It is shown only once and cannot be looked up later.<div class="font-monospace user-select-all text-break fs-6 mt-2"><?=h($newTok[1])?></div></div><?php endif; ?>
<?php foreach ($ints as $i): $sc = explode(',', $i['scopes']); $toks = q("SELECT * FROM api_tokens WHERE integration_id=? ORDER BY id DESC", [$i['id']])->fetchAll(); ?>
<div class="card mb-3" style="max-width:760px"><div class="card-header d-flex justify-content-between"><strong><?=h($i['name'])?></strong><span class="badge text-bg-<?=$i['active']?'success':'secondary'?>"><?=$i['active']?'On':'Off'?></span></div><div class="card-body small">
<div class="text-muted mb-2">ID <?=$i['id']?> · last used <?=h($i['last_used_at'] ?: 'never')?></div>
<form method="post" class="mb-2"><?=csrf_field()?><input type="hidden" name="id" value="<?=$i['id']?>"><input type="hidden" name="action" value="scopes">
<?php foreach ($sl as $k => $lab): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="scopes[]" value="<?=$k?>" id="s<?=$i['id'].$k?>"<?=in_array($k, $sc, true) ? ' checked' : ''?>><label class="form-check-label" for="s<?=$i['id'].$k?>"><?=h($lab)?></label></div><?php endforeach; ?>
<button class="btn btn-sm btn-outline-primary mt-2">Save permissions</button></form>
<table class="table table-sm mb-2"><thead><tr><th>Key ends in</th><th>Created</th><th>Last used</th><th></th></tr></thead><tbody>
<?php foreach ($toks as $t): ?><tr class="<?=$t['revoked_at']?'text-muted':''?>"><td class="font-monospace">…<?=h($t['hint'])?></td><td><?=h($t['created_at'])?></td><td><?=h($t['last_used_at'] ?: '-')?></td><td class="text-end"><?php if ($t['revoked_at']): ?>revoked<?php else: ?><form method="post" class="d-inline"><?=csrf_field()?><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?=$i['id']?>"><input type="hidden" name="tid" value="<?=$t['id']?>"><button class="btn btn-sm btn-link text-danger p-0" data-confirm="Revoke this key? The other system loses access with it.">Revoke</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$toks): ?><tr><td colspan="4" class="text-muted">No keys yet.</td></tr><?php endif; ?></tbody></table>
<form method="post" class="d-flex flex-wrap gap-2"><?=csrf_field()?><input type="hidden" name="id" value="<?=$i['id']?>">
<button class="btn btn-sm btn-outline-secondary" name="action" value="token">New key</button>
<button class="btn btn-sm btn-outline-secondary" name="action" value="rotate" data-confirm="Make a new key and revoke all current keys? Update the other system right after.">Rotate (new key, revoke old)</button>
<button class="btn btn-sm btn-outline-<?=$i['active']?'warning':'success'?>" name="action" value="toggle"><?=$i['active']?'Switch off':'Switch on'?></button>
<button class="btn btn-sm btn-outline-danger ms-auto" name="action" value="delete" data-confirm="Delete this integration and its keys?">Delete</button></form>
</div></div>
<?php endforeach; ?>
<div class="card" style="max-width:760px"><div class="card-header fw-bold">Add an integration</div><form method="post" class="card-body"><?=csrf_field()?><input type="hidden" name="action" value="create">
<input class="form-control mb-2" name="name" maxlength="80" placeholder="Name, e.g. OpsHub" required>
<?php foreach ($sl as $k => $lab): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="scopes[]" value="<?=$k?>" id="n<?=$k?>"<?=$k === 'read' ? ' checked' : ''?>><label class="form-check-label" for="n<?=$k?>"><?=h($lab)?></label></div><?php endforeach; ?>
<button class="btn btn-primary mt-3">Create and show key</button></form></div>
<?php include 'inc/footer.php'; ?>
