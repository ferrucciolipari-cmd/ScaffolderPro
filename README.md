# Scaffolder Pro

VS Code-style web editor to manage PHP website files directly on the server (locally with XAMPP/MAMP or online on a web host).
No package installations, no build: just copy the folder and open it in the browser.

## ✨ Features

- **Explorer** with multi-selection, drag & drop (including from the computer), right-click menu, rename, duplicate (⌘D), delete with trash bin.
- **Go to file** (⌘P / Ctrl+P): fuzzy search on all the file paths of the root.
- **Go to line** (⌃G / Ctrl+G): type `120` or `120:5` (line:column). A button next to the zoom switches word wrap on and off.
- **Go back / Go forward** through the places you visited, like VS Code: ⌃- / ⌃⇧- on Mac, Alt+← / Alt+→ on Windows and Linux, the side buttons of the mouse or the arrows in the editor bar.
- **Root picker** with search: the folders of your web space as a collapsible tree (open only the branches you need), with a badge for writable (R/W) and read-only (R) folders.
- **Tabbed editor** (Ace) with syntax highlighting, ⌘S / Ctrl+S, side-by-side website preview (in the ☰ menu of the editor bar, with download and delete). If a file was changed on disk after you opened it (FTP, another user), Scaffolder Pro asks before overwriting it.
- **Format document** (⇧⌥F / Shift+Alt+F, or on save): Prettier for PHP, JS, TypeScript, JSON, CSS/SCSS, HTML, Vue, Markdown and YAML, loaded only when you use it.
- **Emmet**: type `ul>li.item*3`, `.box`, `!` (HTML/PHP) or `m10`, `df` (CSS) and press Tab.
- **Minimap** beside the editor and **Go to symbol** (⇧⌘O / Ctrl+Shift+O): functions, classes, methods, CSS selectors, headings and ids of the open file.
- **Dark, light or system theme** (Settings → Interface).
- **Find and replace** in the content of all files (⌘⇧F / Ctrl+Shift+F), with regular expressions.
- **PHP syntax check** when saving: the file is always saved, and a forgotten bracket is flagged right away (line in red) before it turns into a blank page or Error 500.
- **Site PHP errors** in a panel, with a click to open the file at the correct line.
- **Versions** of each file and **trash bin**: every save keeps the previous version; each version opens as a diff with the current text.
- **Large uploads** (1 GB and more) without touching the server configuration: big files travel in small chunks, with progress, speed, pause/resume, automatic retries and resume after an interruption. Large ZIP files are extracted in steps.
- **ZIP**: download everything, compress, upload and extract safely.
- **21 languages**: the interface starts in English; the language is changed from the user button on the top right (flag). Claude responds in the chosen language.
- **API requests** executed by the server (keys never reach the browser), with cURL import.
- **Integrated Claude AI**: reads the project and proposes changes that you approve one by one.
- **Import from Golem Lite**: brings the structure of a [Golem Lite](#-import-from-golem-lite) headless CMS instance into the root, so Claude can build a front-end that reads the contents from the API.

## 📋 Requirements

- Apache with active `.htaccess` (`AllowOverride All`).
- PHP 7.2 to 8.5 with the `pdo_sqlite` extension (usually already active). `zip` and `curl` recommended.

## 🚀 Installation

1. **Copy the folder** into your web space, next to the projects you want to manage:
   ```bash
   git clone https://github.com/ferrucciolipari-cmd/ScaffolderPro.git scaffolder-pro
   ```
   or download the ZIP, extract it and upload it via FTP (e.g. in `htdocs/scaffolder-pro/` or `httpdocs/scaffolder-pro/`).
2. **Open in the browser** `http://localhost/scaffolder-pro/` (or `https://yourdomain.com/scaffolder-pro/`).
   At the first launch, the database and settings are created by themselves in the `data/` folder.
3. If the "missing an installation step" page appears, PHP cannot write in `data/`: make it writable
   (`chmod -R 777 data` from terminal, or 777 permissions on the `data` folder from FTP client / file manager) and reload.
4. Choose the folder to work on from the **Root** dropdown at the top. The settings (language, name, Anthropic API key, editor) are in the user button with the flag, on the top right.

> ⚠️ **Just installed, Scaffolder Pro has no password** and shows a red warning at the top.
> Anyone who knows the address can read and modify the site's files: **enable protection immediately**, especially online.

## 🔒 Enable protection

The password is checked by Scaffolder Pro itself (`auth.php`) reading the **`.htpasswd`** file in its folder.
Just add a user in that file: there's no need to modify `.htaccess` or know server paths.

**Quickest way:** click **Set password now** in the red warning at the top, choose user name and password (at least 8 characters)
and confirm: Scaffolder Pro writes `.htpasswd` by itself (the Scaffolder Pro folder must be writable by the web server) and reloads.
The button is available only while no user exists. Otherwise, generate the credentials by hand:

### 1. Generate credentials

Every line of `.htpasswd` has the form `user:hash`, where the hash is the password encrypted with **bcrypt**. Choose the method you prefer:

- **With the `htpasswd` command** (Apache; in XAMPP macOS it's in `/Applications/XAMPP/xamppfiles/bin/htpasswd`):
  ```bash
  htpasswd -B -c .htpasswd admin
  ```
  Run it inside the `scaffolder-pro/` folder: it asks for the password and creates the file. (`-c` overwrites the file: to add other users omit it.)
- **With PHP from terminal**:
  ```bash
  php -r 'echo "admin:" . password_hash("YOUR_PASSWORD", PASSWORD_BCRYPT) . PHP_EOL;'
  ```
  Copy the printed line inside `.htpasswd`.
- **On an hosting without terminal**: create a temporary `hash.php` file in the Scaffolder Pro folder with
  ```php
  <?php echo 'admin:' . password_hash('YOUR_PASSWORD', PASSWORD_BCRYPT);
  ```
  open it once in the browser (`https://yourdomain.com/scaffolder-pro/hash.php`), copy the shown line into `.htpasswd`
  and **delete `hash.php` immediately**.

Example of how `.htpasswd` looks (one line per user; lines starting with `#` are comments; the hash below is fake, generate your own):
```text
admin:$2y$12$ZczvSVijnz98R5Fc1xW.Guh4D5jhojzV2St7pT97jYFDG5w9.0Oty
```

### 2. Reload the page

Scaffolder Pro shows its sign-in page and the red warning disappears. Done: all pages and actions of Scaffolder Pro are protected.

After signing in you **stay signed in on that browser until you click _Sign out_** (top right, next to the user button):
no popup, no session that expires after a few minutes. Signing out deletes the login on the server too. If you change a password in `.htpasswd`,
every browser signed in with the old one has to sign in again.
The `.htpasswd`, `.htaccess` files, the database and other sensitive files are however always blocked via web.

### More security (Settings → Security)

- **Blocked attempts**: after 10 wrong passwords (or codes) from the same IP address in 15 minutes, signing in from that address is blocked
  for 15 minutes. The block is written in the activity log. Anyone already signed in keeps working.
- **Two-step verification** (optional, per user): scan the QR code with an authenticator app (Google Authenticator, Microsoft Authenticator,
  1Password, Authy…) and confirm with the 6-digit code. You get **8 emergency codes**, each valid once: keep them safe.
  Lost the phone and the codes? Change the password of that user in `.htpasswd` (see step 1): this turns two-step verification off.
  With two-step verification on, HTTP Basic (step 3, scripts) is no longer accepted for that user.
- **Signed-in devices**: browser, address, last use; sign out a single device or all the others.

### 3. (Optional) Double protection at Apache level

If you want Apache to ask for the password too, even before PHP starts, in `.htaccess` there's a block already ready but commented:

```apache
# AuthType Basic
# AuthName "Scaffolder Pro"
# AuthUserFile /absolute/path/scaffolder-pro/.htpasswd
# Require valid-user
```

1. **Find the absolute path** of the folder on the server. `AuthUserFile` wants a disk path, **not a URL**:
   - XAMPP macOS: `/Applications/XAMPP/xamppfiles/htdocs/scaffolder-pro/.htpasswd`
   - XAMPP Windows: `C:/xampp/htdocs/scaffolder-pro/.htpasswd`
   - Linux / Plesk: usually `/var/www/vhosts/yourdomain.com/httpdocs/scaffolder-pro/.htpasswd`
   - To know for sure create a temporary `path.php` file with `<?php echo __DIR__;`, open it in the browser,
     note down the result and **delete the file**. The path to use is the one shown + `/.htpasswd`.
2. **Uncomment the four lines** removing the initial `#` and write the found path after `AuthUserFile`.
3. Reload the page. If **Error 500** appears, the path is wrong or the `mod_auth_basic` module is not active:
   put the `#` back in front of the lines and double check the path.

## 🧩 Import from Golem Lite

Scaffolder Pro can prepare a front-end for an instance of the Golem Lite headless CMS. **The contents stay on Golem** (even on
another domain): Scaffolder Pro imports only the *structure* (languages, sitemaps, models and fields, endpoints), Claude writes the
front on top of it, and the front reads the updated data from the Golem API at every request.

1. In Golem Lite open **API Keys** of the instance → **Structure for Scaffolder Pro**, copy the link
   (`…/api.php?instance=…&blueprint=1`) and create a key with the `content.read` scope **without IP or domain filters**
   (it is used only by the server of the front, never by the browser).
2. In Scaffolder Pro choose the root of the front, open the ☰ menu of the Explorer → **Import from Golem Lite…**, paste link and key, **Import**.
   The key is saved on the server like the keys of *API requests* (the browser sees only `••••1234`).
   Locally with a self-signed certificate (`https://127.0.0.1`, "SSL certificate problem") tick **Don't verify the SSL certificate**: it also
   writes `'verify_ssl' => false` in `golem/config.php`. Never online.
3. In the root a `golem/` folder appears:
   - `blueprint.json` — the structure as Golem exports it;
   - `GOLEM.md` — instructions for Claude: which endpoint for each kind of node, languages, relative permalinks, media, rules;
   - `client.php` — small PHP 7.2+ client for the front (`golem()->sitemap()`, `->content()`, `->category()`), with a file cache and ETag / `If-None-Match` (304);
   - `config.php` — API address, instance and key (created only if missing: afterwards it is yours);
   - `.htaccess` (denies web access to the whole folder) and `.gitignore` (`config.php`, `cache/`).
4. Ask Claude in the side panel, e.g. *"Build the front-end of this site from the Golem structure"*: when `golem/blueprint.json`
   exists, `GOLEM.md` and the structure are always in Claude's context.
5. When the structure changes in Golem (new pages, models, fields), reopen the window and press **Update structure**: only
   `blueprint.json` and `GOLEM.md` are rewritten (the previous text stays in *Versions*); `config.php`, `client.php` and the front are not touched.

The front written this way has no domain inside: links are relative and the base path is found by itself, so you can move it
to another domain or subfolder without changing anything (only `golem/config.php` if Golem moves).
Golem permalinks have no leading `/` and no language prefix (`camere`, `camere-en`) and are unique across languages:
the URL of a content is the base path + `/` + permalink, and the home page (`/`) is chosen by the front.

## ⬆️ Upgrading from TreeForge

Scaffolder Pro is the new name of TreeForge. To upgrade, replace the program files and keep your `data/` folder and `.htpasswd`:
the old database (`data/treeforge.sqlite`) is renamed automatically at the first start, so versions, trash, log and API requests are kept.
You sign in once with the new sign-in page, and the layout saved in the browser (columns, open tabs) starts from the defaults.

## 🧰 Common problems

- **Error 500 after touching `.htaccess`**: almost always a wrong path in `AuthUserFile`. Re-comment the lines from step 3.
- **The sign-in page comes back after every click**: the browser is refusing cookies for the site (or a proxy strips them). Allow cookies for your domain.
- **With the optional Apache protection the password is asked infinitely** (hosting with PHP-FPM): active `mod_rewrite` is needed; `.htaccess` already passes the `Authorization` header to PHP.
- **"Apache cannot write…"** in the top bar: the web server user doesn't have permissions on the chosen root folder.
  Use a writable subfolder or fix the permissions (`chmod -R a+rwX folder`).
- **Uploading a big file stops on a hosting behind nginx/a proxy**: chunks are at most 5 MB, so `client_max_body_size` must be at least 6 MB.
  Uploads interrupted for more than 24 hours are deleted automatically; the maximum size per file is in Settings → Download and server.
- **The API requests card refuses an address**: cloud metadata addresses (`169.254.x.x`, `fe80::`) are always blocked for safety; `localhost` and private networks are allowed.
- **Claude does not respond**: insert the Anthropic API key in the settings (user button on top right). The key stays on the server in `data/config.json`.

## 🧪 Automated tests (for developers)

```bash
tests/run.sh
```

One command checks the PHP syntax on every PHP version found (Homebrew `php@7.4`, `php@8.4`, `php@8.5`…), `app.js`, the 21 language files,
then runs about 80 checks on `api.php` for each version: root picker, save conflicts, duplicate, API requests, Claude (with a fake server),
chunked uploads, ZIP extraction in steps, settings, sign-in, two-step verification, devices, blocked attempts.
It works on a temporary copy with low PHP limits and never touches your installation or your sites.
`tests/run.sh --big` also uploads a 1 GB file in chunks; `tests/run.sh --php "8.4"` tests one version; `PHP_BINS="/path/php"` picks the binaries.
Needs `python3` and the PHP command line. The `tests/` folder is blocked from the web by its own `.htaccess`.

## 📁 What not to publish

`.gitignore` already excludes `.htpasswd`, the content of `data/` (database, settings with the API key) and system files
(`.DS_Store`, `._*`, `__MACOSX/`). If you distribute the folder as a ZIP, create it without these files.