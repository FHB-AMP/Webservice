<?php
	// Debug Mode
	// ini_set('display_errors', 'On');
	// error_reporting(E_ALL | E_STRICT);
	// Set local time
	date_default_timezone_set("Europe/Berlin");
	setlocale (LC_ALL, 'de_DE@euro', 'de_DE', 'de', 'ge');
	header('Content-Type: application/json;charset=utf-8');
	
	// date from 'Do, 18. Juni 2015' to '2015-06-08'
	function formatDate($weirdDate) {
		$date = date_parse_from_format('D, d. M Y', $weirdDate);
		$day = $date["day"];
		$year = $date["year"];
		
		// parse localized months manually
		$aMonths = array("Januar", "Februar", "März", "April", "Mai", "Juni", "Juli", "August", "September", "Oktober", "November", "Dezember");
		$dateElements = explode(' ', trim(substr($weirdDate, 3)));
		$month = $dateElements[1];
		
		// use the number of the month
		if (in_array($month, $aMonths)) {
			$nMonth = array_search($month, $aMonths);
			// array key +1
			$nMonth = $nMonth+1;
		} else {
			$nMonth = "1";
		}
		
		return strftime("%Y-%m-%d", mktime(0, 0, 0, $nMonth, $day, $year));
	}
	
	// string from 'Gegrillte H&auml;hnchenkeule mit Letscho\r\nund gebackenen Kartoffelecken'
	// to 'Gegrillte Hähnchenkeule mit Letscho und gebackenen Kartoffelecken'
	// or from 'Nudeln all arrabiata (vegan), dazu Reibek&auml;se'
	// to 'Nudeln all arrabiata, dazu Reibekäse'
	// TODO or from 'Hähnchengeschnetzeltes ;&quot;Calvados;&quot;'
	// to 'Hähnchengeschnetzeltes "Calvados"'
	function formatString($weirdString) {
		$weirdString = preg_replace("/\([^)]+\)/", "", $weirdString);
		$weirdString = preg_replace('/\s*,/', ',', $weirdString);
		$weirdString = str_replace(' - ', '-', $weirdString);
		$weirdString = preg_replace('/\s(\r\n)/', ' ', $weirdString);
		$weirdString = preg_replace('/(\r\n)/', ' ', $weirdString);
		$weirdString = html_entity_decode($weirdString, ENT_COMPAT, 'UTF-8');
		//$weirdString = preg_replace('\"', '\u0022', $weirdString);
		return $weirdString;
	}
	
	// Meal API
	// Fetches meals from a web page and provides RESTful web service
	// @author André Nitze (andre.nitze@fh-brandenburg.de)
	// TODO Error handling
	// TODO Fix bug of not-up-to-date meal plan
	$filename = date('Y-m-d');
	$resultArray = array();
	if (file_exists($filename) && !isset($_GET['force_update'])) {
		$resultArray = unserialize( file_get_contents($filename) );
	} else {
		$dates = array();
		$meals = array();
		$json = "";
		// Fetch HTML with curl extension
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		// Create a DOM parser object
		$dom = new DOMDocument();
		// No meals after 2 pm (closing time)
		if (date('G') < 14) {
			// Get meal from todays' meal plan
			$url = "http://www.studentenwerk-potsdam.de/mensa-brandenburg.html";
			curl_setopt($ch, CURLOPT_URL, $url);
			$html = curl_exec($ch);
			if($html === FALSE) {
				die(curl_error($ch));
			}
			@$dom->loadHTML($html);
			// Create xPath expression and query
			$xpath = new DOMXpath($dom);
			$xPathQueryMeals = "//table[@class]/tr[2]/td";
			$xPathQuerySymbols = "//table[@class]/tr[3]/td/div[2]/a/img";
			// Set current date
			$dates[0] = date("Y-m-d");
			// Query the DOM for xPaths where meals are
			$elements = $xpath->query($xPathQueryMeals);
			$symbols = $xpath->query($xPathQuerySymbols);
			
			// fetch meals from today
			$j = 0;
			foreach ($elements as $element) {
				$meals[$j] = formatString(htmlentities($element->nodeValue));
				$j++;
			}
			
			// combine meals and other stuff
			for ($k = 1; $k < count($meals); $k++) {
				$cleanSymbolsString = $symbols->item($k-1)->getAttribute('title');
				$cleanString = $meals[$k-1];
				
				if (strlen($cleanString) > 0 && strlen($cleanSymbolsString) > 0 ) {
					$mealsArray[] = ['mealNumber' => $k, 'name' => $cleanString, 'symbols' => $cleanSymbolsString, 'additives' => array(), 'allergens' => array()];
				}
			}
			
			// Add todays' meal to resultArray set, if meals exist
			if (count($mealsArray) > 0 ) {
				$resultArray[] = ['date' => $dates[0], 'meals' => $mealsArray];
				$UP_TO_DATE = true;
			} else {
				$UP_TO_DATE = false;
			}
				// Reset date array for 'upcoming meals' processing
			unset($dates);
		}
		
		// Append all upcoming meals to todays meal
		// Get meals from 'upcoming meals' schedule
		$url = "http://www.studentenwerk-potsdam.de/speiseplan.html";
		curl_setopt($ch, CURLOPT_URL, $url);
		$html = curl_exec($ch);
		if($html === FALSE) {
			die(curl_error($ch));
		}
		
		@$dom->loadHTML($html);
		
		// Create xPath expression
		$xpath = new DOMXpath($dom);
		// Query the DOM for date nodes
		$xPathQueryDates = "//div[@class='date']";
		$dateNodes = $xpath->query($xPathQueryDates);
		foreach ($dateNodes as $date) {
			$dates[] = formatDate($date->nodeValue);
		}
		
		// Query the DOM for xPaths where meals and additives are
		$xPathQueryMeals = "//td[@class='text1'] | //td[@class='text2'] | //td[@class='text3'] | //td[@class='text4']";
		$meals = $xpath->query($xPathQueryMeals);
		
		// making xpath more simple
		$doc = new DOMDocument();
		$doc->loadHTML($html);
		$xml = simplexml_import_dom($doc);
		
		// arrays for all known additives and allergens
		$knownAdditives = array("(1)", "(2)", "(3)", "(4)", "(5)", "(6)", "(7)", "(8)", "(9)", "(11)", "(13)", "(14)", "(20)", "(21)", "(22)", "(23)", "(KF)", "(TL)", "(AL)", "(GE)");
		$knownAllergens = array("(A)", "(B)", "(C)", "(D)", "(E)", "(F)", "(G)", "(H)", "(I)", "(J)", "(K)", "(L)", "(M)", "(N)");
		
		// Combine dates and corresponding meals
		$mealsPerDay = 4;
		$j = 1;
		$actualDay = 1;
		$div = 3;
		$mealsArray = array();
		$additives = array();
		$allergens = array();
		
		foreach ($dates as $date) {
			for ($i = 1; $i <= $mealsPerDay; $i++) {
				
				// fetch and sanitize meals-string
				$cleanString = formatString(htmlentities($meals->item($j + $i - 2)->nodeValue, ENT_COMPAT));
				
				// set actual xPathes
				$xPathQuerySymbols = "//table/tr/td/div[$div]/table[$actualDay]/tr[4]/td[$i]/div[2]/a/img";
				$xPathQueryAdditives = "//table/tr/td/div[$div]/table[$actualDay]/tr[4]/td[$i]/div[1]/a";
				
				// fetch symbols
				foreach ($xml->xpath($xPathQuerySymbols) as $img) {
					if (isset($img["title"])) {
						$symbol = $img['title'];
						$symbols[] = $symbol;
					}	
				}
				
				// fetch additives and allergens
				foreach ($xpath->query($xPathQueryAdditives) as $textNode) {
					$value = $textNode->nodeValue;
					if (in_array($value, $knownAdditives) && !(in_array($value, $additives))) {
						$additives[] = $value;
					} elseif (in_array($value, $knownAllergens)&& !(in_array($value, $allergens))) {
						$allergens[] = $value;
					}
				}
				
				// fill empty arrays
				if(!$additives)
				{
					$additives[] = '';
				}
				
				if(!$allergens)
				{
					$allergens[] = '';
				}
				
				//
				if (strlen($cleanString) > 0) {
					$mealsArray[$i - 1] = ['mealNumber' => $i, 'name' => $cleanString, 'symbols' => $symbols, 'additives' => $additives, 'allergens' => $allergens];
				}
				
				// unset for the next run
				unset($symbols);
				unset($additives);
				unset($allergens);
			}
			
			$j += $mealsPerDay;
			
			// Add this days' meals to resultArray set
			$resultArray[] = ['date' => $date, 'meals' => $mealsArray];
			
			// persist for the next time to save traffic
			if ($UP_TO_DATE) {
				// file_put_contents($filename, serialize($resultArray));
			}
			
			// it's because of the stupid html on the side
			if ($actualDay == 5) {
				$actualDay = 1;
				$div = 4;
			} else {
				$actualDay++;
			}		
		}
		curl_close($ch);
	}
	
	// put the array in a list
	$result = ['days' => $resultArray];
	
	echo json_encode($result);
?> 
