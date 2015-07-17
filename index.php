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
			
			$j = 0;
			foreach ($elements as $element) {
				$meals[$j] = formatString(htmlentities($element->nodeValue));
				$j++;
			}
			
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
		$xPathQueryAdditives = "//td[@class='label1']/div[contains(@style, 'font-size:10px') and contains(@style, 'text-align:right')]/a[@class='external-link-new-window'] | //td[@class='label2']/div[contains(@style, 'font-size:10px') and contains(@style, 'text-align:right')]/a[@class='external-link-new-window'] | //td[@class='label3']/div[contains(@style, 'font-size:10px') and contains(@style, 'text-align:right')]/a[@class='external-link-new-window'] | //td[@class='label4']/div[contains(@style, 'font-size:10px') and contains(@style, 'text-align:right')]/a[@class='external-link-new-window']";
		$meals = $xpath->query($xPathQueryMeals);
		$additives = $xpath->query($xPathQueryAdditives);
		
		// Combine dates and corresponding meals
		$mealsPerDay = 4;
		$j = 1;
		
		$mealsArray = array();
		foreach ($dates as $date) {
			for ($i = 1; $i <= $mealsPerDay; $i++) {
				// Sanitize string
				$cleanString = formatString(htmlentities($meals->item($j + $i - 2)->nodeValue, ENT_COMPAT));
				$cleanAdditivesString = formatString(htmlentities($additives->item($j + $i - 2)->nodeValue, ENT_COMPAT));
				if (strlen($cleanString) > 0) {
					$mealsArray[$i - 1] = ['mealNumber' => $i, 'name' => $cleanString, 'symbols' => array(), 'additives' => array(), 'allergens' => array()];
				}
			}
			
			$j += $mealsPerDay;
			
			// Add this days' meals to resultArray set
			$resultArray[] = ['date' => $date, 'meals' => $mealsArray];
			
			// persist for the next time to save traffic
			if ($UP_TO_DATE) {
				// file_put_contents($filename, serialize($resultArray));
			}
			
			unset($allMealsPerDay);
		}
		curl_close($ch);
	}
	
	// put the array in a list
	$result = ['days' => $resultArray];
	
	echo json_encode($result);
?> 
