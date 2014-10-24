<?php
/*******************************************************************************
Script for parsing different components of Wikipedia.
*******************************************************************************/

require_once 'Libraries/Parser.php';
require_once 'Libraries/Utileries.php';

mb_internal_encoding( "UTF-8" );			//Character encoding for multibyte string operations.

$parser = new Parser();
$root = "../";								//Root is relative to THIS php file.
$filePath = "Wikipedia2013/BI";

$startTime = microtime( true );
//$parser->parse( $root, $filePath, "page" );		//Parse page entries.
//$parser->parse( $root, $filePath, "dictionary" );	//Parse dictionary entries.
//$parser->parse( $root, $filePath, "disambiguation" );	//Parse disambiguation pages.
//$parser->parse( $root, $filePath, "redirect" );		//Parse redirect pages.
$elapsedTime = microtime( true ) - $startTime;
Utileries::logMessage( sprintf("[*] Finished parsing %s after %f seconds.\n\n", $filePath, $elapsedTime) );

?>