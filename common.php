<?php

/**
 * Common functions used by all the scripts using the mysql database.
 * 
 * @uses $dbConfigfile
 * @package mysqlonsru
 */

namespace ACDH\FCSSRU\mysqlonsru;
/**
 * Configuration options and function common to all fcs php scripts
 */
require_once "../utils-php/common.php";

use clausvb\vlib\vlibTemplate;

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

    $db = new \mysqli($server, $user, $password, $database);
    if ($db->connect_errno) {
        \ACDH\FCSSRU\diagnostics(1, 'MySQL Connection Error: Failed to connect to database: (' . $db->connect_errno . ") " . $db->connect_error);
    }
    return $db;
}

/**
 * Decode custom encoding used by web_dict databases to UTF-8
 * 
 * @param string $str
 * @return string The decoded string as an UTF-8 encoded string. May contain
 *                characters that need to be escaped in XML/XHTML.
 */
function decodecharrefs($str) {
    $replacements = array(
        "#8#38#9#" => '&amp;amp;', // & -> &amp;
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
    return \ACDH\FCSSRU\html_entity_decode_numeric($str);
}

/**
 * Take a string and encode it the way it's stored in web_dict dbs.
 * 
 * @param type $str String to encode.
 * @return type Encoded String
 */
function encodecharrefs($str) {
    if ($str === null) {return null;}
    $replacements = array(
        ";" => "#9#",
        "&#" => "#8#",
//     "&gt;" => "%gt",
//     "&lt;" => "%lt",
//     "&amp;" => "&#amp;",
//     "&x" => "&#x",
    );
    $htmlEncodedStr = \ACDH\FCSSRU\utf8_character2html_decimal_numeric(utf8_decode($str));
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
 *                      Maybe overidden by dbtable in options.
 * @param string $xpath XPath like statement of the form -node-node-node-.
 *                      An empty string will select every XPath.
 *                      My be overridden by xpath in options.
 * @param array $options Options: show-lemma => return a lemma column
 *                                query => The term searched for in the specified nodes
 *                                filter => A term to filter from the specified nodes, eg. - (no text)
 *                                xpath-filters => An array of the form $xpaht => $text values which limits
 *                                                 the result to all those entries that end in an
 *                                                 xpath having the value text.
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
 *                                dbtable => Overrides $table.
 *                                xpath => Overrides $xpath.                   
 * @return string
 */
function sqlForXPath($table, $xpath, $options = NULL) {
    $lemma = "";
    $query = "";
    $filter = "";
    $groupCount = "";
    $justCount = false;
    if (isset($options) && is_array($options)) {
        if (isset($options["dbtable"])) {
            $table = $options["dbtable"];
        }
        if (isset($options["xpath"])) {
            $xpath = $options["xpath"];
        }        
        if (isset($options["show-lemma"]) && $options["show-lemma"] === true) {
            $lemma = ", base.lemma";
        }
        if (isset($options["query"])) {
            $q = encodecharrefs($options["query"]);
            if (isset($options["exact"]) && $options["exact"] === true) {
               $query .= " AND ndx.txt = '$q'";
            } else {
               $query .= " AND ndx.txt LIKE '%$q%'";
            }
        }
        if (isset($options["filter"])) {
            $f = $options["filter"];
            if (strpos($f, '%') !== false) {
                $filter .= " AND ndx.txt NOT LIKE '$f'";
            } else {
                $filter .= " AND ndx.txt != '$f'";
            }
        }
        if (isset($options["justCount"]) && $options["justCount"] === true) {
            $justCount = true;
        }
        if (isset($options["distinct-values"]) && $options["distinct-values"] === true) {
            $groupCount = ", COUNT(*)";
            $filter .= " GROUP BY ndx.txt ORDER BY ndx.txt";
        } else if ($justCount !== true) {
            $groupCount = ", COUNT(*)";
            $filter .= " GROUP BY base.sid";
        }
        if (isset($options["startRecord"])) {
            $filter .= " LIMIT " . ($options["startRecord"] - 1);
            if (isset($options["maximumRecords"])) {
                $filter .= ", " . $options["maximumRecords"];
            }
        }
        if (isset($options["xpath-filters"])) {
            $tableOrPrefilter = genereatePrefilterSql($table, $options);
        } else {
            $tableOrPrefilter = $table;
        }
    }
    return "SELECT" . ($justCount ? " COUNT(*) " : " ndx.txt, base.entry, base.sid" . $lemma . $groupCount) .
            " FROM " . $tableOrPrefilter . " AS base " .
            "INNER JOIN " . $table . "_ndx AS ndx ON base.id = ndx.id " .
            "WHERE ndx.xpath LIKE '%" . $xpath . "'".$query.$filter;            
}

function genereatePrefilterSql($table, $options) {
    $recursiveOptions = $options;
    $recursiveOptions["xpath-filters"] = array_slice($recursiveOptions["xpath-filters"], 2, 0, true);
    if (count($recursiveOptions["xpath-filters"]) === 0) {
        $tableOrPrefilter = $table;
    } else {
        $tableOrPrefilter = genereatePrefilterSql($table, $recursiveOptions);
    }
    return '(SELECT base.* FROM '. $table . ' AS base INNER JOIN ' . $table .
           "_ndx AS prefilter ON base.id=prefilter.id WHERE prefilter.xpath LIKE  '%" .
            key($options["xpath-filters"]) . "' AND prefilter.txt = '" . current($options["xpath-filters"]) ."')";
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
 * Fill in the ZeeRex explain template and return it to the client.
 * 
 * @uses $explainTemplate
 * @param object $db
 * @param string $table The table from which the teiHeader at id 1 is fetched.
 * @param string $publicName The public name for this resource.
 * @param array $indices An array with index configuratiuons. Should contain maps
 *                       with the following keys:
 *                       title string: An intelligable title for the index.
 *                       name string: The name of the index unterstood by the script
 *                       search bool
 *                       scan bool
 *                       sott bool
 * @see http://zeerex.z3950.org/overview/index.html
 */
function populateExplainResult ($db, $table, $publicName, $indices) {
    global $explainTemplate;
    
    $teiHeaderXML = getMetadataAsXML($db, $table);
    $title = "";
    $authors = "";
    $restrictions = "";
    $description = "";
    if (isset($teiHeaderXML)) {
        $title = $teiHeaderXML->evaluate('string(//titleStmt/title)');

        $authorsNodes = $teiHeaderXML->query('//fileDesc/author');
        $authors = "";
        foreach ($authorsNodes as $author) {
            $authors .= "; " . $author->nodeValue;
        }
        $authors = substr($authors, 2);

        $restrictions = $teiHeaderXML->evaluate('string(//publicationStmt/availability[@status="restricted"]//ref/@target)');

//        $description = $xmlDocXPath->evaluate('string(//publicationStmt/pubPlace)') . ', ' .
//                $xmlDocXPath->evaluate('string(//publicationStmt/date)') . '. Edition: ' .
//                $xmlDocXPath->evaluate('string(//editionStmt/edition)') . '.';
        $frontMatterXML = getFrontMatterAsXML($db, $table);
        if ($frontMatterXML !== null) {
            $description = $frontMatterXML->document->saveXML($frontMatterXML->document->firstChild);
        } else {
            $description = $teiHeaderXML->document->saveXML($teiHeaderXML->document->firstChild);
        }
    }
    
    $tmpl = new vlibTemplate($explainTemplate);
    
    $tmpl->setLoop('maps', $indices);
    
    $tmpl->setVar('hostid', htmlentities($_SERVER["HTTP_HOST"]));
    $tmpl->setVar('database', $publicName);
    $tmpl->setVar('databaseTitle', $title);
    $tmpl->setVar('databaseAuthor', $authors);
    $tmpl->setVar('dbRestrictions', $restrictions);
    $tmpl->setVar('dbDescription', $description);
    $tmpl->pparse();
}

/**
 * Get the metadata stored in the db as XPaht object which also contains a
 * representation of the document.
 * 
 * @param type $db The db connection
 * @param type $table The table in the db that should be queried
 * @return \DOMXPath|null The metadata (teiHeader) as 
 */
function getMetadataAsXML($db, $table) {
    // It is assumed that there is a teiHeader for the resource with this well knonwn id 1
    return getWellKnownTEIPartAsXML($db, $table, 1);
}

/**
 * Get the front matter from the given db
 * 
 * @param type $db The db connection
 * @param type $table The table in the db that should be queried
 * @return \DOMXPath|null The front matter
 */
function getFrontMatterAsXML($db, $table) {
    // It is assumed that there is a front part for the resource with this well knonwn id 5
    return getWellKnownTEIPartAsXML($db, $table, 5);
}

/**
 * Get some TEI part by well known id
 * 
 * @param type $db The db connectio
 * @param type $table The table in the db that should be queried
 * @param type $id The well known id of the TEI part to fetch 
 * @return \DOMXPath|null Some TEI-XML, null if the id is not in teh db
 */
function getWellKnownTEIPartAsXML ($db, $table, $id) {
    $result = $db->query("SELECT entry FROM $table WHERE id = $id");
    if ($result !== false) {
        $line = $result->fetch_array();
        if (is_array($line) && trim($line[0]) !== "") {
            return getTEIDataAsXMLQueryObject(decodecharrefs($line[0]));
        } else {
            return null;
        }
    } else {
        return null;
    } 
}

/**
 * Turn the input text into a queryable object
 * 
 * @param type $xmlText A chunk of TEI XML
 * @return \DOMXPath The input text consisting of TEI XML as DOMXPath queryable object
 */
function getTEIDataAsXMLQueryObject($xmlText) {
    $xmlDoc = new \DOMDocument();
    $xmlDoc->loadXML($xmlText);
    // forcebly register default and tei xmlns as tei
    $xmlDoc->createAttributeNS('http://www.tei-c.org/ns/1.0', 'create-ns');
    $xmlDoc->createAttributeNS('http://www.tei-c.org/ns/1.0', 'tei:create-ns');
    $xmlDocXPath = new \DOMXPath($xmlDoc);
    return $xmlDocXPath;
}
/**
 * Execute a search and return the result using the $responseTemplate
 * @uses $responseTemplate
 * @uses $sru_fcs_params
 * @param object $db An object supporting query($sqlstr) which should return a
 *                   query object supporting fetch_row(). Eg. a mysqli object
 *                   or an sqlite3 object.
 * @param array|string $sql Either an arrary that can be used to get a query string using
 *                          sqlForPath;
 *                          or a query string to exequte using $db->query()
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
function populateSearchResult($db, $sql, $description, $processResult = NULL) {
    global $responseTemplate;
    global $sru_fcs_params;
    
    $baseURL = curPageURL();
    
    $dbTeiHeaderXML = null;
    $wantTitle = (stripos($sru_fcs_params->xdataview, 'title') !== false);
    $wantMetadata = (stripos($sru_fcs_params->xdataview, 'metadata') !== false);

    $extraCountSql = false;
    if (is_array($sql)) {
        $options = $sql;
        $sql = sqlForXPath("", "", $options);
        if ($wantMetadata || $wantTitle) {
            $dbTeiHeaderXML = getMetadataAsXML($db, $options['dbtable']);
        }
        if (isset($options["maximumRecords"])) {
            $options["startRecord"] = NULL;
            $options["maximumRecords"] = NULL;
            $options["justCount"] = true;
            $countSql = sqlForXPath("", "", $options);
            $result = $db->query($countSql);
            if ($result !== false) {
                $line = $result->fetch_row();
                $extraCountSql = $line[0];
            }
        }
    } else if ($wantMetadata || $wantTitle) {
        $dbtable = preg_filter('/.* FROM (\\w+) .*/', '$1', $sql);
        if ($dbtable !== false) {
            $dbTeiHeaderXML = getMetadataAsXML($db, $dbtable);
        }
    }
    
    $result = $db->query($sql);
    if ($result !== FALSE) {
        if ($extraCountSql !== false) {
            $numberOfRecords = $extraCountSql;
        } else {
            $numberOfRecords = $result->num_rows;
        }

        $tmpl = new vlibTemplate($responseTemplate);

        $tmpl->setVar('version', $sru_fcs_params->version);
        $tmpl->setVar('numberOfRecords', $numberOfRecords);
        // There is currently no support for limiting the number of results.
        $tmpl->setVar('returnedRecords', $result->num_rows);
        $tmpl->setVar('query', $sru_fcs_params->query);
        $tmpl->setVar('transformedQuery', $sql);
        $tmpl->setVar('baseURL', $baseURL);
        $tmpl->setVar('xcontext', $sru_fcs_params->xcontext);
        $tmpl->setVar('xdataview', $sru_fcs_params->xdataview);
        // this isn't generated by fcs.xqm either ?!
        $nextRecordPosition = 0;
        $tmpl->setVar('nextRecordPosition', $nextRecordPosition);
        $tmpl->setVar('res', '1');

        $hits = array();
        $hitsMetaData = null;
//        $hitsMetaData = array();
//        array_push($hitsMetaData, array('key' => 'copyright', 'value' => 'ICLTT'));
//        array_push($hitsMetaData, array('key' => 'content', 'value' => $description));

        while (($line = $result->fetch_row()) !== NULL) {
            //$id = $line[0];
            if (isset($processResult)) {
                $content = $processResult($line, $db);
            } else {
                $content = $line[1];
            }
            
            $decodedContent = decodecharrefs($content);
            $title = "";
            
            if ($wantTitle) {               
                $contentXPath = getTEIDataAsXMLQueryObject($decodedContent);
                foreach ($contentXPath->query('//teiHeader/fileDesc/titleStmt/title') as $node) {
                    $title .= $node->textContent;
                }
                if ($title === "") {
                    foreach ($dbTeiHeaderXML->query('//teiHeader/fileDesc/titleStmt/title') as $node) {
                        $title .= $node->textContent;
                    }
                }
            }

            array_push($hits, array(
                'recordSchema' => $sru_fcs_params->recordSchema,
                'recordPacking' => $sru_fcs_params->recordPacking,
                'queryUrl' => $baseURL,
                'content' => $decodedContent,
                'wantMetadata' => $wantMetadata,
                'wantTitle' => $wantTitle,
                'title' => $title,
                'hitsMetaData' => $hitsMetaData,
                'hitsTeiHeader' => isset($dbTeiHeaderXML) ? $dbTeiHeaderXML->document->saveXML($dbTeiHeaderXML->document->firstChild) : null,
                // TODO: replace this by sth. like $sru_fcs_params->http_build_query
                'queryUrl' => '?' . htmlentities(http_build_query($_GET)),
            ));
        }
        $result->close();

        $tmpl->setloop('hits', $hits);
        $tmpl->pparse();
    } else {
        \ACDH\FCSSRU\diagnostics(1, 'MySQL query error: Query was: ' . $sql);
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

        $terms = new \SplFixedArray($result->num_rows);
        $i = 0;
        while (($row = $result->fetch_array()) !== NULL) {
            $term = array(
                'value' => decodecharrefs($row[0]),
                'numberOfRecords' => $row["COUNT(*)"],
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
            while ($startAtString !== "" && $startPosition < count($sortedTerms)) {
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
        \ACDH\FCSSRU\diagnostics(1, 'MySQL query error: Query was: ' . $sqlstr);
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
 * @return string|NULL The term to search for, NULL if this is not the index the user wanted. The string
 * is encoded as needed by the web_dict dbs!
 */
function get_search_term_for_wildcard_search($index, $queryString, $index_context = NULL) {
    $ret = NULL;
    if (isset($index_context)) {
        $ret = preg_filter('/(' . $index_context . '\.)?' . $index . ' *(=|any) *(.*)/', '$3', $queryString);
    } else {
        $ret = preg_filter('/' . $index . ' *(=|any) *(.*)/', '$2', $queryString);
    }
    return encodecharrefs($ret);
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
 * @return string|NULL NULL if this is not the index the user wanted. The string
 * is encoded as needed by the web_dict dbs!
 */
function get_search_term_for_exact_search($index, $queryString, $index_context = NULL) {
    $ret = NULL;
    if (isset($index_context)) {
        $ret = preg_filter('/(' . $index_context . '\.)?' . $index . ' *(==|(cql\.)?string) *(.*)/', '$4', $queryString);
    } else {
        $ret = preg_filter('/' . $index . ' *(==|(cql\.)?string) *(.*)/', '$3', $queryString);
    }
    return encodecharrefs($ret);
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
