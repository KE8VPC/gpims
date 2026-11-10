GPIMS - General Purpose Inventory Management System (Milestone 6c - Email, passkeys, check photos, reports (includes everything from 3a through 5c))
Requires: PHP 7.4+ (PDO MySQL, mbstring; GD and exif recommended), MySQL/MariaDB, Apache (for .htaccess). Use HTTPS so camera scanning works.

NEW INSTALL
1. Create an empty MySQL database + user in your host control panel.
2. Upload everything in this folder to your web directory. Make the folder and uploads/ writable by PHP.
3. Open /install.php. After install, DELETE install.php from the server.

UPGRADING FROM MILESTONE 1 OR 2
1. Upload this zip's contents over your existing files (config.php is not in the zip and is not touched).
2. Open the site and sign in as an admin. You are sent to the Upgrade page automatically. Click "Apply updates".
   Other users see a short "update in progress" notice until an admin does this.
3. Open Admin > System health and confirm everything is OK.

NEW IN 3b (on top of 3a)
- People list (Admin people permission): anyone who can borrow equipment, with an optional badge barcode. Badges only speed up entering a name; they never log anyone in.
- Badge scanning at check-out, return and transfer, with a person page showing what they hold and their history.
- Account types: Admin, User, Viewer, Vendor. Existing Managers become Users.
- Per-person permissions (Users > Access): add items, edit items, archive, stock moves, check-out, photos, labels, checklists, categories, locations, people.
- Per-person visibility: everything, or only items matching category and/or location rules. Applies to lists, items, scanning, labels, CSV and the dashboard.
- Nested locations (Building > Room). A rule for a building includes its rooms.
- Vendor accounts see only the Supply page for the categories you choose.
- Only admins create categories and locations by default. Users can add items.

NEW IN 3c
- Multiple codes per item, each with its own type (QR, Code 128, Code 39, ITF, UPC-A, EAN-13, EAN-8, UPC-E, Codabar, other). One is marked primary and is the one printed on your own labels.
- Scanning any code on an item finds it. UPC-A and EAN-13 versions of the same number match, and Code 39 start/stop marks are ignored.
- The scanner reports what type it read; check digits are verified for UPC and EAN codes so a misread is rejected.
- Labels: choose QR, Code 128, Code 39, ITF, or "each code's own type" (which reprints a stored UPC-A or EAN-13 as that type). Unprintable combinations fall back to Code 128.
- Existing barcodes are carried over automatically as one code per item.

NEW IN 4a
- Vendors list (Vendors menu, admin by default): contact details, account number, active flag.
- Each item can have several ranked vendors with part number and price. A vendor can be marked unavailable for an item (with a note), which moves it to the bottom of the list.
- Items now have par level, reorder quantity and unit cost.
- Expiration dates per item (lot number, quantity, date). Warning window is set in Admin > Settings.
- Attention page and a red count in the top bar: low stock, out of stock, and expired or expiring items, scoped to what each person can see. Shows the reorder amount and the vendors to call in order.
- Supply notes: staff add notes on the Attention page. Vendor accounts see a Supply page and can acknowledge a low item with a note ("will order 5 Friday, delivery Monday"). Notes clear automatically when stock goes back above the alert level.

NEW IN 4b
- Installable app: Android Chrome menu > Install app, iPhone Safari Share > Add to Home Screen (also a button on the Account page). Needs HTTPS. Pages are never cached offline, so inventory is always live.
- Passkey sign-in: add a passkey on the Account page (one per device). The sign-in page gets a "Sign in with a passkey" button. Admins can turn passkeys on or off in Settings and remove a user's passkeys from Users > Access.
- Passkeys are tied to the exact site address. Always use the same address (with or without www) and do not move domains without re-registering.
- Time zone setting (Admin > Settings) and a condensed top menu (Manage and Admin dropdowns).
- Passkeys need the OpenSSL PHP extension; System health checks for it.
- When a later update changes CSS or scripts, the app refreshes its stored copies in the background on the next visit.

NEW IN 4c (email)
- Admin > Settings > Email: choose SMTP (recommended) or PHP mail(), set the From name and address, the SMTP server, port, security and login, and the site address used in links. Use "Send test email" to check it. The SMTP password is stored encrypted.
- Messages are queued and sent right after the page finishes, retried up to 5 times, and logged in Admin > Email log (with a Retry failed button).
- Password recovery: "Forgot your password?" on the sign-in page emails a one-hour, single-use link. Admins can also email a reset link (Users > Access) or email a new user an invitation to set their own password (tick the box when adding a user).
- Low and out-of-stock emails: sent when an item drops to its alert level or hits zero. Each person opts in on their Account page (or an admin sets it on Users > Access) and only gets items they can see, including vendor accounts. Emails show the suggested order amount and the vendors to call.
- Checklist emails: each checklist can email addresses when it is completed and/or when issues are found (Checklists > edit).
- For best delivery, use an address on your own domain as the From address and your host's SMTP server.

NEW IN 4d (equipment out on loan or on a detail)
- Three kinds of item: Consumable (used up), Returnable by quantity (cones, flares, tarps: issued out and brought back), and Asset (one specific item such as a radio).
- Events / incidents (Manage menu): start one to plan a loan or deployment, or for a fire, traffic control, event or training. Issue gear and record supplies used against it; the incident page shows what is out, what was used, damaged or lost, and a full activity trail.
- Issue returnable items to a person (type a name or scan their badge) and optionally to an incident. Hand some to someone else, return part or all, and mark each return Good, Damaged, Lost or Used up.
- Assets: return with a condition (good, needs charging or cleaning, damaged, lost). Swap a dead radio for a spare in one step; the spare goes to the same person and incident and the original is set to maintenance.
- Out now (top menu, field.php): everything currently out, grouped by person, incident or item. Collect all returns everything a person (or an incident) still has in good condition, recorded as collected by you from them.
- Scan a person's badge on the Scan page to open their page and collect their gear if they left early.
- Home page: a Scan bar (Find item or Add item) and an "Out now" card.
- Item photos: Take photo (opens the camera) or From album; photos are shrunk on the phone before upload.
- New permission "Create and close events / incidents" (on by default for the User role; people with custom permissions need it ticked on Users > Access).
- Fixes: Forgot password / reset pages, links in plain-text emails, long text in forms, scanner manual entry when the camera is unavailable, pending emails are retried during normal use.

NEW IN 4e (vehicles and structured checks) - this zip also contains everything from 4d
- UPGRADE: upload over your site (keep config.php), then open Admin > Upgrade and click Upgrade. It applies database updates 9 (equipment out on loan or on a detail) and 10 (vehicles) in one go.
- Vehicles (top menu): each vehicle is unique, no templates. Agency vehicles are visible to everyone who can view; Personal vehicles have an owner (a person from the People list who is linked to a login) and are visible to the owner, admins and anyone the owner chooses to share with.
- The owner can edit their vehicle, its checks and run them. Admins (or anyone given the new "Add and edit agency vehicles" permission) manage agency vehicles. Regular users can add their own personal vehicle.
- Convert an existing asset into a vehicle (Vehicles > Add vehicle): the old asset record is released and archived, history kept.
- Checks are built from compartments (e.g. Left Rear Bay 6, Crew Cab). Each line is one of: OK / Not OK, Count (expected quantity, flagged if short), Level (Empty, 1/4, 1/2, 3/4, Full with a minimum such as fuel at least 1/2), or Reading / note.
- Reference photos ("how it should look") on compartments and lines; Take photo or From album. While running a check, tap the camera on any line to attach a photo of a problem.
- A count line can be linked to an inventory item. When the count is short, the result notes where that item went (still out with Bob Smith for the structure fire, etc.). Nothing is changed in stock automatically.
- Schedule each check (daily, weekly, monthly, annual or every N days). Overdue checks and checks whose last run had issues appear on the Attention page and in the Attention count, but are not part of the inventory totals. A passing run clears the alert.
- Overdue emails go once to the addresses in "Email when issues are found or the check is overdue" and to the vehicle owner (if they have an email on their login), with a reminder after 7 days.
- General checklists (not tied to a vehicle) still work and use the same editor.

NEW IN 5a (milestone 5) - this zip contains everything from 4d and 4e too
- UPGRADE: upload over your site (keep config.php), open Admin > Upgrade and click Upgrade (applies database update 11). Then run Admin > System health.
- Small changes since 4e: Admins can delete any item (Delete forever, blocked while gear is out). "Out in the field" is now "Out now". Overdue loans (assets past their due-back date) appear on the Attention page.
- Loans to other agencies: on the People page tick "outside agency or organization" and add a phone number. Check an asset out to them with a due date. When it is overdue you see it on Attention (with their phone) and an email goes to whoever issued it and admins who have alert emails turned on (once, then a reminder after 7 days).
- Label styles (Manage > Labels): Standard, address sheet, small tag, wide strip, large (cases and bins) or a custom size; choose what is printed (name, code text, location, organization) and copies each. A live preview updates as you change options.
- Kits (Manage > Kits): a named bundle of returnable items and assets. Issue the whole kit to a person or agency in one step (optionally to an incident, with a due date). All or nothing: if any piece is short or already out, nothing is issued. Return pieces normally from Out now.
- Recount (Manage > Recount): pick a location and/or category, walk the shelf and enter counts (scan an item to jump to its row). Only changed items are adjusted, each logged with old and new number, and a results page lists the differences.
- Documents: attach manuals, warranties, receipts and records (PDF, Office, CSV, text, images up to 15 MB) to any item or vehicle. They are only downloadable by logged-in users who can see that item or vehicle. Stored in the uploads folder under random names; this relies on the uploads/.htaccess file (Apache) so keep it.
- Reports (Manage > Reports): inventory value, reorder list, expiring items, stock activity, out now / loans, check results and recount differences, each with CSV download. New permission "View reports" is on by default for Viewer, User and Manager; people with custom permissions need it ticked on Users > Access.
- Backup (Admin > Backup): download everything (database + photos + documents) as one zip, or the database alone as a .sql file. Restore with phpMyAdmin Import. The page shows when you last made a backup.

NEW IN 5b - this zip contains everything from earlier versions
- UPGRADE: upload over your site (keep config.php), open Admin > Upgrade and click Upgrade (applies database update 12), then run Admin > System health.
- Sub-categories: Manage > Categories now builds a tree, e.g. Communication / Radios / Portable, Mobile, Pagers. Add a category "under" another, rename or move it with the pencil, and delete one (its sub-categories move up a level and its items become uncategorized). A category cannot be moved under itself or its own sub-categories.
- Picking a parent includes everything under it: the inventory filter, recount, supply page and a user's category access rule. Custom fields on a parent also apply to its sub-categories. Lists and reports show the full path.
- Scan moved out of the top bar (Home has Scan and More buttons; Inventory has a Scan button). Checklists is now a menu with All checklists, Vehicles and History.
- "Incidents / details" is now "Events / incidents" (planned loans, deployments, training).

NEW IN 5c - this zip contains everything from earlier versions
- UPGRADE: upload over your site (keep config.php), open Admin > Upgrade and click Upgrade (applies database update 13), then run Admin > System health. Also reload the installed phone app once so it picks up the new offline page.
- Categories page and Inventory list are now collapsible trees (Inventory has By category / Flat list, an Uncategorized section at the bottom, Expand all / Collapse all; open/closed state is remembered per device).
- Reservations (Manage > Reservations): book returnable gear or an asset for a future date range, optionally tied to an Event. It warns when an item is already booked for those dates (tick "Book anyway" to double-book). "Issue now" turns a reservation into a real checkout / issue. Events show what is reserved and have "Issue all reserved". Reservations starting within 2 days appear on Attention and the dashboard.
- Loan sheets: a printable loan / receipt form (borrower, event, items with serials, due dates, condition boxes, signature lines). Open it from an Event, a person's page, or the printer button on Out now. A reservation sheet shows planned items instead. Edit the wording under Admin > Settings.
- Maintenance (Manage > Maintenance, and a panel on each item): recurring tasks such as battery swaps, oil changes, calibration. Mark one done and the next due date moves forward. Due or overdue tasks show on Attention and the dashboard; admins with "stock alert" email on get an overdue email (repeats weekly). Warning window is in Settings.
- Full history: every item has a "Full history" timeline (stock moves, loans, edits, maintenance, checks, recounts, reservations, documents) with filters.
- Dashboard shows upcoming reservations and maintenance due.
- Offline scan log: with no signal the app shows an offline page where you can log stock Used / Added / Counted by typing or scanning a code (camera scanning only on phones that support it). Entries wait on the phone; a banner appears when you are back online, and Manage > Offline scans (or the banner) reviews and syncs them. Each entry is applied once. The phone refreshes its item lookup list in the background every few hours while you use the app.
- Audit log: search, filter by user / action / date, pages of 100, and CSV export.
- Fix: day counts for expiring items and overdue loans/checks could be off by one around the daylight-saving change; corrected.
- New permission "Set up and complete maintenance tasks" (given to Users and Managers by default; admins can change it per user).

NOTES
- Photo files in uploads/ are not access-controlled (their names are random and unguessable).
- Run Admin > System health after upgrading.

IMPORT (Admin > Import)
-----------------------
Upload one or more CSV exports (Equipment List / Inventory List formats are auto-detected). You get a preview
of new items, categories, locations, people and possible duplicate pairs before anything is saved.
Same serial / asset tag = merged automatically; name-only matches are shown as checkboxes. Owner/crew names
become open checkouts, group names become locations. Re-running skips items already imported.

RESERVATIONS CALENDAR: Reservations > Calendar shows a month grid (desktop); list view remains on phones.

NEW IN 6 - this zip contains everything from earlier versions
-------------------------------------------------------------
After uploading, open Admin > Upgrade once (database update 14).

EMAIL
- Each person chooses what they get on their Account page (or an admin sets it on Users > Access): low stock, overdue loans, overdue maintenance, reservations starting soon, and a daily or weekly SUMMARY email. The summary lists low stock, expiring items, loans overdue or due in 3 days, checks overdue, maintenance due this week and reservations in the next 3 days, only items that person can see, and is only sent when there is something to report.
- Accounts that never saved their choices keep the old behaviour (admins with low-stock alerts on also get loan and maintenance mail).
- Reservation reminders go to whoever made the reservation, the borrower's linked login, and anyone who ticked the option, the day before and the day it starts.
- Settings > Email: "send summaries after (hour)", "Email me a summary now" (preview), and the scheduled-task address.
- Scheduled tasks: mail is sent while people use the site. For on-time delivery when nobody is signed in, add a cron job in your hosting panel, every 15 minutes, that opens the address shown in Settings (cron.php?key=...). "New address" replaces the key if it leaks.
- Passkey added/removed security emails go to the account owner.

PASSKEYS
- Account page: each passkey shows its type (this device / phone / security key), when it was added and last used, and can be renamed.
- Sign-in page: tapping the username box offers your saved passkeys (no need to press the passkey button). The button still works.
- The home page shows a dismissible hint to add a passkey on devices that support it.
- Still needs HTTPS and the OpenSSL PHP extension, and one site address (see 4b notes).

CHECK PHOTOS
- Up to 4 photos per problem line; each shows a thumbnail with a remove button before you submit.
- Tap any photo on a result to enlarge it; if the line has a "how it should look" reference photo it appears beside the reported photo.
- Checklist edit > "Require a photo for every problem found": the Submit button is blocked until every Not OK / Missing / Problem line has a photo.
- Issue emails link to each photo. Check history shows a camera count; each vehicle page has a "Recent problem photos" strip.

REPORTS (Manage > Reports)
- 14 reports in three groups: Stock (inventory list, value, reorder, expiring, stock activity, recount differences), Loans & planning (out now, held by person, reservations, maintenance schedule, maintenance history), Checks (results, compliance, problem lines that fail most).
- Category and location filters where they make sense; click a column heading to sort; filter box; CSV; Print / PDF (use the browser's Save as PDF); "Email me" sends the current report to you.
- Admins can schedule a report by email (every morning, every Monday or the 1st of the month) to any addresses. Scheduled reports show everything regardless of who set them up. Reports with a fixed date range always reuse those same dates, so prefer reports without a range for schedules.

ALSO
- Reservations calendar (Reservations > Calendar), a month grid on desktop.
- The CSV import page is hidden from the menu for now (the code remains in import.php and inc/import.php for later).

NEW IN 6a - import tidy-up (open Admin > Upgrade once, database update 15)
-------------------------------------------------------------------------
- Admin > Import is back in the menu. Upload the Equipment List and Inventory List CSV files; the preview shows everything before anything is saved.
- You do not have to fix anything first. Buttons above the table show the rows that probably need a second look: no name, no category, no location, quantity unknown, out of service, merged duplicates. Pairs that matched on name only can be ticked all or none.
- Every import is a batch. "Past imports" on the Import page has an Undo button: it removes the items that import created, plus any categories, locations and people it created that nothing else uses. Items that have been used since (stock changes, loans, reservations, maintenance, documents, kits) are kept and listed.
- Re-importing the same files skips what is already in GPIMS, so you can import in rounds.
- CSV template: Admin > Import has "Download blank template" and "Download with 3 example rows". Columns: Name (required), Type, Category (Parent / Child), Location, Quantity, Unit, Alert At, Serial, Tag, Unit Cost, Assigned To, Status, Notes. Upload it like the other files; you can mix it with the Equipment/Inventory list exports. Delete the example rows before importing.

NEW IN 6b - security and speed pass (open Admin > Upgrade once, database update 16)
-----------------------------------------------------------------------------------
- Security headers on every page (no framing by other sites, no content-type sniffing, same-origin referrer, camera allowed only for GPIMS, HSTS on HTTPS) plus a content-security policy that only allows GPIMS itself and the Bootstrap CDN.
- inc/ folder, README.txt, *.sql, *.zip, *.bak, *.log and *.md are blocked from direct download (Apache .htaccess).
- Checked: every page requires sign-in and the right permission; every form needs a CSRF token; all database queries use bound parameters; all output is HTML-escaped; uploads are re-encoded images or whitelisted documents served through a permission check; out-of-scope items are refused on every page and the offline scan endpoint.
- Housekeeping: once a day old sign-in attempts (30 days), delivered/failed email (90 days) are removed so the tables stay small.
- Speed: new database indexes (stock history, audit log, loans, check history), gzip compression and static-file caching (ignored by hosts without those Apache modules); CSS/JS links carry the version so upgrades are picked up immediately.
- Import files are capped at 20,000 rows each.
- Inventory list: Qty, Unit and Status are now separate columns (items.php).
- Recount page: Unit is its own column (recount.php).

NEW IN 6c - paging, expiry, quick add, backup and restore, trash and undo (open Admin > Upgrade once, database update 17)
-----------------------------------------------------------------------------------------------------------------------
Paging and live search
- Inventory search updates as you type (no Go button needed); filters apply instantly. Flat list is paged (25/50/100/200). By-category view loads each category's items when you open it once there are more than 120 matches, so the page stays small however large the inventory gets.
- Labels page: search and page through every item; ticked items stay ticked across searches and pages. "Select all matching" ticks everything the search found.
- Out now is paged by group (25 per page) and has a filter box.
Expiry
- Items list shows an "exp <date>" / "expired" badge and has an "Expired or expiring soon" filter; CSV export includes the next expiry date.
- New item form has an optional expiry date (creates the first lot with the starting quantity).
- Each lot has a Discard button: takes its quantity out of stock (logged, and undoable) and removes the entry.
- Email reminders: one email per person at 3 stages per lot (inside the warning window, within 7 days, expired). Turn on "Items that are about to expire or have expired" under My account > email. Admins with stock alerts on who never chose preferences get them by default. Needs email set up and the cron address (Settings) or normal site traffic.
Quick add (Add item)
- Scan a barcode, or pick/take a photo of one, and the name, brand/size, a category suggestion and a product photo are filled in from free public databases (Open Food Facts, Open Products Facts, UPCitemdb). Only the barcode number is sent. Results are cached. Turn it off in Settings. It needs your host to allow outgoing HTTPS (curl or allow_url_fopen); if it cannot reach them you simply type the details.
- "Take a photo of the item" attaches photos when you save.
Backup and restore (Admin > Backup)
- Download everything (zip: database + photos/documents) or the database only.
- Optional automatic daily database backup kept on the server (newest N kept), listed on the page with download/delete.
- Restore from an uploaded .sql / .sql.gz / full .zip, or from a copy stored on the server (a file you FTP into the backups folder works too). You must enter your password and type RESTORE. A safety copy of the current data is saved first, only GPIMS tables and statements are accepted, backups from a newer GPIMS version are refused, and you are signed out afterwards. If a big file will not upload (host limit), use the backups folder instead.
- System health shows the age of the last backup.
Trash and undo (Manage > Trash and undo)
- Delete on an item now moves it to the trash (admins). Restore it for 30 days (change in Settings); after that it is removed for good. "Delete forever" and "Empty trash" are there for admins.
- Undo for stock changes: the latest add / use / count change on an item can be undone (own changes within 7 days, or any if admin) from the item's history or the Trash and undo page.
