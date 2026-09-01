<?php

/**
 * Per-Hosting DB Override - TEMPLATE
 * ------------------------------------------------------------------
 * Use this ONLY when this same adf.system codebase is deployed on a
 * DIFFERENT hosting account/server than the original adfsystem.online
 * (e.g. Sunsea / Explore Karimunjawa running standalone on its own
 * cPanel hosting under karimunjawaexplore.com).
 *
 * SETUP on the NEW server:
 *   1. In that server's cPanel -> MySQL Databases, create:
 *        - a "master" database  (e.g. yourprefix_adf)
 *        - a "business" database (e.g. yourprefix_sunsea)
 *        - one MySQL user with ALL PRIVILEGES on both databases
 *      (the business database name does NOT need to match exactly -
 *       config/database.php auto-maps 'adf_sunsea' -> '{your DB_USER
 *       prefix}_sunsea' automatically based on DB_USER below)
 *   2. Copy THIS file to config/local-db-config.php (same folder) via
 *      cPanel File Manager - do NOT commit it to git, do NOT create it
 *      through a git push. It must only ever exist directly on the server.
 *   3. Fill in the 4 values below with what cPanel shows you.
 *   4. Import the SQL dump (from the export tool) into those 2 databases
 *      via phpMyAdmin.
 *
 * config/config.php will automatically pick this file up (if present)
 * BEFORE falling back to the original adfsystem.online credentials - so
 * the original hosting is completely unaffected by this file existing
 * elsewhere.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'yourprefix_adf');   // the MASTER database on this new server
define('DB_USER', 'yourprefix_dbuser');
define('DB_PASS', 'GANTI_password_database_disini');
