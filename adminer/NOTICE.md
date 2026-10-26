# Adminer

`adminer.php` is **Adminer 5.4.1** (MySQL / MariaDB edition), © Jakub Vrána, https://www.adminer.org/ .
It is distributed under the Apache License 2.0 or the GNU GPL v2 (one or other); the author and licence
notice at the top of the file is kept unchanged. The file itself is not modified.

Scaffolder Pro only adds around it:

- `index.php` — entry point: checks the Scaffolder Pro sign-in, signs in to MySQL with the data saved in
  the **Configurations** tab and customises Adminer through its official extension point (`adminer_object()`).
- `adminer-scaffolder.css` — theme that gives Adminer the look of Scaffolder Pro.
- `.htaccess` — blocks the direct access to `adminer.php`.

To update Adminer replace `adminer.php` with a newer single-file MySQL release from https://www.adminer.org/ .
