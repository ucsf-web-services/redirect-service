<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
echo '<pre>';
DEFINE('REDIRECT_LIST', 'rules.csv');

//what are we getting in the server
echo 'HOST: '			. $_SERVER['HTTP_HOST'] . '<br>'; 
echo 'PATH: ' 			. $_SERVER['REQUEST_URI']. '<br>'; 
echo 'QUERY: ' 		. $_SERVER['QUERY_STRING'] . '<br>'; 
//echo ' HTTP_REFERER: ' 	. $_SERVER['HTTP_REFERER']. '<br>'; 
//echo ' PATH_INFO: ' 		. $_SERVER['PATH_INFO']. '<br>'; 
echo '<br>';
//parse the server URL
$protocol = (isset($_SERVER['HTTPS'])) ? 'https://' : 'http://';
$request = parse_url($protocol.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']);
//read the rules
$externals = file(REDIRECT_LIST, FILE_IGNORE_NEW_LINES);

//store a possible host to redirect too if no specific URL path was found
$ph = null;
$i = 1;
//should only read the external file if we know we have a valid redirect...
foreach ($externals as $line) {
    echo '<br>Line #'.$i .' : '.$line;
    //here we handle if the URL can come from 2 or more domains as in the httpd.conf example
    if ($line[0]=='(') {
    	echo '<br>Oh look we found a uncommon double or tripple domain rule!';
    	
    	$line = trim($line);
    	
    	$start 	= 1;
    	$end 	= strrpos($line,')');
    	
    	$domains = substr($line,$start,$end-1);
    	$domains = explode('|',$domains);
    	echo '<br>';
    	$pair = substr($line, $end+1);
    	$pair = explode('|', $pair);
    	
    	
    	if (!in_array($request['host'], $domains)) {
    		echo 'Host was not found in line, so move onto next line.';
    		continue;
    	}	
    	$d = 1;
    	foreach($domains as $dom) 
    	{
    			
    		echo '<br>Domain #'.$d++.' : ' . $dom; 
    		$fileline = parse_url('http://'.$dom.$pair[0]);
			if (isset($request['query'])) {
				$fileline['query'] = '?'.$request['query'];	
			} else {
				$fileline['query'] = '';
			}
			echo '<br>'.print_r($fileline,true);
			if ($fileline['host'] == $request['host']) {
				//host found
				$ph = parse_url($pair[1]);
				$ph = $ph['host'];
				echo "<br>Possible host found for redirect to homepage if all else fails!<br>";
				if (isset($request['path']) && $request['path'] == $fileline['path']) {
					
					echo '<br>http://'.$dom.$pair[0] . '&nbsp;&nbsp;  &gt;&gt;&gt;    &nbsp;&nbsp;' . $pair[1].$fileline['query'];
					echo '<br>Location: '.$pair[1].$fileline['query'];
				}
			}
    	}
    } else {
    	//handle single line string	
		$line = trim($line);
		$pair = preg_split("/\|/", $line); 
		$fileline = parse_url('http://'.$pair[0]);
		if (isset($request['query'])) {
			$fileline['query'] = '?'.$request['query'];	
		} else {
			$fileline['query'] = '';
		}
		
		if ($fileline['host'] == $request['host']) {
			echo 'Host found in rules.csv<br>';
			$ph = parse_url($pair[1]);
			$ph = $ph['host'];
			
			if (isset($request['path']) && $request['path'] == $fileline['path']) {
				//header("Location: $pair[1]");
				echo '<br>http://'.$pair[0] . '&nbsp;&nbsp;   &gt;&gt;&gt;   &nbsp;&nbsp;' . $pair[1].$fileline['query'];
				echo '<br>Location: '.$pair[1].$fileline['query'];
				//exit;
			} 
			//can't find the path then we need to move onto the next loop
			//but we can save the host as a possible match to redirect to the root of the site if neccessary
		}
    } 
    $i++;
}

 echo '<br>'.$ph;