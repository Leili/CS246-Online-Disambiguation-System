<?php
/*******************************************************************************
Class to perform common operations in the database.
*******************************************************************************/
mb_internal_encoding( "UTF-8" );

require_once 'Utileries.php';

class MySQL
{
	/*
	 * Function to connect to database with writing privileges.
	 * $connection is a reference to a MySQLi object.
	 */
	public static function connectforWriting( & $connection )
	{
		if( !empty( $connection ) )
			$connection->close();			//Close any open connection.
		
		//Connect to shine database.
		@ $connection = new mysqli( "localhost", "root", "", "shine" );
	
		if( mysqli_connect_errno() )		//Check connection was established.
		{
			Utileries::logError( "Could not connect to database!" );
			return false;
		}
		
		//Set character-set for MySQL connection.
		//Using SET NAMES 'utf8' also works.
		$connection->query( "SET character_set_results='utf8',
				character_set_client='utf8',
				character_set_connection='utf8', 
				character_set_database = 'utf8',
				character_set_server = 'utf8'" );		//Communication packet.
		
// 		$result = $connection->query("SHOW VARIABLES LIKE 'max_allowed_packet'");
// 		$row = $result->fetch_assoc();
//		ATTENTION Set the max_allowed_packet from 1M to 64M in order to avoid the "mysql server has gone away" error.
		
		return true;
	}
	
	/*
	 * Function to connect to database with reading privileges.
	 * $connection is a reference to a MySQLi object.
	 */
	public static function connectforReading( & $connection )
	{
		if( !empty( $connection ) )
			$connection->close();			//Close any open connection.
	
		//Connect to shine database.
		@ $connection = new mysqli( "localhost", "cs246", "reader", "shine" );
	
		if( mysqli_connect_errno() )		//Check connection was established.
		{
			Utileries::logError( "Could not connect to database!" );
			return false;
		}
	
		//Set character-set for MySQL connection.
		//Using SET NAMES 'utf8' also works.
		$connection->query( "SET character_set_results='utf8',
				character_set_client='utf8',
				character_set_connection='utf8',
				character_set_database = 'utf8',
				character_set_server = 'utf8'" );
		
		return true;
	}
	
	/*
	 * Function to disconnect from database (either from writing or reading).
	 * $connection is a reference to a MySQLi object.
	*/
	public static function disconnect( & $connection )
	{
		if( !empty( $connection ) )			//Disconnect only if there is a connection established.
			$connection->close();
		else
			Utileries::logError( "Connection to database cannot close because the resource is null!" );
	}
}

?>