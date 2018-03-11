<?php
/*
 *      thplugins.php v180311
 *
 *      Copyright (C) 2009-2018 Till Theato <theato@ttill.de>
 *           http://ttill.de/toah
 *
 *      This program is free software: you can redistribute it and/or modify
 *      it under the terms of the GNU General Public License as published by
 *      the Free Software Foundation, either version 3 of the License, or
 *      (at your option) any later version.
 */


function getInfo()
{
    Toah::$snip["author"] = array(Toah::q("/~html/~head/~meta[@name='author']")->getAttribute("content"), "//*[@id='thAuthor']");
    Toah::$snip["change"] = array(date("d.m.Y H:i:s", filemtime($_SERVER["SCRIPT_FILENAME"])), "//*[@id='thChange']");
}

/* Allows to disable toah by passing the (get-)parameter/query-string "notoah". */
function noToah()
{
    if(isset($_GET["notoah"]))
        die(Toah::$input);
}

/* Sets up a connection to a mysql server.
   Connection details are stored in a separate file (@see mysqlDataPath) in the variables $dbServer, $dbUser, $dbPassword, $dbName. */
function mysqlConnection()
{
    if (is_file(mysqlDataPath)) {
        include_once mysqlDataPath;
    } else {
        Toah::log(mysqlDataPath . " not found! Required for MySQL connection.", E_ERROR);
        return false;
    }

    $db = new mysqli($dbServer, $dbUser, $dbPassword, $dbName);

    if($db->connect_errno > 0) {
        Toah::log('Unable to connect to database [' . $db->connect_error . ']', E_ERROR);
        return false;
    }

    return $db;
}

/* Provides a very simple counter functionality using a MySQL database. Each page hit increases the counter. @see mysqlConnection */
function counter()
{
    $db = mysqlConnection();
	if($db)
	{
		$date = filemtime($_SERVER["SCRIPT_FILENAME"]);

		$query = $db->query("SELECT * FROM `counter` WHERE page='" . $_SERVER["PHP_SELF"] . "'");

		if($obj = $query->fetch_assoc()) {
			$obj["views"] += 1;
			$db->query("UPDATE `counter` SET views='" . $obj["views"] . "'"
					   . ($obj["date"] < $date ? ", date='" . $date . "' " : " ")
					   .  "WHERE page='" . $_SERVER["PHP_SELF"] . "'");
		} else
			$db->query("INSERT INTO `counter` (page, views, date) VALUES ( '" . $_SERVER["PHP_SELF"] . "', '1', '" . $date . "')");

		Toah::$snip["counter"] = array($obj["views"] ? $obj["views"] : 1, "//*[@id='thCounter']");
	}
}

/* Outputs and formats the log and some additional data. */
function debugToah()
{
    if (!isset($_GET["debug"]))
        return;

    $dom = new DomDocument("1.0", "UTF-8");
    $dom->strictErrorChecking = false;
    if (Toah::$isXml)
        $dom->loadXML(Toah::$output);
    else
        $dom->loadHTML(Toah::$output);

    $style = $dom->createDocumentFragment();
    $style->appendXML("
<style type=\"text/css\">
#thDebug { background-color: white; color: black; border: 1px solid black }
#thDebugLog li span.type { font-weight: bold; }
#thDebugLog li.error span.type { color: red; }
#thDebugLog li.warning span.type { color: #cc0; }
</style>
    ");
    $dom->documentElement->getElementsByTagName("head")->item(0)->appendChild($style);

    $acc = '<div id="thDebug"><h1>toaH Debug Center</h1>';

    $acc .= '<p>Using <a href="http://ttill.de/toah">toaH</a> version ' . Toah::VERSION . '</p>';
    $acc .= '<p><a href="' . $_SERVER["PHP_SELF"] . '?notoah">Show original page</a> (requires noToah module)</p>';

    if (count(Toah::$log)) {
        $acc .= '<h2>Log</h2><ol id="thDebugLog">';
        foreach (Toah::$log as $entry) {
            switch ($entry[0]) {
                case E_ERROR:
                    $acc .= '<li class="error"><span class="type">[ERROR] ';
                    break;
                case E_WARNING:
                    $acc .= '<li class="warning"><span class="type">[WARNING] ';
                    break;
                case E_NOTICE:
                    $acc .= '<li class="notice"><span class="type">[NOTICE] ';
                    break;
            }
            $acc .=  $entry[2][1]["function"] . ": </span>$entry[1]</li>";

        }
        $acc .= "</ol>";
    }

    $acc .= "</div>";

    $accFrag = $dom->createDocumentFragment();
    $accFrag->appendXML($acc);
    $dom->documentElement->getElementsByTagName("body")->item(0)->appendChild($accFrag);

    Toah::$output = Toah::$isXml ? $dom->saveXML() : $dom->saveHTML();
}

?>
