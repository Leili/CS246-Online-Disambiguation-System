<?php
/*******************************************************************************
Additional utileries.
*******************************************************************************/

mb_internal_encoding( 'UTF-8' );		//Change encoding for both strings and regex.
mb_regex_encoding( 'UTF-8' );

define("TOTALWIKIPAGES", 3918446 );		//Number of Wikipedia pages to be used in computing the WLM.
define("MAX_SOURCES", 10);				//At most, how many distinct sources we want for each candidate (to compute its context).
define("MAX_SURFACEFORMS", 8);			//To avoid overwhelming the server, only 8 surface forms maximum.

class Utileries
{	
	public static $errorStackTrace = null;		//Allows to accumulate errors that are to be shown in the XML conversion.
	public static $warningStackTrace = null;	//Allows to accumulate warning messages to be shown in the XML conversion.
	/*
	 * Function to trim trailing white spaces from a UTF-8 string. 
	 */
	public static function utf8_trim( $mbString )
	{
		return preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u', '', $mbString);	//Remove trailing spaces.
	}
	
	/*
	 * Function to transform a Wikipedia article URL into a surfaceForm.
	 */
	public static function urlToSurfaceForm( $url )
	{
		return mb_strtolower( preg_replace('/_/u', ' ', $url ) );	//Change underscores into spaces, and make it lowercase.
	}
	
	/*
	 * Function to return an array of references to be used in binding parameters
	 * in a prepared statement.
	 */
	public static function refValues( $arr )
	{
		if( strnatcmp( phpversion(),'5.3' ) >= 0 ) 	//Reference is required for PHP 5.3+
		{
			$refs = array();
			foreach( $arr as $key => $value )
				$refs[$key] = &$arr[$key];
			
			return $refs;
		}
		
		return $arr;
	}
	
	/*
	 * Function to know if a character in a string is an alphabeticl letter.
	 */
	public static function isAlpha( $char )
	{
		return ( preg_match('/^[a-z]$/u', $char ) > 0 );
	}
	
	/*
	 * Function to insert data in a log file.
	 */
	public static function logMessage( $log )
	{
		file_put_contents( 'logMessages.txt', $log."\n", FILE_APPEND );
		echo $log."\n";
	}
	
	/*
	 * Function to log an error.
	 */
	public static function logError( $msg )
	{
		if( Utileries::$errorStackTrace === null )			//Create the stack for the first time.
			Utileries::$errorStackTrace = array();
		
		array_push( Utileries::$errorStackTrace, $msg );	//Accumulate errors. 
		fwrite( STDERR, $msg."\n" );
	}
	
	/*
	 * Function to log a warning message.
	 */
	public static function logWarning( $msg )
	{
		if( Utileries::$warningStackTrace === null )		//Create warning stack for the first time.
			Utileries::$warningStackTrace = array();
		
		array_push( Utileries::$warningStackTrace, $msg );	//Accumulate warnings.
		fwrite( STDERR, "[Warning] ".$msg."\n" );
	}
}

?>