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
 * Decode custom encoding used by web_dict databases to UTF-8
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
    return html_entity_decode_numeric($str);
}

/**
 * Take a string and encode it the way it's stored in web_dict dbs.
 * 
 * @param type $str String to encode.
 * @return type Encoded String
 */
function encodecharrefs($str) {
    $replacements = array(
        ";" => "#9#",
        "&#" => "#8#",
//     "&gt;" => "%gt",
//     "&lt;" => "%lt",
//     "&amp;" => "&#amp;",
//     "&x" => "&#x",
    );
    $htmlEncodedStr = utf8_character2html_decimal_numeric($str);
    foreach ($replacements as $search => $replace) {
        $htmlEncodedStr = str_replace($search, $replace, $htmlEncodedStr);
    }
    return $htmlEncodedStr;
}

/**
 * Genereates an SQL statement that can be used to fetch data from tables used
 * and generated by web_dict_editor. The result contains the text searched for
 * in the first column and the (full text) entry in the second one. Optionally
 * the third column, lemma, contains the lemma associated with the entry.
 * @param string $table Name of the table to search in.
 * @param string $xpath XPath like statement of the form -node-node-node-.
 *                      An empty string will select every XPath.
 * @param array $options Options: show-lemma => return a lemma column
 *                                query => The term searched for in the specified nodes
 *                                filter => A term to filter from the specified nodes, eg. - (no text)
 *                                distinct-values => whether the result should have only a single
 *                                                   column for each term found among the XPaths
 *                                exact => Whether to search for exactly that string, default
 *                                         is to just search for the string anywhere in the
 *                                         specified tags.
 *                                startRecord => limited search starting at this position
 *                                               of the result set. Default is start at the first.
 *                                maximumRecords => maximum number of records to return.
 *                                                  Needs startRecord to be set.
 *                                                  Default is return all records.                          
 * @return string
 */
function sqlForXPath($table, $xpath, $options = NULL) {
    $lemma = "";
    $query = "";
    $filter = "";
    if (isset($options) && is_array($options)) {
        if (isset($options["show-lemma"]) && $options["show-lemma"] === true) {
            $lemma = ", base.lemma";
        }
        if (isset($options["query"])) {
            $q = $options["query"];
            if (isset($options["exact"]) && $options["exact"] === true) {
               $query .= " AND ndx.txt = '$q'";
            } else {
               $query .= " AND ndx.txt LIKE '%$q%'";
            }
        }
        if (isset($options["filter"])) {
            $f = $options["filter"];
            $filter .= " AND ndx.txt != '$f'";
        }
        if (isset($options["distinct-values"]) && $options["distinct-values"] === true) {
            $filter .= " GROUP BY ndx.txt ORDER BY ndx.txt";
        }
        if (isset($options["startRecord"])) {
            $filter .= " LIMIT " . ($options["startRecord"] - 1);
            if (isset($options["maximumRecords"])) {
                $filter .= ", " . $options["maximumRecords"];
            }
        }
    }
    return "SELECT ndx.txt, base.entry".$lemma." FROM " .
            $table . " AS base " .
            "INNER JOIN " . $table . "_ndx AS ndx ON base.id = ndx.id " .
            "WHERE ndx.xpath LIKE '%" . $xpath . "'".$query.$filter;            
}

/**
 * Get the URL the client requested so this script was called
 * @return string The URL the client requested.
 */
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

/**
 * Execute a search and return the result using the $responseTemplate
 * @uses $responseTemplate
 * @uses $sru_fcs_params
 * @param object $db An object supporting query($sqlstr) which should return a
 *                   query object supporting fetch_row(). Eg. a mysqli object
 *                   or an sqlite3 object.
 * @param string $sqlstr A query string to exequte using $db->query()
 * @param string $description A description used by the $responseTemplate.
 * @param function $processResult An optional (name of a) function called on every result record
 *                                so additional processing may be done. The default
 *                                is to return the result fetched from the DB as is.
 *                                The function receives the record line (array) returned by
 *                                the database as input and the db access object.
 *                                It is expected to return
 *                                the content that is placed at the appropriate
 *                                position in the returned XML document.
 */
function populateSearchResult($db, $sqlstr, $description, $processResult = NULL) {
    global $responseTemplate;
    global $sru_fcs_params;
    
    $baseURL = curPageURL();
    
    $result = $db->query($sqlstr);
    if ($result !== FALSE) {
        $numberOfRecords = $result->num_rows;

        $tmpl = new vlibTemplate($responseTemplate);

        $tmpl->setVar('version', $sru_fcs_params->version);
        $tmpl->setVar('numberOfRecords', $numberOfRecords);
        // There is currently no support for limiting the number of results.
        $tmpl->setVar('returnedRecords', $numberOfRecords);
        $tmpl->setVar('query', $sru_fcs_params->query);
        $tmpl->setVar('transformedQuery', $sqlstr);
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
            if (isset($processResult)) {
                $content = $processResult($line, $db);
            } else {
                $content = $line[1];
            }

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

/**
 * Execute a scan and return the result using the $scanTemplate
 * @uses $scanTemplate
 * @uses $sru_fcs_params
 * @param object $db An object supporting query($sqlstr) which should return a
 *                   query object supporting fetch_row(). Eg. a mysqli object
 *                   or an sqlite3 object.
 * @param string $sqlstr A query string to exequte using $db->query()
 * @param string|array $entry An optional entry from which to start listing terms.
 *                       If this is an array it is assumed that it its buld like this:
 *                       array[0] => the beginning search string
 *                       array[1] => wildcard(s)
 *                       array[2] => the end of the search string
 * @param bool $exact If the start word needs to be exactly the specified or
 *                    if it should be just anywhere in the string.
 */
function populateScanResult($db, $sqlstr, $entry = NULL, $exact = true) {
    global $scanTemplate;
    global $sru_fcs_params;
    
    $maximumTerms = $sru_fcs_params->maximumTerms;
                
    $result = $db->query($sqlstr);
    if ($result !== FALSE) {
        $numberOfRecords = $result->num_rows;

        $tmpl = new vlibTemplate($scanTemplate);

        $terms = new SplFixedArray($result->num_rows);
        $i = 0;
        while (($row = $result->fetch_array()) !== NULL) {
            $term = array(
                'value' => decodecharrefs($row[0]),
                'numberOfRecords' => 1,
            );
            // for sorting ignore some punctation marks etc.
            $term["sortValue"] = trim(preg_replace('/[?!()*,.\\-\\/|=]/', '', mb_strtoupper($term["value"], 'UTF-8')));
            // sort strings that start with numbers at the back.
            $term["sortValue"] = preg_replace('/^(\d)/', 'zz${1}', $term["sortValue"]);
            // only punctation marks or nothing at all last.
            if ($term["sortValue"] === "") {
                $term["sortValue"] = "zzz";
            }
            if (isset($row["lemma"]) && decodecharrefs($row["lemma"]) !== $term["value"]) {
                $term["displayTerm"] = decodecharrefs($row["lemma"]);
            }
            $terms[$i++] = $term;
        }
        $sortedTerms = $terms->toArray();
        usort($sortedTerms, function ($a, $b) {
            $ret = strcmp($a["sortValue"],  $b["sortValue"]);
            return $ret;
        });
        $startPosition = 0;
        if (isset($entry)) {
            $startAtString = is_array($entry) ? $entry[0] : $entry;          
            while ($startPosition < count($sortedTerms)) {
                $found = strpos($sortedTerms[$startPosition]["value"], $startAtString);
                if ($exact ? $found === 0 : $found !== false) {
                    break;
                }
                $startPosition++;
            }
        }
        $position = $startPosition;
        $shortList = array();
        while ($position < min($maximumTerms + $startPosition, count($sortedTerms))){
            array_push($shortList, $sortedTerms[$position]);
            $shortList[$position]["position"] = $position + 1;
//            $shortList[$position]["value"] = encodecharrefs($shortList[$position]["value"]);
//            $shortList[$position]["value"] = utf8_character2html_decimal_numeric($shortList[$position]["sortValue"]) . "->" . $shortList[$position]["value"];
            $position++;
        }

        $tmpl->setloop('terms', $shortList);

        $tmpl->setVar('version', $sru_fcs_params->version);
        $tmpl->setVar('count', $numberOfRecords);
        $tmpl->setVar('transformedQuery', $sqlstr);
        $tmpl->setVar('clause', $sru_fcs_params->scanClause);
        $responsePosition = 0;
        $tmpl->setVar('responsePosition', $responsePosition);
        $tmpl->setVar('maximumTerms', $maximumTerms);

        $tmpl->pparse();
    } else {
        diagnostics(1, 'MySQL query error: Query was: ' . $sqlstr);
    }
}
/**
 * Initializes the global object holding the parameters and switches off the
 * header declaration of xml on request. (TODO discuss ???)
 * @uses $sru_fcs_params
 */
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

/**
 * Switching function that initiates the correct action as specified by the
 * operation member of $sru_fcs_params.
 * @uses $sru_fcs_params
 */
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

/**
 * Returns the search term if a wildcard search is requested for the given index
 * @param string $index The index name that should be in the query string if
 * this function is to return a search term.
 * @param string $queryString The query string passed by the user.
 * @param string $index_context An optional context name for the index.
 * As in _cql_.serverChoice.
 * @return string|NULL The term to search for, NULL if this is not the index the user wanted.
 */
function get_search_term_for_wildcard_search($index, $queryString, $index_context = NULL) {
    $ret = NULL;
    if (isset($index_context)) {
        $ret = preg_filter('/(' . $index_context . '\.)?' . $index . ' *(=|any) *(.*)/', '$3', $queryString);
    } else {
        $ret = preg_filter('/' . $index . ' *(=|any) *(.*)/', '$2', $queryString);
    }
    return $ret;
}

/**
 * Returns the search term if an exact search is requested for the given index
 * 
 * Note that the definition of a search anywhere and an exact one is rather close
 * so get_search_term_for_wildcard_search will also return a result (_=_+search).
 * 
 * @param string $index The index name that should be in the query string if
 * this function is to return a search term.
 * @param string $queryString The query string passed by the user.
 * @param string $index_context An optional context name for the index.
 * As in _cql_.serverChoice.
 * @return string|NULL NULL if this is not the index the user wanted.
 */
function get_search_term_for_exact_search($index, $queryString, $index_context = NULL) {
    $ret = NULL;
    if (isset($index_context)) {
        $ret = preg_filter('/(' . $index_context . '\.)?' . $index . ' *(==|(cql\.)?string) *(.*)/', '$4', $queryString);
    } else {
        $ret = preg_filter('/' . $index . ' *(==|(cql\.)?string) *(.*)/', '$3', $queryString);
    }
    return $ret;
}

/**
 * Look for the * or ? wildcards
 * @param string $input A string that may contain ? or * as wildcards.
 * @return string|array An array consisting of the first part, the wildcard and 
 *                      the last part of the search string
 *                      or just the input string if it didn't contain wildcards
 */
function get_wild_card_search($input) {
    $search = preg_filter('/(\w*)([?*][?]*)(\w*)/', '$1&$2&$3', $input);
    if (isset($search)) {
        $ret = explode("&", $search);
    } else {
        $ret = $input;
    }
    return $ret;
}