<?php

require_once 'JSON.php';
require_once 'Utileries.php';
require_once 'MySQL.php';

mb_internal_encoding( "UTF-8" );			//Indicate the HTTP conversion encoding to UTF-8.

class Parser
{
	private $db = NULL;							//MySQLi object to connect to database for writing.
	private $letters = array('0','a','b','c','d','e','f','g','h','i','j','k','l','m','n','o','p','q','r','s','t','u','v','w','x','y','z');
	
	/*
	 * Constructor.
	 */
	public function __construct()
	{	
		MySQL::connectforWriting( $this->db );	//Just establish connection to database.
		
		mysqli_report( MYSQLI_REPORT_ALL  );	//Turn on error display.
		
		//Creating auxiliary temporal table for inserting entries in the dictionaries.
		if( $this->db->query( 'CREATE TEMPORARY TABLE tempDictionary(
					surfaceForm varchar( 255 ) COLLATE utf8_bin NOT NULL,
					title varchar( 255 ) COLLATE utf8_bin NOT NULL,
					count int( 11 ) NOT NULL,
					list text COLLATE utf8_bin DEFAULT NULL,
					PRIMARY KEY ( surfaceForm, title ))
					ENGINE InnoDB default CHARSET = utf8 COLLATE = utf8_bin' ) )
			Utileries::logMessage( "[*] Temporary dictionary table has been created.\n" );
			
		Utileries::logMessage( "[*] Connected to database with writing privileges.\n\n" );
	}
	
	/*
	 * Desctructor.
	 */
	public function __destruct()
	{
		MySQL::disconnect( $this->db );
		Utileries::logMessage( "[*] Connection to database has been closed.\n" );
	}
	
	/*
	 * Function to recursively parse the files inside a folder.
	 * $root is the directory root relative to CALLER php file. It is useful
	 * to later change the location of all Wikipedia files.
	 * $filePath is the real file name, not relative, of the file to be
	 * parsed.
	 * $type is one of the following: "page", "dictionary", "redirect", "disambiguation". 
	 */
	public function parse( $root, $filePath, $type )
	{
		$file = $root.$filePath;
		
		//Check existence.
		if( !file_exists( $file ) )
		{
			Utileries::logMessage( "[X] Error: File $file does not exist.\n" );
			exit();
		}
		
		//If it is a directory, parse each of its items (excludig . and ..)
		if( is_dir( $file ) )
		{
			$ls = scandir( $file );							//Get contents of directory.
			$ls = array_diff( $ls, array(".", "..") );		//Remove . and ..
			
			foreach( $ls as $key => $value )				//Recursively parse elements.
			{
				$newFilePath = $filePath ."/". $value;
				$this->parse( $root, $newFilePath, $type );
			}
		}
		else	//Since it is a file, call the respective function.
		{
			Utileries::logMessage( "========================== ".$file." ========================== \n\n" );
			$startTime = microtime( true );
			switch( $type )
			{
				case "page": $this->parsePageEntries( $root, $filePath ); break;
				case "dictionary": $this->parseDictionaryEntries( $root.$filePath ); break;
				case "disambiguation": $this->parseDisambiguationPages( $root.$filePath ); break;
				case "redirect": $this->parseRedirectPages( $root.$filePath ); break;
			}
			$elapsedTime = microtime( true ) - $startTime;
			Utileries::logMessage( "-- Done after $elapsedTime seconds!\n\n" );
		}
	}
	
	/*
	 * Function to parse ONLY Wikipedia with textual content.
	 * $root is the file root relative to CALLER php document.
	 * $filePath is the actual path to be stored in the database.
	 */
	private function parsePageEntries2( $root, $filePath )
	{
		//Check that filePath is non empty.
		if( empty( $filePath ) )
		{
			Utileries::logMessage( "[X] Error: The file path is empty.\n" );
			exit();
		}
		
		//Build full qualified file path.
		$file = $root . $filePath;
		
		//Read the input file, line by line, collecting the JSON objects
		//and parsing them.
		@ $fp = fopen( $file, "r" );
		if( !$fp )
		{
			Utileries::logMessage( "[X] Error: File $file could not be opened.\n" );
			exit();
		}
		else
		{
			//Put pairs of (id, url) into an array to later create bulk insert statements.
			$pages = array();
			
			while( !feof( $fp ) )				//Parse until end of file.
			{
				$objectString = fgets( $fp );	//Read line.
				$objectString = Utileries::utf8_trim( $objectString );			//Remove trailing spaces.
				
				if( !empty( $objectString ) )	//Parse non empty JSON object.
				{
					if( JSON::parseString( $objectString, $jsonArray ) )
					{
						$url = $jsonArray['url'];								//Extract content.
						$id = $jsonArray['id'];
						$text = $jsonArray['text'];
						
						if( mb_strpos( $url, "(disambiguation)" ) === false )	//Exclude disambiguation pages.
						{
							if( mb_strpos( $url, "List_of" ) !== 0 && mb_strpos( $url, "Lists_of" ) !== 0)		//Exclude lists.
							{
								$titleSize = mb_strlen( $url );
								$textSize = mb_strlen( $text );
								$sizeDif = $textSize - $titleSize;				//If paga has no annotations, consider it if it has
																				//enough text.
								if( count( $jsonArray['annotations'] ) > 0  || $sizeDif >= 100 )
								{
									if( mb_strlen( $url ) < 255 )					//Only bounded urls.
										array_push( $pages, array( 'id' => $id, 'url' => $url ) );	//Insert a new entry in the pages array.
									else
										Utileries::logMessage( "-> Page $id has a very long URL in file $file\n" );
								}
								else
									Utileries::logMessage( "-> Page $url has no annotations and $sizeDif characters in file $file\n" );
							}
						}
					}
				}
			}
			
			//After filling information into the $pages array, send it to MySQL for bulk insertion.
			$this->insertPageEntries( $pages, $filePath );
			
			unset( $pages );			//Free memory.
		}
		
		fclose( $fp );
	}
	
	/*
	 * Function to write Wikipedia pages in the database.
	 * $db must be connected to database already.
	 */
	private function insertPageEntries2( $pages, $filePath )
	{
		//First, build the entries for the Prepared Statement.
		$types = '';		//These are of the kind iss - int + str + str
		$marks = '';		//These are of the kind (?,?,?),
		$vars = array();	//Collects the values from pages array into a one dimensional array.
		while( list( $key, $pair ) = each( $pages ) )
		{
			$types .= 'iss';			//Concatenate query types and marks.
			$marks .= '(?,?,?),';
			array_push( $vars, $pair['id'] );		//Integer.
			array_push( $vars, $pair['url'] );	//String.
			array_push( $vars, $filePath );		//String.
		}
		$terms = array_merge( array($types), $vars );	//$terms contains ississ...id1 url1 filePath id2...
		
		//Next, construct the query.
		$query = "INSERT INTO page(id, title, file) VALUES ";
		$marks = mb_substr( $marks, 0, -1).' ';
		$query .= $marks . "ON DUPLICATE KEY UPDATE id = id";
		
		//Finally, get the MySQL statement working.
		if( !( $pStatement = $this->db->prepare( $query ) ) )	//Check there is no error.
		{
			Utileries::logMessage( "[X] Error: Statement for inserting entries in page could not be prepared.\n" );
			exit();
		}
		
		call_user_func_array( array( $pStatement, "bind_param" ), Utileries::refValues( $terms ) );	//Call $pStatement->bind_param().
		$pStatement->execute();
		
		if( count( $pages ) != $pStatement->affected_rows )		//Check duplicate entries.
			Utileries::logMessage( "[X] Error: $pStatement->affected_rows pages were inserted instead of ".count( $pages ).".\n" );
		else
			Utileries::logMessage( "[*] $pStatement->affected_rows pages were inserted.\n" );
		
		$pStatement->close();
		
		//Display inserted data.
		/*$q = "Director\'s_cut";		//Escape apostrophe.
		$result = $this->db->query("SELECT id FROM page WHERE title='$q'");
		while( ( $row = $result->fetch_assoc() ) != NULL )
			echo $row['id']. "\n";
		$result->free();*/
	}
	
	/////////////////////////////////// Parsing Page Entries Second Version (JSON) ///////////////////////////////////
	
	/*
	 * Function to parse ONLY Wikipedia with textual content.
	* $root is the file root relative to CALLER php document.
	* $filePath is the actual path to be stored in the database.
	*/
	private function parsePageEntries( $root, $filePath )
	{
		//Check that filePath is non empty.
		if( empty( $filePath ) )
		{
			Utileries::logMessage( "[X] Error: The file path is empty.\n" );
			exit();
		}
	
		//Build full qualified file path.
		$file = $root . $filePath;
	
		//Read the input file, line by line, collecting the JSON objects
		//and parsing them.
		@ $fp = fopen( $file, "r" );
		if( !$fp )
		{
			Utileries::logMessage( "[X] Error: File $file could not be opened.\n" );
			exit();
		}
		else
		{
			//Put pairs of (id, url) into an array to later create bulk insert statements.
			$pages = array();
				
			while( !feof( $fp ) )				//Parse until end of file.
			{
				$objectString = fgets( $fp );	//Read line.
				$objectString = Utileries::utf8_trim( $objectString );			//Remove trailing spaces.
	
				if( !empty( $objectString ) )	//Parse non empty JSON object.
				{
					if( JSON::parseString( $objectString, $jsonArray ) )
					{
						$url = $jsonArray['url'];								//Extract content.
						$id = $jsonArray['id'];
						$text = $jsonArray['text'];
	
						if( mb_strpos( $url, "(disambiguation)" ) === false )	//Exclude disambiguation pages.
						{
							if( mb_strpos( $url, "List_of" ) !== 0 && mb_strpos( $url, "Lists_of" ) !== 0)		//Exclude lists.
							{
								$titleSize = mb_strlen( $url );
								$textSize = mb_strlen( $text );
								$sizeDif = $textSize - $titleSize;				//If paga has no annotations, consider it if it has
								//enough text.
								if( count( $jsonArray['annotations'] ) > 0  || $sizeDif >= 100 )
								{
									if( mb_strlen( $url ) < 255 )					//Only bounded urls.
										array_push( $pages, array( 'id' => $id, 'url' => $url, 'json' => $objectString ) );
									else
										Utileries::logMessage( "-> Page $id has a very long URL in file $file\n" );
								}
								else
									Utileries::logMessage( "-> Page $url has no annotations and $sizeDif characters in file $file\n" );
							}
						}
					}
				}
			}
				
			//After filling information into the $pages array, send it to MySQL for bulk insertion.
			$this->insertPageEntries( $pages );
				
			unset( $pages );			//Free memory.
		}
	
		fclose( $fp );
	}
	
	/*
	 * Function to write Wikipedia pages in the database.
	* $db must be connected to database already.
	*/
	private function insertPageEntries( $pages )
	{
		//First, build the entries for the Prepared Statement.
		$types = '';		//These are of the kind iss - int + str + str
		$marks = '';		//These are of the kind (?,?,?),
		$vars = array();	//Collects the values from pages array into a one dimensional array.
		while( list( $key, $pair ) = each( $pages ) )
		{
			$types .= 'iss';			//Concatenate query types and marks.
			$marks .= '(?,?,?),';
			array_push( $vars, $pair['id'] );		//Integer.
			array_push( $vars, $pair['url'] );		//String.
			array_push( $vars, $pair['json'] );		//String.
		}
		$terms = array_merge( array($types), $vars );	//$terms contains ississ...id1 url1 filePath id2...
	
		//Next, construct the query.
		$query = "INSERT INTO page(id, title, json) VALUES ";
		$marks = mb_substr( $marks, 0, -1).' ';
		$query .= $marks . "ON DUPLICATE KEY UPDATE id = id";
	
		//Finally, get the MySQL statement working.
		if( !( $pStatement = $this->db->prepare( $query ) ) )	//Check there is no error.
		{
			Utileries::logMessage( "[X] Error: Statement for inserting entries in page could not be prepared.\n" );
			exit();
		}
	
		call_user_func_array( array( $pStatement, "bind_param" ), Utileries::refValues( $terms ) );	//Call $pStatement->bind_param().
		$pStatement->execute();
		
		//Check for truncated data.
		if( $this->db->warning_count )
		{
			if( $result = $$this->db->query("SHOW WARNINGS") )
			{
				while( ( $row = $result->fetch_row() ) != null )
					Utileries::logMessage( srintf( "[W] Warning: %s (%d): %s\n", $row[0], $row[1], $row[2] ) );
				$result->close();
			}
			
			exit();
		}
		
	
		if( count( $pages ) != $pStatement->affected_rows )		//Check duplicate entries.
			Utileries::logMessage( "[X] Error: $pStatement->affected_rows pages were inserted instead of ".count( $pages ).".\n" );
		else
			Utileries::logMessage( "[*] $pStatement->affected_rows pages were inserted.\n" );
	
		$pStatement->close();
	}
	
	////////////////////////////////////////// Parsing Dictionary Entries ////////////////////////////////////////////
	
	/*
	 * Function to parse links inside Wikipedia articles and generate dictionary entries.
	 * $file is the file path relative to CALLER php document.
	 */
	private function parseDictionaryEntries( $file )
	{
		//Check that file is non empty.
		if( empty( $file ) )
		{
			Utileries::logMessage( "[X] Error: The file path is empty.\n" );
			exit();
		}
	
		//Read the input file, line by line, collecting the JSON objects
		//and parsing them.
		@ $fp = fopen( $file, "r" );
		if( !$fp )
		{
			Utileries::logMessage( "[X] Error: File $file could not be opened.\n" );
			exit();
		}
		else
		{	
			$totalOperations = 0;
			
			$tuples = array();					//Crear/clear the tuples array.
			
			while( !feof( $fp ) )				//Parse until end of file.
			{
				$objectString = fgets( $fp );	//Read line.
				$objectString = Utileries::utf8_trim( $objectString );			//Remove trailing spaces.
	
				if( !empty( $objectString ) )	//Parse non empty JSON object.
				{
					if( JSON::parseString( $objectString, $jsonArray ) )
					{
						$id = $jsonArray['id'];
						$url = $jsonArray['url'];
						$annotations = $jsonArray['annotations'];
	
						if( mb_strpos( $url, "(disambiguation)" ) === false )	//Exclude disambiguation pages.
						{
							if( mb_strpos( $url, "List_of" ) !== 0 && mb_strpos( $url, "Lists_of" ) !== 0)		//Exclude lists.
							{
								if( count( $annotations ) > 0 )					//Take it into account if there are annotations.
								{
// 									$tuples = array();							//Crear/clear the tuples array.
									while( list( $key, $annotation ) = each( $annotations ) )
									{
										array_push( $tuples, array( 'surfaceForm' => $annotation['surfaceForm'],
										 	'title' => $annotation['uri'], 'cLast' => 1, 'containerId' => '_'.$id ) );
									}
									
									//Insert self reference.
									array_push( $tuples, array( 'surfaceForm' => mb_strtolower( Utileries::urlToSurfaceForm($url) ),
										'title' => $url, 'cLast' => 1, 'containerId' => '_'.$id ) );
									
// 									if( count( $tuples ) > 0 )							//Check there is something to insert.
// 									{
// 										$this->db->query( 'TRUNCATE tempDictionary' );	//Clean contents of the temporal dictionary.
// 										$this->insertDictionaryEntries( $tuples, $totalOperations );	//Insert tuples into the dictionary.
// 									}
// 									else
// 										Utileries::logMessage( "[X] Error: Page $url did not generate any annotation to insert in the dictionaries.\n" );
								}
							}
						}
					}
				}
			}
			
			if( count( $tuples ) > 0 )							//Check there is something to insert.
				$this->insertDictionaryEntries( $tuples, $totalOperations );	//Insert tuples into the dictionary.
			else
				Utileries::logMessage( "[X] Error: $file did not generate any annotation to insert in the dictionaries.\n" );
			
			Utileries::logMessage( "[*] Total operations = $totalOperations.\n" );
		}
	
		fclose( $fp );				//Close file.
	}
	
	/*
	 * Function to write dictionary entries in the database.
	 * It also write entries in the link table.
	 * $db must be connected to database already.
	 * To avoid running into the problem of depleting PHP allocated memory, make sure
	 * that the directive memory_limit is set to 1024MB in php.ini
	 */
	private function insertDictionaryEntries( $inputTuples, & $totalOperations )
	{
		//We need to divide the inputTuples into groups of 10000 attempts of insertions,
		//so that it would not take a very long time to create the dictionary.
		$I = 0;
		while( $I < count( $inputTuples ) )
		{
			//Create up to 10000 tuples.
			$tuples = array();				//An empty container.
			$J = 0;							//Counter for current number of tuples in subgroup.
			while( $J < 10000 && $I < count( $inputTuples ) )
			{
				array_push( $tuples, $inputTuples[ $I ] );
				$I++;
				$J++;
			}
			
			Utileries::logMessage( "# Processing $J/$I tuples.\n" );
			
			//////////////// Part 1, fill in tempDictionary table //////////////////
			
			if( !($this->db->query( 'TRUNCATE tempDictionary' ) ) )	//Clean contents of the temporal dictionary.
			{
				Utileries::logMessage("[X] Error: Could not truncate tempDictionary table.\n");
				exit();
			}
			
			//First, build the entries for the Prepared Statement.
			$types = '';		//These are of the kind ssis - str str int str
			$marks = '';		//These are of the kind (?,?,?,?),
			$vars = array();	//Collects the values from tuples array into a one dimensional array.
			while( list( $key, $tuple ) = each( $tuples ) )
			{
				$types .= 'ssis';						//Concatenate query types and marks.
				$marks .= '(?,?,?,?),';
				array_push( $vars, $tuple['surfaceForm'] );		//String.
				array_push( $vars, $tuple['title'] );			//String.
				array_push( $vars, $tuple['cLast'] );			//Int - This is for count.
				array_push( $vars, $tuple['containerId'] );		//String - This is for list.
			}
			$terms = array_merge( array($types), $vars );	//$terms contains ssis...sF1 title1 count1 list1 sF2 title2...
			
			//Next, construct the query.
			//We need to use VALUES() because we use the ON DUPLICATE KEY UPDATE with the individual data for each (surfaceForm,title).
			$query = "INSERT INTO tempDictionary(surfaceForm, title, count, list) VALUES ";
			$marks = mb_substr( $marks, 0, -1).' ';
			$query .= $marks . "ON DUPLICATE KEY UPDATE count = count+VALUES(count), list = CONCAT(list, VALUES(list))";
			
			//Finally, get the MySQL statement working.
			if( !( $pStatement = $this->db->prepare( $query ) ) )	//Check there is no error.
			{
				Utileries::logMessage( "[X] Error: Statement for inserting entries in tempDictionary could not be prepared.\n" );
				exit();
			}
			call_user_func_array( array( $pStatement, "bind_param" ), Utileries::refValues( $terms ) );	//Call $pStatement->bind_param().
			$pStatement->execute();
			
			if( $pStatement->affected_rows == 0 )			//Check it really updated.
				Utileries::logMessage( "[X] Error: There were no insertions/updates in the tempDictionary table.\n" );
			
			$pStatement->close();
			
			////// Part 2, Inner join with page table to obtain the page id ////////
			// At the same time, we are getting rid of (surfaceForm,title) whose
			// titles are not in the page table. Also, split into 27 groups for the
			// different dictionaries.
			// Collect entries for the link table too.
			
			$result = $this->db->query( 'SELECT surfaceForm, id, count, list
				FROM tempDictionary INNER JOIN page ON tempDictionary.title=page.title' );
			
			//Create 27 dictionaries.
			$dictionaries = array();						//Allocate space.
			foreach( $this->letters as $letter )
				$dictionaries[ $letter ] = array();
			
			//Create array for links table with the form (from, to)
			$links = array();
			
			//Collect results from the inner join.
			while( ( $row = $result->fetch_assoc() ) != NULL )
			{
				$entry = array( $row['surfaceForm'], $row['id'], $row['count'], $row['list'] );		//Array to insert.
				$initialLetter = mb_substr( $row['surfaceForm'], 0, 1 );
					
				if( Utileries::isAlpha( $initialLetter ) )			//If initial character is alphabetic.
					array_push( $dictionaries[ $initialLetter ], $entry );
				else 												//If initial character is not alphabetic
					array_push( $dictionaries['0'], $entry );
					
				//Entry for the link table.
				$fromArray = array_filter( explode( '_', $row['list'] ) );		//Get tokens between the list separator '_'.
				foreach( $fromArray as $from )
					$links[ $from ][ strval( $row['id'] ) ] = true;				//Remove duplicity of 'from'. The tuple in links is (from,to).
			}
			
			$result->free();								//Free memory.
			
			//////////////////// Parte 3, insert links entries /////////////////////
			
			$types = '';									//These are of the type ii - int int.
			$marks = '';									//These are of the type (?,?),
			$vars = array();								//Collects values in tuples in a flat array.
			foreach( $links as $from => $toArray )			//$from is the first key in the associative array of links.
			{
				foreach( $toArray as $to => $value )		//$to is the second key in the associative array of links.
				{
					$types .= 'ii';							//Concatenate query types and marks.
					$marks .= '(?,?),';
					array_push( $vars, intval( $from ) );
					array_push( $vars, intval( $to ) );
				}
			}
			$terms = array_merge( array($types), $vars );	//$terms contains ii... from1 to1 from2 to2 ...
			
			//Next, construct the query.
			//We need to use VALUES() because we use the ON DUPLICATE KEY UPDATE.
			$query = "INSERT INTO link(source, destination) VALUES ";
			$marks = mb_substr( $marks, 0, -1).' ';
			$query .= $marks . "ON DUPLICATE KEY UPDATE source = VALUES(source), destination = VALUES(destination)";
			
			//Get the MySQL statement working.
			if( !( $pStatement = $this->db->prepare( $query ) ) )	//Check there is no error.
			{
				Utileries::logMessage( "[X] Error: Statement for inserting entries table link could not be prepared.\n" );
				exit();
			}
			call_user_func_array( array( $pStatement, "bind_param" ), Utileries::refValues( $terms ) );	//Call $pStatement->bind_param().
			$pStatement->execute();
			
			$pStatement->close();
			
			////// Part 4, insert dictionary entries in each of the 27 tables //////
			
			$startTime = microtime( true );
			while( list( $letter, $dictionary ) = each( $dictionaries ) )
			{
				if( !empty( $dictionary ) )					//Check that there are entries to insert.
				{
					$table = ( Utileries::isAlpha( $letter ) )? "dictionary".$letter: "dictionary";	//Which table we will be referring to?
			
					$types = '';		//These are of the kind ssis - str str int str
					$marks = '';		//These are of the kind (?,?,?,?),
					$vars = array();	//Collects the values from tuples array into a one dimensional array.
					while( list( $key, $tuple ) = each( $dictionary ) )
					{
						$types .= 'siis';					//Concatenate query types and marks.
						$marks .= '(?,?,?,?),';
						array_push( $vars, $tuple[0] );		//surfaceForm.
						array_push( $vars, $tuple[1] );		//id.
						array_push( $vars, $tuple[2] );		//count.
						array_push( $vars, $tuple[3] );		//list.
					}
					$terms = array_merge( array($types), $vars );	//$terms contains siis... sF1 id1 count1 list1 sF2 id2...
			
					//Next, construct the query.
					//We need to use VALUES() because we use the ON DUPLICATE KEY UPDATE with the individual data for each (surfaceForm,id).
					$query = "INSERT INTO $table(surfaceForm, id, count, list) VALUES ";
					$marks = mb_substr( $marks, 0, -1).' ';
					$query .= $marks . "ON DUPLICATE KEY UPDATE count = count+VALUES(count), list = CONCAT(list, VALUES(list))";
			
					//Finally, get the MySQL statement working.
					if( !( $pStatement = $this->db->prepare( $query ) ) )	//Check there is no error.
					{
						Utileries::logMessage( "[X] Error: Statement for inserting entries in $table could not be prepared.\n" );
						exit();
					}
					call_user_func_array( array( $pStatement, "bind_param" ), Utileries::refValues( $terms ) );	//Call $pStatement->bind_param().
					$pStatement->execute();
			
					if( $pStatement->affected_rows == 0 )			//Check it really updated.
						Utileries::logMessage( "[X] Error: There were no insertions/updates in table $table.\n" );
					else
						$totalOperations += $pStatement->affected_rows;		//Accumulate transaction information.
			
					$pStatement->close();
				}
					
				unset( $dictionary );						//Free memory.
			}
			$elapsedTime = microtime( true ) - $startTime;
			Utileries::logMessage( sprintf("+ Finished inserting dictionary entries after %f seconds.\n", $elapsedTime) );
		}
	}
	
	////////////////////////////////////////// Parsing Disambiguation Pages ////////////////////////////////////////////
	
	/*
	 * Function to parse disambiguation pages.
	* $file is the file path relative to CALLER php document.
	*/
	private function parseDisambiguationPages( $file )
	{
		//Check that file is non empty.
		if( empty( $file ) )
		{
			Utileries::logMessage( "[X] Error: The file path is empty.\n" );
			exit();
		}
	
		//Read the input file, line by line.
		@ $fp = fopen( $file, "r" );
		if( !$fp )
		{
			Utileries::logMessage( "[X] Error: File $file could not be opened.\n" );
			exit();
		}
		else
		{
			$state = 0;							//State for automaton.
			$errorMessage = "";					//Detect any error and put it here.
			$title = "";						//Title for disambiguation page.
			$nLine = 0;							//Number of line for error detection.
			$wikiPages = array();				//Array of Wikipedia pages that appear under <disambiguous> tag.
			
			while( !feof( $fp ) )				//Scan the input file line by line.	
			{
				$line = fgets( $fp );
				$line = Utileries::utf8_trim( $line );		//Remove trailing spaces.
				$nLine++;
				
				if( !empty( $line ) )			//Skip lines in blank.
				{
					switch( $state )			//Select automaton state.
					{
						case 0:
							if( mb_stripos( $line, '<doc' ) === 0 )		//Detected new document tag?
								$state = 1;
							else 
							{
								$state = 6;
								$errorMessage = "[X] Error: Expected <doc> tag. $line was found instead in line $nLine.\n";
							}
							break;
							
						case 1:
							if( mb_substr( $line, 0, 1 ) != '<' )		//Is it the title? Normal string.
							{
								$line = preg_replace( '/disambiguation|\(disambiguation\)/u', '', $line );	//Replace disambiguation word.
								$line = Utileries::utf8_trim( $line );	
								$title = mb_strtolower( $line );		//Convert surface forms into lowercase.
								$state = 2;
								Utileries::logMessage( "[*] Began parsing object $title.\n" ); 
							}
							else 
							{
								$state = 6;
								$errorMessage = "[X] Error: Expected a title. $line was found instead in line $nLine.\n";
							}
							break;
							
						case 2:
							if( $line == '<disambiguous>' )				//Entered disambiguous part?
							{
								$wikiPages = array();					//Prepare array of Wikipedia pages.
								$totalOperations = 0;
								$state = 3;
							}
							else
							{
								$state = 6;
								$errorMessage = "[X] Error: Expected <disambiguous> tag. $line was found instead in line $nLine.\n";
							}
							break;
							
						case 3:
							if( mb_substr( $line, 0, 1 ) != '<' )		//Found (at least) first wikiPage?
							{
								$state = 4;
								$line = urldecode( $line );				//Remove URL encodings (like %AB).
								$line = preg_replace( '/ /u', '_', $line );	//Replace spaces for underscores.
								
								if( ( $indexOfSharp = mb_stripos( $line, '#' ) ) !== false )
									$line = mb_substr( $line, 0, $indexOfSharp );	//Remove possible # sign.
								
								//Add wikiPage to array.
								//Each tuple is of the form (surfaceForm,wikiPage). 
								array_push( $wikiPages, array( 'surfaceForm' => $title, 'title' => $line ) );
							}
							else 
							{
								if( $line == '</disambiguous>' )		//Finished reading wikiPages?
								{
									$state = 5;
									Utileries::logMessage( "[W] Warning: Expected at least one wikiPage for object $title.\n" );
								}
								else 
								{
									$state = 6;
									$errorMessage = "[X] Error: Expected a wikiPage or </disambiguous> tag. 
										$line was found instead in line $nLine.\n";
								}
							}
							break;
							
						case 4:
							if( mb_substr( $line, 0, 1 ) != '<' )		//Found another wikiPage?
							{
								$state = 4;
								$line = urldecode( $line );				//Remove URL encodings (like %AB).
								$line = preg_replace( '/ /u', '_', $line );	//Replace spaces for underscores.
							
								if( ( $indexOfSharp = mb_stripos( $line, '#' ) ) !== false )
									$line = mb_substr( $line, 0, $indexOfSharp );	//Remove possible # sign.
							
								//Add wikiPage to array.
								//Each tuple is of the form (surfaceForm,wikiPage). 
								array_push( $wikiPages, array( 'surfaceForm' => $title, 'title' => $line ) );
							}
							else
							{
								if( $line == '</disambiguous>' )		//Finished reading wikiPages?
									$state = 5;
								else
								{
									$state = 6;
									$errorMessage = "[X] Error: Expected a wikiPage or </disambiguous> tag. 
										$line was found instead in line $nLine.\n";
								}
							}
							break;
							
						case 5:
							if( $line == '</doc>' )						//Find end of disambiguation xml document?
							{
								$state = 0;								//Go back to initial state.
								
								//Insert disambiguation entries here.
								if( count( $wikiPages ) > 0 )
									$this->insertDisambiguationEntries( $wikiPages, $totalOperations );
								
								Utileries::logMessage( "... $totalOperations done!\n\n" );
							}
							else
							{
								$state = 6;
								$errorMessage = "[X] Error: Expected </doc> tag. $line was found instead in line $nLine.\n";
							}
							break;
					}
				}
				
				if( $state == 6 )				//Break loop early if there were errors.
					break;
			}
			
			if( $state != 0 )					//Finished in an undesired state?
			{
				if( $state == 6 )				//Error state?
					Utileries::logMessage( $errorMessage );
				else
					Utileries::logMessage( "[X] Error: Parsing disambiguation file $file finished in state $state.\n" );
				exit();
			}
			else
				Utileries::logMessage( "[*] Disambiguation file parsed with no errors!.\n\n" );
				
			unset( $wikiPages );				//Free memory.
			
		}
	
		fclose( $fp );				//Close file.
	}
	
	/*
	 * Function to insert entries in the dictionaries regarding disambiguation pages from
	 * Wikipedia.
	 * $inputTuples is an array of pairs (surfaceForm, wikiPage).
	 * $totalOperations is passed by reference to count how many insertions and updates there were.
	 * For disambiguation pages the 'list' field of dictionary remains the same.
	 */
	private function insertDisambiguationEntries( $inputTuples, & $totalOperations )
	{
		//We need to divide the inputTuples into groups of 10000 attempts of insertions,
		//so that it would not take a very long time to create the dictionary.
		$I = 0;
		while( $I < count( $inputTuples ) )
		{
			//Create up to 10000 tuples.
			$tuples = array();				//An empty container.
			$J = 0;							//Counter for current number of tuples in subgroup.
			while( $J < 10000 && $I < count( $inputTuples ) )
			{
				array_push( $tuples, $inputTuples[ $I ] );
				$I++;
				$J++;
			}
				
			//Utileries::logMessage( "# Processing $J/$I tuples.\n" );
				
			//////////////// Part 1, fill in tempDictionary table //////////////////
				
			if( !($this->db->query( 'TRUNCATE tempDictionary' ) ) )	//Clean contents of the temporal dictionary.
			{
				Utileries::logMessage("[X] Error: Could not truncate tempDictionary table.\n");
				exit();
			}
				
			//First, build the entries for the Prepared Statement.
			$types = '';		//These are of the kind ssis - str str int str
			$marks = '';		//These are of the kind (?,?,?,?),
			$vars = array();	//Collects the values from tuples array into a one dimensional array.
			while( list( $key, $tuple ) = each( $tuples ) )
			{
				$types .= 'ssis';						//Concatenate query types and marks.
				$marks .= '(?,?,?,?),';
				array_push( $vars, $tuple['surfaceForm'] );		//String.
				array_push( $vars, $tuple['title'] );			//String.
				array_push( $vars, 1 );							//Int - This is for count.
				array_push( $vars, '' );						//String - This is for list.
			}
			$terms = array_merge( array($types), $vars );	//$terms contains ssis...sF1 title1 count1 list1 sF2 title2...
				
			//Next, construct the query.
			//Here we assume that for each disambiguation page there is exactly one copy per Wikipedia Page; so count should remain 1.
			$query = "INSERT INTO tempDictionary(surfaceForm, title, count, list) VALUES ";
			$marks = mb_substr( $marks, 0, -1).' ';
			$query .= $marks . "ON DUPLICATE KEY UPDATE count = count, list = list";
				
			//Finally, get the MySQL statement working.
			if( !( $pStatement = $this->db->prepare( $query ) ) )	//Check there is no error.
			{
				Utileries::logMessage( "[X] Error: Statement for inserting entries in tempDictionary could not be prepared.\n" );
				exit();
			}
			call_user_func_array( array( $pStatement, "bind_param" ), Utileries::refValues( $terms ) );	//Call $pStatement->bind_param().
			$pStatement->execute();
				
			if( $pStatement->affected_rows == 0 )			//Check it really updated.
				Utileries::logMessage( "[X] Error: There were no insertions/updates in the tempDictionary table.\n" );
				
			$pStatement->close();
				
			////// Part 2, Inner join with page table to obtain the page id ////////
			// At the same time, we are getting rid of (surfaceForm,title) whose
			// titles are not in the page table. Also, split into 27 groups for the
			// different dictionaries.
				
			$result = $this->db->query( 'SELECT surfaceForm, id, count, list
				FROM tempDictionary INNER JOIN page ON tempDictionary.title=page.title' );
				
			//Create 27 dictionaries.
			$dictionaries = array();						//Allocate space.
			foreach( $this->letters as $letter )
				$dictionaries[ $letter ] = array();
				
			//Collect results from the inner join.
			while( ( $row = $result->fetch_assoc() ) != NULL )
			{
				$entry = array( $row['surfaceForm'], $row['id'], $row['count'], $row['list'] );		//Array to insert.
				$initialLetter = mb_substr( $row['surfaceForm'], 0, 1 );
					
				if( Utileries::isAlpha( $initialLetter ) )			//If initial character is alphabetic.
					array_push( $dictionaries[ $initialLetter ], $entry );
				else 												//If initial character is not alphabetic
					array_push( $dictionaries['0'], $entry );
			}
				
			$result->free();								//Free memory.
				
			////// Part 3, insert dictionary entries in each of the 27 tables //////
				
			$startTime = microtime( true );
			while( list( $letter, $dictionary ) = each( $dictionaries ) )
			{
				if( !empty( $dictionary ) )					//Check that there are entries to insert.
				{
					$table = ( Utileries::isAlpha( $letter ) )? "dictionary".$letter: "dictionary";	//Which table we will be referring to?
						
					$types = '';		//These are of the kind ssis - str str int str
					$marks = '';		//These are of the kind (?,?,?,?),
					$vars = array();	//Collects the values from tuples array into a one dimensional array.
					while( list( $key, $tuple ) = each( $dictionary ) )
					{
						$types .= 'siis';					//Concatenate query types and marks.
						$marks .= '(?,?,?,?),';
						array_push( $vars, $tuple[0] );		//surfaceForm.
						array_push( $vars, $tuple[1] );		//id.
						array_push( $vars, $tuple[2] );		//count.
						array_push( $vars, $tuple[3] );		//list.
					}
					$terms = array_merge( array($types), $vars );	//$terms contains siis... sF1 id1 count1 list1 sF2 id2...
						
					//Next, construct the query.
					//We need to use VALUES() because we use the ON DUPLICATE KEY UPDATE with the individual data for each (surfaceForm,id).
					$query = "INSERT INTO $table(surfaceForm, id, count, list) VALUES ";
					$marks = mb_substr( $marks, 0, -1).' ';
					$query .= $marks . "ON DUPLICATE KEY UPDATE count = count+VALUES(count), list = list";
						
					//Finally, get the MySQL statement working.
					if( !( $pStatement = $this->db->prepare( $query ) ) )	//Check there is no error.
					{
						Utileries::logMessage( "[X] Error: Statement for inserting entries in $table could not be prepared.\n" );
						exit();
					}
					call_user_func_array( array( $pStatement, "bind_param" ), Utileries::refValues( $terms ) );	//Call $pStatement->bind_param().
					$pStatement->execute();
						
					if( $pStatement->affected_rows == 0 )			//Check it really updated.
						Utileries::logMessage( "[X] Error: There were no insertions/updates in table $table.\n" );
					else
						$totalOperations += $pStatement->affected_rows;		//Accumulate transaction information.
						
					$pStatement->close();
				}
					
				unset( $dictionary );						//Free memory.
			}
			$elapsedTime = microtime( true ) - $startTime;
			//Utileries::logMessage( sprintf("+ Finished inserting dictionary entries after %f seconds.\n", $elapsedTime) );
		}
	}
	
	////////////////////////////////////////// Parsing Redirect Pages ////////////////////////////////////////////
	
	/*
	 * Function to parse redirect pages.
	 * $file is the file path relative to CALLER php document.
	 */
	private function parseRedirectPages( $file )
	{
		//Check that file is non empty.
		if( empty( $file ) )
		{
			Utileries::logMessage( "[X] Error: The file path is empty.\n" );
			exit();
		}
	
		//Read the input file, line by line.
		@ $fp = fopen( $file, "r" );
		if( !$fp )
		{
			Utileries::logMessage( "[X] Error: File $file could not be opened.\n" );
			exit();
		}
		else
		{
			$state = 0;							//State for automaton.
			$surfaceForm = "";					//Title for redirect page.
			$page = "";							//Wikipedia page to which redirection points to.
			$nLine = 0;							//Number of line for error detection.
			$wikiPages = array();				//Array of Wikipedia pages that appear under <disambiguous> tag.
			$skip = false;						//Flag to skip tags to recover from an error.
			$totalOperations = 0;				//Count how many operations there were.
				
			while( !feof( $fp ) )				//Scan the input file line by line.
			{
				$line = fgets( $fp );
				$line = Utileries::utf8_trim( $line );		//Remove trailing spaces.
				$nLine++;
				$errorMessage = "";				//Detect any error and put it here.
	
				if( !empty( $line ) )			//Skip lines in blank.
				{
					switch( $state )			//Select automaton state.
					{
						case 0:
							if( mb_stripos( $line, '<doc' ) === 0 )		//Detected new document tag?
							{
								$skip = false;							//Do not skip tags.
								
								if( ( $surfaceFormIndex = mb_stripos( $line, 'title=' ) ) !== false )	//Does it containe query marker?
								{
									$surfaceForm = mb_substr( $line, $surfaceFormIndex + 6 );			//Extract surface form with " ".
								
									if( ( $surfaceFormLen = mb_strlen( $surfaceForm ) ) > 3 )			//Appropriate length?
									{
										$surfaceForm = mb_substr( $surfaceForm, 1, $surfaceFormLen - 3 );	//Remove quotes and > symbol.
										if( preg_match('/^\((.)*\)$/u', $surfaceForm ) === 0 )			//Do not touch '(...)' expressions.
											$surfaceForm = preg_replace( '/\((.)*\)$/u', '', $surfaceForm );	//Remove terms that end in -(...).
										$surfaceForm = html_entity_decode( $surfaceForm, ENT_QUOTES, 'UTF-8' );	//Remove HTML entities.
										$surfaceForm = Utileries::utf8_trim( $surfaceForm );
										$surfaceForm = mb_strtolower( $surfaceForm );					//Surface forms are always lowercase.
										
										if( !empty( $surfaceForm ) )									//Remove trailing spaces and check.
										{
											$state = 1;
											//echo "* Object: $surfaceForm.\n";
										}
										else
										{
											$state = 3;
											$errorMessage = "[X] Error: The surface form is empty after removing trailing spaces.\n";
										}
									}
									else 
									{
										$state = 3;
										$errorMessage = "[X] Error: The surface form has an innapropriate length.\n";
									}
								}
								else
								{
									$state = 3;
									$errorMessage = "[X] Error: Could not find parameter 'title' in  <doc> tag.\n";
								}
							}
							else
							{
								if( !$skip )	//Trigger error only if it is not in recover state.
								{
									$state = 3;
									$errorMessage = "[X] Error: Expected <doc> tag. $line was found instead in line $nLine.\n";
								}
								else
									Utileries::logMessage( "+ Skipping lines: $line.\n" );
							}
							break;
								
						case 1:
							if( preg_match('#^<redirect>(.)*</redirect>$#iu', $line ) == 1 )	//Is it redirect tag? Using # as delimiter.
							{
								$ends = mb_stripos( $line, '</redirect>' );						//Where does redirect content ends?
								$page = mb_substr( $line, 10, $ends - 10 );						//Extract Wikipedia page.
								$page = urldecode( $page );										//Remove URL encodings (like %AB).
									
								if( ( $indexOfSharp = mb_stripos( $page, '#' ) ) !== false )
									$page = mb_substr( $page, 0, $indexOfSharp );				//Remove possible # sign.
								
								$page = html_entity_decode( $page, ENT_QUOTES, 'UTF-8' );		//Remove HTML entities.
								$page = Utileries::utf8_trim( $page );							//Remove trailing spaces.
								$page = preg_replace( '/ /u', '_', $page );						//Replace spaces for underscores.
															
								if( !empty( $page ) )
									$state = 2;
								else
								{
									$state = 3;
									$errorMessage = "[X] Error: The redirect Wiki page for $surfaceForm is empty.\n";
								}
								
							}
							else
							{
								$state = 3;
								$errorMessage = "[X] Error: Expected a <redirect></redirect>. $line was found instead in line $nLine.\n";
							}
							break;
								
						case 2:
							if( $line == '</doc>' )						//End of document?
							{
								array_push( $wikiPages, array(  'surfaceForm' => $surfaceForm, 'title' => $page ) );
								$state = 0;								//Go back to idle state.
								//echo "... Object done!\n";
							}
							else
							{
								$state = 3;
								$errorMessage = "[X] Error: Expected </doc> tag. $line was found instead in line $nLine.\n";
							}
							break;
					}
				}
	
				if( $state == 3 )				//Error state?
				{
					Utileries::logMessage( $errorMessage );
					echo "[*] Entering the skipping state until a new <doc> is found.\n";
					$skip = true;
					$state = 0;
				}
			}

			if( $state != 0 )					//Finished in an undesired state?
			{
				Utileries::logMessage( "[X] Error: Parsing disambiguation file $file finished prematurely.\n" );
				exit();
			}
			else
			{
				//Insert tuples in the dictionaries.
				$this->insertRedirectEntries( $wikiPages, $totalOperations );
				Utileries::logMessage( "[*] Done with redirect file with $totalOperations operations.\n\n" );
			}
	
			unset( $wikiPages );				//Free memory.							
		}
	
		fclose( $fp );				//Close file.
	}
	
	/*
	 * Function to insert entries in the dictionaries regarding redirect pages from
	 * Wikipedia.
	 * $inputTuples is an array of pairs (surfaceForm, wikiPage).
	 * $totalOperations is passed by reference to count how many insertions and updates there were.
 	 * For redirect pages the 'list' field of dictionary remains the same.
	 */
	private function insertRedirectEntries( $inputTuples, & $totalOperations )
	{
		//We need to divide the inputTuples into groups of 10000 attempts of insertions,
		//so that it would not take a very long time to create the dictionary.
		$I = 0;
		while( $I < count( $inputTuples ) )
		{
			//Create up to 10000 tuples.
			$tuples = array();				//An empty container.
			$J = 0;							//Counter for current number of tuples in subgroup.
			while( $J < 10000 && $I < count( $inputTuples ) )
			{
				array_push( $tuples, $inputTuples[ $I ] );
				$I++;
				$J++;
			}
	
			Utileries::logMessage( "# Processing $J/$I tuples.\n" );
	
			//////////////// Part 1, fill in tempDictionary table //////////////////
	
			$startTime = microtime( true );
			if( !($this->db->query( 'TRUNCATE tempDictionary' ) ) )	//Clean contents of the temporal dictionary.
			{
				Utileries::logMessage("[X] Error: Could not truncate tempDictionary table.\n");
				exit();
			}
	
			//First, build the entries for the Prepared Statement.
			$types = '';		//These are of the kind ssis - str str int str
			$marks = '';		//These are of the kind (?,?,?,?),
			$vars = array();	//Collects the values from tuples array into a one dimensional array.
			while( list( $key, $tuple ) = each( $tuples ) )
			{
				$types .= 'ssis';						//Concatenate query types and marks.
				$marks .= '(?,?,?,?),';
				array_push( $vars, $tuple['surfaceForm'] );		//String.
				array_push( $vars, $tuple['title'] );			//String.
				array_push( $vars, 1 );							//Int - This is for count.
				array_push( $vars, '' );						//String - This is for list.
			}
			$terms = array_merge( array($types), $vars );	//$terms contains ssis...sF1 title1 count1 list1 sF2 title2...
	
			//Next, construct the query.
			//Here we assume that for each disambiguation page there is exactly one copy per Wikipedia Page; so count should remain 1.
			$query = "INSERT INTO tempDictionary(surfaceForm, title, count, list) VALUES ";
			$marks = mb_substr( $marks, 0, -1).' ';
			$query .= $marks . "ON DUPLICATE KEY UPDATE count = count, list = list";
	
			//Finally, get the MySQL statement working.
			if( !( $pStatement = $this->db->prepare( $query ) ) )	//Check there is no error.
			{
				Utileries::logMessage( "[X] Error: Statement for inserting entries in tempDictionary could not be prepared.\n" );
				exit();
			}
			call_user_func_array( array( $pStatement, "bind_param" ), Utileries::refValues( $terms ) );	//Call $pStatement->bind_param().
			$pStatement->execute();
	
			if( $pStatement->affected_rows == 0 )			//Check it really updated.
				Utileries::logMessage( "[X] Error: There were no insertions/updates in the tempDictionary table.\n" );
	
			$pStatement->close();
			$elapsedTime = microtime( true ) - $startTime;
			Utileries::logMessage( sprintf("+ Filling tempDictionary took %f seconds.\n", $elapsedTime) );
	
			////// Part 2, Inner join with page table to obtain the page id ////////
			// At the same time, we are getting rid of (surfaceForm, title) whose
			// titles are not in the page table. Also, split into 27 groups for the
			// different dictionaries.
	
			$startTime = microtime( true );
			$result = $this->db->query( 'SELECT surfaceForm, id, count, list
				FROM tempDictionary, page WHERE tempDictionary.title=page.title' );
	
			//Create 27 dictionaries.
			$dictionaries = array();						//Allocate space.
			foreach( $this->letters as $letter )
				$dictionaries[ $letter ] = array();
	
			//Collect results from the inner join.
			while( ( $row = $result->fetch_assoc() ) != NULL )
			{
				$entry = array( $row['surfaceForm'], $row['id'], $row['count'], $row['list'] );		//Array to insert.
				$initialLetter = mb_substr( $row['surfaceForm'], 0, 1 );
					
				if( Utileries::isAlpha( $initialLetter ) )			//If initial character is alphabetic.
					array_push( $dictionaries[ $initialLetter ], $entry );
				else 												//If initial character is not alphabetic
					array_push( $dictionaries['0'], $entry );
			}
	
			$result->free();								//Free memory.
			$elapsedTime = microtime( true ) - $startTime;
			Utileries::logMessage( sprintf("+ Inner join took %f seconds.\n", $elapsedTime) );
	
			////// Part 3, insert dictionary entries in each of the 27 tables //////
	
			$startTime = microtime( true );
			while( list( $letter, $dictionary ) = each( $dictionaries ) )
			{
				if( !empty( $dictionary ) )					//Check that there are entries to insert.
				{
					$table = ( Utileries::isAlpha( $letter ) )? "dictionary".$letter: "dictionary";	//Which table we will be referring to?
	
					$types = '';		//These are of the kind ssis - str str int str
					$marks = '';		//These are of the kind (?,?,?,?),
					$vars = array();	//Collects the values from tuples array into a one dimensional array.
					while( list( $key, $tuple ) = each( $dictionary ) )
					{
						$types .= 'siis';					//Concatenate query types and marks.
						$marks .= '(?,?,?,?),';
						array_push( $vars, $tuple[0] );		//surfaceForm.
						array_push( $vars, $tuple[1] );		//id.
						array_push( $vars, $tuple[2] );		//count.
						array_push( $vars, $tuple[3] );		//list.
					}
					$terms = array_merge( array($types), $vars );	//$terms contains siis... sF1 id1 count1 list1 sF2 id2...
	
					//Next, construct the query.
					//We need to use VALUES() because we use the ON DUPLICATE KEY UPDATE with the individual data for each (surfaceForm,id).
					$query = "INSERT INTO $table(surfaceForm, id, count, list) VALUES ";
					$marks = mb_substr( $marks, 0, -1).' ';
					$query .= $marks . "ON DUPLICATE KEY UPDATE count = count+VALUES(count), list = list";
	
					//Finally, get the MySQL statement working.
					if( !( $pStatement = $this->db->prepare( $query ) ) )	//Check there is no error.
					{
						Utileries::logMessage( "[X] Error: Statement for inserting entries in $table could not be prepared.\n" );
						exit();
					}
					call_user_func_array( array( $pStatement, "bind_param" ), Utileries::refValues( $terms ) );	//Call $pStatement->bind_param().
					$pStatement->execute();
	
					if( $pStatement->affected_rows == 0 )			//Check it really updated.
						Utileries::logMessage( "[X] Error: There were no insertions/updates in table $table.\n" );
					else
						$totalOperations += $pStatement->affected_rows;		//Accumulate transaction information.
	
					$pStatement->close();
				}
					
				unset( $dictionary );						//Free memory.
			}
			$elapsedTime = microtime( true ) - $startTime;
			Utileries::logMessage( sprintf("+ Finished inserting dictionary entries after %f seconds.\n", $elapsedTime) );
		}
	}
}

?>