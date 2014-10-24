<?php
/*******************************************************************************
Class for a candidate entity. It inherits from class Entity.
*******************************************************************************/

require_once 'Entity.php';
require_once 'Libraries/JSON.php';
require_once 'Libraries/Utileries.php';
//What if there are repeated candidates for several surfaces forms.

class Candidate extends Entity
{
	private $wikiID = 0;					//Wikipedia article ID in Shine database, table 'page'.
	private $count = 0;						//How many times has appeared under a given surface form.
	private $linkProbability = -1;			//Proportion of count with respect to other candidates for same surface formm.
	private $sources = -1;					//How many distinct entities link to this candidate.
	private $namedEntityIndex = -1;			//Index for the surface form that this candidate belongs to.
	private $score = -1.0;					//Temporal linking quality score for this candidate.
	private $finalScore = -1.0;				//Final linking quality score, after every iteration when there is improvement in LQ.
	private $contextString = '';			//Temporally store the context in string format.
	
	/*
	 * Constructor for Candidate object.
	 */
	function __construct( $title, $id, $count, $contextString, $surfaceFormIndex )
	{
		//Register the wiki title.
		parent::__construct( $title );
		$this->wikiID = $id;
		$this->count = $count;
		$this->contextString = $contextString;
		$this->namedEntityIndex = $surfaceFormIndex;
	}
	
	/*
	 * Destructor for Candidate object.
	 */
	function __destruct()
	{
		parent::__destruct();
	}
	
	/*
	 * Function to access private properties.
	 */
	function __get( $name )
	{
		return $this->$name;
	}
	
	/*
	 * Function to set the linking qualiy score of this candidate, in case it is in the list
	 * of mapping entities.
	 */
	public function setScore( $val )
	{
		if( $val < 0 || $val > 1 )		//Score is always between 0 and 1.
		{
			Utileries::logError( "You are attempting to set a score of $val to candidate $this->name!" );
			return false;
		}
		
		$this->score = $val;
		return true;					//If no error, return true and assign value to score.
	}
	
	/*
	 * Function to set the final score from the linking quality of this candidate.
	 */
	public function putFinalScore()
	{
		$this->finalScore = $this->score;	//A simple copy.
	}
	
	/*
	 * Function to compute the link probability based on a given total count.
	 */
	public function computeLinkProbability( $totalCount )
	{
		if( $totalCount > 0 && $totalCount >= $this->count )	//Check that the total count to compute a probability is in correct range.
		{
			$this->linkProbability = $this->count / $totalCount;
			return $this->linkProbability;
		}
		else
		{
			Utileries::logError( "The total count for computing link probability for candidate $this->name is incorrect!" );
			return false;
		}
	}
	
	/*
	 * Function to set the sources value for this candidate from the link table.
	 */
	public function setSources( $db )
	{
		if( empty( $db ) )				//Check there is a connection.
		{
			Utileries::logError( "Database connection for reading is null!" );
			return false;
		}
		
		///////// Collect source pages where this candidate is referenced /////////
		
		$query = "SELECT count(source) AS total FROM link WHERE destination = $this->wikiID";
		if( ( $result = $db->query( $query ) ) !== false )		//Get source pages' wikiIDs.
		{
			$row = $result->fetch_assoc();
			$this->sources = $row['total'];						//Consider self reference.
			$result->free();			//Free result's memory.
			
			if( $this->sources == 0 )	//Check nonzero in-links.
				$this->sources = 1;		//At least one in-link.
			
			return $this->sources;		//Return collected context.
		}
		else
		{
			Utileries::logError( "Failed querying number of sources of references to candidate $this->name! " );
			return false;
		}
	}
	
	/*
	 * Function to extract the context vector with term frequencies from the
	 * JSON object obtained from the database.
	 */
	public function extractContext()
	{
		if( empty( $this->contextString ) )				//Check JSON array is not empty.
		{
			Utileries::logError( "Context JSON array is empty for candidate $this->name!" );
			return false;
		}
		
		$this->context = array();				//Prepare context array.
		$j = json_decode( $this->contextString );		//Decode context JSON array.
		
		if( json_last_error() == JSON_ERROR_NONE )	//If no error.
		{
			foreach( $j as $pair )
				$this->context[ strval($pair[0]) ] = $pair[1];	//Context is of the form "key"=>freq.
			
			$this->contextString = '';			//Clean context string. Its content is now an array.
			return $this->context;
		}
		else 
		{
			Utileries::logError( "Could not decode context JSON array for candidate $this->name!" );
			return false;
		}
	}
	
}