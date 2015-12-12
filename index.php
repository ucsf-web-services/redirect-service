<?php
/**
*	REDIRECT.UCSF.EDU SCRIPT
*	
*	Purpose of this file is to manage redirection of traffic to the new current server.
*	
*	RULES
*	- each line of the rules.csv file contains a possible redirect route
*	- in some cases the redirect route might work for 2 or more domains - implemented
*	- QUERY STRINGS should be passed along to the new route when possible
*	- @todo - there might be conditions that require re-writing of some of the query results
*	- condition required where old path needs to write to new path domain-a.com/PATH > domain-b.com/PATH
* 	- @todo - condition where anything underneath a subpath like /realestate/(^*) is rewritten on the new domain to match
*	- more specific rules may need to live closer to the top of the file and less specific rules might need to go below
*	- $request is the original given URL requested, $match is the first segment of the current line in the CSV file
*/
//ini_set('display_errors',1);
//error_reporting(E_ALL);

require 'vendor/autoload.php';
use Psr\Log\LogLevel;


DEFINE('REDIRECT_LIST', 'rules.csv');
DEFINE('ECHO_LOG',	false);


$log   = array();
//$log[] =  'HOST: '			. $_SERVER['HTTP_HOST'];
//$log[] =  'PATH: ' 			. $_SERVER['REQUEST_URI'];
//$log[] =  'QUERY: ' 			. $_SERVER['QUERY_STRING'];


//parse the server URL
$protocol 	= (isset($_SERVER['HTTPS'])) ? 'https://' : 'http://';
$request 	= $protocol.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
$log[]		= 'BEGIN';
$log[]		= 'INCOMING URL REQUEST: '. $request;
$request 	= parse_url($request);

//read the rules
$externals = file(REDIRECT_LIST, FILE_IGNORE_NEW_LINES);

//store a possible host to redirect too if no specific URL path was found
$ph     = null; //host line found so use this line if all else fails.
$i      = 1;

//should only read the external file if we know we have a valid redirect...
foreach ($externals as $line) {
    //$log[] =  'Line #'.$i++.' : '.$line;
    //here we handle if the URL can come from 2 or more domains as in the httpd.conf example
    $has 	= strpos(trim($line), $request['host']);
	$com	= strpos(trim($line), '#');

	if ($has === false || $com===0) { //skip comment lines.
        //speed up the process by skipping all the tasks we don't need to check
        continue;
    }

    if ($line[0]=='(') {
        $log[] =  'ATTENTION: MULTI-DOMAIN RULE';
    	
    	$line = trim($line);
    	
    	$start 	= 1;
    	$end 	= strrpos($line,')');
    	
    	$domains = substr($line,$start,$end-1);
    	$domains = explode('|',$domains);

    	$pair = substr($line, $end+1);
    	list($path, $redirect) = explode('|', $pair);
		$d = 1; //domain counter

		//we should never reach here, cause technically we check above.
    	if (!in_array($request['host'], $domains)) {
            $log[] = 'Host not found in multi-domain string.';
    		continue;
    	}	

    	foreach($domains as $dom) 
    	{
			//loop and parse each domain and find a matching path.
            $log[] = 'Domain #'.$d++.' : ' . $dom;
    		$match = parse_url('http://'.$dom.$path);

			if ($match['host'] == $request['host']) {
				//host found
				$ph = parse_url($redirect);

				$match['query'] = 	(isset($request['query'])) ? '?'.$request['query'] : '';
				$match['path'] 	= 	(!isset($match['path'])) ?  '/' : $match['path'];
				
				if (isset($request['path']) && $request['path'] == $match['path']) {
                    $log[] = 'http://'.$dom.$path.$match['query'] . '  REDIRECT TO  ' . $redirect.$match['query'];
                    $log[] = 'LOCATION: '.$redirect.$match['query'];
                    $log[] = 'END';
                    handle_log($log);
					header('HTTP/1.1 301 Moved Permanently');
					header('Location:'.$redirect.$response['query']);
                    exit;
				}
			}
    	}
    } else {
    	//handle single line string	
		$line = trim($line);
		list($path, $redirect) = explode('|', $line); 
		$match = parse_url('http://'.$path);

		if ($match['host'] == $request['host']) {
            $log[] 		= 'Host '.$request['host'].' found in rules file.';
			$ph 		= parse_url($redirect);
            
			$match['query'] = (isset($request['query'])) ? '?'.$request['query'] : '';
			
		
			//print_r($request);
			//$log[] = "Request: \n".print_r($request, true);
			//$log[] = "Redirect: \n".print_r($response, true);
			
			//make the path constant
			$match['path'] = (!isset($match['path'])) ?  '/' : $match['path'];
			//print_r($match);
			if (isset($request['path']) && ($request['path'] == $match['path'])) {
				
                $log[] =  'http://'.$path . '  REDIRECT TO  ' . $redirect.$match['query'];
                $log[] =  'LOCATION: '.$redirect.$match['query'];
            	$log[] =  'END';
                handle_log($log);
				header('HTTP/1.1 301 Moved Permanently');
				header('Location:'.$redirect.$match['query']);
                exit;
			} 
		}
    } 

}

if ($ph !== null) {
	//either URL had no paths or we couldn't find a matching path so just redirect to root of site
	$log[] = 'Redirect to root location, since no path was found: '. $ph['host'];
	$log[] =  'END';
	handle_log($log);
	//header('Location: http://'.$ph['host']);
	exit;
} else {
	$log[] = 'WHOOPS: Cannot find destination URL, just goto www.ucsf.edu.';
	$log[] = 'END';
	handle_log($log);
	header('Location: http://www.ucsf.edu');
	exit;
}



function handle_log($log) {
    if (ECHO_LOG) {
        echo '<pre>';
        echo implode("\n\n", $log);
        echo '</pre>';
    } else {
    	$logger = new Katzgrau\KLogger\Logger(__DIR__.'/logs', Psr\Log\LogLevel::INFO, array('extension'=>'log','prefix'=>'redirect_'));
		
    	//log the results to KLogger class
    	foreach($log as $l) {
    		$logger->info($l);
    	}
    }
    return true;
}