=== BDACI Portal Access ===
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 2.11.0
License: GPLv2 or later

Restricts the BDACI Board & Committee Governance Portal to authorized users.

== Description ==

* Portal sign-in page at /portal-login/ (email + password, Microsoft 365, Google).
* A 6-digit code is emailed after the password (can be turned off).
* Only people on the Authorized Users list (BDACI Portal > Authorized Users) can get in.
* Roles: Board Member, Education Committee Member, Finance Committee Member, Portal Admin.
* Everything under the chosen "Portal home page" is protected; single pages can be narrowed to Board-only, Education Committee or Finance Committee.
* Sections can be hidden until they're ready (BDACI Portal > Sections); hidden pages show "not found" to everyone but admins.
* Private document storage for agendas, minutes and reports, with drag-and-drop, paste and multi-file upload.
* Activity log of sign-ins and access changes.
* The portal itself (2.0): its own layout with a dashboard, calendar, meetings with RSVP, Leadership and committee directories, Governance folders with minutes by year, New Director Orientation and Quality Assurance sign-offs with an audit trail, Request access for committee sections, profile pictures and a Support form.

Shortcodes:
* [bdaci_document id="123" text="Agenda (PDF)"]: link to a private document.
* [bdaci_portal_only level="board|committee|finance|portal"]...[/bdaci_portal_only]: show content to portal users only.
* [bdaci_login_link] and [bdaci_logout_link]: sign-in and sign-out links.

See SETUP-GUIDE.md for installation and Microsoft/Google setup.

== Changelog ==

= 2.11.0 =
* Document viewer: documents open in a pop-up in the portal instead of a new tab, with Expand, Full screen, Print, Download and Open in new tab. PDFs, pictures and text files show inside it; Word, Excel and PowerPoint files offer a download.
* Sign-off in the viewer: when a document asks for a sign-off, the viewer shows the statement, a confirm box and a signature (type your name or draw it). Closing without signing asks first ("Sign now" or "Close, sign later").
* Sign-off packs (New Director Orientation, Quality Assurance) can be signed from the viewer once every document has been opened, with "Next" buttons for the ones still to open. The sign-off page asks for a signature too.
* Sections & Uploads: "Ask people to sign off after reading it" with an optional statement, on upload and in Edit, rename or move. The document list shows "Sign-off: 2 of 5 signed".
* Sign-off Audit Trail: new "Document sign-offs" tab with who signed (date, time and signature), who opened it, who hasn't, an "Email a reminder" button, CSV and printable exports. Signatures also show for the sign-off packs, in the CSV and on the printable receipts.
* Home: documents waiting for your signature are on your to-do list.

= 2.10.0 =
* Updates from GitHub: new versions show on the Plugins screen like any other plugin update, with "update now" and "View details" (release notes and changelog).
* "Check for updates" link on the Plugins screen and a "Check for updates now" button in Settings › Updates, with the installed version, the newest version and the last check time.
* Automatic updates (on by default, can be turned off in Settings › Updates).
* Settings › Updates: GitHub repository and a read-only GitHub access key for the private repository (or BDACI_PA_UPDATE_REPO / BDACI_PA_UPDATE_TOKEN in wp-config.php). The key is never shown back.
* Plugin updates are recorded in the activity log.

= 2.9.1 =
* QA Testing CSV: notes that start with =, +, - or @ are exported as text so a spreadsheet can't run them as formulas.
* QA Testing screenshots: the size limit now follows the server's upload limit, files that are too big or not pictures are left out before sending (and named), and a post too large for the server shows a clear message instead of a blank page.

= 2.9.0 =
* Rename an uploaded document's file (the name it downloads as); the type stays the same and links keep working.
* Move a document to other sections from its 'Edit, rename or move' panel. Renames and moves are listed on the document with who and when, and written to the activity log.

= 2.8.0 =
* Sections & Uploads: search box above the section cards filters them as you type.
* The document date on the upload form starts as today.
* Upload times shown with every document (member pages, Home, admin list, WordPress admin list), and sign-off sent, reminded and signed times shown on the audit trail.

= 2.7.0 =
* QA Testing: add screenshots to a result (pick, paste with Ctrl+V or drop on the card), and save a note without changing the result. Notes and screenshots show on the card, in the history, in the QA record and as links in the CSV. Screenshots are kept in the protected folder and only people with QA access can open them.
* QA Testing: every scenario shows 'Where to find it' (for example Portal › Portal admin › Meetings) with an Open button.
* Breadcrumbs at the top of every portal page and admin screen, for example Home › Board › Governance & Core Documents.
* Fix: the Skipped button on QA Testing was hidden.

= 2.6.0 =
* New QA Testing page in Portal admin (also under BDACI Portal in WordPress admin). Test scenarios are grouped into tabs by topic, with filters for not tested, failed and marked by me.
* Testers mark each scenario Passed, Failed or Skipped with a note. Every check is recorded with who and when, and the QA record tab can be filtered by group, tester and result.
* Testing rounds, CSV export, and a 'Who can see this page' tab to give chosen members access.

= 2.5.0 =
* New sign-in page design to match the portal: a brand panel on the left (70 years badge, illustration of a board meeting, headline and tagline) and the sign-in form on the right with the BDACI logo.
* Settings: optional Sign-in picture (a photo from the Media Library, tinted with the BDACI colours), Sign-in headline and Sign-in tagline.
* Portal Admins can see the sign-in page while signed in with "Preview the sign-in page" in Settings (/portal-login/?preview=1). Signed-in members are still sent straight to the portal.

= 2.4.0 =
* Section groups now use the full width, each with a large map of the portal page showing every spot where its files appear.
* Point at or click a section to highlight exactly where it shows (menu item and spot on the page). Click any map to zoom it.

= 2.3.0 =
* Sections & Uploads: sections are grouped by where they appear (Meeting documents, Governance & Core Documents, Board sign-offs, Committee pages, Sections you added), each with a mini map of the portal highlighting the menu and page.
* Each section card says where it shows. A live "Where this file will appear" panel updates as you tick sections and who can open it.

= 2.2.0 =
* Portal Admins can now run the portal without going into WordPress admin: Authorized Users (with access requests and directory details), Sign-off Audit Trail, Sections & Uploads, Meetings and Test data open inside the portal from the "Portal admin" side menu. The same screens remain under BDACI Portal in WordPress admin.
* Links in the portal (Edit directory, Add documents, Show to users) open the portal versions.

= 2.1.0 =
* New BDACI Portal > Test data screen (site administrators): "Add sample data" adds people, meetings, documents, RSVPs, sign-off activity and an access request each time it's pressed; "Remove all test data" removes only those; "Get ready for launch" clears all portal activity.
* Fix: adding a meeting returned the wrong ID internally.
* Portal pages also drop Elementor's global styles (unless "Theme styles" is kept on in Settings).

= 2.0.0 =
* Portal layout: pages under the portal home now use the portal's own top bar, side menu and screens (can be turned off in Settings). "Create the portal pages" builds the page tree and reuses existing pages.
* Dashboard: next meeting with RSVP and documents, to-do list, recently posted documents, mini calendar.
* Meetings screen for admins (Board / Education / Finance / Other, Teams link, agenda, package and minutes); member Calendar and Meetings pages; Attending / Regrets / Tentative with replies for admins and the Secretary.
* Sections & Uploads replaces Documents and Sections: file a document in one or more sections, choose one or more groups who can open it, set a document date, edit or delete it, upload history with CSV export, hide/show Governance folders (Strategic Plan hidden by default) and add new sections.
* Sign-offs: New Director Orientation and Quality Assurance with "open every document first", confirmation emails with CC, printable receipts, send and remind, and a Sign-off Audit Trail with CSV and print export.
* Request access for the Education and Finance Committee sections, approved or declined on Authorized Users.
* Leadership and committee directories with directory details per person; My profile with photo upload, 30 illustrated pictures or initials, and an optional shared phone number.
* Support form that emails the help contact.

= 1.2.0 =
* Finance Committee Member role and a "Finance Committee + Board Members" access level for pages and documents.
* Committee Member is now labelled Education Committee Member (same role, nothing to migrate).
* Hide / show sections: "Hide this section" in the Portal access box and a new BDACI Portal > Sections screen. Hidden sections return 404 to everyone except admins, and changes are logged.
* Fix: upgrades now run on init, so updating the plugin no longer fails when its version changes.

= 1.1.0 =
* Documents: drag-and-drop, copy-and-paste (including screenshots) and up to 20 files per upload.

= 1.0.0 =
* First release.
