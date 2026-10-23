# TreeForge

VS Code-style web editor to manage PHP website files directly on the server (locally with XAMPP/MAMP or online on a web host).
No package installations, no build: just copy the folder and open it in the browser.

## ✨ Features

- **Explorer** with multi-selection, drag & drop (including from the computer), right-click menu, rename, duplicate (⌘D), delete with trash bin.
- **Go to file** (⌘P / Ctrl+P): fuzzy search on all the file paths of the root.
- **Go back / Go forward** through the places you visited, like VS Code: ⌃- / ⌃⇧- on Mac, Alt+← / Alt+→ on Windows and Linux, the side buttons of the mouse or the arrows in the editor bar.
- **Root picker** with search: the folders of your web space as a tree, with a badge for writable (R/W) and read-only (R) folders.
- **Tabbed editor** (Ace) with syntax highlighting, ⌘S / Ctrl+S, side-by-side website preview. If a file was changed on disk after you opened it (FTP, another user), TreeForge asks before overwriting it.
- **Find and replace** in the content of all files (⌘⇧F / Ctrl+Shift+F), with regular expressions.
- **PHP syntax check** before saving: no more blank pages or 500 Error for a forgotten bracket.
- **Site PHP errors** in a panel, with a click to open the file at the correct line.
- **Versions** of each file and **trash bin**: every save keeps the previous version; each version opens as a diff with the current text.
- **Large uploads** (1 GB and more) without touching the server configuration: big files travel in small chunks, with progress, speed, pause/resume, automatic retries and resume after an interruption. Large ZIP files are extracted in steps.
- **ZIP**: download everything, compress, upload and extract safely.
- **21 languages**: the interface starts in English; the language is changed from the user button on the top right (flag). Claude responds in the chosen language.
- **API requests** executed by the server (keys never reach the browser), with cURL import.
- **Integrated Claude AI**: reads the project and proposes changes that you approve one by one.

## 📋 Requirements

- Apache with active `.htaccess` (`AllowOverride All`).
- PHP 7.2 to 8.5 with the `pdo_sqlite` extension (usually already active). `zip` and `curl` recommended.

## 🚀 Installation

1. **Copy the folder** into your web space, next to the projects you want to manage:
   ```bash
   git clone https://github.com/ferrucciolipari-cmd/TreeForge.git treeforge
   ```
   or download the ZIP, extract it and upload it via FTP (e.g. in `htdocs/treeforge/` or `httpdocs/treeforge/`).
2. **Open in the browser** `http://localhost/treeforge/` (or `https://yourdomain.com/treeforge/`).
   At the first launch, the database and settings are created by themselves in the `data/` folder.
3. If the "missing an installation step" page appears, PHP cannot write in `data/`: make it writable
   (`chmod -R 777 data` from terminal, or 777 permissions on the `data` folder from FTP client / file manager) and reload.
4. Choose the folder to work on from the **Root** dropdown at the top. The settings (language, name, Anthropic API key, editor) are in the user button with the flag, on the top right.

> ⚠️ **Just installed, TreeForge has no password** and shows a red warning at the top.
> Anyone who knows the address can read and modify the site's files: **enable protection immediately**, especially online.

## 🔒 Enable protection

The password is checked by TreeForge itself (`auth.php`) reading the **`.htpasswd`** file in its folder.
Just add a user in that file: there's no need to modify `.htaccess` or know server paths.

**Quickest way:** click **Set password now** in the red warning at the top, choose user name and password (at least 8 characters)
and confirm: TreeForge writes `.htpasswd` by itself (the TreeForge folder must be writable by the web server) and reloads.
The button is available only while no user exists. Otherwise, generate the credentials by hand:

### 1. Generate credentials

Every line of `.htpasswd` has the form `user:hash`, where the hash is the password encrypted with **bcrypt**. Choose the method you prefer:

- **With the `htpasswd` command** (Apache; in XAMPP macOS it's in `/Applications/XAMPP/xamppfiles/bin/htpasswd`):
  ```bash
  htpasswd -B -c .htpasswd admin
  ```
  Run it inside the `treeforge/` folder: it asks for the password and creates the file. (`-c` overwrites the file: to add other users omit it.)
- **With PHP from terminal**:
  ```bash
  php -r 'echo "admin:" . password_hash("YOUR_PASSWORD", PASSWORD_BCRYPT) . PHP_EOL;'
  ```
  Copy the printed line inside `.htpasswd`.
- **On an hosting without terminal**: create a temporary `hash.php` file in the TreeForge folder with
  ```php
  <?php echo 'admin:' . password_hash('YOUR_PASSWORD', PASSWORD_BCRYPT);
  ```
  open it once in the browser (`https://yourdomain.com/treeforge/hash.php`), copy the shown line into `.htpasswd`
  and **delete `hash.php` immediately**.

Example of how `.htpasswd` looks (one line per user; lines starting with `#` are comments; the hash below is fake, generate your own):
```text
admin:$2y$12$ZczvSVijnz98R5Fc1xW.Guh4D5jhojzV2St7pT97jYFDG5w9.0Oty
```

### 2. Reload the page

The browser asks for user and password and the red warning disappears. Done: all pages and actions of TreeForge are protected.
The `.htpasswd`, `.htaccess` files, the database and other sensitive files are however always blocked via web.

### 3. (Optional) Double protection at Apache level

If you want Apache to ask for the password too, even before PHP starts, in `.htaccess` there's a block already ready but commented:

```apache
# AuthType Basic
# AuthName "TreeForge"
# AuthUserFile /absolute/path/treeforge/.htpasswd
# Require valid-user
```

1. **Find the absolute path** of the folder on the server. `AuthUserFile` wants a disk path, **not a URL**:
   - XAMPP macOS: `/Applications/XAMPP/xamppfiles/htdocs/treeforge/.htpasswd`
   - XAMPP Windows: `C:/xampp/htdocs/treeforge/.htpasswd`
   - Linux / Plesk: usually `/var/www/vhosts/yourdomain.com/httpdocs/treeforge/.htpasswd`
   - To know for sure create a temporary `path.php` file with `<?php echo __DIR__;`, open it in the browser,
     note down the result and **delete the file**. The path to use is the one shown + `/.htpasswd`.
2. **Uncomment the four lines** removing the initial `#` and write the found path after `AuthUserFile`.
3. Reload the page. If **Error 500** appears, the path is wrong or the `mod_auth_basic` module is not active:
   put the `#` back in front of the lines and double check the path.

## 🧰 Common problems

- **Error 500 after touching `.htaccess`**: almost always a wrong path in `AuthUserFile`. Re-comment the lines from step 3.
- **The password is asked infinitely** (hosting with PHP-FPM): active `mod_rewrite` is needed; `.htaccess` already passes the `Authorization` header to PHP.
- **"Apache cannot write…"** in the top bar: the web server user doesn't have permissions on the chosen root folder.
  Use a writable subfolder or fix the permissions (`chmod -R a+rwX folder`).
- **Uploading a big file stops on a hosting behind nginx/a proxy**: chunks are at most 5 MB, so `client_max_body_size` must be at least 6 MB.
  Uploads interrupted for more than 24 hours are deleted automatically; the maximum size per file is in Settings → Download and server.
- **The API requests card refuses an address**: cloud metadata addresses (`169.254.x.x`, `fe80::`) are always blocked for safety; `localhost` and private networks are allowed.
- **Claude does not respond**: insert the Anthropic API key in the settings (user button on top right). The key stays on the server in `data/config.json`.

## 📁 What not to publish

`.gitignore` already excludes `.htpasswd`, the content of `data/` (database, settings with the API key) and system files
(`.DS_Store`, `._*`, `__MACOSX/`). If you distribute the folder as a ZIP, create it without these files.