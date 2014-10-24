<?php
/*******************************************************************************
Class to hold information about an entity.
*******************************************************************************/
require_once 'Libraries/Utileries.php';

define( 'NEIGHBORS', 10 );						//Number of words to create the context neighborhood of a named entity.

mb_internal_encoding( 'UTF-8' );				//Set encodings to UTF8.
mb_regex_encoding( 'UTF-8' );

class Entity
{
	public static $entitiesCount = 0;			//To assign consecutive global IDs.
	
	protected $name;							//If it is a named entity, this is a surfa form; if it is a candidate, this is the wikiTitle,
	public $context;							//An array of unique words with their frequencies.
	protected $globalID;						//To identify uniquely any entity (wheter is a named entity or a candidate).
	public $magnitude;							//Magnitude for context vector (used for TF-IDF).
	
	/*
	 * Constructor for the Entity class.
	 */
	function __construct( $s )
	{
		$this->name = $s;
		$this->context = array();
		$this->globalID = Entity::$entitiesCount;
		$this->magnitude = 0;
		
		Entity::$entitiesCount++;				//One more entity to the list.
	}
	
	/*
	 * Destructor for the Entity class.
	 */
	function __destruct()
	{
		unset( $this->context );
	}
	
	/*
	 * Function to read entity private parameters.
	 */
	public function __get( $name )
	{
		return $this->$name;
	}
	
	/*
	 * Function to compute the context or NEIGHBORS surrounding each occurrence
	 * of surface form in $nText.
	 * $nText must be already normalized: single space, lowercase, with no [[ ]] entity markers.
	 * $surfaceForm must contain the string to look for in $nText and has to be normalized as $nText.
	 */
	public function extractContext( $nText, $surfaceForm )
	{
		if( mb_strlen( $surfaceForm ) == 0 )				//Check surface form is non empty.
		{
			Utileries::logError( "The surface form is empty!" );
			return false;
		}
		
		if( mb_strlen( $nText ) == 0 )					//Check that $nText is non empty.
		{
			Utileries::logError( "Input normalized text is empty!" );
			return false;
		}
		
		//Explode normalized text in chunks delimited by current surfaceForm.
		//strChunks is an array of strings before, between, and after ocurrences of the surface form.
		$pattern = "\\W".preg_quote( $surfaceForm, '/' )."\\W";		//Make sure of escaping PERL characters in surface form.
		$strChunks = mb_split( $pattern, ' '.$nText.' ' );			//NOTICE: Pad normalized text with ' ' so that \\W can work on the edges.
		
		//Check there are at least two chunks, (i.e. before and after) otherwise, emit a warning. This might mean that
		//the surface form in the annotations array differs from the occurrence in the actual text.
		$this->context = array();		//Clean the context.
		if( count( $strChunks ) > 1 )
		{
			//Purify string chunks.
			if( ( $strChunks = $this->purifyText( $strChunks ) ) !== false )
			{
				//Explode individual chunks by taking single spaces as separators (call after purify).
				if( ( $explodedChunks = $this->explodeTextChunks( $strChunks ) ) !== false )
				{		
					$before = $explodedChunks[0];			//Look at exploded chunks before first occurrence.
					for( $I = 1; $I < count( $explodedChunks ); $I++ )
					{
						//Collect tokens before occurrence.
						$c = 0;								//Count neighboring tokens.
						$b = count( $before ) - 1;			//Start in last position.
						while( $b >= 0 && $c < NEIGHBORS )
						{
							$this->accumulateTerms( $before[ $b ] );
							$c++;
							$b--;
						}
		
						//Collect tokens after occurrence.
						$after = $explodedChunks[ $I ];
						$a = 0;								//Start in first position.
						while( $a < count( $after ) && $a < NEIGHBORS )
						{
							$this->accumulateTerms( $after[ $a ] );
							$a++;
						}
		
						//Redefine $before for next surface form occurrence.
						$before = array();
						if( $a < count( $after ) )			//Are token remaining in $after?
							$before = array_slice( $after, $a );
					}
					
					//Return a reference to updated context at the end of the process.
					return $this->context;
				}
				else
					return false;
			}
			else
				return false;
		}
		else
		{
			Utileries::logWarning( "Number of chunks for $surfaceForm is less than 2!" );
			return $this->context;
		}
	}
	
	/*
	 * Function to accumulate unique elements in a given context.
	 */
	protected function accumulateTerms( $term )
	{
		if( mb_strlen( $term ) > 0 )							//Skip empty string.
		{
			if( isset( $this->context[ strval($term) ] ) )		//Is there an entry for this term?
				$this->context[ strval($term) ]++;				//Increase frequency.
			else
				$this->context[ strval($term) ] = 1;			//Begin accumulating frequency.
		}
	}
	
	/*
	 * Function to purify text in a string or array of strings.
	 * If any input is not a string, returns false.
	 */
	protected function purifyText( $var )
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
						Utileries::logError( "Invalid input array to purify!" );
						return false;
					}
				}
	
				return $pureVar;				//If there is no problem, return the purified string array.
			}
			else
			{
				Utileries::logError( "[X] Error: Invalid input variable to purify!" );
				return false;					//Return false if there is an error.
			}
		}
	}
	
	/*
	 * Function to explode an array of text chunks.
	 * Call this function after purifying the text if want to get canonical words only (with no punctuation).
	 */
	protected function explodeTextChunks( $var )
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
						Utileries::logError( "Invalid input array of text chunks!" );
						return false;
					}
				}
	
				return $explodedVar;			//If there is no problem, return the purified string array.
			}
			else
			{
				Utileries::logError( "Invalid input text chunk!" );
				return false;					//Return false if there is an error.
			}
		}
	}
	
}

?>