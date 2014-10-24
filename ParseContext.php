<?php
/*******************************************************************************
Code to parse the context for each of the wikipedia pages.
*******************************************************************************/
require_once 'Libraries/MySQL.php';
require_once 'Libraries/JSON.php';
require_once 'Libraries/Utileries.php';

mb_internal_encoding( 'UTF-8' );

MySQL::connectforWriting( $db );

/////////// Get text and suface forms for a range of wikipedia pages ///////////
$begin = 39000000;
$end = 40000000;
Utileries::logMessage( ":: Parsing context from $begin to $end" );

//Creat file to hold the load file for the table pagecontext of Shine database.
$fh = fopen('context/context_'.$begin.'_'.$end.'.csv', 'w' );

$startTime = microtime( true );
$objects = 0;

$query = "SELECT * FROM page WHERE id > $begin AND id <= $end";
if( !( $result = $db->query( $query ) ) )			//Execute query to get a collection of pages.
{
	Utileries::logMessage( "[X] Failed obtaining data for object $name!" );
	exit();
}

Utileries::logMessage( "** Finished query after ".(microtime(true) - $startTime)." seconds..." );

//Collect the objects and write their context in the output file.
while( ( $row = $result->fetch_assoc() ) !== null )
{
	$id = $row['id'];		//Get the fields.
	$name = $row['title'];
	$json = $row['json'];
	
	$nText = '';			//$nText is the normalized text: lowercase and with single spaces.
	$GLOBALS['context'] = array();		//Clean context for this entity.
	if( ( $surfaceForm = getNormTextAndSufaceForm( $nText, $json, $name ) ) !== false )
	{
		if( ( extractContextAll( $nText, $surfaceForm ) ) === false )
		{
			Utileries::logMessage( "[X] Failed extracting context for wikipedia page $name!" );
			exit();
		}
		else
		{
			//If no error, write an entry into the infile.
			if( !empty( $GLOBALS['context'] ) )
			{
				$bagOfWords = array();
				foreach( $GLOBALS['context'] as $term => $freq )	//Extract the context.
					array_push( $bagOfWords, array( strval( $term ), $freq ) );
					
				//Get a json object.
				$j = json_encode( $bagOfWords );
					
				//Write data.
				if( fputcsv($fh, array( $id, $name, $j ), "\t" ) === false )
				{
					Utileries::logMessage( "[X] Could not write entry for $name into infile!" );
					exit();
				}
			}
			
			$objects++;		//Count processed objects.
			
			if( $objects % 1000 == 0 )
				Utileries::logMessage( "** $objects processed after ".(microtime(true) - $startTime)." seconds..." );
		}
	}
	else
	{
		Utileries::logError( "[X] Failed collecting surface form for page $name!" );
		exit();
	}
}
$result->free();		//Free result resource.

//Finish connections to file and database.
fclose( $fh );
MySQL::disconnect( $db );

$elapsedTime = microtime( true ) - $startTime;
Utileries::logMessage( "[*] Finished processing $objects objects' context in $elapsedTime seconds.\n\n" );

/*******************************************************************************
Auxiliary functions for parsing context.
*******************************************************************************/

/*
 * Function to extract the text from a json object, and to get an equivalence for
 * the wikipedia page by providing its surface form.
 */
function getNormTextAndSufaceForm( & $nText, $jsonString, $name )
{
	if( mb_strlen( $jsonString ) == 0 )		//Check input arguments are not empty.
	{
		Utileries::logMessage( "[X] The JSON string to get normalized text for $name is empty!" );
		return false;
	}

	if( JSON::parseString( $jsonString, $jsonArray ) )
	{
		$nText = mb_strtolower( $jsonArray['text'] );			//Extract text and normalize it.
		$nText = preg_replace( '/[\pZ\pC]+/u', ' ', $nText );	//Remove multiple space and control characters.
			
		//Obtain the normalized surface form version for this candidate.
		$sf = Utileries::urlToSurfaceForm( $name );
		if( preg_match('/^\((.)*\)$/u', $sf ) === 0 )			//Do not touch '(...)' expressions.
			$sf = preg_replace( '/\((.)*\)$/u', '', $sf );		//Remove terms that end in -(...).
		$sf = Utileries::utf8_trim( $sf );
			
		//Check that surface form is not empty.
		if( mb_strlen( $sf ) == 0 )
		{
			Utileries::logMessage( "[X] Equivalent surface form for candidate $name is empty!" );
			return false;
		}
			
		return $sf;				//Return surface form if there was no error.
	}
	else
	{
		Utileries::logMessage( "[x] Failed parsing JSON object for candidate $name!" );
		return false;
	}
}

/*
 * Function to extract the bag of words for a given wikipedia page.
 */
function extractContextAll( $nText, $surfaceForm )
{
	if( mb_strlen( $surfaceForm ) == 0 )				//Check surface form is non empty.
	{
		Utileries::logMessage( "[X] The surface form is empty!" );
		return false;
	}

	if( mb_strlen( $nText ) == 0 )						//Check that $nText is non empty.
	{
		Utileries::logError( "[X] Input normalized text is empty!" );
		return false;
	}

	$pattern = preg_quote( $surfaceForm, '/' );					//Make sure of escaping PERL characters in surface form.
	$nText = preg_replace( '/'.$pattern.'/u', '', $nText );		//Remove the normalized surface form from normalized text.

	//Purify $nText.
	if( ( $nText = purifyText( $nText ) ) !== false )
	{
		if( mb_strlen( $nText ) > 0 )		//Check whether purified text did not become empty.
		{
			//Explode purified text.
			if( ( $explodedText = explodeTextChunks( $nText ) ) !== false )
			{
				foreach( $explodedText as $word )
					accumulateTerms( $word );

				//Return true because there was no error.
				return true;
			}
			else
				return false;
		}
		else
		{
			Utileries::logMessage( "[W] Purified text became empty for $surfaceForm! Nothing has been added to its context!" );
			return true;
		}
	}
	else
		return false;
}

/*
 * Function to accumulate terms and frequencies.
 */
function accumulateTerms( $term )
{
	if( mb_strlen( $term ) > 0 )						//Skip empty string.
	{
		if( isset( $GLOBALS['context'][ $term ] ) )		//Is there an entry for this term?
			$GLOBALS['context'][ $term ]++;				//Increase frequency.
		else
			$GLOBALS['context'][ $term ] = 1;			//Begin accumulating frequency.
	}
}

/*
 * Function to purify text in a string or array of strings.
 * If any input is not a string, returns false.
 */
function purifyText( $var )
{
	if( is_string( $var ) )
	{
		$var = preg_replace('/\'/u', '', $var);		//Delete apostrophe.
		$var = preg_replace('/\W+/u', ' ', $var);	//Replace non-word characters by a space.
		$var = preg_replace('/\pZ+/u', ' ', $var);	//Replace multiple spaces by a single space.
		$var = Utileries::utf8_trim( $var );		//Remove trailing spaces.

		return $var;
	}
	else
	{
		if( is_array( $var ) )				//If it is an array, purify each string.
		{
			$pureVar = array();				//Store output here.
			foreach( $var as $key => $str )
			{
				$p = $this->purifyText( $str );		//Recursive call.
				if( $p !== false )			//Was everything OK?
					$pureVar[ $key ] = $p;
				else
				{
					Utileries::logMessage( "[X] Invalid input array to purify!" );
					return false;
				}
			}

			return $pureVar;				//If there is no problem, return the purified string array.
		}
		else
		{
			Utileries::logMessage( "[X] Error: Invalid input variable to purify!" );
			return false;					//Return false if there is an error.
		}
	}
}

/*
 * Function to explode an array of text chunks.
 * Call this function after purifying the text if want to get canonical words only (with no punctuation).
 */
function explodeTextChunks( $var )
{
	if( is_string( $var ) )
	{
		$var = explode( ' ', $var );		//Split text by spaces.
		return $var;
	}
	else
	{
		if( is_array( $var ) )				//If it is an array, explode each string.
		{
			$explodedVar = array();			//Store output here.
			foreach( $var as $key => $str )
			{
				$p = $this->explodeTextChunks( $str );		//Recursive call.
				if( $p !== false )			//Was everything OK?
					$explodedVar[ $key ] = $p;
				else
				{
					Utileries::logMessage( "[X] Invalid input array of text chunks!" );
					return false;
				}
			}

			return $explodedVar;			//If there is no problem, return the purified string array.
		}
		else
		{
			Utileries::logMessage( "[X] Invalid input text chunk!" );
			return false;					//Return false if there is an error.
		}
	}
}

// $result = $db->query('select * from pagecontext where id=14');
// $row = $result->fetch_assoc();
// $j = json_decode($row['context']);

// foreach ($j as $pair)
// 	echo $pair[0]." ".$pair[1]."\n";

// $json1 = json_encode( array( array("\"así es\"", 2), array("안녕", 5) ) );
// $j = json_decode($json1);

// foreach ($j as $pair)
// 	echo $pair[0]." ".$pair[1]."\n";

// $fh = fopen('context'.time().'.csv', 'w' );
// fputcsv($fh, array( 14, "Hola\"! 안녕하세요!", $json1 ), "\t" );
// fputcsv($fh, array( 15, "Hola!", $json1 ), "\t" );
// fclose( $fh );
?>