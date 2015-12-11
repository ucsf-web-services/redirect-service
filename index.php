<?php
/**
*	REDIRECT.UCSF.EDU SCRIPT
*
*	Purpose of this file is to manage redirection of traffic to the new current server.
*	
*	RULES
*	- each line of the rules.csv file contains a possible redirect route
*	- in some cases the redirect route might work for 2 or more domains
*	- QUERY STRINGS should be passed along to the new route when possible
*	- there might be conditions that require re-writing of some of the query results
*	- condition required where old path needs to write to new path domain-a.com/PATH > domain-b.com/PATH
* 	- condition where anything underneath a subpath like /realestate/(^*) is rewriteen on the new domain to match
*	- more specific rules may need to live closer to the top of the file and less specific rules might need to go below
*	- $request is the original given URL requested, $response is the first segment of the current line in the CSV file
*/
//ini_set('display_errors',1);
//error_reporting(E_ALL);
require 'vendor/autoload.php';
use Psr\Log\LogLevel;


DEFINE('REDIRECT_LIST', 'rules.csv');
DEFINE('ECHO_LOG',		false);


$log   = array();
//$log[] =  'HOST: '			. $_SERVER['HTTP_HOST'];
//$log[] =  'PATH: ' 			. $_SERVER['REQUEST_URI'];
//$log[] =  'QUERY: ' 			. $_SERVER['QUERY_STRING'];


//parse the server URL
$protocol 	= (isset($_SERVER['HTTPS'])) ? 'https://' : 'http://';
$request 	= $protocol.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
$log[]		= 'REQUEST: '. $request;
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
        $log[] =  'Found multi-domain rule.';
    	
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
            $log[] = 'Host not found in array.';
    		continue;
    	}	

    	foreach($domains as $dom) 
    	{
			//loop and parse each domain and find a matching path.
            $log[] =  'Domain #'.$d++.' : ' . $dom;
    		$response = parse_url('http://'.$dom.$path);

			if ($response['host'] == $request['host']) {
				//host found
				$ph = parse_url($redirect);

				$response['query'] = (isset($request['query'])) ? '?'.$request['query'] : '';
				
				if (!isset($request['path']) && !isset($response['path'])) {
					$log[] =  'Location: '.$redirect.$response['query'];
					handle_log($log);	
					header("HTTP/1.1 301 Moved Permanently");
					header('location:'.$redirect.$response['query']);
					exit;
				}
				
				
				if (isset($request['path']) && $request['path'] == $response['path']) {
                    $log[] =  'http://'.$dom.$path . '  REDIRECT TO  ' . $redirect.$response['query'];
                    $log[] =  'Location: '.$redirect.$response['query'];
                    handle_log($log);
					header("HTTP/1.1 301 Moved Permanently");
					header('Location:'.$redirect.$response['query']);
                    exit;
				}
			}
    	}
    } else {
    	//handle single line string	
		$line = trim($line);
		list($path, $redirect) = explode('|', $line); 
		$response = parse_url('http://'.$path);

		if ($response['host'] == $request['host']) {
            //$log[] 			= 'Host found in rules file.';
			$ph 			= parse_url($redirect);
            
			$response['query'] = (isset($request['query'])) ? '?'.$request['query'] : '';
			
			if (!isset($request['path']) && !isset($response['path'])) {
				//just a direct URL rewrite rule.
				handle_log($log);
				header("HTTP/1.1 301 Moved Permanently");
				header('Location:'.$redirect.$response['query']);
				exit;
			}
			
			//$log[] = "Request: \n".print_r($request, true);
			//$log[] = "Redirect: \n".print_r($response, true);
			
			//make the path constant
			$response['path'] = (isset($request['path'])) ? $request['path'] : '/';
			
			if (isset($request['path']) && ($request['path'] == $response['path'])) {
				
                $log[] =  'http://'.$path . '  REDIRECT TO  ' . $redirect.$response['query'];
                $log[] =  'Location: '.$redirect.$response['query'];
                handle_log($log);
				header("HTTP/1.1 301 Moved Permanently");
				header('Location:'.$redirect.$response['query']);
                exit;
			} 
		}
    } 

}

if ($ph !== null) {
	//either URL had no paths or we couldn't find a matching path so just redirect to root of site
	$log[] = 'Redirect to root location, since no path was found.';
	handle_log($log);
	header('Location: http://'.$ph['host']);
	exit;
} else {
	$log[] = 'No where to go but up.';
	handle_log($log);
	header('Location: http://www.ucsf.edu');
	exit;
}



function handle_log($log) {
    if (ECHO_LOG) {
        echo implode("\n\n", $log);
    } else {
    	$logger = new Katzgrau\KLogger\Logger(__DIR__.'/logs', Psr\Log\LogLevel::INFO, array('extension'=>'log','prefix'=>'redirect_'));
		
    	//log the results to KLogger class
    	foreach($log as $l) {
    		$logger->info($l);
    	}
    }
    return true;
}