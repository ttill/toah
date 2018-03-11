<?php
/*
 *      Copyright (C) 2009-2014 Till Theato <root@ttill.de>
 *           http://ttill.de/toah
 * 
 *      This program is free software: you can redistribute it and/or modify
 *      it under the terms of the GNU General Public License as published by
 *      the Free Software Foundation, either version 3 of the License, or
 *      (at your option) any later version.
 */
 
 /**
  * Configuration
  */
/* Handles whether toah should be directly accessible or only via include or redirect (htaccess).
   Combined with redirect this only works if the webserver sets $_SERVER["REDIRECT_STATUS"] (Apache does). */
define("TOAH_ALLOW_DIRECT_ACCESS", false);


class Toah
{
    /**
     * Static functions and variables. These provide the interface for modules, so no access to the
     * toaH object is required.
     */

    /* Version number of toaH */
    const VERSION = 141004;

     /* Module stages. Use them when calling registerModule. */
    const PreDomCreation = 0,
          PreTemplateLoad = 2,
          PreFilesFind = 4,
          PreSnippetsInsert = 6,
          PreOutputGeneration = 8,
          PreOutput = 10;

    /* the DomDocument toaH is build around */
    static $dom;

    /* text snippets, that will be inserted into the document by toaH. Values are arrays with 2 elements:
     * [1] the string to be inserted, [2] a xpath expression specifing the element to insert [1] into. */
    static $snip = array();

    /* Custom data. Can be used for example to share information between multiple file module function calls. */
    static $data = array();

    /* Log entries. Should not directly be written to. @see log(). Note that toaH itself does not use/output the log data. */
    static $log = array();

    static $input, $output, $isXml = true;

    /* Shortcut for xpath querys; ~ will be replaced by the namesapce prefix "xhtml:" if the document has a namespace */
    static function q($q) { return self::$xpath->query(str_replace("~", self::$ns ? "xhtml:" : "", $q))->item(0); }

    /* Adds $function as a module. $stage specifies when the module will be called. See the stage constants. */
    static function registerModule($stage, $function) { self::$modules[$stage][] = $function; }

    /* Registers $function as a file module. It will be called when file $name was found in the directory of the input file or a parent one.
     * If $multiple is false $function will be called only once. $function will be called with two parameters:
     * [1] file $name (with the current working directory being the one where the file was found, so use this for file operations/includes/...)
     * [2] $name with path relative to webroot, to be used in links,... (user visible things) */
    static function registerFile($name, $function, $multiple=true) { self::$files[$name] = array($multiple, $function); }

    /* Adds a log entry.
     * The following constants should be used as messageType E_ERROR, E_WARNING, E_NOTICE
     * In addition to type and message a backtrace is stored in a Toah::$log entry. */
    static function log($message, $messageType=E_NOTICE) { self::$log[] = array($messageType, $message, debug_backtrace()); }


    /**
     * toaH internal
     */

    public function __construct($input) {
        mb_internal_encoding("UTF-8");
        self::$input = $input;

        self::registerModule(self::PreDomCreation + 1, array($this, "createDOM"));
        self::registerModule(self::PreTemplateLoad + 1, array($this, "loadTemplate"));
        self::registerModule(self::PreFilesFind + 1, array($this, "findFiles"));
        self::registerModule(self::PreSnippetsInsert + 1, array($this, "insertSnippets"));
        self::registerModule(self::PreOutputGeneration + 1, array($this, "generateOutput"));

        ksort(self::$modules);
        foreach(self::$modules as $stage)
            array_walk($stage, "call_user_func");

        die(self::$output);
    }

    public function createDOM() {
        self::$dom = new DOMDocument("1.0", "UTF-8");
        // try to handle invalid input too
        self::$dom->strictErrorChecking = false;
        // properly handle both XHTML and HTML
        if(!self::$dom->loadXML(self::$input)) {
            self::log("Unable to handle document as XML. Falling back to HTML.", E_WARNING);
            self::$dom->loadHTML(self::$input);
            self::$isXml = false;
        }

        self::$xpath = new DomXPath(self::$dom);
        if(self::$ns = strlen(self::$dom->lookupNamespaceUri(self::$dom->namespaceURI)) ? true : false)
            self::$xpath->registerNamespace("xhtml", "http://www.w3.org/1999/xhtml");

        if (self::$isXml && (stristr($_SERVER["HTTP_ACCEPT"], "application/xhtml+xml") || stristr($_SERVER["HTTP_USER_AGENT"], "W3C_Validator")))
            header("Content-Type: application/xhtml+xml; charset=UTF-8");
        else
            header("Content-Type: text/html; charset=UTF-8");
        header("Vary: Accept");
    }

    public function loadTemplate() {
        if($templateContent = file_get_contents(rtrim(dirname(__FILE__), '/') . "/template.xml")) {
            $template = self::$dom->createDocumentFragment();
            $template->appendXML($templateContent);

            // replace the body tag and its children with the content from the template
            $content = self::$dom->documentElement->replaceChild($template, self::q("/~html/~body"))->childNodes;

            // append the old content (everything in original body) to thContent
            $wrapper = self::q("//div[@id='thContent']");
            while ($content->length)
                $wrapper->appendChild($content->item(0));
        }
    }

    public function findFiles() {
        // set absolute path (will be updated in the loop) to be able to access $file without concerns about the directory
        chdir(dirname($_SERVER["SCRIPT_FILENAME"]));
        // append "/" so that we stay in the specified directory in the first iteration (the appended "/" is removed again there)
        $directory = rtrim(dirname($_SERVER["PHP_SELF"]), '/') . '/';
        do {
            // move one folder up
            $directory = preg_replace("/^(.*)\/[^\/]*$/", "$1", $directory);
            $files = self::$files;
            while (list($file, $val) = each($files)) {
                if (is_file($file)) {
                    call_user_func($val[1], $file, $directory . "/" . $file);
                    // if $multiple was set to false remove from list to avoid future use
                    if (!$val[0])
                        unset($files[$file]);
                }
            }
        } while(strlen($directory) && chdir(".."));
    }

    public function insertSnippets() {
        foreach(self::$snip as $snippet) {
            if($w = self::q($snippet[1])) {
                $elements = self::$dom->createDocumentFragment();
                $elements->appendXML($snippet[0]);
                $w->appendChild($elements);
            }
        }
    }

    public function generateOutput() {
        self::$output = self::$isXml ? self::$dom->saveXML() : self::$dom->saveHTML();
    }

    private static $modules = array(), $files = array(), $xpath, $ns = false;
}


/**
 * Core modules
 */

/* file module: Adds a stylesheet link pointing to $link to the document. */
function createStyleHref($file, $link)
{
    if(!array_key_exists("style", Toah::$snip))
        Toah::$snip["style"] = array("", "/~html/~head");

    Toah::$snip["style"][0] = "<link rel=\"stylesheet\" type=\"text/css\" href=\"$link\"/>" . Toah::$snip["style"][0];
} Toah::registerFile("style.css", "createStyleHref");

/* Creates menu and path from menu.txt in current and parent directories. */
class ToahMenu
{
    private $path = array(), $pathItemTag, $pathDelimiter;
    private $menuItemTag, $subMenuWrapperTag, $subMenuWrapperClass, $subMenuItemTag;
    private $subMenus = array();

    public function __construct() {
        Toah::registerFile("menu.txt",  array($this, "createPathMenu"));
        Toah::registerModule(Toah::PreSnippetsInsert, array($this, "joinPath"));
    }

    /* Creates a menu at the first call and assembles a path at every additional call. */
    public function createPathMenu($file, $link) {
        $fileHandler = fopen($file, "r");

        // first line should contain main title
        if(count($title = fgetcsv($fileHandler, 1024, ";")) == 1) {
            $this->path[] = dirname($link) == dirname($_SERVER["PHP_SELF"]) ? "<span id=\"thPathCurrent\">$title[0]</span>"
                                                                            : "<a href=\"" . dirname($link) . "\">$title[0]</a>";
            // use first one found
            if(!array_key_exists("mtitle", Toah::$snip))
                Toah::$snip["mtitle"] = array($title[0], "//*[@id='thMainTitle']");
        }

        // if this function is called the first time: create menu
        if(!array_key_exists("path", Toah::$snip)) {
            Toah::$snip["path"] = array("", "//*[@id='thPath']");
            Toah::$snip["menu"] = array("", "//*[@id='thMenu']");

            if ($menu = Toah::q(Toah::$snip["menu"][1])) {
                $this->menuItemTag = $menu->removeChild($menu->firstChild)->nodeName;
                if ($wrapper = $menu->firstChild) {
                    $this->subMenuWrapperClass = $wrapper->getAttribute("class") ?: "";
                    $this->subMenuWrapperTag = $menu->removeChild($wrapper)->nodeName;
                    $this->subMenuItemTag = $menu->removeChild($menu->firstChild)->nodeName;
                }
            }
            if ($path = Toah::q(Toah::$snip["path"][1])) {
                $this->pathItemTag = $path->removeChild($path->firstChild)->nodeName;
                // used to seperate path entries
                $this->pathDelimiter = $path->hasChildNodes() ? Toah::$dom->saveXML($path->removeChild($path->firstChild)) : "";
            }

            $this->createMenuEntries($fileHandler, rtrim(dirname($link), "/"), Toah::$snip["menu"][0]);
        }

        fclose($fileHandler);
    }

    /* Joins path entries to html snippet. */
    public function joinPath() {
        if (count($this->path))
            Toah::$snip["path"][0] = '<' . $this->pathItemTag . '>'
                                     . implode('</' . $this->pathItemTag . '>' . $this->pathDelimiter . '<' . $this->pathItemTag . '>', array_reverse($this->path))
                                     . '</' . $this->pathItemTag . '>';
    }

    /* Reads the file line for line and adds menu entries. Sub-menus are handled by recursion. */
    private function createMenuEntries(&$fileHandler, $basePath, &$parentItem, $topLevel = true) {
        $entries = array();

        while($line = fgetcsv($fileHandler, 1024, ";")) {
            if(count($line) >= 3) {
                // current page (add the page title) or normal link
                if(strstr($_SERVER["PHP_SELF"], "/" . $line[0])) {
                    $entries[] = "<span id=\"thMenuCurrent\">$line[1]</span>";
                    Toah::$snip[] = array($line[2], "//*[@id='thPageTitle']");
                } else {
                    $entries[] = "<a href=\"$basePath/$line[0]\">$line[1]</a>";
                }

                if (count($line) > 3) {
                    end($entries);
                    $this->subMenus[$line[3]] = &$entries[key($entries)];
                }
            } elseif (count($line) == 1 && array_key_exists($line[0], $this->subMenus)) {
                $this->createMenuEntries($fileHandler, $basePath, $this->subMenus[$line[0]], false);
            }
        }

        $itemTag = $topLevel ? $this->menuItemTag : $this->subMenuItemTag;
        $entryString = "<$itemTag>" . implode("</$itemTag><$itemTag>", $entries) . "</$itemTag>";

        if (!$topLevel)
            $entryString = "<" . $this->subMenuWrapperTag . (empty($this->subMenuWrapperClass) ? "" :  " class=\"" . $this->subMenuWrapperClass . "\"") . ">"
                           . $entryString
                           . "</" . $this->subMenuWrapperTag . ">";
        $parentItem .= $entryString;
    }
}
new ToahMenu();

/**
 * Basic setup and loading of toaH
 */

function toah()
{
    if(isset($_GET["file"]) && is_string($_GET["file"]) && isset($_GET["link"]) && is_string($_GET["link"])) {
        if(!is_file($_GET["file"])
        || !(strpos(__FILE__, $_SERVER["DOCUMENT_ROOT"]) === 0 ? strpos($_GET["file"], $_SERVER["DOCUMENT_ROOT"]) === 0 : true)
        || !TOAH_ALLOW_DIRECT_ACCESS && !isset($_SERVER["REDIRECT_STATUS"])) {
            header("HTTP/1.0 404 Not Found");
            die("404 - File not found");
        }

        // toaH is accessed via paramter (e.g. by mod_rewrite), so fake environment/set executing script
        $_SERVER["SCRIPT_FILENAME"] = $_GET["file"];
        $_SERVER["PHP_SELF"] = $_GET["link"];
    }

    if(is_file(rtrim(dirname(__FILE__), '/') . "/thplugins.php"))
        include_once rtrim(dirname(__FILE__), '/') . "/thplugins.php";

    // include files with the extension 'php' so possible code gets executed
    // TODO: check for mimetype instead of extension
    if(pathinfo($_SERVER["SCRIPT_FILENAME"], PATHINFO_EXTENSION) == "php") {
        ob_start();
        include $_SERVER["SCRIPT_FILENAME"];
        $contents = ob_get_contents();
        ob_end_clean();
    } else {
        $contents = file_get_contents($_SERVER["SCRIPT_FILENAME"]);
    }

    new Toah($contents);
}

toah();
?>
