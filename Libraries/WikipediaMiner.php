<?php
/*******************************************************************************
Class that implements Wikipedia Miner service connection and information
retrieval.
*******************************************************************************/

mb_internal_encoding( 'UTF-8' );
mb_regex_encoding( 'UTF-8' );

class WikipediaMiner
{
	private $url = 'http://wikipedia-miner.cms.waikato.ac.nz/services/wikify?';	//Connection variables.
	private $linkFormat = 'linkFormat=wiki';
	private $sourceMode = 'sourceMode=wiki';
	private $repeatMode = 'repeatMode=all';
	private $minProbability = 'minProbability=0.6';
	
	/*
	 * Function to set the web text for querying the Wikify! service.
	 */
	public function wikify( $webText )
	{
		if( mb_strlen( $webText ) == 0 )				//Check that it receives a parameter.
		{
			Utileries::logError( 'The input text for the Wikify service is empty!' );
			return false;
		}
		
		//Connect to the Wikify! service from Wikipedia Miner RESTful interface.
		$source = 'source=' . urlencode( $webText );
		$request = $this->url . $source .'&'. $this->linkFormat .'&'. $this->repeatMode .'&'. $this->sourceMode .'&'. $this->minProbability;
		$xml = @simplexml_load_file( $request, null, LIBXML_NOCDATA );		//Make it detect CDATA nodes as text.
		
		if( !$xml )							//Try twice to get connected.
			$xml = @simplexml_load_file( $request, null, LIBXML_NOCDATA );	//Make it detect CDATA nodes as text.
		
		if( !$xml )
		{
			Utileries::logError( 'Unable to retrieve XML object from Wikipedia Miner Wikify! service...' );
			return false;
		}
		
		//If everything succeeded at this point, $wikifiedDocument contains named entities discovered by Wikify!
		//plus the entities that the user is enforcing to recognize with [[]].
		$wikifiedDocument = $xml->wikifiedDocument;
		
		//The wikified document may contain pipes between [[ and ]], i.e. [[entity | surfaceForm]].
		//Only [[surfaceForm]] must remain.
		$wikifiedDocument = preg_replace( '/\[\[(?:[^\|\]]*\|)?([^\]]+)\]\]/u', '[[$1]]', $wikifiedDocument );
		
		return $wikifiedDocument;
		
	}
}

	
/*	foreach( $xml->detectedTopics->detectedTopic as $topic )	//detectedTopics contains an array detectedTopic because that is the
	{															//name under which each topic appears in the XML document.
		//Show attributes.
		echo "- Atributes:\n";
		foreach( $topic->attributes() as $key => $attribute )
			echo "[$key]: $attribute\n";

		echo "\n";
	}
*/
?>