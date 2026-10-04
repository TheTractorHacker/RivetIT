# Getting started

This page shows you how to sign in to RivetIT, find your way around, set up your own account, and use the list features that almost every page shares. Read it first: the other guides assume you know these basics.

| | |
|---|---|
| **Where to find it** | Sign-in page: your RivetIT address followed by `/login.php`. Your account: user menu (your name, top right) → **Account**. |
| **Who can use it** | Everyone who signs in. Which sidebar entries and buttons you see depends on your role (see [Roles and what you see](#roles-and-what-you-see)). |
| **Turn it on** | Nothing to turn on. Administrators control the start page, the look of the sign-in page and the session length under Administration → Settings (**Defaults**, **Appearance** and **Security**). |

## Signing in

![The sign-in page with numbered call-outs on the passkey button, email, password, Stay signed in and Sign In](images/getting-started/01-sign-in.png)

*Figure 1 — The sign-in page. Agents and employees use the same page.*

The page follows your company's look. The backdrop, the **Sign In** button and the links use the accent colour an administrator chose (Administration → Settings → **Appearance** → **Accent Color**), the card uses the **Card Corner Radius**, and your company logo sits on a white backing so a dark logo stays readable. An administrator can change the backing colour or turn it off (**Logo Background**). Without a logo, the RivetIT mark is shown.

1. Open the sign-in address your administrator gave you.
2. Type your email address **(2)** and password **(3)**.
3. Tick **Stay signed in (4)** only on a computer you trust (see below).
4. Click **Sign In (5)**.

You land on the **start page**, which an administrator chooses (Administration → Settings → **Defaults** → **Start Page**, for example Dashboard, Department Management or Support Tickets). If you opened a specific page while signed out, RivetIT returns you to that page after you sign in.

### Other things the sign-in page can ask

- **MFA code.** If you turned on multi-factor authentication, a second screen asks for the 6-digit code from your authenticator app (**Verify your 2FA code**), with a **Remember Me** box and a **Verify & Sign In** button. You have two minutes to finish; after that, the message "Your MFA session expired" tells you to start again. Department Portal logins that use 2FA see the same code box without the **Remember Me** box, and have five minutes ("Your 2FA session expired").
- **Choosing a side.** If the same email address belongs to both an agent and an employee, you see two buttons: **Log in as Agent** and **Log in as Department**. "Department" here means the Department Portal, the employee self-service site.
- **Passkey.** **Sign in with a Passkey (1)** needs no email or password: your device asks for your fingerprint, face or PIN. It works only after you add a passkey to your account (see [Security](#security-password-mfa-and-passkeys)). If your device has no passkey for this site, an error appears; sign in with your password instead.
- **Login message.** An administrator can show a notice at the top of the sign-in card.
- **Employees.** Employees sign in on this same page and go straight to the Department Portal. See the [Department Portal guide](11-employee-portal.md).

Below the sign-in card, a few links appear only when the Department Portal is on and an administrator has set them up, so you may not see them:

- **Forgot password?** appears when outgoing email is set up. It resets the password of a **Department Portal** account that signs in with a password.
- **Login with Microsoft Entra** appears when the Microsoft Entra app is configured (Administration → Settings → **Identity provider**).
- **Login with company SSO** appears when an administrator has turned on OpenID Connect sign-in under the same **Identity provider** settings and filled in the issuer, client ID and secret.
- **Login with Odoo** appears when the Odoo integration's Department Portal sign-in is on.

All of these sign in **Department Portal** accounts only. They do not work for agents. If an agent forgets a password, an administrator sets a new one (Administration → Users → edit the user → **Security** tab → **New Password**).

### Stay signed in, and when you are signed out

**Stay signed in** keeps you signed in on this browser for the number of days set in Administration → Settings → **Security** (**2FA Remember Me Expire**). The minimum is 30 days; a lower value is raised to 30. If the browser loses its session (for example after an internet outage), RivetIT signs you straight back in from this setting. With MFA on, it also skips the code on that browser for the same period. Each time it is used the stored token is replaced, and **Revoke All Tokens** under **Account → Security** removes it.

Without **Stay signed in**, your session still lasts for the **Session Lifetime**, which is at least 30 days (43,200 minutes) and at most 90 days (129,600 minutes). Lower values are raised to 30 days. Open pages keep the session alive in the background.

If you use a shared computer, leave **Stay signed in** unticked and use user menu → **Sign out** when you finish. Because sessions last so long, signing out is the way to end one.

> If you type a wrong password several times, sign-in is blocked for your network address: 15 failed attempts in 10 minutes lock that address out until the window passes. Every failed attempt is logged.

## A first look at the app

![The Departments page with call-outs on a sidebar group, the fold button, Search everywhere, the bell and the open user menu](images/getting-started/02-app-shell.png)

*Figure 2 — The main screen. The user menu is open so you can see its entries.*

| # | What it is | What it does |
|---|---|---|
| **(1)** | Sidebar group (here **Service Desk**) | Click a group name to open or close it. The group that holds the page you are on opens by itself. A number badge shows a live count, such as open tickets. |
| **(2)** | Fold button (three horizontal lines) | Shrinks the sidebar to icons and back. Your choice is remembered in this browser. On a narrow window the sidebar is hidden until you press it; **Esc** or a click outside closes it. |
| **(3)** | **Search everywhere** | On a narrow window only a magnifier icon is shown, and it opens the full search page. Type at least two characters and matching records appear, grouped by type, up to five per group. Click a result to open it, or choose **See all results** for the full page. |
| **(4)** | Bell | Your personal notifications. The number is how many you have not dismissed. |
| **(5)** | User menu | Your name, photo and role, then **Administration** (administrators only), **Account** and **Sign out**. |

The company name at the top of the sidebar always takes you to the **Dashboard**. It is covered in the [Dashboard and Reports guide](10-dashboard-and-reports.md). Your administrator can also add custom links as icons in the top bar, beside the bell.

On a detail page (a person, ticket or asset) a breadcrumb trail above the record shows where you are, for example the department, then the list, then the record; click any part to go back to it. Administration pages that sit under **Settings**, **Tags & Categories**, **Ticketing**, **Templates** or **Maintenance** show a back link such as **All settings / Security** at the top of the page.

### Sidebar groups

Entries appear only when the feature is switched on (Administration → Settings → **Modules**) **and** your role may use it.

| Group | Entries | Shown when |
|---|---|---|
| **Dashboard** | Dashboard | Always, except for module-only logins. |
| **Alerts** | Alerts, with a red count of new alerts | Your role can see RMM alerts. This is monitoring alerts from connected tools, not your notifications. |
| **Organization** | Departments (count of active departments), Org Chart | Departments: Read or higher. |
| **Service Desk** | Tickets, Recurring Tickets, Request service, CSAT Ratings, Requests, Problems, Changes | **Show Ticketing** is on and Tickets, assets & docs: Read or higher. CSAT Ratings needs CSAT turned on. |
| **Work** | Projects, Calendar | Tickets, assets & docs: Read or higher. Projects also needs Ticketing on. |
| **Knowledge** | Knowledge Base, Credentials, Printers, Network Drives | Knowledge Base: **Show Knowledge Base** on and Knowledge base: Read. The others: **Show IT Documentation** on and Tickets, assets & docs: Read. Credentials also needs Credentials: Read. |
| **Training** | Overview, Courses, Learning Paths, Assignments, Records & sessions, Reports, People, and more | **Show Training (LMS)** is on and Training: Read or higher. Some entries need Modify or Full. |
| **Infrastructure** | Assets, Locations, Vendors, Licenses, Domains, Certificates | **Show IT Documentation** on and Tickets, assets & docs: Read. A role with only the Assets permission sees only **Assets**. |
| **Endpoints**, **Backups** | Monitoring and backup pages | Only when an endpoint integration is turned on. See the [Endpoints guide](09-endpoints-and-integrations.md). |
| **People** | Opens the list of employees | Departments: Read or higher. |
| **Reports** | Opens the reports | Reporting permission. |
| Custom links | Links your administrator added, such as an intranet page or a password manager. They open another page and show an arrow at the right | Shown to everyone except module-only logins. They can also appear as icons in the top bar. |

The list of people is called **People** in the sidebar and **Contacts** on its page and inside a department. It is the same list.

## Three navigation scopes

The sidebar changes depending on how wide a view you are in. The scope is decided by the address of the page you open: a page for one department, a company-wide page, or neither, which gives the app-level sidebar.

![Three sidebars side by side: app-level, department workspace and company-wide](images/getting-started/03-three-scopes.png)

*Figure 3 — The same app in three scopes. Call-outs (1) and (2) mark the way back and the scope label.*

- **App-level sidebar.** All departments together. It is shown on the Dashboard, the Departments list and on lists you open from a sidebar group, such as **Service Desk → Tickets**, **Knowledge → Credentials** or **Infrastructure → Assets**. Those lists cover every department, but you stay in the app-level sidebar.
- **Department workspace.** When a page is opened for one department (for example, you click a department in the Departments list, or open a ticket that belongs to it), the sidebar is replaced by that department's own rail. The top shows the department's name and type, then its **Overview**, **Contacts**, **Locations**, **SUPPORT** (Tickets, Recurring Tickets, Projects), **Vendors**, **Calendar**, **Knowledge Base** and **DOCUMENTATION** (Assets, Licenses, Credentials, Networks, Printers, Network Drives, Racks, Certificates, Domains, Services, Contracts, Files). Every list and count in it is limited to that department. A department's header strip shows its location, primary contact and ticket counts, and its **⋮** menu holds actions such as **New Ticket** and **Edit Department**.
- **Company-wide.** Choosing **People** in the sidebar opens the **Company-wide** rail, which says so under **All Departments**. Its lists (Contacts, Locations, Assets, Licenses, Credentials, Networks, Certificates, Domains, Services) span every department you may see, and clicking one keeps you in this rail.

To get back, click **All Departments (1)** at the top of either rail. That returns you to the Departments list, and from there the sidebar is app-level again. If you cannot tell where you are, look at the top of the sidebar: it names the department or says **Company-wide**. The same list can look different in each scope: **Credentials** under **Knowledge** is the app-level list, while **Credentials** inside the Company-wide rail or a department is limited by that rail.

The Departments list starts with the department you opened most recently.

## Your account

Open user menu → **Account**. A small sidebar replaces the main one and offers **Details**, **Security**, **Preferences**, **Activity** and **Integrations**. The arrow at the top (**Account**) returns you to your start page.

### Details: profile and email signature

![The Account Details page with an email signature built from the template](images/getting-started/04-account-details.png)

*Figure 4 — Account → Details. The signature shown here was made with **Use Template** and not saved.*

1. Open **Account → Details**.
2. Change your **Name**, **Email**, **Job Title** or **Direct Phone**. **Role** is read-only; an administrator changes it. Your email address is also your sign-in name.
3. To add a photo, click **Upload (3)**. **Remove** clears it.
4. In **Email Signature**, write your own or click **Use Template (4)**. The template builds a signature from your photo (or the company logo), name, job title, phone and email and the company details. **Shrink** and **Grow** resize the whole signature at once.
5. Click **Save Changes (5)**.

The signature is added to ticket replies and emails you send from RivetIT. **Use Template** replaces whatever is in the box. If you change your email address and outgoing email is set up, a notice goes to the old address.

### Security: password, MFA and passkeys

![The Account Security page with Update Password, Enable MFA and Add Passkey highlighted](images/getting-started/05-account-security.png)

*Figure 5 — Account → Security.*

**Change your password**

1. Open **Account → Security**.
2. Enter your **Current Password** and a **New Password** (at least 8 characters).
3. Click **Update Password (1)**.

You are signed out straight away and must sign in with the new password. If outgoing email is set up, RivetIT emails a confirmation.

**Turn on MFA (authenticator app)**

![The multi-factor authentication pop-up with a QR code, a code box and the Enable button](images/getting-started/06-mfa-setup.png)

*Figure 6 — The MFA pop-up. The QR code and secret in this picture are examples.*

1. Click **Enable MFA (2)**.
2. Scan the QR code **(1)** with an authenticator app (or copy the secret shown under it).
3. Type the 6-digit code from the app into the box **(2)**.
4. Click **Enable (3)**.

The card then shows **Enabled**, and every sign-in asks for a code. Turning MFA on or off also ends every **Remember Me** trust on your other browsers, and **Revoke All Tokens** does the same on demand. **Disable** removes MFA, but an administrator can require it. If **Force MFA** is set on your account, you are taken to a **Multi-Factor Authentication Enforced** page after sign-in until you enrol, and you cannot disable it later.

**Add a passkey**

1. Click **Add Passkey (3)**.
2. Give it a name that tells you which device it is, for example "MacBook Touch ID".
3. Click **Register Passkey** and confirm on your device.

The list shows each passkey with when it was added and last used. The trash button removes one. Passkeys need a device that supports them and a secure (HTTPS) connection. If credentials show as locked after a passkey sign-in, sign in once with your password. If the Passkeys card shows a warning that passkeys cannot open the credential vault yet, an administrator still has to set up the vault key; until then a passkey on a new browser shows the vault as locked.

Further down the page:

- **Two-Factor Authentication** also lists your **Remember-Me Tokens** (one for each browser where you ticked **Stay signed in** or **Remember Me**) with **Revoke All Tokens**.
- **Mobile App Tokens** lists phones signed in to the RivetIT mobile app. **Revoke** signs one out.
- **Trainer PIN** appears only if you are a trainer on the Training module. It is the 6-digit PIN you use at the training kiosk as a trainer, separate from your own learner PIN. Choose **Set Trainer PIN** (or **Change Trainer PIN**) and type it twice.

### Preferences

![The Preferences card with Theme, Calendar starts on and Records per page](images/getting-started/07-account-preferences.png)

*Figure 7 — Account → Preferences.*

| Setting | What it does |
|---|---|
| **Theme (1)** | **Dark** switches you to dark mode. **Light** follows the company default, so if an administrator made dark the default (Administration → Settings → Appearance), choosing **Light** does not turn it off. |
| **Calendar starts on (2)** | **Sunday** or **Monday**, on the Calendar page only. |
| **Records per page (3)** | 10, 25, 50 or 100 rows in lists. |

Click **Save Preferences (4)** for changes to take effect.

Below that, **Push Notifications** lists **My Devices** (with **Revoke**) and lets you choose which categories are pushed to your phone. It works only when you are signed in to the mobile app and an administrator has allowed those categories. **Send Test Notification** appears once a phone is registered. Choose one of the four row counts above; the footer of long lists offers other values (see [Lists](#lists-search-filter-sort-and-page)).

**Activity** shows your last 10 successful sign-ins (**Recent Sign-ins**: when, device, browser, address) and your last 15 actions (**Recent Activity**: when, type, description). Check it if you suspect someone else used your account. **Integrations** holds **My Calendar Color**, which colours you on the calendar, and **Outlook Calendar Sync**, which needs an administrator to finish the Microsoft setup first. It is not shown to module-only logins.

### Notifications

![The notifications pop-up listing several notifications with Dismiss all and See all Notifications](images/getting-started/08-notifications.png)

*Figure 8 — The bell. The pop-up lists undismissed notifications, eight to a page.*

Click the bell **(1)**. Each entry **(2)** shows its type, time and text; click it to go to the related page. **Dismiss all (3)** clears the list. **See all Notifications (4)** opens the full page, where you can search, filter by date, dismiss one at a time and view **Dismissed** ones. Notifications are yours alone: dismissing one does not remove it for anyone else.

### Search everywhere

![The live search dropdown for the word logistics showing grouped results](images/getting-started/09-search-everywhere.png)

*Figure 9 — Search everywhere. Type two or more characters.*

Results are grouped by type (Departments, Contacts, Tickets, Assets, Knowledge Base and more) and show only what your role may see. Archived records are not included. **Esc** closes the list. Administrators also see matching Settings pages. Module-only logins have no search box.

## Roles and what you see

Every agent has one **role**. A role gives each area of the app a level: **None**, **Read** (view), **Modify** (add, edit and archive) or **Full** (also delete). Areas include Departments, Tickets, assets & docs, Assets, Credentials, Knowledge base, Reporting and Training. Administrators manage roles in Administration → Roles. See the [Administration guide](12-administration-users-and-security.md).

![The Administrator sidebar next to the Technician sidebar](images/getting-started/10-admin-vs-technician.png)

*Figure 10 — Same app, two roles. With the standard Technician role, Alerts, Training, Reports, Backups and the Knowledge Base entry are missing.*

Compared with an administrator, a standard Technician:

- has no **Administration** entry in the user menu; opening an Administration page shows "Administration is for administrators only";
- has no **Knowledge Base**, **Training**, **Reports** or **Alerts** entries until an administrator grants those permissions;
- can add and edit records, but cannot delete them, because deleting needs **Full**.

When you open something your role lacks, you see **You don't have access to this page** with a message such as "Your role needs view access to Reports. Ask an administrator if you need it." and a **Go to** button back to your home page. Your administrator may have changed the standard roles.

Two more limits apply:

- **Department access.** An administrator can restrict an agent to chosen departments (Administration → Users → edit → **Access** tab). You then see only those departments in lists and search, and opening another one shows "Access Denied - You do not have permission to access that department!".
- **Module-only (limited) logins.** A role that holds none of Departments, Tickets, assets & docs and Assets is treated as a module-only login, typical for a Training manager or a knowledge base editor. Such a login has no Dashboard, no Work group, no Search everywhere box, no custom links and no Integrations page. The sidebar shows only its own modules, and any other page answers "You don't have access to this page". After sign-in it lands on its first module, in this order: Training, Knowledge Base, Reports, RMM, Alerts, then its own account page.

## Lists: search, filter, sort and page

Most list pages, such as People, Departments, Assets and Credentials, work the same way. The examples use People.

![The People list with call-outs on the search box, filters, Archived button, column heading and an open row menu](images/getting-started/11-list-anatomy.png)

*Figure 11 — A typical list, filtered to one department, with a row menu open.*

| # | Control | How it works |
|---|---|---|
| **(1)** | Search box | Type and press **Enter** or click the magnifier. It matches several fields at once (name, email, phone and so on). |
| **(2)** | Filters | Drop-downs beside the search box, here **Select Tags** and a department. They apply as soon as you choose. |
| **(3)** | **Archived** | Switches between active and archived records. The button turns blue while you view archived ones. |
| **(4)** | Column heading | Click to sort by that column; click again to reverse. An arrow marks the sorted column. The first click sorts in the opposite direction to the current sort, so it may go Z to A first. |
| **(5)** | Row menu (**⋯**) | Actions for that row, for example **Details**, **Make Note**, **Edit** and **Archive**. What appears depends on the page and your role. |

Some pages hide the filters behind a funnel button instead.

![The Departments filter panel opened with Date range, Tag, Industry and Referral](images/getting-started/12-filter-panel.png)

*Figure 12 — Click the funnel (1) to open the filter panel.*

Change a filter to apply it. The date range filters by the date the record was created. It starts as all time, which the box shows as `1970-01-01 - 2099-12-31`; click it to pick a range or a shortcut such as **This Month**. The panel opens by itself when a filter is active.

### Select several rows and act on them

![Two rows ticked with the Bulk Action menu open](images/getting-started/13-bulk-actions.png)

*Figure 13 — Tick rows, then open Bulk Action.*

1. Tick the box beside each row, or the box in the header **(1)** to tick every row on the current page only.
2. The **Bulk Action (n)** button **(3)** appears with the number selected. It is hidden until you tick something. (On Departments it is called **Action (n)**.)
3. Choose an action. Some open a pop-up to ask for a value, such as **Set Department**. **Archive** asks you to confirm.

### Paging

![The list footer with the per-page selector, the record range and page links](images/getting-started/14-paging.png)

*Figure 14 — The footer appears when a list has more than five records.*

The selector **(1)** sets how many rows show per page (5, 10, 20, 50, 100 or 500) and is saved as your **Records per page**. **(2)** shows the range; **(3)** moves between pages.

### Pop-up forms

![The Make Note pop-up with a title bar, a type field, a text box, Create and Cancel](images/getting-started/15-popup-form.png)

*Figure 15 — A pop-up form.*

Adding and editing usually happens in a pop-up over the list. The title **(1)** says what you are doing. Fields marked with a red **\*** are required. Add forms end with **Create (3)** and edit forms with **Save**; **Cancel (4)** closes the form without saving. Pressing **Esc** or clicking outside the pop-up also closes it, and anything you typed is lost.

Saving shows a short message at the top of the page for about five seconds. Green means success; red is used for errors and also for archive and delete messages.

## Archive, Restore and Delete

These three actions are different, and only one of them can be undone.

| Action | What really happens | Who can do it |
|---|---|---|
| **Archive** | The record is hidden from lists, drop-downs, counts and search, but kept with all its links. Nothing else is removed. | **Modify** on the area (a few lists limit the row-menu entry to administrators). |
| **Restore** | Brings an archived record back. Open the list, click **Archived**, then choose **Restore**. | **Modify**. |
| **Delete** | Removes the record permanently. It cannot be undone. | **Full**, so normally administrators. |

![The Archived view of People with the Bulk Action menu showing Restore and Delete](images/getting-started/16-archived-view.png)

*Figure 16 — The Archived view. Restore and Delete appear only here.*

1. To remove something you no longer need, choose **Archive** and confirm the **Are you sure?** box.
2. To find it again, click **Archived (1)**.
3. To bring it back, tick it and choose **Restore (2)**.
4. Use **Delete (3)** only when the record must be gone for good.

Things to know:

- **Delete has no safety net.** In the Archived view, the bulk **Delete** entry on most lists does not show an "Are you sure?" box, unlike **Archive**. Check your ticks before you choose it. Deleting a department also removes everything filed under it, such as its people, assets, tickets and documents.
- **Archiving a person also switches off their Department Portal sign-in;** restoring them switches it back on. Archiving a department stops its employees signing in to the portal too.
- On **People**, **Anonymize & Archive** replaces the person's name with asterisks and erases their contact details and notes. It cannot be reversed.
- A person or department with **training records** cannot be deleted. Archive it instead.
- On some lists (People, Locations, Vendors, Licenses, Printers, Network Drives), the row-menu **Delete** is hidden unless an administrator has enabled destructive deletes on the server.
- Archive, restore and delete are all written to the audit log.

## Glossary

| Term | Meaning in RivetIT |
|---|---|
| **Department** | A part of the company, such as Finance & Accounting. Its people, tickets, assets and documents are filed under it. Also called a workspace when you view one. |
| **People** (Contacts) | An employee record. It belongs to one department. Not the same as an agent. |
| **Agent** | Staff who sign in to the RivetIT app to work: technicians and administrators. |
| **Role** | The set of levels (None, Read, Modify, Full) that decides what an agent can do. |
| **Department Portal** | The self-service site where employees sign in, on the same sign-in page as agents. |
| **Ticket** | A request or problem logged with the service desk. |
| **Asset** | A device or piece of equipment, such as a laptop or switch, documented under a department. |
| **Credential** | A stored login (username, password, 2FA code) kept in the encrypted vault. |
| **Knowledge Base** | How-to articles for agents and employees. |
| **Training** | The learning module: courses, quizzes, assignments and records. |
| **Location** | A physical site. A department can use several. |
| **Module** | A feature area an administrator can switch on or off (Ticketing, IT Documentation, Knowledge Base, Training and others). |
| **Start page** | The page you land on after signing in. |
| **Scope** | How wide the sidebar's view is: app-level (all departments), one department workspace, or Company-wide. See [Three navigation scopes](#three-navigation-scopes). |
| **Company-wide** | The sidebar rail opened from **People**, whose lists span every department you may see. |
| **Custom link** | A link an administrator adds to the sidebar or top bar, for example an intranet page. |
| **Stay signed in** | The sign-in box that remembers this browser for at least 30 days, so you stay signed in and skip the MFA code there. |
| **MFA / passkey** | Two ways to prove it is you: a code from an authenticator app, or your device's fingerprint, face or PIN. |

## Tips and good practice

- Archive instead of deleting. You can always restore an archive, never a deletion.
- Use **Search everywhere** to jump to a person, ticket or asset without clicking through menus.
- Turn on MFA or add a passkey. Both protect the credential vault behind your account.
- If a button or menu entry is missing, it is usually your role. Ask an administrator rather than looking for a workaround.
- RivetIT has no app-wide keyboard shortcuts. **Esc** closes pop-ups, the search list and the narrow-window sidebar.
- Sign out on shared computers: sessions last at least 30 days.

## Related guides

- [Dashboard and Reports](10-dashboard-and-reports.md)
- [Department Portal](11-employee-portal.md)
- [Administration: Users, Roles and Security](12-administration-users-and-security.md)
- [Administration: System Settings, Mail, Integrations and Maintenance](13-administration-settings.md)
- [Endpoints and Integrations](09-endpoints-and-integrations.md)
