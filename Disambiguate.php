<?php
require_once 'Libraries/MySQL.php';
require_once 'Candidate.php';
require_once 'Libraries/WikipediaMiner.php';
require_once 'Entity.php';
require_once 'Shine.php';

mb_internal_encoding( 'UTF-8' );
mb_regex_encoding( 'UTF-8' );

//Text to disambiguate.
//$text = 'An oracle told the king that the only way to get rid of the sea monster was to surrender his virgin daughter Andromeda to the 
//		sea monster; so he did. King [[Cepheus]] chained Andromeda to a rock in the sea where the hero saw her. [[Perseus]] was still wearing 
//		the winged sandals of [[Hermes]] that he had used in the task of carefully decapitating [[Medusa]], while watching what he was doing only 
//		through a mirror. He asked what had happened to [[Andromeda]]; then, when he heard, he promptly offered to rescue her by killing the 
//		sea monster, but on condition that her parents give her to him in marriage.';

//$text = "[[Prometheus]] acts as a shepherding satellite, constraining the extent of the inner edge of Saturn's [[F Ring]]. 
//		Prometheus is extremely irregular and has visible craters; some up to 20 km (12.4 miles) in diameter. 
//		However, it is much less cratered than its nearby neighbors [[Pandora]], [[Janus]], and [[Epimetheus]]. The density of 
//		Prometheus has been estimated to be low; it is probably a porous, icy body. The [[Voyager 1]] science team 
//		discovered Prometheus in October 1980.";

$text = "This month [[MNet]] Countdown is presenting [[Infinite]] and [[Boyfriend]] for the very first time together. This South Korean
		bands have become very famous in the KPop charts in [[서울]] and in Japan. Infinite debuted in 2010 with Nothing is Over,
		while Boyfriend premiered their music video and single Boyfriend a year later.";

//Create Shine object.
$shine = new Shine();

//Validate length of input text.
$text = Utileries::utf8_trim( $text );
if( mb_strlen( $text ) < 128 )	//Require at least 128 characters.
	Utileries::logError( 'The input text must have at least 128 characters!' );
else
{
	//Get other surface forms from Wikipedia Miner.
	//$wikify = new WikipediaMiner();
	//if( ( $wText = $wikify->wikify( $text ) ) === false )	//Failed connection to Wikipedia Miner?
	//{
	//	echo "We will proceed with original text!\n";
	$wText = $text;
	//}
	
	echo "======================== Text to disambiguate ==========================\n";
	echo $wText."\n\n";
	
	//Extract the context for each named entity.
	if( ( $namedEntities = $shine->extractSufaceForms( $wText ) ) !== false )
	{
		//Now, generate candidates for all surface forms, and extract their context.
		$startTime = microtime( true );
		if( ( $candidateMappings = $shine->generateCandidateMappings() ) !== false )
		{
			$elapsedTime = microtime( true ) - $startTime;
			echo "[*] Finished extracting candidates in $elapsedTime seconds.\n\n";
	
			//Create context to compute context similarity with TFIDF.
			$startTime = microtime( true );
			if( $shine->createTFIDF() !== false )
			{
				$elapsedTime = microtime( true ) - $startTime;
				echo "[*] Finished creating IDF structure in $elapsedTime seconds.\n\n";
					
				//Implement the linking task.
				$mappings = $shine->linkEntities();
			}
		}
	}
}

echo $shine->generateXML();		//Output XML document.

?>