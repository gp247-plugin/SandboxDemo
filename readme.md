> 🌐 **Language:** [🇻🇳 Tiếng Việt](./readme_vi.md) · 🇬🇧 English (current)

# SandboxDemo — Read-only admin demo mode for GP247 v3

## Introduction
The **SandboxDemo** plugin turns the GP247 admin into a **safe demo**: visitors can **explore every feature** (browse, search, open forms, paginate) but **cannot change any data** — every Save/Delete/Edit is blocked with a "demo mode" notice. This document is for **site owners** who want to publish a public admin demo, and for **developers** who need to understand or customize the blocking mechanism. After reading it you will be able to turn demo mode on/off and know exactly which actions are blocked and which are allowed.

## 1. Plugin information
- **Name**: SandboxDemo module
- **Author**: GP247
- **Core requirement**: `>= 2.1` (GP247 v3 — Laravel + Livewire)
- **Path**: `app/GP247/Plugins/SandboxDemo`
- **Switch**: the `SANDBOX_DEMO_ENABLED` variable in the `.env` file

## 2. How it works (in plain terms)
GP247 v3 renders the admin UI with **Livewire** — every button (including Save and Delete) posts to one shared endpoint, so the old "block by request method" approach no longer works. SandboxDemo therefore blocks at **two layers**, independent of the UI:

- **Layer A — block database writes.** The plugin sits right in front of every statement sent to the database. **Read** statements (list views, search) always pass; **write** statements (add/edit/delete data) are blocked. Because it lives at the data layer, it catches every write path — whether it comes from Livewire, a legacy controller, or a direct query.
- **Layer B — block destructive file operations.** The image/file manager has delete/rename/move/crop/new-folder operations that run as plain links (GET). Layer B blocks exactly those operations by name, while browsing/viewing files stays allowed.

So the system keeps working while "viewing" (login, session, cache), a few **infrastructure** tables are always allowed to be written (see Conditions & Rules).

## 3. Installation and enabling demo mode
1. Make sure the source code is at `app/GP247/Plugins/SandboxDemo`.
2. Go to the admin **Extensions** page, find the **SandboxDemo** plugin, and click **Install**.
3. After installing, click **Enable**.
4. Open the `.env` file at the site root, add (or edit) exactly this line, and save:

   ```
   SANDBOX_DEMO_ENABLED=1
   ```

5. Clear the config cache so the change takes effect. Open a **Terminal** at the site root, type the following, and press Enter:

   ```
   php artisan optimize:clear
   ```

   On success you will see a few `... DONE` lines. From now on, log into the admin and try **Save** on any screen — the system will report "Demo mode: changes to system data are disabled" and the data will **not** change.

**Disabling demo mode:** set `SANDBOX_DEMO_ENABLED=0` (or remove the line), then run `php artisan optimize:clear` again. Setting `=0` disables blocking **even if** the plugin is still Enabled in the admin.

## 4. Customization (for developers)
Every list is declared in `app/GP247/Plugins/SandboxDemo/config.php` — edit it there, **no** core code changes needed:

- `infra_write_allowlist` — tables **always writable** while sandboxed (default: `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens`). If your site has another background-write table (e.g. a view counter or an audit log) that gets wrongly blocked on a view page, add its name (prefix stripped) here.
- `lfm_destructive_routes` — the File Manager routes blocked while sandboxed. Add/remove as needed.

## 5. Conditions & Rules (know before you act)
Understand the rules so a blocking notice never surprises you:

**When is demo mode in effect?**
- **The switch must be on**: `SANDBOX_DEMO_ENABLED=1` in `.env` — if `=0` or missing, the plugin blocks nothing (even if Enabled in the admin).
- **Someone must be logged in** as admin / PMO partner / vendor — so **logging in** and any pre-login pages keep working (never wrongly blocked).
- **Only the admin and admin-API (`api/core`) surfaces are affected.** The entire **storefront** always works normally — even when an admin is logged in the same browser — including customer **account registration, login, add-to-cart, and checkout/order placement**. The customer REST API (`api/front/*`) is not blocked either. The admin prefixes are declared in `admin_surface_prefixes` (`config.php`); the site's admin prefix and any installed vendor/PMO backend prefixes are added automatically.

**Which actions are blocked (while in effect)?**
- **Every data write**: add, edit, delete records on any screen — because the goal is to keep the demo data intact.
- **Destructive File Manager operations**: delete, rename, move, resize, crop, new folder, upload — even when they run as GET links.
- **A write whose target table cannot be identified** is also blocked (fail-safe: better to block wrongly than to let one through).

**Which actions are always allowed?**
- **Every read action**: open lists, search, filter, paginate, open a view form — so the experience is not interrupted.
- **Writes to infrastructure tables** (login session, cache, queue) — so you are not logged out mid-session and the system keeps running.

**Out of scope (not blocked in this version):** effects that reach **outside the database** such as sending real emails, calling payment-gateway APIs, or webhooks are **not** blocked yet. For a public demo server, configure email/payment in the provider's test/sandbox mode to stay safe.

## 6. Support
- Website: `https://GP247.net`
- Email: `support@gp247.net`

## Q&A
**Q1: I enabled the plugin in the admin but nothing is being blocked — why?**

→ The `.env` switch is missing. Add `SANDBOX_DEMO_ENABLED=1` to the `.env` file, run `php artisan optimize:clear`, then try again.

**Q2: Will enabling demo mode log visitors out repeatedly?**

→ No. The session/cache tables are on the always-writable list, so the login session is kept normally while viewing.

**Q3: Can visitors paginate and search, or is everything blocked?**

→ Viewing, searching, filtering, and pagination are all allowed — only **data-changing** actions are blocked.

**Q4: Can visitors delete/edit images in the File Manager?**

→ No. Delete/rename/move/crop/new-folder/upload are all blocked, even when they run as GET links. Only browsing/viewing files is allowed.

**Q5: What do visitors see when an action is blocked?**

→ A short notice, "Demo mode: changes to system data are disabled" (as a floating toast on the Livewire admin), not a raw error page.

**Q6: A view-only page is wrongly blocked — how do I fix it?**

→ That page probably writes to a background table (view counter, audit log). Add that table name to `infra_write_allowlist` in `config.php`, then clear the cache.

**Q7: Does demo mode block sending real emails or payments?**

→ The current version does **not** block effects outside the database (email, payment gateway, webhooks). Configure those in test mode for the demo server.

**Q8: How do I quickly turn demo mode off?**

→ Set `SANDBOX_DEMO_ENABLED=0` in `.env` (or remove the line), then run `php artisan optimize:clear`. This turns it off even while the plugin is still Enabled.

**Q9: Why not reuse the old approach (block by request method)?**

→ On GP247 v3, every admin action goes through one shared Livewire endpoint, so blocking by request method can no longer see the write. That is why the new version blocks at the database layer and the file-operation layer.

**Q10: Does the plugin change any of my data or structure?**

→ No. The plugin only **blocks** while demo mode is on; it adds no tables and changes none of your business data.

---

<sub>📅 **Last updated:** 2026-09-07 · ✍️ **Author:** GP247</sub>
