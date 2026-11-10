<?php
const DOC_TYPES = ['pdf'=>'application/pdf','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','xls'=>'application/vnd.ms-excel','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','ppt'=>'application/vnd.ms-powerpoint','pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation','csv'=>'text/csv','txt'=>'text/plain','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
const DOC_MAX = 15728640;
function doc_size($b){ return $b >= 1048576 ? round($b / 1048576, 1).' MB' : max(1, round($b / 1024)).' KB'; }
// Handle doc_add / doc_del for an item or a vehicle (caller has already checked permission).
function docs_post($item_id, $vehicle_id){
  $a = $_POST['action'] ?? '';
  $where = $item_id ? ['item_id', (int)$item_id] : ['vehicle_id', (int)$vehicle_id];
  if ($a === 'doc_del') {
    $d = q("SELECT * FROM documents WHERE id=? AND {$where[0]}=?", [(int)($_POST['doc_id'] ?? 0), $where[1]])->fetch();
    if ($d) { drop_upload($d['filename']); q("DELETE FROM documents WHERE id=?", [$d['id']]); audit('doc_delete', $d['title']); flash('Document removed.'); }
    return;
  }
  $f = $_FILES['doc'] ?? null;
  if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) { flash('Choose a file to upload.', 'danger'); return; }
  if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { flash($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE ? 'That file is larger than the server allows.' : 'Upload failed. Try again.', 'danger'); return; }
  if ($f['size'] > DOC_MAX) { flash('Files can be up to 15 MB.', 'danger'); return; }
  $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
  if (!isset(DOC_TYPES[$ext])) { flash('That file type is not allowed. Use PDF, Office documents, CSV, text or images.', 'danger'); return; }
  $dir = dirname(__DIR__).'/uploads'; if (!is_dir($dir)) @mkdir($dir, 0755);
  $name = 'd_'.bin2hex(random_bytes(12)).'.dat';
  if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) { flash('Could not save the file. Check that the uploads folder is writable.', 'danger'); return; }
  $orig = mb_substr(preg_replace('/[^\w.\- ()]+/u', '_', basename($f['name'])), 0, 190); $title = trim($_POST['title'] ?? '') ?: pathinfo($orig, PATHINFO_FILENAME);
  q("INSERT INTO documents(item_id,vehicle_id,title,filename,orig_name,size,uploaded_by) VALUES(?,?,?,?,?,?,?)", [$item_id ?: null, $vehicle_id ?: null, mb_substr($title, 0, 150), $name, $orig, (int)$f['size'], me()['id']]);
  audit('doc_add', $title); flash('Document added.');
}
function docs_panel($item_id, $vehicle_id, $canEdit){
  $col = $item_id ? 'item_id' : 'vehicle_id'; $id = $item_id ?: $vehicle_id;
  $docs = q("SELECT d.*,u.name uname FROM documents d LEFT JOIN users u ON u.id=d.uploaded_by WHERE d.$col=? ORDER BY d.id DESC", [$id])->fetchAll();
  if (!$docs && !$canEdit) return '';
  ob_start(); ?>
<div class="card mt-3" id="docs"><div class="card-header fw-bold"><i class="bi bi-file-earmark-text"></i> Documents <span class="badge text-bg-secondary"><?=count($docs)?></span></div>
<ul class="list-group list-group-flush"><?php foreach ($docs as $d): ?><li class="list-group-item d-flex justify-content-between align-items-center gap-2"><div class="min-w-0"><a href="download.php?id=<?=$d['id']?>"><?=h($d['title'])?></a><div class="small text-muted"><?=h($d['orig_name'])?> · <?=h(doc_size($d['size']))?> · <?=h(date('M j, Y', strtotime($d['created_at'])))?><?=$d['uname'] ? ' · '.h($d['uname']) : ''?></div></div>
<?php if ($canEdit): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="doc_del"><input type="hidden" name="doc_id" value="<?=$d['id']?>"><button class="btn btn-sm btn-outline-danger py-0" data-confirm="Remove this document?"><i class="bi bi-x-lg"></i></button></form><?php endif; ?></li><?php endforeach; if (!$docs) echo '<li class="list-group-item text-muted small">No documents yet (manuals, warranties, calibration records, receipts).</li>'; ?>
<?php if ($canEdit): ?><li class="list-group-item bg-body-tertiary"><form method="post" enctype="multipart/form-data" class="row g-2"><?=csrf_field()?><input type="hidden" name="action" value="doc_add"><div class="col-md-4"><input class="form-control form-control-sm" name="title" maxlength="150" placeholder="Title (optional)"></div><div class="col-md-6"><input class="form-control form-control-sm" type="file" name="doc" required></div><div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Upload</button></div></form></li><?php endif; ?></ul></div>
<?php return ob_get_clean();
}
function docs_purge($item_id, $vehicle_id){
  $col = $item_id ? 'item_id' : 'vehicle_id'; $id = $item_id ?: $vehicle_id;
  foreach (q("SELECT filename FROM documents WHERE $col=?", [$id])->fetchAll() as $d) drop_upload($d['filename']);
}
