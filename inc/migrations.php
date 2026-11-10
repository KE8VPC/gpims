<?php
function col_exists($t, $c){ return (bool)q("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?", [$t,$c])->fetchColumn(); }
function tbl_exists($t){ return (bool)q("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?", [$t])->fetchColumn(); }
function db_version(){
  global $SETTINGS;
  if (isset($SETTINGS['db_version'])) return (int)$SETTINGS['db_version'];
  return (col_exists('items','barcode') && tbl_exists('item_photos')) ? 2 : 1;
}
function migrations(){
  return [
    2 => ['Photos and barcode support', function(){
      if (!col_exists('items','barcode')) { q("ALTER TABLE items ADD COLUMN barcode VARCHAR(100) NULL AFTER sku"); q("ALTER TABLE items ADD INDEX(barcode)"); }
      q("CREATE TABLE IF NOT EXISTS item_photos(id INT AUTO_INCREMENT PRIMARY KEY,item_id INT NOT NULL,filename VARCHAR(80) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(item_id),FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE) ENGINE=InnoDB");
    }],
    3 => ['Settings, login protection, change history and archiving', function(){
      q("CREATE TABLE IF NOT EXISTS login_attempts(id INT AUTO_INCREMENT PRIMARY KEY,username VARCHAR(50) NULL,ip VARCHAR(45) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(username),INDEX(ip),INDEX(created_at)) ENGINE=InnoDB");
      q("CREATE TABLE IF NOT EXISTS item_history(id INT AUTO_INCREMENT PRIMARY KEY,item_id INT NOT NULL,user_id INT NULL,action VARCHAR(20) NOT NULL,field VARCHAR(40) NULL,old_value TEXT NULL,new_value TEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(item_id),FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE) ENGINE=InnoDB");
      if (!col_exists('items','archived_at')) { q("ALTER TABLE items ADD COLUMN archived_at DATETIME NULL"); q("ALTER TABLE items ADD INDEX(archived_at)"); }
      if (!col_exists('users','last_login')) q("ALTER TABLE users ADD COLUMN last_login DATETIME NULL");
      global $cfg;
      q("INSERT IGNORE INTO settings(k,v) VALUES('org_name',?)", [$cfg['app_name'] ?? 'GPIMS']);
    }],
    4 => ['People list, account types, per-person access and nested locations', function(){
      q("ALTER TABLE users MODIFY role ENUM('vendor','viewer','user','manager','admin') NOT NULL DEFAULT 'user'");
      if (!col_exists('users','perms')) q("ALTER TABLE users ADD COLUMN perms TEXT NULL");
      if (!col_exists('users','scope_all')) q("ALTER TABLE users ADD COLUMN scope_all TINYINT(1) NOT NULL DEFAULT 1");
      q("UPDATE users SET role='user' WHERE role='manager'");
      if (!col_exists('locations','parent_id')) q("ALTER TABLE locations ADD COLUMN parent_id INT NULL");
      if (!col_exists('checkouts','person_id')) q("ALTER TABLE checkouts ADD COLUMN person_id INT NULL");
      q("CREATE TABLE IF NOT EXISTS people(id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,badge_code VARCHAR(100) NULL,department VARCHAR(100) NULL,user_id INT NULL,active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(badge_code),INDEX(user_id)) ENGINE=InnoDB");
      q("CREATE TABLE IF NOT EXISTS user_access(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,category_id INT NULL,location_id INT NULL,INDEX(user_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE CASCADE,FOREIGN KEY(location_id) REFERENCES locations(id) ON DELETE CASCADE) ENGINE=InnoDB");
    }],
    5 => ['Multiple codes per item', function(){
      q("CREATE TABLE IF NOT EXISTS item_codes(id INT AUTO_INCREMENT PRIMARY KEY,item_id INT NOT NULL,code VARCHAR(100) NOT NULL,code_type VARCHAR(12) NOT NULL DEFAULT 'other',is_primary TINYINT(1) NOT NULL DEFAULT 0,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(item_id),INDEX(code),FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE) ENGINE=InnoDB");
      q("INSERT INTO item_codes(item_id,code,code_type,is_primary) SELECT i.id,i.barcode,'other',1 FROM items i WHERE i.barcode IS NOT NULL AND i.barcode<>'' AND NOT EXISTS (SELECT 1 FROM item_codes c WHERE c.item_id=i.id)");
    }],
    6 => ['Vendors, par levels and costs, expiration lots, supply alerts and notes', function(){
      q("CREATE TABLE IF NOT EXISTS vendors(id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL,contact_name VARCHAR(100) NULL,phone VARCHAR(40) NULL,email VARCHAR(150) NULL,website VARCHAR(200) NULL,account_no VARCHAR(60) NULL,address VARCHAR(255) NULL,notes TEXT NULL,active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
      q("CREATE TABLE IF NOT EXISTS item_vendors(id INT AUTO_INCREMENT PRIMARY KEY,item_id INT NOT NULL,vendor_id INT NOT NULL,rank_no INT NOT NULL DEFAULT 1,part_no VARCHAR(80) NULL,unit_cost DECIMAL(12,2) NULL,unavailable TINYINT(1) NOT NULL DEFAULT 0,unavail_note VARCHAR(255) NULL,INDEX(item_id),INDEX(vendor_id),FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE,FOREIGN KEY(vendor_id) REFERENCES vendors(id) ON DELETE CASCADE) ENGINE=InnoDB");
      q("CREATE TABLE IF NOT EXISTS item_lots(id INT AUTO_INCREMENT PRIMARY KEY,item_id INT NOT NULL,lot_no VARCHAR(60) NULL,qty DECIMAL(12,2) NULL,expires_on DATE NULL,note VARCHAR(255) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(item_id),INDEX(expires_on),FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE) ENGINE=InnoDB");
      q("CREATE TABLE IF NOT EXISTS alert_notes(id INT AUTO_INCREMENT PRIMARY KEY,item_id INT NOT NULL,user_id INT NULL,kind VARCHAR(10) NOT NULL DEFAULT 'note',note VARCHAR(255) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,cleared_at DATETIME NULL,INDEX(item_id),FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE) ENGINE=InnoDB");
      foreach (['par_qty','reorder_qty','unit_cost'] as $c) if (!col_exists('items',$c)) q("ALTER TABLE items ADD COLUMN $c DECIMAL(12,2) NULL");
    }],
    7 => ['Passkeys', function(){
      q("CREATE TABLE IF NOT EXISTS passkeys(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,credential_id VARCHAR(400) NOT NULL,public_key TEXT NOT NULL,alg INT NOT NULL,sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,name VARCHAR(80) NOT NULL,transports VARCHAR(100) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,last_used DATETIME NULL,UNIQUE KEY(credential_id(190)),INDEX(user_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB");
      q("INSERT IGNORE INTO settings(k,v) VALUES('passkeys_enabled','1')");
    }],
    8 => ['Email notifications and password recovery', function(){
      q("CREATE TABLE IF NOT EXISTS email_queue(id INT AUTO_INCREMENT PRIMARY KEY,to_email VARCHAR(190) NOT NULL,subject VARCHAR(200) NOT NULL,body_html MEDIUMTEXT NOT NULL,body_text MEDIUMTEXT NOT NULL,event VARCHAR(40) NULL,status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',attempts INT NOT NULL DEFAULT 0,last_error VARCHAR(250) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,sent_at DATETIME NULL,INDEX(status),INDEX(created_at)) ENGINE=InnoDB");
      q("CREATE TABLE IF NOT EXISTS password_resets(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,token_hash CHAR(64) NOT NULL,expires_at DATETIME NOT NULL,used_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY(token_hash),INDEX(user_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB");
      if (!col_exists('users','notify_stock')) q("ALTER TABLE users ADD COLUMN notify_stock TINYINT(1) NOT NULL DEFAULT 0");
      if (!col_exists('checklists','notify_complete')) q("ALTER TABLE checklists ADD COLUMN notify_complete TEXT NULL");
      if (!col_exists('checklists','notify_fail')) q("ALTER TABLE checklists ADD COLUMN notify_fail TEXT NULL");
    }],
    9 => ['Incidents, issued equipment and returns', function(){
      q("ALTER TABLE items MODIFY type ENUM('consumable','returnable','asset') NOT NULL DEFAULT 'consumable'");
      if (!col_exists('transactions','incident_id')) q("ALTER TABLE transactions ADD COLUMN incident_id INT NULL");
      if (!col_exists('checkouts','incident_id')) q("ALTER TABLE checkouts ADD COLUMN incident_id INT NULL");
      q("CREATE TABLE IF NOT EXISTS incidents(id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL,kind VARCHAR(40) NULL,starts_on DATE NULL,notes VARCHAR(500) NULL,status ENUM('open','closed') NOT NULL DEFAULT 'open',created_by INT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,closed_at DATETIME NULL,closed_by INT NULL,INDEX(status)) ENGINE=InnoDB");
      q("CREATE TABLE IF NOT EXISTS issues(id INT AUTO_INCREMENT PRIMARY KEY,item_id INT NOT NULL,holder_name VARCHAR(100) NOT NULL,person_id INT NULL,incident_id INT NULL,qty_out DECIMAL(12,2) NOT NULL,qty_back DECIMAL(12,2) NOT NULL DEFAULT 0,qty_gone DECIMAL(12,2) NOT NULL DEFAULT 0,qty_moved DECIMAL(12,2) NOT NULL DEFAULT 0,note VARCHAR(255) NULL,issued_by INT NULL,issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,closed_at DATETIME NULL,INDEX(item_id),INDEX(person_id),INDEX(incident_id),INDEX(closed_at),FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE) ENGINE=InnoDB");
      q("CREATE TABLE IF NOT EXISTS issue_events(id INT AUTO_INCREMENT PRIMARY KEY,issue_id INT NOT NULL,event VARCHAR(20) NOT NULL,qty DECIMAL(12,2) NOT NULL DEFAULT 0,outcome VARCHAR(20) NULL,by_user INT NULL,by_name VARCHAR(100) NULL,from_name VARCHAR(100) NULL,to_name VARCHAR(100) NULL,note VARCHAR(255) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(issue_id),FOREIGN KEY(issue_id) REFERENCES issues(id) ON DELETE CASCADE) ENGINE=InnoDB");
    }],
    10 => ['Vehicles and structured checks', function(){
      q("CREATE TABLE IF NOT EXISTS vehicles(id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL,kind ENUM('agency','personal') NOT NULL DEFAULT 'agency',owner_person_id INT NULL,item_id INT NULL,plate VARCHAR(30) NULL,notes VARCHAR(500) NULL,active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(owner_person_id)) ENGINE=InnoDB");
      q("CREATE TABLE IF NOT EXISTS vehicle_users(vehicle_id INT NOT NULL,user_id INT NOT NULL,PRIMARY KEY(vehicle_id,user_id),FOREIGN KEY(vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB");
      q("CREATE TABLE IF NOT EXISTS checklist_sections(id INT AUTO_INCREMENT PRIMARY KEY,checklist_id INT NOT NULL,name VARCHAR(100) NOT NULL,sort INT NOT NULL DEFAULT 0,ref_photo VARCHAR(80) NULL,FOREIGN KEY(checklist_id) REFERENCES checklists(id) ON DELETE CASCADE) ENGINE=InnoDB");
      if (!col_exists('checklists','vehicle_id')) q("ALTER TABLE checklists ADD COLUMN vehicle_id INT NULL, ADD COLUMN frequency_days INT NULL, ADD COLUMN overdue_notified_on DATE NULL, ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ADD INDEX(vehicle_id)");
      if (!col_exists('checklist_items','section_id')) q("ALTER TABLE checklist_items ADD COLUMN section_id INT NULL, ADD COLUMN kind ENUM('ok','count','level','value') NOT NULL DEFAULT 'ok', ADD COLUMN expect_qty DECIMAL(12,2) NULL, ADD COLUMN min_level TINYINT NULL, ADD COLUMN item_id INT NULL, ADD COLUMN ref_photo VARCHAR(80) NULL");
      if (!col_exists('checklist_run_items','kind')) q("ALTER TABLE checklist_run_items ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'ok', ADD COLUMN section VARCHAR(100) NULL, ADD COLUMN item_id INT NULL, ADD COLUMN expected DECIMAL(12,2) NULL, ADD COLUMN actual VARCHAR(60) NULL, ADD COLUMN photo VARCHAR(80) NULL");
    }],
    11 => ['Loans, kits, documents and recounts', function(){
      if (!col_exists('people','phone')) q("ALTER TABLE people ADD COLUMN phone VARCHAR(40) NULL, ADD COLUMN is_agency TINYINT(1) NOT NULL DEFAULT 0");
      if (!col_exists('checkouts','overdue_notified_on')) q("ALTER TABLE checkouts ADD COLUMN overdue_notified_on DATE NULL");
      q("CREATE TABLE IF NOT EXISTS kits(id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL,description VARCHAR(255) NULL,active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
      q("CREATE TABLE IF NOT EXISTS kit_items(id INT AUTO_INCREMENT PRIMARY KEY,kit_id INT NOT NULL,item_id INT NOT NULL,qty DECIMAL(12,2) NOT NULL DEFAULT 1,FOREIGN KEY(kit_id) REFERENCES kits(id) ON DELETE CASCADE,FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE) ENGINE=InnoDB");
      q("CREATE TABLE IF NOT EXISTS documents(id INT AUTO_INCREMENT PRIMARY KEY,item_id INT NULL,vehicle_id INT NULL,title VARCHAR(150) NOT NULL,filename VARCHAR(80) NOT NULL,orig_name VARCHAR(190) NOT NULL,size INT NOT NULL DEFAULT 0,uploaded_by INT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(item_id),INDEX(vehicle_id),FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE,FOREIGN KEY(vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE) ENGINE=InnoDB");
      q("CREATE TABLE IF NOT EXISTS recounts(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NULL,scope VARCHAR(150) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
      q("CREATE TABLE IF NOT EXISTS recount_lines(id INT AUTO_INCREMENT PRIMARY KEY,recount_id INT NOT NULL,item_id INT NULL,item_name VARCHAR(150) NOT NULL,expected DECIMAL(12,2) NOT NULL,counted DECIMAL(12,2) NOT NULL,FOREIGN KEY(recount_id) REFERENCES recounts(id) ON DELETE CASCADE) ENGINE=InnoDB");
    }],
    12 => ['Sub-categories', function(){
      if (!col_exists('categories','parent_id')) q("ALTER TABLE categories ADD COLUMN parent_id INT NULL, ADD INDEX(parent_id)");
    }],
    13 => ['Reservations and maintenance schedules', function(){
      q("CREATE TABLE IF NOT EXISTS reservations(id INT AUTO_INCREMENT PRIMARY KEY,item_id INT NOT NULL,incident_id INT NULL,borrower VARCHAR(100) NOT NULL,person_id INT NULL,qty DECIMAL(12,2) NOT NULL DEFAULT 1,starts_on DATE NOT NULL,ends_on DATE NOT NULL,status ENUM('reserved','fulfilled','cancelled') NOT NULL DEFAULT 'reserved',note VARCHAR(255) NULL,created_by INT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(item_id),INDEX(incident_id),INDEX(starts_on),FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE) ENGINE=InnoDB");
      q("CREATE TABLE IF NOT EXISTS maintenance_tasks(id INT AUTO_INCREMENT PRIMARY KEY,item_id INT NOT NULL,title VARCHAR(150) NOT NULL,every_days INT NOT NULL,last_done DATE NULL,next_due DATE NOT NULL,notes VARCHAR(255) NULL,active TINYINT(1) NOT NULL DEFAULT 1,notified_on DATE NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(item_id),INDEX(next_due),FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE) ENGINE=InnoDB");
      q("CREATE TABLE IF NOT EXISTS maintenance_log(id INT AUTO_INCREMENT PRIMARY KEY,item_id INT NOT NULL,title VARCHAR(150) NOT NULL,done_on DATE NOT NULL,user_id INT NULL,note VARCHAR(255) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(item_id),FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE) ENGINE=InnoDB");
    }],
    14 => ['Email preferences, digests, check photos, scheduled reports', function(){
      if (!col_exists('users','notify_prefs')) q("ALTER TABLE users ADD COLUMN notify_prefs VARCHAR(200) NULL, ADD COLUMN digest ENUM('off','daily','weekly') NOT NULL DEFAULT 'off', ADD COLUMN digest_last DATE NULL");
      if (!col_exists('reservations','reminded_on')) q("ALTER TABLE reservations ADD COLUMN reminded_on DATE NULL");
      if (!col_exists('checklists','require_photo')) q("ALTER TABLE checklists ADD COLUMN require_photo TINYINT(1) NOT NULL DEFAULT 0");
      q("CREATE TABLE IF NOT EXISTS check_photos(id INT AUTO_INCREMENT PRIMARY KEY,run_item_id INT NOT NULL,filename VARCHAR(80) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(run_item_id),FOREIGN KEY(run_item_id) REFERENCES checklist_run_items(id) ON DELETE CASCADE) ENGINE=InnoDB");
      q("CREATE TABLE IF NOT EXISTS report_schedules(id INT AUTO_INCREMENT PRIMARY KEY,report VARCHAR(20) NOT NULL,params VARCHAR(200) NULL,frequency ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'weekly',recipients TEXT NOT NULL,active TINYINT(1) NOT NULL DEFAULT 1,last_sent DATE NULL,created_by INT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    }],
    15 => ['Import batches (undo)', function(){
      q("CREATE TABLE IF NOT EXISTS import_batches(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NULL,summary VARCHAR(300) NULL,made TEXT NULL,undone_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
      if (!col_exists('items','import_batch')) q("ALTER TABLE items ADD COLUMN import_batch INT NULL, ADD INDEX(import_batch)");
    }],
    16 => ['Speed-up indexes', function(){
      $want = ['transactions' => ['created_at'], 'audit_log' => ['created_at', 'user_id', 'action'], 'checkouts' => ['in_at', 'person_id'], 'checklist_runs' => ['created_at'], 'item_history' => ['created_at']];
      foreach ($want as $t => $cols) foreach ($cols as $c) { if (!q("SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND column_name=? AND seq_in_index=1 LIMIT 1", [$t, $c])->fetchColumn()) q("ALTER TABLE `$t` ADD INDEX(`$c`)"); }
    }],
    17 => ['Trash, undo, expiry reminders, barcode lookup cache', function(){
      if (!col_exists('items','deleted_at')) q("ALTER TABLE items ADD COLUMN deleted_at DATETIME NULL, ADD COLUMN deleted_by INT NULL, ADD INDEX(deleted_at)");
      if (!col_exists('transactions','undone_at')) q("ALTER TABLE transactions ADD COLUMN undone_at DATETIME NULL, ADD COLUMN undo_of INT NULL");
      if (!col_exists('item_lots','reminded_stage')) q("ALTER TABLE item_lots ADD COLUMN reminded_stage TINYINT NOT NULL DEFAULT 0");
      q("CREATE TABLE IF NOT EXISTS barcode_cache(code VARCHAR(40) NOT NULL PRIMARY KEY,found TINYINT(1) NOT NULL DEFAULT 0,name VARCHAR(200) NULL,brand VARCHAR(120) NULL,size VARCHAR(80) NULL,category VARCHAR(160) NULL,image VARCHAR(80) NULL,source VARCHAR(30) NULL,looked_up_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    }],
  ];
}
function run_upgrade(){
  global $SETTINGS; $log = [];
  q("CREATE TABLE IF NOT EXISTS settings(k VARCHAR(60) NOT NULL PRIMARY KEY,v TEXT NULL) ENGINE=InnoDB");
  $cur = db_version();
  foreach (migrations() as $v => $m) {
    if ($v <= $cur) continue;
    $m[1]();
    q("INSERT INTO settings(k,v) VALUES('db_version',?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [$v]);
    $SETTINGS['db_version'] = $v; $log[] = "Applied update $v: {$m[0]}";
  }
  $up = dirname(__DIR__).'/uploads';
  if (!is_dir($up)) { @mkdir($up, 0755); $log[] = 'Created uploads folder.'; }
  if (!$log) $log[] = 'Nothing to do. The database was already up to date.';
  return $log;
}
