# BDACI Portal Access: setup guide

This plugin locks the BDACI Board & Committee Governance Portal on bdaci.com so that only approved people can see it. People sign in with email + password (plus a code sent to their email), or with their Microsoft 365 or Google account.

---

## 1. Before you install

1. **Back up the site** (your host's backup tool or a plugin like UpdraftPlus).
2. **Make sure the site sends email.** Verification codes and password links go out by email. If the site doesn't send email reliably, install an SMTP plugin (for example WP Mail SMTP) and connect it to BDACI's Microsoft 365 or Google mailbox. Test it with that plugin's "send test email" button.
3. **Use pretty permalinks.** Go to Settings → Permalinks and pick anything except "Plain" (for example "Post name"). The sign-in address and the Microsoft/Google redirect addresses depend on it.
4. The site must use **HTTPS** (bdaci.com already does).

## 2. Install

1. In WordPress go to **Plugins → Add New → Upload Plugin**.
2. Choose `bdaci-portal-access.zip`, click **Install Now**, then **Activate**.
3. A new **BDACI Portal** menu appears in the left sidebar.

## 3. Create the portal pages

1. Go to **BDACI Portal → Settings** and pick the **Portal home page** (for example a page called "Board Portal"). If you don't have one yet, skip this: step 2 creates it.
2. Go to **BDACI Portal → Sections & Uploads** and click **Create the portal pages**. This builds the whole portal under the home page:
   Calendar, Meetings, Support, My profile, Leadership, Governance & Core Documents, New Director Orientation, Quality Assurance, Education Committee and Finance Committee.
   * If a page with one of those titles already exists under the portal home, it is reused instead of duplicated.
   * Run it again at any time; it only adds what's missing (the screen tells you when something is missing).
3. Every page under the portal home is protected automatically and shown in the portal's own layout: top bar, side menu, dashboard, calendar and so on. Anything you type into one of these pages in the editor (or in Elementor) appears below the portal screen, so you can add extra text.
4. To narrow a page, open it and use the **Portal access** box on the right: *All portal users*, *Board Members only*, *Education Committee only*, *Finance Committee + Board Members*, or *Public*. The created pages already have the right setting (Orientation and QA are Board-only, Education is committee-only, Finance is Finance + Board).

The Pages list shows a 🔒 "Portal access" column so you can check what's protected.

### Sections & visibility (BDACI Portal → Sections & Uploads)

* **Hide / Show** a page and everything under it. Hidden pages show "page not found" to everyone except Portal Admins, who see them with a "Hidden" label so they can prepare content.
* **Document folders** on the Governance page (Bylaws, Board Policies, Strategic Plan, Financial Reports) have their own Hide / Show. **Strategic Plan starts hidden** (BDACI's decision); documents filed only there can't be opened until you show it.
* **Add a section**: give it a name, pick the menu (Board or Committees) and who can open it. The plugin creates the page and it appears in the side menu straight away. People who can't open it see it marked "Restricted".
* Every change is recorded in the Activity Log.

### Managing the portal from the portal itself

Portal Admins don't need to go into WordPress admin for day-to-day work. When they sign in, the portal's side menu has a **Portal admin** group:

* **Authorized Users**: access requests, add people (one at a time or many at once), change roles, approve, revoke, restore or remove, send set-password emails, and edit directory details.
* **Sign-off Audit Trail**, **Sections & Uploads** and **Meetings**: the same screens as in WordPress admin, in the portal's look.
* **Test data** (site administrators only) and a link to the **Activity Log**.

Everything they do there is the same as doing it under **BDACI Portal** in WordPress admin, and both stay in step. Settings (sign-in options, Microsoft/Google keys) stay in WordPress admin for site administrators.

## 4. Settings (BDACI Portal → Settings)

| Setting | What to do |
|---|---|
| Portal home page | Pick the "Board Portal" page. **Nothing is protected until you do this.** |
| Education Committee pages | **Leave off** (BDACI's decision): Education pages are for committee members only, and others use Request access. Turning it on lets all Board Members see them. |
| Access requests | When on, someone who signs in with Microsoft/Google but isn't on the list becomes a "Pending" request that you can approve. |
| Send requests to | Who is emailed about new requests. |
| Portal name, welcome text, logo URL, brand color, help email | Branding for the sign-in page. Upload the logo in Media and paste its URL. |
| Sign-in picture, headline, tagline | The left side of the sign-in page. It shows a built-in illustration of a board meeting by default. To use a photo instead, upload it in Media and paste its URL; it is tinted blue-green so the text stays readable. **Preview the sign-in page** shows it while you are signed in. |
| Email + password sign-in | Keep "require a 6-digit code" **on**. |
| Minimum password length | 12 by default. Passwords also need 3 of: lowercase, uppercase, numbers, symbols. |
| Portal layout | Leave **on**. Turning it off shows portal pages in the normal site theme instead (no dashboard, calendar or side menu). |
| Portal fonts | Loads Ubuntu and Open Sans from Google Fonts. Turn off if the site already loads them or you prefer not to use Google Fonts. |
| Theme styles | Leave **off**. The portal hides the theme's and Elementor's stylesheets on its own pages so they don't clash. Turn on only if content you add to a portal page in the editor looks unstyled. |

The sign-in page is **https://bdaci.com/portal-login/**. If you are already signed in, that address takes you straight into the portal; use **/portal-login/?preview=1** (or the Preview button in Settings) to see the page, or open it in a private window. Add it to your site menu as "Board Portal Login", or use the `[bdaci_login_link]` shortcode. The portal's top bar has its own **Sign out** link.

The **Help contact email** receives messages from the portal's Support page (it falls back to "Send requests to").

## 5. Add the people (BDACI Portal → Authorized Users)

* **One at a time:** enter email, name, tick roles, and leave "Create their account now and email them a link to set a password" ticked. They get an email to set a password.
* **Many at once:** open "Add many at once" and paste lines like
  `jane@example.org, Jane Doe, board;finance`
  Roles: `board`, `committee` (Education Committee), `finance` (Finance Committee), `admin`.
* If someone will only use Microsoft or Google sign-in, untick the invitation. Their account is created the first time they sign in.
* **Revoke** signs the person out right away and blocks them. **Restore** gives access back.
* **Pending** rows are access requests. Tick their roles and click **Approve with these roles**. They get an email saying they can sign in.

Roles:

* **Board Member**: all portal pages + Board-only pages.
* **Education Committee Member**: all portal pages + Education Committee pages. (Called "Committee Member" before version 1.2; nothing changes for existing people.)
* **Finance Committee Member**: all portal pages + Finance Committee pages. Use this for Finance members who are not directors; Board Members can already see Finance pages.
* **Portal Admin**: sees everything and can manage the Authorized Users list, Documents and Activity Log. (Only WordPress Administrators can change Settings.)

A person can have more than one role, for example Board + Committee.

**Directory details.** Click **Directory details** next to a person to set what appears on the Leadership and committee pages: board position (e.g. President, Secretary), term, Education/Finance committee position, organization, display order, or hide them from the directory. Whoever's board position contains "Secretary" can see meeting RSVP replies, like Portal Admins.

**Access requests.** When a board member opens the Education or Finance Committee section without access, they can click **Request access** with an optional note. You get an email, and the request appears at the top of **Authorized Users** with **Approve** / **Decline**. Approving adds the committee role to their account, so it stays until a Portal Admin removes it; they are emailed when it's approved.

## 6. Upload documents privately (BDACI Portal → Sections & Uploads)

Files in the normal Media Library are **public**: anyone with the link can open them, even without signing in. Upload agendas, minutes, financial reports and so on here instead:

1. Add the files by **dragging** them onto the dotted box, **pasting** (Ctrl+V / ⌘+V, screenshots too) or **clicking** the box. Up to 20 at a time.
2. Give it a title (used for a single file; with several, each is titled from its file name) and a **document date** (for example the meeting date; it sorts the minutes archive by year).
3. Pick the **Section(s)** it belongs to: Agenda, Meeting package, Minutes, Bylaws, Board Policies, Strategic Plan, Financial Reports, New Director Orientation, Quality Assurance, Education Committee, Finance Committee, or a section you added. The file appears in each one. The sections are grouped by where they show (Meeting documents, Governance & Core Documents, Board sign-offs, Committee pages, Sections you added). Each group has a small map of the portal with its menu and page highlighted, and each card says "Shows on: …". As you tick sections and who can open it, the **Where this file will appear** box above the Upload button lists every menu and page the file will show on. Point at or click a section card to highlight exactly where it shows on its group's map, and click any map to zoom it.
4. Pick **Who can open it**: All portal users, Board Members, Education Committee, Finance Committee (+ Board). Tick more than one to combine groups. Portal Admins can always open everything. Click **Upload**.

The **Documents** list below lets you edit a file's title, date, sections and who can open it, or delete it. **Upload history** shows what was posted when and by whom, with a CSV export. Each document also has a shortcode, for example `[bdaci_document id="123"]`, if you want to link it from a page's text.

The files are stored in a private folder whose name is randomized. On Apache/LiteSpeed hosts a `.htaccess` file blocks direct access. **If your host uses Nginx**, ask them to add this rule (replace the folder name with the one under `wp-content/uploads/` that starts with `bdaci-portal-private-`):

```
location ~* /wp-content/uploads/bdaci-portal-private-[a-z0-9]+/ { deny all; }
```

**Move existing documents.** If board PDFs are already in the Media Library, re-upload them here and delete the public copies.

## 7. Meetings and the calendar (BDACI Portal → Meetings)

1. **Add a meeting**: pick the group (Board, Education Committee, Finance Committee, or Other for events everyone sees), title, date, start and optional end time, location and optional Microsoft Teams link (must start with https://).
2. Attach the **agenda, meeting package and minutes** from documents you've uploaded. You can add them later with **Edit**, for example the minutes after the meeting.
3. The meeting appears on the **Calendar**, on **Meetings** and, if it's the next one, in the **Next meeting** card on the dashboard, only for the people invited. Board Members also see Finance Committee meetings, matching their access.
4. Members answer **Attending / Regrets / Tentative**. The counts show on this screen; click **Replies** to see who said what. Unanswered meetings appear in each person's to-do list.

## 8. Sign-offs and the audit trail (BDACI Portal → Sign-off Audit Trail)

There are two sign-offs: **New Director Orientation** and **Quality Assurance {year}**. Board Members get both by default.

1. File the documents under the New Director Orientation or Quality Assurance section (step 6).
2. Open **Sign-off settings** on this screen to change the title, intro text, the statement people confirm, the due date, who must sign (roles or named people) and the CC address for confirmation emails (QA copies afenlong@bdaci.com by default).
3. Click **Send** next to a person, or **Send to … people not sent yet**, to email them a link. **Remind** / **Remind all** send reminders.
4. In the portal, the person must **open every document** before the "I have read and understood" box and **Sign off** button unlock. They sign by typing their name or drawing a signature (mouse, finger or pen). They can also sign right inside the document viewer once they have opened every document. They get a confirmation email (with the CC) and can print a receipt that shows the signature.
5. This screen shows who has signed, who hasn't, when each was sent, every reminder, each document opened and each signature, with **Export CSV** and **Print / save as PDF**. "Start a new round" (for next year's QA) clears the current status but keeps the history in the log.

## 9. What members see

* **Home**: a greeting, the next meeting (with RSVP and documents), their to-do list (sign-offs and RSVPs), recently posted documents and a mini calendar.
* **Leadership** and committee pages: a directory with pictures, positions, terms, email and (if they choose) phone.
* **Governance & Core Documents**: folders, with minutes organized by year.
* **My profile**: upload a photo (JPG/PNG, up to 5 MB, only visible to signed-in members), pick one of 30 illustrated pictures, or show initials. They can add a phone number and choose whether to share it.
* **Support**: a short form that emails the Help contact address.
* Sections they can't open show "Members only" in the menu and a **Request access** button.

## 9a. Test data for staging (BDACI Portal → Test data)

Site administrators get a **Test data** screen for trying the portal before launch.

* **Add sample data** adds 6 people (Board, Education and Finance), 14 private PDF documents filed in the sections, 5 meetings with RSVPs, sign-off activity and one access request. Press it again for more; each press uses new names and later meeting dates. No emails are sent.
* To **see the portal as a test person**, sign in at /portal-login/ with their email and the password shown on the screen. Their emails are your own address with a "+bdaci-test-…" tag (for example you+bdaci-test-margaret-hughes@yourdomain), so their sign-in codes arrive in your inbox. Gmail and Microsoft 365 both deliver these.
* **Remove all test data** deletes only what that button added. Anything you created yourself stays.
* **Get ready for launch** (type RESET to confirm) clears all RSVPs, sign-off history, access requests and the activity log, and by default removes the test data first. People, meetings, documents, pages and settings you created are kept. Use it once, just before you invite the real board.

## 8a. Document viewer and single-document sign-offs (new in 2.11)

**The viewer.** Every document in the portal (sections, meetings, sign-off pages, the Home to-do list and `[bdaci_document]` links) now opens in a pop-up viewer instead of a new tab. The viewer has **Open in new tab**, **Expand** (fills the browser window) and **Full screen**. There are no Download or Print buttons, and the browser's own PDF toolbar is hidden where the browser allows it (Chrome and Edge; Firefox and Safari may still show their own controls). PDFs, pictures and text files show inside it. Word, Excel and PowerPoint files can't be shown in a browser, so the viewer offers a download for those. Holding Ctrl / ⌘ while clicking still opens the file in a new tab.

**Ask people to sign off on a document.**
1. When uploading, or later from **Edit, rename or move** on the document, tick **Ask people to sign off after reading it**. Optionally change the statement people confirm (default: *I have read and understood "title".*).
2. Everyone who can open that document gets a **Sign-off** item on their Home to-do list.
3. In the viewer, a folded bar at the bottom says **Sign off on this document** with a **Sign now** button, so the document stays easy to read. They tick the statement and sign. The **Type** tab is the default and already shows their first and last name as a signature in a script font; they can change the name (**Use my name** puts it back) or choose **Draw** to sign by hand.
4. If they try to close the viewer (✕, Esc or clicking outside) without signing, it asks first: **Sign now** or **Close, sign later**.
5. They get a confirmation email and a printable receipt with the signature. The document then shows a **Signed** pill.

**Audit.** Sign-off Audit Trail › **Document sign-offs** lists each document with who signed (signature, date and time) and who hasn't (opened or not opened yet), with **Email a reminder**, **Export CSV** and **Print / save as PDF**. Each document in Sections & Uploads shows "Sign-off: X of Y signed".

## 9b. QA Testing (BDACI Portal → QA Testing)

A built-in checklist for your team to test the portal before launch. Open it from Portal admin › QA Testing, or WordPress admin › BDACI Portal › QA Testing.

- **Test scenarios tab.** Scenarios are grouped by topic (Sign-in, Roles and access, Home and calendar, Meetings, Documents, Sign-offs, Admin, Profile, Devices). Each group tab shows how many are done and how many failed. Filter chips show All, Not tested, Failed, or Marked by me. Each scenario has steps and the expected result; mark it Passed, Failed or Skipped and add a note. The card shows who marked it and when, plus the full history for the round. Version 2.11 adds scenarios for the document viewer, signing inside it, drawn signatures, the close warning, asking for a sign-off on upload and the Document sign-offs audit.
- **Where to find it.** Every scenario shows a breadcrumb such as *Portal › Portal admin › Meetings* and an **Open** button that opens that page in a new tab.
- **Notes and screenshots.** Type a note, then add screenshots with **Add screenshots**, by pasting into the note box (Ctrl+V / ⌘+V), or by dropping files on the card. Pressing Passed, Failed or Skipped saves them with the result; **Save note only** adds them without changing the result. PNG, JPG, GIF or WebP, up to 8 MB each and 6 at a time. Screenshots are stored in the protected documents folder and open only for people who can see QA Testing.
- **QA record tab.** Every check ever made in the round, newest first, with tester, time, result, note and screenshots. Filter by group, tester or result.
- **Who can see this page.** Portal Admins always can. Tick any other active member to let them test; they get a "QA Testing" link in their portal menu. Changes are written to the activity log.
- **Rounds.** Start a new round (for example "Round 2, after fixes") to test everything again from scratch. Earlier rounds stay viewable from the round picker. Only Portal Admins can start a round.
- **Export CSV.** Downloads the latest result per scenario (with where to find it) and every check, with links to its screenshots, for sharing or sign-off.
- **Breadcrumbs.** Every portal page and admin screen now shows where it sits at the top, for example *Home › Board › Governance & Core Documents* or *Home › Portal admin › QA Testing*.

Before go-live you can remove testers' access on the same tab. Deleting the plugin removes the QA data.

## 9c. Sections & Uploads tips

- **Search sections.** Type in the search box above the section cards (for example "minutes" or "finance") to show only matching sections.
- **Document date** starts as today. Change it to the meeting date when uploading agendas or minutes. The upload time is recorded automatically.
- **Times everywhere.** Each document shows its date and when it was uploaded ("Uploaded 11:21 AM", or the full date and time if it was uploaded on another day). The Sign-off Audit Trail shows the date and time each person was sent, reminded and signed.
- **Rename or move a document.** In the document list, open **Edit, rename or move**. Change **File name** to rename the file people download (the .pdf/.docx type stays the same, and existing links keep working). To move it, untick the section it is in now and tick the new one. Each rename and move is listed on the document with who did it and when, and recorded in the Activity Log.

## 9d. Updates (Plugins screen and BDACI Portal → Settings → Updates)

New versions of the plugin are published as releases in the private GitHub repository. The plugin checks for them like any WordPress.org plugin:

- **Plugins screen.** When a newer version exists, the BDACI Portal Access row says "There is a new version available" with **View details** (release notes and changelog) and **update now**. The row also has a **Check for updates** link that asks GitHub straight away.
- **Automatic updates.** On by default: WordPress installs new versions on its own (it checks about twice a day). Turn it off under Settings → Updates → Automatic updates. The Plugins screen shows "Auto-updates enabled" while it is on.
- **Settings → Updates** shows the installed version, the newest version, when it last checked, any problem (for example a wrong access key), and a **Check for updates now** button. Each update is recorded in the Activity Log.

**One-time setup on each site.** The repository is private, so each site needs a read-only GitHub access key:

1. On GitHub, open your profile picture → **Settings** → **Developer settings** → **Personal access tokens** → **Fine-grained tokens** → **Generate new token**.
2. Name it "BDACI portal updates", pick an expiry (for example one year, and put a reminder in the calendar to renew it), and under **Repository access** choose **Only select repositories** → `bdaci-portal`.
3. Under **Repository permissions**, set **Contents** to **Read-only**. Leave everything else as "No access". Generate the token and copy it.
4. In WordPress, go to **BDACI Portal → Settings → Updates**, paste it into **GitHub access key**, and save. Leave **GitHub repository** as `ericmdizon/bdaci-portal`. Click **Check for updates now** to confirm it works.

Or put the key in `wp-config.php` instead (see section 12).

**Publishing a new version** (for whoever maintains the code): raise the version number in the plugin header and `BDACI_PA_VERSION`, add a changelog entry to `readme.txt`, and merge to `main`. A GitHub Action builds `bdaci-portal-access.zip` and publishes release `vX.Y.Z` with the changelog as notes; sites pick it up at their next check. Sites still on 2.9.1 or older don't have the updater yet, so install 2.10.0 on them by uploading the zip once.

## 10. Microsoft 365 sign-in (Azure / Microsoft Entra)

You need someone who can register apps in BDACI's Microsoft 365 tenant (a Global Admin or Application Developer).

1. Go to **https://entra.microsoft.com** → **Identity → Applications → App registrations → New registration**.
2. Name: `BDACI Board Portal`.
3. Supported account types:
   * **"Accounts in this organizational directory only"** if all board members have bdaci.com Microsoft accounts. This is the **recommended** option.
   * **"Accounts in any organizational directory"** if board members use their own employers' Microsoft 365 accounts (see the note below).
4. Redirect URI: platform **Web**, value **`https://bdaci.com/portal-login/callback/microsoft/`** (copy the exact value from the plugin's Settings page).
5. Click **Register**. On the Overview page copy the **Application (client) ID** and **Directory (tenant) ID**.
6. **Certificates & secrets → New client secret**. Give it a description and an expiry (24 months max). Copy the **Value** right away; it's only shown once.
7. **API permissions**: the default *Microsoft Graph → User.Read* is fine. If it asks, click "Grant admin consent".
8. In WordPress (**BDACI Portal → Settings → Microsoft 365**): tick Enable, then paste the Client ID, the **tenant ID** and the secret value. Save.

**If you chose "any organizational directory"**, set the tenant field to `organizations`. Then in the app registration go to **Token configuration → Add optional claim → ID → tick `email` and `xms_edov`** → Add. The plugin only accepts outside-organization emails that Microsoft has verified (the `xms_edov` claim). This stops someone in another tenant from faking a board member's address. To also allow personal Outlook/Hotmail accounts, use `common`.

**Put a reminder in your calendar** a few weeks before the client secret expires. When it expires, Microsoft sign-in stops working until you create a new secret and paste it in.

## 11. Google sign-in (Google Cloud)

1. Go to **https://console.cloud.google.com**, sign in with a BDACI Google account, and create a project (for example "BDACI Portal").
2. **APIs & Services → OAuth consent screen** (also called "Google Auth Platform → Branding/Audience"):
   * User type **External** (or **Internal** if every user is in BDACI's Google Workspace).
   * App name `BDACI Board Portal`, support email, and authorized domain `bdaci.com`.
   * Scopes: `openid`, `email`, `profile` (all non-sensitive).
   * **Publish the app** (move it from Testing to In production). Otherwise only listed test users can sign in. These scopes don't need Google verification.
3. **APIs & Services → Credentials → Create credentials → OAuth client ID**:
   * Application type **Web application**.
   * Authorized redirect URI: **`https://bdaci.com/portal-login/callback/google/`**
4. Copy the **Client ID** and **Client secret**.
5. In WordPress (**BDACI Portal → Settings → Google**): tick Enable and paste both. Leave "Limit to Google Workspace domain" blank to allow Gmail addresses, or enter `bdaci.com` to allow only BDACI Workspace accounts. Save.

## 12. Optional: keep secrets out of the database

Instead of pasting the secrets into Settings, you (or your host) can add these lines to `wp-config.php`. They take priority over the Settings fields:

```php
define( 'BDACI_PA_MS_CLIENT_SECRET', 'paste-value-here' );
define( 'BDACI_PA_GOOGLE_CLIENT_SECRET', 'paste-value-here' );
define( 'BDACI_PA_UPDATE_TOKEN', 'paste-github-access-key-here' );
```

## 13. Test before announcing

1. In a private/incognito window open https://bdaci.com/board-portal/. You should land on the sign-in page.
2. Add yourself under a second email as a Board Member, sign in with email + password + code, and check that you see the portal but not Committee-only pages.
3. Try "Sign in with Microsoft 365" and "Sign in with Google".
4. Revoke the test person, refresh, and check that they are signed out.
5. Hide a test page in **BDACI Portal → Sections & Uploads**, open it in the private window as a Board Member and check you get "page not found", then show it again.
6. Add a test meeting, RSVP to it as the test person, and check the reply shows under **Replies**.
7. Upload a test document to New Director Orientation, open it as the test person and sign off. Check the confirmation email and the audit trail.
8. As a Board Member without committee access, open Education Committee, click **Request access**, approve it as admin, and check the section opens.
9. Check **BDACI Portal → Activity Log**.
10. Before launch, use **BDACI Portal → Test data → Get ready for launch** to clear test data and activity.
11. If you use a caching plugin (WP Rocket, LiteSpeed Cache and so on) or Cloudflare, exclude `/portal-login/*`, `/board-portal/*` and URLs containing `bdaci_doc=` or `bdaci_avatar=` from caching. The plugin already sends "do not cache" headers, but an explicit rule is safer.

## How it works (for whoever maintains the site)

* **Sign-in.** Portal accounts (Board/Committee/Portal Admin without editing rights) can't use `wp-login.php`; they're sent to `/portal-login/`. After the password, a 6-digit code (stored hashed, valid 10 minutes, 5 tries, 3 resends) is emailed. Five wrong passwords lock that email for 15 minutes from that IP, and ten from any IP. Application passwords are disabled for portal accounts.
* **Microsoft / Google.** OpenID Connect authorization-code flow with PKCE, a one-time `state` tied to a browser cookie, and `nonce`. The ID token is fetched directly from the provider over TLS, and its issuer, audience, expiry, nonce, tenant and verified-email claims are checked. On first sign-in the account is bound to that provider identity; a different identity with the same email is refused later. SSO can't be used to sign in to WordPress Administrator or Editor accounts.
* **Content.** Protected pages redirect visitors to the sign-in page, return 403 to signed-in people without the right role, and are hidden from search, feeds, menus, page lists, the REST API, oEmbed and the XML sitemap. They also get `noindex` and no-cache headers.
* **Admins.** Site Administrators can always see the portal and keep using `wp-login.php` as usual.
* **Uninstall.** Deleting the plugin keeps all data unless "Delete … when the plugin is deleted" is ticked in Settings. Private files are always kept.

* **Portal data.** Meetings, RSVPs, access requests and sign-off events are kept in their own database tables (`wp_bdaci_pa_*`). Documents are private attachments with their sections, date and access stored as post meta. Uploaded profile photos are private too and served only to signed-in members.

## Still to decide

* Whether meetings should also come from a Microsoft 365 calendar. For now they are entered on the Meetings screen.
