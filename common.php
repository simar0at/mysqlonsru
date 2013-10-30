<?php

/**
 * Common functions used by all the scripts using the mysql database.
 * 
 * @uses $dbConfigfile
 * @package mysqlonsru
 */
/**
 * Configuration options and function common to all fcs php scripts
 */
require_once "../utils-php/common.php";

/**
 * Load database and user data
 */
require_once $dbConfigFile;

/**
 * Get a database connection object (currently mysqli)
 * 
 * @uses $server
 * @uses $user
 * @uses $password
 * @uses $database
 * @return \mysqli
 */
function db_connect() {
    global $server;
    global $user;
    global $password;
    global $database;

    $db = new mysqli($server, $user, $password, $database);
    if ($db->connect_errno) {
        diagnostics(1, 'MySQL Connection Error: Failed to connect to database: (' . $db->connect_errno . ") " . $db->connect_error);
    }
    return $db;
}

/**
 * Process custom encoding used by web_dict databases
 * 
 * @param string $str
 * @return string
 */
function decodecharrefs($str) {
    $replacements = array(
        "#9#" => ";",
        "#8#" => "&#",
//     "%gt" => "&gt;",
//     "%lt" => "&lt;",
//     "&#amp;" => "&amp;",
//     "&#x" => "&x",
    );
    foreach ($replacements as $search => $replace) {
        $str = str_replace($search, $replace, $str);
    }
    return $str;
}

function sqlForXPath($table, $xpath, $options = NULL) {
    $sqlstr = "SELECT DISTINCT ndx.txt, base.entry FROM " .
            $table . " AS base " .
            "INNER JOIN " . $table . "_ndx AS ndx ON base.id = ndx.id " .
            "WHERE ndx.xpath LIKE '%" . $xpath . "'";
    if (isset($options) && is_array($options)) {
        if (isset($options["query"])) {
            $query = $options["query"];
            $sqlstr .= " AND ndx.txt = '$query'";
        }
        if (isset($options["filter"])) {
            $filter = $options["filter"];
            $sqlstr .= " AND ndx.txt != '$filter'";
        }
    }
    return $sqlstr;
}

function curPageURL() {
    $pageURL = 'http';
    if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] == "on") {
        $pageURL .= "s";
    }
    $pageURL .= "://";
    if ($_SERVER["SERVER_PORT"] != "80") {
        $pageURL .= $_SERVER["SERVER_NAME"] . ":" . $_SERVER["SERVER_PORT"] . $_SERVER["PHP_SELF"];
    } else {
        $pageURL .= $_SERVER["SERVER_NAME"] . $_SERVER["PHP_SELF"];
    }
    return $pageURL;
}

function populateSearchResult($db, $sqlstr, $description) {
    global $responseTemplate;
    global $sru_fcs_params;
    
    $baseURL = curPageURL();
    
    $result = $db->query($sqlstr);
    if ($result !== FALSE) {
        $numberOfRecords = $result->num_rows;

        $tmpl = new vlibTemplate($responseTemplate);

        $tmpl->setVar('version', $sru_fcs_params->version);
        $tmpl->setVar('numberOfRecords', $numberOfRecords);
        $tmpl->setVar('query', $sru_fcs_params->query);
        $tmpl->setVar('baseURL', $baseURL);
        $tmpl->setVar('xcontext', $sru_fcs_params->xcontext);
        $tmpl->setVar('xdataview', "full");
        // this isn't generated by fcs.xqm either ?!
        $nextRecordPosition = 0;
        $tmpl->setVar('nextRecordPosition', $nextRecordPosition);
        $tmpl->setVar('res', '1');

        $hits = array();
        $hitsMetaData = array();
        array_push($hitsMetaData, array('key' => 'copyright', 'value' => 'ICLTT'));
        array_push($hitsMetaData, array('key' => 'content', 'value' => $description));

        while (($line = $result->fetch_row()) !== NULL) {
            //$id = $line[0];
            $content = $line[1];

            array_push($hits, array(
                'recordSchema' => $sru_fcs_params->recordSchema,
                'recordPacking' => $sru_fcs_params->recordPacking,
                'queryUrl' => $baseURL,
                'content' => decodecharrefs($content),
                'hitsMetaData' => $hitsMetaData,
                // TODO: replace this by sth. like $sru_fcs_params->http_build_query
                'queryUrl' => '?' . htmlentities(http_build_query($_GET)),
            ));
        }
        $result->close();

        $tmpl->setloop('hits', $hits);
        $tmpl->pparse();
    } else {
        diagnostics(1, 'MySQL query error: Query was: ' . $sqlstr);
    }
}

function populateScanResult($db, $sqlstr) {
    global $scanTemplate;
    global $sru_fcs_params;
    
    $maximumTerms = 100;

    $result = $db->query($sqlstr);
    if ($result !== FALSE) {
        $numberOfRecords = $result->num_rows;

        $tmpl = new vlibTemplate($scanTemplate);

        $terms = array();
        $startPosition = 0;
        $position = $startPosition;
        while ((($row = $result->fetch_array()) !== NULL) &&
        ($position < $maximumTerms + $startPosition)) {
            $term = array(
                'value' => decodecharrefs($row[0]),
                'numberOfRecords' => 1,
                'position' => ++$position,
            );
            if (isset($row["lemma"]) && decodecharrefs($row["lemma"]) !== $term["value"]) {
                $term["displayTerm"] = decodecharrefs($row["lemma"]);
            }
            array_push($terms, $term);
        }

        $tmpl->setloop('terms', $terms);

        $tmpl->setVar('version', $sru_fcs_params->version);
        $tmpl->setVar('count', $numberOfRecords);
        $tmpl->setVar('clause', $sru_fcs_params->scanClause);
        $responsePosition = 0;
        $tmpl->setVar('responsePosition', $responsePosition);
        $tmpl->setVar('maximumTerms', $maximumTerms);

        $tmpl->pparse();
    } else {
        diagnostics(1, 'MySQL query error: Query was: ' . $sqlstr);
    }
}

function getParamsAndSetUpHeader() {
    global $sru_fcs_params;
    
    $sru_fcs_params = new SRUWithFCSParameters("lax");
// TODO: what's this for ???
    $sru_fcs_params->query = str_replace("|", "#", $sru_fcs_params->query);
    if ($sru_fcs_params->recordPacking === "") {
        $sru_fcs_params->recordPacking = "xml";
    }
// TODO: why ... ???
    if ($sru_fcs_params->recordPacking !== "xml") {
        $sru_fcs_params->recordPacking = "raw";
    }

    if ($sru_fcs_params->recordPacking === "xml") {
        header("content-type: text/xml");
    }
}

function processRequest() {
    global $sru_fcs_params;
    
    if ($sru_fcs_params->operation == "explain" || $sru_fcs_params->operation == "") {
        explain();
    } else if ($sru_fcs_params->operation == "scan") {
        scan();
    } else if ($sru_fcs_params->operation == "searchRetrieve") {
        search();
    }
}