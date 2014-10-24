<?php

/*******************************************************************************
Library with JSON parsing functionalities for formatted objects extracted from
the Wikipedia 2013 dump.
*******************************************************************************/

mb_internal_encoding( "UTF-8" );	//UTF8 document and variables encoding.

require_once 'Utileries.php';

class JSON
{
	/*
	 * Function that parses a JSON string into an array of components.
	 * Returns parsed object into an associative array if successful, otherwhise
	 * the returned object is null. (Uses pass by reference).
	 */
	public static function parseString( $objectString, & $output )
	{
		//Clear output object.
		$output = null;
		
		//Use PHP built-in functino.
		$jsonArray = json_decode( $objectString, true );
		
		//Check that there was not error.
		$errorCode = json_last_error();
		if( $errorCode == JSON_ERROR_NONE )
		{
			//Extract the Wikipedia article identifier from the URL.
			$url = $jsonArray['url'];
			$urlPos = mb_stripos( $url, "/wiki/" );
			if( $urlPos !== false )		//Found delimiter?
			{
				$url = mb_substr( $url, $urlPos+6 );		//Extract substring.
				$url = urldecode( $url );					//Remove the %xx encoded characters.
				
				//Obtain the Wikipedia id for this article.
				$id = $jsonArray['id'][0];					//It appears as an array in the JSON object.
				
				//Extract the Wikitext.
				$text = $jsonArray['text'];					//Attention! Text contains scaped " (quotation) marks.
				
				//Obtain the annotations (without offsets).
				$annotations = array();						//Initialize dynamic annotations array for pairs of elements.
				while( list($I, $annotation) = each( $jsonArray['annotations'] ) )
				{
					$surfaceForm = Utileries::utf8_trim( mb_strtolower( $annotation['surface_form'] ) );	//All surface forms to lowercase.
					$uri = urldecode( $annotation['uri'] );							//Remove %xx characters.
					
					if( mb_strlen( $surfaceForm ) > 0 && mb_strlen( $surfaceForm ) < 255 )	//Reasonable size of surface form?
					{
						if( mb_strlen( $uri ) > 0 && mb_strlen( $uri ) < 255 )		//Reasonable size of uri?
							$annotations[ $I ] = array( 'surfaceForm' => $surfaceForm, 'uri' => $uri );
					}
				}
				
				//Build output variable.
				$output = array( 'url' => $url, 'id' => $id, 'text' => $text, 'annotations' => $annotations );
				return true;								//Everything went OK? Return true.
			}
			else
				Utileries::logMessage( "[X] JSON Error: Could not find the /wiki/ delimiter to extract the url.\n" );
		}
		else
		{
			//Indicate which error code was generated.
			switch ( $errorCode )
			{
				case JSON_ERROR_DEPTH:
					Utileries::logError( "JSON Error: Maximum stack depth exceeded\n" );
					break;
				case JSON_ERROR_STATE_MISMATCH:
					Utileries::logError( "JSON Error: Underflow or the modes mismatch\n" );
					break;
				case JSON_ERROR_CTRL_CHAR:
					Utileries::logError( "JSON Error: Unexpected control character found\n" );
					break;
				case JSON_ERROR_SYNTAX:
					Utileries::logError( "JSON Error: Syntax error, malformed JSON\n" );
					break;
				case JSON_ERROR_UTF8:
					Utileries::logError( "JSON Error: Malformed UTF-8 characters, possibly incorrectly encoded\n" );
					break;
				default:
					Utileries::logError( "JSON Error: Unknown error\n" );
					break;
			}
		}
		
		return false;			//Return false if there was any error when parsing the object.
	}
}

?>