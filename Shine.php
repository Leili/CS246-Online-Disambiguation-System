<?php
/*******************************************************************************
Class that implements the disambiguation system.
*******************************************************************************/
require_once 'Libraries/MySQL.php';
require_once 'Entity.php';
require_once 'Candidate.php';

mb_internal_encoding( 'UTF-8' );
mb_regex_encoding( 'UTF-8' );

class Shine
{
	private $db = null;					//Connection to database.
	private $namedEntities;				//Array of Entity objects corresponding to surface forms in the input text.
	private $candidateMappings;			//Array of candidate mappings for each named entity.
	private $validSurfaceForms;			//How many surface forms have candidates?
	
	private $TRMap;						//An associative map to cache topical relatedness values.
	private $mappings;					//Array for final entity mappings.
	
	private $IDF;						//Inverse document frequency array.
	private $totalDocuments = 0;		//Number of documents to compute IDF.
	private $CSMap;						//An associative map to cache context similarity values.	
	
	const alpha = 0.1;					//Weight for link probability.
	const beta = 0.4;					//Weight for context similarity.
	const gamma = 0.5;					//Weight for global coherence.
	
	private $globalScore = -1;			//Global linking quality.
	private $text = '';					//Text that the user passes and is retrieved with the XML response.
	
	/*
	 * Constructor.
	 */
	function __construct()
	{
		if( !MySQL::connectforReading( $this->db ) )	//Connect to server, with reading privileges.
			Utileries::logError( "Could not connect to Shine database was successful!" );
		
		$this->namedEntities = array();
		$this->candidateMappings = array();
		$this->TRMap = array();
		$this->CSMap = array();
		$this->mappings = array();
		$this->IDF = array();
	}
	
	/*
	 * Destructor.
	 */
	function __destruct()
	{
		MySQL::disconnect( $this->db );
	}
	
	/*
	 * Function to extract surface forms from the given $text.
	 * $text must contain all intended surface forms in [[ ]], not of the form [[ | ]].
	 * Return false if there is an error, otherwise return the array of named entities.
	 * This function assumes that Wikify! service has been called (if user wanted to) already.
	 */
	public function extractSufaceForms( $text )
	{
		if( mb_strlen( $text ) == 0 )			//Empty input text?
		{
			Utileries::logError( "The input text is empty!" );
			return false;
		}
		
		//Store a copy of the text.
		$this->text = $text;
		
		//Normalized text by first making it lowercase.
		$nText = mb_strtolower( $text );
		
		//Catch all terms enclosed in [[ ]].
		$matches = array();
		if( preg_match_all('/\[\[([^\]]+)\]\]/u', $nText, $matches, PREG_SET_ORDER ) > 0 )
		{
			$surfaceForms = array();			//An array that stores unique named entities.
			foreach( $matches as $match )
			{
				$surfaceForm = preg_replace( '/[\pZ\pC]+/u', ' ', $match[1] );
				$surfaceForm = Utileries::utf8_trim( $surfaceForm );
		
				if( mb_strlen( $surfaceForm ) > 0 )	//Consider only non empty surface forms.
					$surfaceForms[ $surfaceForm ] = true;			//Remove duplicates.
				
				if( count( $surfaceForms ) >= MAX_SURFACEFORMS )	//Clamp to a max number of surface forms.
				{
					Utileries::logWarning( "Warning! You reached the maximum number of surface forms! Rest will be discarded." );
					break;
				}
			}
		
			//Renormalize text, now without [[]] -> " innerText ", with delimiting spaces.
			$nText = preg_replace( '/\[\[([^\]]+)\]\]/u', ' $1 ', $nText );	//Remove square brackets and leave inner node.
			$nText = preg_replace( '/[\pZ\pC]+/u', ' ', $nText );	//Remove space and control characters only once over all text.
		
			/////////// Find the context for each occurence of surface forms ///////////
		
			$this->namedEntities = array();							//Holds all named entities in input text.
			
			if( !empty( $surfaceForms ) )							//Check there was at least one surface form.
			{
				foreach( $surfaceForms as $surfaceForm => $boolean )//Go through each surface form.
				{
					$entity = new Entity( $surfaceForm );
					
					//Check that extraction of context is correct.
					if( ( $context = $entity->extractContext( $nText, $entity->name ) ) === false )
					{
						Utileries::logError( "Failed extracting context associated with $entity->name in the input text!" );
						$this->namedEntities = array();				//Free memory before exiting.
						return false;
					}
					else 
					{
						//Check that the context is not empty.
						if( empty( $context ) )
						{
							Utileries::logError( "The context for named entitiy $entity->name is empty in the input text!" );
							$this->namedEntities = array();			//Clear memory before exiting.
							return false;
						}
						else
						{
							//No errors?
							array_push( $this->namedEntities, $entity );	//Fill in the array of named entities.
						}
					}
				}
				
				unset( $surfaceForms );								//Free memory.
				return $this->namedEntities;						//Returns a reference to the array of named entities in input text.
			}
			else 
			{
				Utileries::logError( "There were no named entities detected in the input text!" );
				return false;
			}
		}
		else
		{
			Utileries::logError( "There were no named entities detected in the input text, enclose them with [[ and ]]!" );
			return false;
		}
	}
	
	/*
	 * Function to generate candidates, together with their context.
	 * This function must be called after collecting surface forms in the array of namedEntities.
	 * Return false if there is an error, or the (multidimensional) array of candidates if not.
	 * There is a one to one correspondence between the array index in namedEntities and candidateMappings.
	 * candidateMappings has an array of Candidate entities at each of its rows. Row 0 corresponds to 
	 * namedEntities at location 0, and so on.
	 */
	public function generateCandidateMappings()
	{
		if( empty( $this->namedEntities ) )		//Expect at least one surface form in the input text.
		{
			Utileries::logError( "There must be at least one named entity in the input text!" );
			return false;
		}
		
		//Restore candidate mappings array.
		$this->candidateMappings = array();
		$this->validSurfaceForms = 0;
		$this->TRMap = array();					//Clean the topical relatedness map.
		$entitiesWithCandidates = 0;			//We expect at least one surface form with candidates.
		
		//Iterate over each named entity and extract its candidates from the Shine database.
		foreach( $this->namedEntities as $key => $namedEntity )
		{		
			//Prepare connection to database.
			$initialLetter = mb_substr( $namedEntity->name, 0, 1);	//Pick the dictionary.
			$dictionary = ( Utileries::isAlpha( $initialLetter ) )? 'dictionary'.$initialLetter: 'dictionary';
			
			$query = "SELECT page.id AS id, title, count, context FROM page INNER JOIN "; 
			$query .= "(SELECT * FROM $dictionary WHERE surfaceForm = ?) AS A ON A.id = page.id";
			
			$startTime = microtime(true);
			if( !( $statement = $this->db->prepare( $query ) ) )	//Prepare statement to get all candidates.
			{
				Utileries::logError( "Failed preparing query to get the candidates of $namedEntity->name!" );
				$this->candidateMappings = array();
				return false;
			}
			$sf = $namedEntity->name;	//Use this because the bind_param method uses references.
			if( !( $statement->bind_param( "s", $sf ) ) ) //Complete parameters (using statement prevents SQL code injection).
			{
				Utileries::logError( "Failed binding parameters to query to get the candidates of $namedEntity->name!" );
				$this->candidateMappings = array();
				return false;
			}
			if( !( $statement->execute() ) )						//Execute the actual query.
			{
				Utileries::logError( "Failed executing query to get the candidates of $namedEntity->name!" );
				$this->candidateMappings = array();
				return false;
			}
			$elapsedTime = microtime(true)-$startTime;
			//echo "[*] Finished query to get candidates in $elapsedTime seconds.\n\n";
			
			//Fetch candidates from querying the data base.
			$candidates = array();									//Array of candidate mappings for the key-th entity.
			$totalCount = 0;										//Accumulate the count for all candidates of this entity.
			$result = $statement->get_result();
			while( ( $row = $result->fetch_assoc() ) !== null )
			{
				$candidate = new Candidate( $row['title'], $row['id'], $row['count'], $row['context'], $key );	//Create a new candidate.
				$totalCount += $row['count'];						//Accumualate count for link probability.
				array_push( $candidates, $candidate );				//Puth all candidate mappings in candidates array for this named entity.
			}
			
			$result->free();			//Free resources for both result and statement.
			$statement->close();
			
			//Update link probability and context for all candidates that belong to this named entity.
			if( !empty( $candidates ) )
			{
				$entitiesWithCandidates++;							//One more named entity with candidates.
				
				//Iterate over all candidates to compute their link probabilities.
				for( $I = 0; $I < count( $candidates ); $I++ )
				{
					if( ( $candidates[ $I ]->computeLinkProbability( $totalCount ) ) === false )
					{
						Utileries::logError( "Failed computing the link probability for one candidate of $namedEntity->name!");
						$this->candidateMappings = array();
						return false;
					}
				}
				
				//Iterate over all candidates to compute the number of in-link sources.
				for( $I = 0; $I < count( $candidates ); $I++ )
				{
					if( ( $candidates[ $I ]->setSources( $this->db ) ) == false )	//Not just false, also zero.
					{
						Utileries::logError( "Failed counting sources for candidate ".$candidates[ $I ]->name." of $namedEntity->name!" );
						$this->candidateMappings = array();
						return false;
					}
				}
				
				//Iterate over all candidates to extract their context
				for( $I = 0; $I < count( $candidates ); $I++ )
				{
					if( ( $candidates[ $I ]->extractContext() ) === false )
					{
						Utileries::logError( "Failed extracting context for one candidate of $namedEntity->name!");
						$this->candidateMappings = array();
						return false;
					}
				}
			}
			else
				Utileries::logWarning( "There are no candidates for named entity $namedEntity->name!" );
			
			//Whether there are candidates or not, an array is pushed into the candidateMappings object's array.
			$this->candidateMappings[ $key ] = $candidates;
			
			unset( $candidates );			//Free memory.
			
		}
		
		//Finally, validate that there was at least one named entity with at least one candidate mapping.
		if( $entitiesWithCandidates == 0 )
		{
			Utileries::logError( "None of the named entities have candidate mappings, try again!" );
			$this->candidateMappings = array();		//Free memory of any previous candidate mappings stored in this object.
			return false;
		}
		
		$this->validSurfaceForms = $entitiesWithCandidates;
		return $this->candidateMappings;			//Return a reference to the array of candidate mappings for each named entity.
	}
	
	/*
	 * Function to create the TFIDF data structure based on the contexts for named entities and candidate mappings.
	 * This function must be called after both contexts have been generated.
	 */
	public function createTFIDF()
	{
		if( empty( $this->namedEntities ) )			//Check there are named entities (with their respective contexts).
		{
			Utileries::logError( "Failed creating TFIDF: The array of named entities is empty! ");
			return false;
		}
		
		if( empty( $this->candidateMappings ) )		//Check there are candidate mappings (with their respective contexts).
		{
			Utileries::logError( "Failed creating TFIDF: The array of candidate mappings is empty!" );
			return false;
		}
		
		//Build the IDF array.
		$this->IDF = array();
		$this->totalDocuments = 0;
		$this->CSMap = array();
		
		//Named entities.
		foreach( $this->namedEntities as $key => $namedEntity )
		{
			if( !empty( $this->candidateMappings[$key] ) )			//Only for named entities with candidates.
			{
				$this->totalDocuments++;							//One more document.
				foreach( $namedEntity->context as $term => $freq )	//Each document affects the IDF at most once per term.
				{
					if( !isset( $this->IDF[ $term ] ) )				//First entry for this term?
						$this->IDF[ $term ] = 1;					//First document with this term.
					else 
						$this->IDF[ $term ]++;						//We are only accumulating document frequencies.
				}
			}
		}
		
		//Candidate mappings.
		foreach( $this->candidateMappings as $candidates )
		{
			if( !empty( $candidates ) )								//Skip candidate mapping arrays that are empty.
			{
				foreach( $candidates as $candidate )				//Go through each candidate in the array for a named entity.
				{
					$this->totalDocuments++;							//One more document.
					foreach( $candidate->context as $term => $freq )	//Each document affects the IDF at most once per term.
					{
						if( !isset( $this->IDF[ $term ] ) )				//First entry for this term?
							$this->IDF[ $term ] = 1;					//First document with this term.
						else
							$this->IDF[ $term ]++;						//We are only accumulating document frequencies.
					}
				}
			}
		}
		
		//At this point, IDF contains frequencies for unique term across current corpus.
		//Compute the actual IDF.
		foreach( $this->IDF as $term => $idf )
			$this->IDF[ $term ] = log( $this->totalDocuments / $idf );
		
		//Next, update context frequencies with TF-IDF values for entities and candidates.
		
		//Named entities.
		foreach( $this->namedEntities as $key => $namedEntity )
		{
			if( !empty( $this->candidateMappings[$key] ) )			//Only for named entities with candidates.
			{
				$namedEntity->magnitude = 0;						//Restart context vector magnitude.
				foreach( $namedEntity->context as $term => $freq )	
				{
					$tfidf = $freq * $this->IDF[ $term ];
					$namedEntity->context[ $term ] = $tfidf;		//freq becomes freq*IDF.
					$namedEntity->magnitude += $tfidf;				//Accumulate size.
				}
				$namedEntity->magnitude = sqrt( $namedEntity->magnitude );	//Finish up magnitude computation for context of this entity.
			}
		}
		
		//Candidate mappings.
		foreach( $this->candidateMappings as $candidates )
		{
			if( !empty( $candidates ) )								//Skip candidate mapping arrays that are empty.
			{
				foreach( $candidates as $candidate )				//Go through each candidate in the array for a named entity.
				{
					$candidate->magnitude = 0;						//Restart context vector magnitude.
					foreach( $candidate->context as $term => $freq )
					{
						$tfidf = $freq * $this->IDF[ $term ];
						$candidate->context[ $term ] = $tfidf;		//freq becomes freq*IDF.
						$candidate->magnitude += $tfidf;				//Accumulate size.
					}
					$candidate->magnitude = sqrt( $candidate->magnitude );	//Finish up magnitude computation for context of this candidate.
				}
			}
		}
		
		unset( $this->IDF );		//Free memoty of IDF because it is not necessary any more.
		return true;	//Return true on success.
	}
	
	/*
	 * Function to call the iterative substitution algorithm to link named entities to
	 * candidates.
	 * Before calling this function, the following functions must have been called already:
	 * 1. extractSurfaceForms().
	 * 2. generateCandidateMappings().
	 * 3. createTFIDF().
	 */
	public function linkEntities()
	{
		//First, assign initial mappings for each named entity (randomly).
		$this->mappings = array();				//Clean memory.
		$this->globalScore = 0;
		foreach( $this->candidateMappings as $key => $candidates )
		{
			if( !empty( $candidates ) )			//Check if there are candidates for each named entity.
				$this->mappings[ $key ] = $candidates[ mt_rand( 0, count( $candidates )-1 ) ];
			else
				$this->mappings[ $key ] = null;	//Otherwise, assign null reference.
		}
		
		//Compute initial global linking quality.
		if( ( $this->globalScore = $this->globalLinkingQuality( $this->mappings ) ) === false )
			return false;
		
		//Diffuse current score into the final score of current mappings, so that
		//this score does not get lost in the following tryouts.
		foreach( $this->mappings as $m )
			if ( $m !== null ) $m->putFinalScore();
		
		//Then, apply the iterative substitution algorithm.
		$iter = 0;
		$tryMappings = $this->mappings;			//Make a copy of current candidate mappings. 
		
		//Print initial configuration.
		echo "-- Iteration $iter. LQ: $this->globalScore\n";
		foreach( $this->mappings as $k => $m )
			echo "[".$this->namedEntities[$k]->name."] :: ".( ( $m === null )? "--\n": "$m->name ($m->finalScore)\n" );
		echo "\n";
		
		while( true )
		{
			$increment = 0.0;					//Account for improvement in the linking quality.
			$rMax = null;						//Candidate that achieve the highest increase in linking quality.
			$startTime = microtime( true );
			
			foreach( $this->namedEntities as $keyE => $namedEntity )		//Visit candidates of each named entity.
			{
				if( count( $this->candidateMappings[$keyE] ) > 1 )			//Execute process if there are at least 2 candidates.
				{
					foreach( $this->candidateMappings[$keyE] as $candidate )
					{
						if( $candidate->wikiID != $this->mappings[$keyE]->wikiID )	//Skip self comparisons.
						{
							$tryMappings[$keyE] = $candidate;				//Modify tryout-mappings.
							
							if( ( $LQ = $this->globalLinkingQuality( $tryMappings ) ) === false )
								return false;
							
							//Check if there is any improvement.
							if( $LQ > $this->globalScore )
							{
								$increment = $LQ - $this->globalScore;
								$this->globalScore = $LQ;
								$rMax = $candidate;
								
								//Diffuse current score into the final score of current mappings, so that
								//this score does not get lost in the following tryouts.
								foreach( $tryMappings as $m )
									if ( $m !== null ) $m->putFinalScore();
							}
							
							//Return tryout mappings to its unmodified value.
							$tryMappings[$keyE] = $this->mappings[$keyE];
						}
					}
				}
			}
			
			//If there was any improvement, update the global mappings.
			if( $increment > 0.0 )
			{
				$this->mappings[ $rMax->namedEntityIndex ] = $rMax;
				$tryMappings[ $rMax->namedEntityIndex ] = $rMax;		//Change also the tryout mappings to avoid full copy.
				$iter++;
				
				//Print new mappings.
				$elapsedTime = microtime( true ) - $startTime;
				echo "-- Iteration $iter: $elapsedTime. LQ: $this->globalScore\n";
				foreach( $this->mappings as $k => $m )
					echo "[".$this->namedEntities[$k]->name."] :: ".( ( $m === null )? "--\n": "$m->name ($m->finalScore)\n" );
				echo "\n";
			}
			else 
				break;		//No improvement? Finish loop.
		}
		
		//If there was no error, return mappings.
		return $this->mappings;
	}
	
	/*
	 * Function to compute the linking quality of a given mapping set.
	 */
	private function globalLinkingQuality( $mappings )
	{
		$LQ = 0;
		
		foreach( $mappings as $r )		//Accumulate the linking quality of individual candidate mappints.
		{
			if( $r !== null )			//Skip null objects that represent a surface form without candidates.
			{
				if( ( $lq = $this->linkingQuality( $r, $mappings) ) === false )
				{
					Utileries::logError( "Failed computing linking quality of candidate mappings!" );
					return false;
				}
				else
				{
					if( $r->setScore( $lq ) === false )		//Failed assigning score to candidate?
					{
						Utileries::logError( "Failed computing linking quality of candidate mappings!" );
						return false;
					}
					
					$LQ += $lq;
				}
			}
		}
		
		return $LQ;
	}
	
	/*
	 * Function to compute the linking quality metric for a given entity r.
	 */
	private function linkingQuality( $r, $mappings )
	{
		if( $r === null )		//Check we are not receiving a null candidate: meaning that the surface form does not have candidates.
		{
			Utileries::logError( "Failed computing Linking Quality: candidate mapping is null!" );
			return false;
		}
		
		if( ( $cs = $this->contextSimilarity( $r ) ) === false )	//Check context similarity is OK.
		{
			Utileries::logError( "Failed computing linking quality of $r->name!" );
			return false;
		}
		
		if( ( $gc = $this->globalCoherence( $r, $mappings ) ) === false )		//Check that global coherence is OK.
		{
			Utileries::logError( "Failed computing linking quality of $r->name!" );
			return false;
		}
		
		//Sum all contributions.
		return ( $this::alpha*$r->linkProbability + $this::beta*$cs + $this::gamma*$gc );
	}
	
	/*
	 * Function to compute the global coherence between a candidate mapping and the rest
	 * of selected mappings for the other surface forms.
	 */
	private function globalCoherence( $r, $mappings )
	{	
		if( $this->validSurfaceForms == 1 )		//If there is only one surface form, the global coherence is 1.
			return 1.0;
		
		//Otherwise, compute topical relatedness with the rest of "final" entity mappings.
		$gc = 0;
		foreach( $mappings as $e )
		{
			if( $e !== null && $e->namedEntityIndex != $r->namedEntityIndex )	//Skip surface form with no candidates.
			{
				if( ( $tr = $this->topicalRelatedness( $r, $e ) ) !== false )
					$gc += $tr;
				else
				{
					Utileries::logError( "Failed computing global coherence for entity $r->name!" );
					return false;
				}
			}
		}
		
		return $gc/(doubleval( $this->validSurfaceForms - 1.0 ));
	}
	
	/*
	 * Function to compute the Topical Relatedness between two entities.
	 * To accelerate computations during optimization, this function caches the metric in a hash map.
	 */
	private function topicalRelatedness( $u1, $u2 )
	{
		//Check the cache.
		$index1 = $u1->wikiID;
		$index2 = $u2->wikiID;
		if( $index2 < $index1 )
		{
			$index1 = $u2->wikiID;
			$index2 = $u1->wikiID;
		}
		if( isset( $this->TRMap[$index1][$index2] ) )	//Already computed?
			return $this->TRMap[$index1][$index2];		//Then, retrieve the TR value.
		
		if( $u1->sources < 0 )							//Check that number of in-links for each entity has been already defined.
		{
			Utileries::logError( "Number of in-links for $u1->name is $u1->sources!" );
			return false;
		}
		
		if( $u2->sources < 0 )
		{
			Utileries::logError( "Number of in-links for $u2->name is $u2->sources!" );
			return false;
		}
		
		//Compute the intersection of U1 and U2, the sets of entities pointing to both u1 and u2.
		$query = "SELECT count(*) AS total FROM ( SELECT * FROM link WHERE destination = $u1->wikiID ) AS A ";
		$query .= "INNER JOIN ( SELECT * FROM link WHERE destination = $u2->wikiID ) AS B ON A.source = B.source";
		
		if( ( $result = $this->db->query( $query ) ) !== false )
		{
			$row = $result->fetch_assoc();
			$intersection = $row['total'];
			
			//Compute the Wikipedia Linking Measure.
			$WLM = 0;		//Check for the case that the intersection of in-links is empty (avoid logarithm of zero).
			if( $intersection > 0 )
			{
				$WLM = 1.0 - ( log( max( array($u1->sources, $u2->sources) ) ) - log( $intersection ) ) 
					/ ( log( TOTALWIKIPAGES ) - log( min( array($u1->sources, $u2->sources) ) ) );
			}
			
			//Write the cache.
			//Check the cache.
			$this->TRMap[$index1][$index2] = $WLM;
			
			$result->free();			//Free memory.
			
			return $WLM;				//Return just computed WLM.
		}
		else 
		{
			Utileries::logError( "Could not retrieve the intersection of sources for $u1->name and $u2->name!" );
			return false;
		}
	}
	
	/*
	 * Function to compute the context similarity of a candidate with its corresponding surface form in the
	 * input query text.
	 * TFIDF must be already created before computing context similarity.
	 */
	private function contextSimilarity( $r )
	{
		if( isset( $this->CSMap[ $r->wikiID ] ) )				//Check the cache first.
			return $this->CSMap[ $r->wikiID ];
		
		$m = $this->namedEntities[ $r->namedEntityIndex ];		//Get corresponding named entity.
		
		//Get intersection of 'keys', which are the common terms between r and m.
		$intersection = array_intersect_key( $m->context, $r->context );
		
		//Compute cosine similarity.
		$dotProduct = 0.0;
		foreach( $intersection as $term => $idf )		//idf contains the idf value from m named entity. We just need to call for r's value.
			$dotProduct += $idf * $r->context[$term];
		
		//Normalize.
		$dotProduct /= ( $m->magnitude * $r->magnitude );
		
		//And store result in cache.
		$this->CSMap[ $r->wikiID ] = $dotProduct;
		
		return $dotProduct;
	}
	
	/*
	 * Function to generate an XML output, generally after the disambiguation has finished.
	 * This method works even if there are errors or warnings because they will be reported.
	 */
	public function generateXML()
	{
		//XML header.
		$msg = new SimpleXMLElement( '<?xml version="1.0" encoding="utf-8" ?><message></message>' );
		
		//Provide final score if there were no errors.
		if( !empty( Utileries::$errorStackTrace ) || $this->globalScore == -1 )
			$msg->addAttribute( 'totalScore', strval( 0 ) );
		else
			$msg->addAttribute( 'totalScore', strval( $this->globalScore ) );
		$msg->addAttribute( 'service', 'Disambiguate' );
		
		//Provide the input text.
		$msg->addChild( 'request', $this->text );
		
		//Output the errors.
		$errors = $msg->addChild( 'errors' );
		if( !empty( Utileries::$errorStackTrace ) )
		{
			foreach( Utileries::$errorStackTrace as $error )
				$errors->addChild( 'error', $error );
		}
		
		//Output warnings.
		$warnings = $msg->addChild( 'warnings' );
		if( !empty( Utileries::$warningStackTrace ) )
		{
			foreach( Utileries::$warningStackTrace as $warning )
				$warnings->addChild( 'warning', $warning );
		}
		
		//Output the mappings, only if there were no errors.
		$mappings = $msg->addChild( 'mappings' );
		
		if( empty( Utileries::$errorStackTrace ) && $this->globalScore != -1 )
		{
			foreach( $this->mappings as $key => $m )
			{
				if( $m != null )		//Skip mappings for surface forms without candidates.
				{
					$mapping = $mappings->addChild( 'mapping', $m->name );
					$mapping->addAttribute( 'surfaceForm', $this->namedEntities[ $key ]->name );
					$mapping->addAttribute( 'score', strval( $m->finalScore ) );
					$mapping->addAttribute( 'wikiID', strval( $m->wikiID ) );
				}
			}
		}
		
		return $msg->asXML();
	}
	
}

?>