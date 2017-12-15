<?php
/*
Copyright (C) Chris Park 2017
diskover is released under the Apache 2.0 license. See
LICENSE for the full license text.
 */

require '../vendor/autoload.php';
use diskover\Constants;

error_reporting(E_ALL ^ E_NOTICE);
require "../src/diskover/Diskover.php";

// redirect to select indices page if no index cookie
$esIndex = getenv('APP_ES_INDEX') ?: getCookie('index');
if (!$esIndex) {
    header("location:selectindices.php");
    exit();
}

// Get search results from Elasticsearch if the user searched for something
$results = [];
$total_size = 0;

if (!empty($_REQUEST['submitted'])) {

    // Save search query
    saveSearchQuery($_REQUEST['q']);

    // Connect to Elasticsearch
    $client = connectES();

    // curent page
    $p = $_REQUEST['p'];

    // Setup search query
    $searchParams['index'] = $esIndex;
    $searchParams['type']  = ($_REQUEST['doctype']) ? $_REQUEST['doctype'] : 'file,directory';

    // Scroll parameter alive time
    $searchParams['scroll'] = "1m";

    // search size (number of results to return per page)
    if (isset($_REQUEST['resultsize'])) {
        $searchParams['size'] = $_REQUEST['resultsize'];
        createCookie("resultsize", $_REQUEST['resultsize']);
    } elseif (getCookie("resultsize") != "") {
        $searchParams['size'] = getCookie("resultsize");
    } else {
        $searchParams['size'] = 100;
    }

    // match all if search field empty
    if (empty($_REQUEST['q'])) {
        $searchParams['body'] = [
            'query' => [
                'match_all' => (object) []
            ]
        ];
        // match what's in the search field
    } else {
        $searchParams['body'] = [
            'query' => [
                'query_string' => [
                    'query' => $_REQUEST['q'],
                    'analyze_wildcard' => 'true'
                ]
            ]
        ];
    }

    // Sort search results
    if (!$_REQUEST['sort'] && !$_REQUEST['sort2'] && !getCookie("sort") && !getCookie("sort2")) {
        $searchParams['body']['sort'] = [ 'path_parent' => [ 'order' => 'asc' ], 'filename' => 'asc' ];
    } else {
        $searchParams['body']['sort'] = [];
        if ($_REQUEST['sort'] && !$_REQUEST['sortorder']) {
            $searchParams['body']['sort'] = $_REQUEST['sort'];
            createCookie("sort", $_REQUEST['sort']);
        } elseif ($_REQUEST['sort'] && $_REQUEST['sortorder']) {
            array_push($searchParams['body']['sort'], [ $_REQUEST['sort'] => [ 'order' => $_REQUEST['sortorder'] ] ]);
            createCookie("sort", $_REQUEST['sort']);
            createCookie("sortorder", $_REQUEST['sortorder']);
        } elseif (getCookie('sort') && !getCookie('sortorder')) {
            $searchParams['body']['sort'] = getCookie('sort');
        } elseif (getCookie('sort') && getCookie('sortorder')) {
            array_push($searchParams['body']['sort'], [ getCookie('sort') => [ 'order' => getCookie('sortorder') ] ]);
        }
        // sort 2
        if ($_REQUEST['sort2'] && !$_REQUEST['sortorder2']) {
            $searchParams['body']['sort'] = $_REQUEST['sort2'];
            createCookie("sort2", $_REQUEST['sort2']);
        } elseif ($_REQUEST['sort2'] && $_REQUEST['sortorder2']) {
            array_push($searchParams['body']['sort'], [ $_REQUEST['sort2'] => [ 'order' => $_REQUEST['sortorder2'] ] ]);
            createCookie("sort2", $_REQUEST['sort2']);
            createCookie("sortorder2", $_REQUEST['sortorder2']);
        } elseif (getCookie('sort2') && !getCookie('sortorder2')) {
            $searchParams['body']['sort'] = getCookie('sort2');
        } elseif (getCookie('sort2') && getCookie('sortorder2')) {
            array_push($searchParams['body']['sort'], [ getCookie('sort2') => [ 'order' => getCookie('sortorder2') ] ]);
        }
    }

    try {
        // Send search query to Elasticsearch and get scroll id and first page of results
        $queryResponse = $client->search($searchParams);
    } catch (Exception $e) {
        //echo 'Message: ' .$e->getMessage();
    }

    // set total hits
    $total = $queryResponse['hits']['total'];

    // Get the first scroll_id
    $scroll_id = $queryResponse['_scroll_id'];

    $i = 1;
    // Loop through all the pages of results
    while ($i <= ceil($total/$searchParams['size'])) {

    // check if we have the results for the page we are on
        if ($i == $p) {
            // Get results
            $results[$i] = $queryResponse['hits']['hits'];
            // Add to total filesize
            for ($x=0; $x<=count($results[$i]); $x++) {
                $total_size += (int)$results[$i][$x]['_source']['filesize'];
            }
            // end loop
            break;
        }

        // Execute a Scroll request and repeat
        $queryResponse = $client->scroll(
        [
            "scroll_id" => $scroll_id,  //...using our previously obtained _scroll_id
            "scroll" => "1m"           // and the same timeout window
        ]
    );

        // Get the scroll_id for next page of results
        $scroll_id = $queryResponse['_scroll_id'];
        $i += 1;
    }
}
?>
	<!DOCTYPE html>
	<html lang="en">

	<head>
		<meta charset="utf-8">
		<meta http-equiv="X-UA-Compatible" content="IE=edge" />
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title>diskover &mdash; Simple Search</title>
		<link rel="stylesheet" href="css/bootswatch.min.css" media="screen" />
		<link rel="stylesheet" href="css/diskover.css" media="screen" />
	</head>

	<body>
		<?php include "nav.php"; ?>

		<?php if (!isset($_REQUEST['submitted'])) {
        $resultSize = getCookie('resultsize') != "" ? getCookie('resultsize') : 100;
    ?>

		<div class="container-fluid" style="margin-top:70px;">
			<div class="row">
				<div class="col-xs-2 col-xs-offset-5">
					<p class="text-center"><img src="images/diskoversmall.png" style="margin-top:120px;" alt="diskover" width="62" height="47" /></p>
				</div>
			</div>
			<div class="row">
				<div class="col-xs-6 col-xs-offset-3">
					<p class="text-center">
						<h1 class="text-nowrap text-center"><i class="glyphicon glyphicon-search"></i> Simple Search</h1>
					</p>
				</div>
			</div>
			<div class="row">
				<div class="col-xs-8 col-xs-offset-2">
					<p class="text-center">
						<form method="get" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>" class="form-inline text-center">
							<input name="q" value="<?php echo $_REQUEST['q']; ?>" type="text" placeholder="Elasticsearch query string syntax" class="form-control input-lg" size="70" />
							<input type="hidden" name="submitted" value="true" />
							<input type="hidden" name="p" value="1" />
                            <input type="hidden" name="resultsize" value="<?php echo $resultSize; ?>" />
                    		<select class="form-control input-lg" name="doctype">
                    		  <option value="file" selected>file</option>
                              <option value="directory">directory</option>
                              <option value="">all</option>
                    		</select>
							<button type="submit" class="btn btn-primary btn-lg">Search</button>
						</form>
					</p>
				</div>
			</div>
			<div class="row">
				<div class="col-xs-8 col-xs-offset-2">
					<p class="text-center">
						<a href="help.php">Search examples</a> &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
						<a href="https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-query-string-query.html#query-string-syntax" target="_blank">Query string syntax help</a> &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
						<a href="advanced.php">Switch to advanced search</a></p>
				</div>
			</div>
            <?php $savedsearches = getSavedSearchQuery();
        if ($savedsearches) { ?>
			<div class="row">
				<div class="col-xs-6 col-xs-offset-3">
					<h5 style="margin-top:60px;"><i class="glyphicon glyphicon-time"></i> Search history</h5>
					<div class="well well-sm">
						<?php
        foreach ($savedsearches as $key => $value) {
            echo '<a class="small" href=/simple.php?submitted=true&p=1&q=' . rawurlencode($value) . '&resultsize=' . $resultSize . '>' . $value . '</a><br />';
        }
    } ?>
					</div>
				</div>
			</div>

			<?php
} ?>

			<?php

if (isset($_REQUEST['submitted'])) {
    include "results.php";
}

?>
	</div>
	<script language="javascript" src="js/jquery.min.js"></script>
	<script language="javascript" src="js/bootstrap.min.js"></script>
	<script language="javascript" src="js/diskover.js"></script>
    <script>
    // listen for msgs from diskover socket server
    listenSocketServer();
    </script>
<iframe name="hiddeniframe" width=0 height=0 style="display:none;"></iframe>
</body>

</html>
