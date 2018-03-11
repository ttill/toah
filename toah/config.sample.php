<?php

/* Handles whether toah should be directly accessible or only via include or redirect (htaccess).
   Combined with redirect this only works if the webserver sets $_SERVER["REDIRECT_STATUS"] (Apache does). */
define("TOAH_ALLOW_DIRECT_ACCESS", true);

/**
 * Registration of plugins (thplugins.php)
 * Uncomment respective line to disable plugin.
 */
Toah::registerModule(Toah::PreSnippetsInsert, "getInfo");
// Toah::registerModule(Toah::PreDomCreation, "noToah");
// Toah::registerModule(Toah::PreSnippetsInsert, "counter");
// Toah::registerModule(Toah::PreOutput + 1, "debugToah");


/* File containing the details required to setup a MySQL connection (required for counter plugin). @see mysqlConnection. */
define("mysqlDataPath", rtrim(dirname(__FILE__), '/') . "/pw.php");
// define("mysqlDataPath", rtrim($_SERVER["DOCUMENT_ROOT"], '/') . "/scripts/pw.php");

?>
