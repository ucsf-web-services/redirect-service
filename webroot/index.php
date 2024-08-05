<?php
/**
 *	REDIRECT.UCSF.EDU SCRIPT
 *
 *	Purpose of this file is to manage redirection of traffic to the new host location.
 *
 *	RULES
 *	- each line of the rules.csv file contains a possible redirect route
 *	- Redirect route might work for 2 or more domains - use parentheses and pipe to seperate (domain1.com|domain2.com)
 *	- QUERY STRINGS should be passed along to the new route when possible
 *	- condition required where old path needs to write to new path domain-a.com/PATH > domain-b.com/PATH
 *	- more specific rules may need to live closer to the top of the file and less specific rules might need to go below
 *	- $request is the original given URL requested, $match is the first segment of the current line in the CSV file
 * @todo - need to verify $redirect includes http:// or https:// if not add it so redirect is successful.
 * @done - need to trim last forward slash off the request and match so that the paths are always equivalent for matching
 * @done - condition where anything underneath a subpath like /realestate/{*} is rewritten on the new domain.
 * @todo - there might be conditions that require re-writing of some of the query results, no support now.
 * @todo - add SQLlite support for faster lookup and retrival of requests.  Maybe cache most requested sites.
 * @todo - need to ensure LOG files are writable, either setting them here or messaging there is a problem when not writable.
 * @todo - need to improve sorting function to get the path closest to the original request path, SQLlite would help here maybe.
 * @todo - this whole thing is do for a rewrite and audit now, recommend integrating into a single service with tiny.ucsf.edu
 */

require '../vendor/autoload.php';
use Performance\Performance;
use Psr\Log\LogLevel;


class redirectToRule {

	//should be the original server request array
	public $request 		= null;
	public $request_string 	= '';
	//if no exact rule found we can route to defaultHost
	public $defaultHost     = null; //host line found so use this line if all else fails.

	//the rule line includes path mapping, may redirect here eventually
	public $pathRemap	 	= false;
	public $pathRemapUrl	= null;
	//the file that contains the rule set
	public $rulesFile		= '../rules.tsv';

	//if enabled show log and errors on screen, don't redirect to destination
	public $debug			= false;
	public $log				= array();

	//final destination from rules
	public $redirectTo		= null;

	//pop or push potential routes here based on rule precident
	public $potentials		= array();

	public $is_docksal 		= false;

	public function __construct($request, $debug=false, $rulesFile=null)
	{
		$this->is_docksal		= getenv('IS_DOCKSAL') ? true : false;
		if ($this->is_docksal) {
			$request['HTTP_HOST'] = 'makeagift.ucsf.edu';
		}
		
		$this->request_string 	= 'http://'.$request['HTTP_HOST'].$request['REQUEST_URI'];
		if (stripos($this->request_string, '&debug')) {
			$this->request_string = str_replace('&debug', '', $this->request_string);
			Performance::point( 'contructor' );
			$this->enableDebugging();
		}
		$this->request  		= parse_url($this->request_string);
	
		if ($debug) {
			Performance::point( 'contructor' );
			$this->enableDebugging();
			if ($this->is_docksal) 	echo "THIS IS DOCKSAL!";
		}

		if ($request['HTTP_HOST']=='makeagift.ucsf.edu') {
			$this->rulesFile = '../makeagift_rules.tsv';
		}

		if ($this->debug) echo '<pre>';
		if ($this->debug) echo 'Request Array: ' . print_r($this->request, true);
		//do nothing with these sophos scanner requests, hundreds of these are happening per second?
		if (isset($this->request['path']) && stristr($this->request['path'],'/sophos/update/')) {
			exit();
		}

		if ($rulesFile!=null) {
			$this->rulesFile = $rulesFile;
		}

		$this->sortRulesFile();
		$this->redirectTo = $this->determineRoute();
		if ($debug) Performance::finish();
	}

	public function enableDebugging() {
		$this->debug = true;
		ini_set('display_errors',1);
		error_reporting(E_ALL);
	}

	/**
	 * This is the bulk of the class right now.
	 *
	 *
	 * @return string
	 *
	 */
	public function sortRulesFile() {
		if ($this->debug) Performance::point( 'sort rules' );

		$rules 	= file($this->rulesFile, FILE_IGNORE_NEW_LINES);
		$querymatch = false;
		//print_r($this->request);

		//should only read the external file if we know we have a valid redirect...
		foreach ($rules as $line) {
			//here we handle if the URL can come from 2 or more domains as in the httpd.conf example
			//this just checks whether or not the string is within the line
			list($originpath, $redirect) = explode("\t", $line);
			$has 	= strpos(trim($originpath), $this->request['host']);
			$comment	= strpos(trim($line), '#');
			$line 	= trim($line);

			if ($has === false || $comment===0) { //skip comment lines.
				//speed up the process by skipping all the tasks we don't need to check
				continue;
			}

			if ($line[0]=='(') {
				if ($this->debug) $this->log[] = "[$line] Line contains multiple domains (domain1|domain2)".PHP_EOL;
				$start 	= 1;
				$end 	= strrpos($line,')');
				$domains = substr($line,$start,$end-1);
				$domains = explode("|",$domains);
				//this should be the path after the (domain|domain) part
				$pair = substr($line, $end+1);

				//we need to check if the path is within the domain list
				if (!in_array($this->request['host'], $domains)) {
					continue;
				}

				//we know the host exists, in the line, so just use the host and forget the possible domain options.
				//echo 'http://'.$this->request['host'].$pair.PHP_EOL;
				$rule = parse_url('http://'.$this->request['host'].$pair);
				if (isset($rule['query'])) {
					//comma's and parens are allowed [',','(',')'] ['%2C','%28','%29']
					$rule['query'] = str_replace([' ',"'"],['%20','%27'], $rule['query']);
				}
				$domain = $this->request['host'];

			} else {
				list($originpath, $redirect) = explode("\t", $line);
				
				$rule = parse_url('http://'.$originpath);
				if (isset($rule['query'])) {
					//comma's and parens are allowed [',','(',')'] ['%2C','%28','%29']
					$rule['query'] = str_replace([' ',"'"],['%20','%27'], $rule['query']);
				}
			
				//need to check if the domain is in the $path
				if (strrpos($this->request['host'], $rule['host'])===false) {
					if ($this->debug) $this->log[] = $this->request['host'].' !== '.$originpath.PHP_EOL;
					continue;
				}
			}

			$rule['path'] 			= (!isset($rule['path'])) ?  '/' : $rule['path'];
			$rule['query']			= (!isset($rule['query'])) ? ''	: $rule['query'];
			$rule['redirect']		= $redirect;
		
			//match both the path and query
			if (
				(isset($this->request['query']) && isset($rule['query'])) 
				&& strtolower($this->request['path'].'?'.$this->request['query']) == strtolower($rule['path'].'?'.strtolower($rule['query']))
				) {
				// this means include the old path from the original string, not the redirect, only used in wildcards.
				$rule['include_path'] = 0;
				$rule['RULE_NAME'] = 'QUERY_AND_PATH_MATCH';
				$querymatch = true;
				array_unshift($this->potentials, array('line' => $line, 'rule' => $rule,'complete'=>1));
			}
			elseif (isset($this->request['query']) && (stripos($rule['query'], 'rule_trigger_api_or_a1') > 0) &&
			(stripos($this->request['query'],'api_rd')>0)) {
				echo $path = strtolower($this->request['query']);
				if (strpos($path,'api_rd')>0) {
					$rule['redirect'] ='https://donate.ucsfbenioffchildrens.org';
				} else {
					$rule['redirect'] ='https://giving.ucsf.edu';
				}
				$rule['include_path'] = 0;
				$rule['RULE_NAME'] = 'RULE_TRIGGER_API_OR_A1';
				$function =  ($querymatch) ? 'array_push' : 'array_unshift';
				
				$function($this->potentials, array('line'=>$line,'rule'=>$rule,'complete'=>1));
			}
			//match just the path
			elseif (isset($this->request['path']) && strtolower(trim($this->request['path'],' /')) == strtolower(trim($rule['path'],' /'))) {
				//if ($this->debug) $this->log[] = 'Path matches: ' .$this->request['path'] . ' == ' .$rule['path'];
				$rule['include_path'] = 0;
				$rule['RULE_NAME'] = 'PATH_MATCH';
				$function =  ($querymatch) ? 'array_push' : 'array_unshift';
				$function($this->potentials, array('line' => $line, 'rule' => $rule,'complete'=>1));
			}
			

			//really match anything after the slash and redirect to equivalent path
			elseif (strpos($rule['path'],'*')) {
				$returnedPath = $this->subpathMatch($this->request, $rule, $redirect);
				if ($returnedPath) {
					if ($returnedPath===true) {
						if ($this->debug) $this->log[] = 'Delete the matching subpath, then append remaining path.';
						$rule['path'] = '';
					} else {
						if ($this->debug) $this->log[] = 'Append the returned path.';
						$rule['path'] = $returnedPath;
					}
					// this means include the old path from the original string, not the redirect, only used in wildcards.
					$rule['include_path'] 	= 1;
					$rule['RULE_NAME'] = 'WILDCARD_PATH_REDIRECT';
					$function =  ($querymatch) ? 'array_push' : 'array_unshift';
					//this should get lower priority
					$function($this->potentials, array('line'=>$line,'rule'=>$rule,'complete'=>1));
				} else {
					if ($this->debug) $this->log[] = 'No return path with * rule: ' .$this->request['path'] . ' == ' .$rule['path'];
					$rule['include_path'] = 0;
					$rule['RULE_NAME'] = 'WILDCARD_REDIRECT_INCONCLUSIVE';
					array_push($this->potentials, array('line'=>$line,'rule'=>$rule,'complete'=>0));
				}
			}
		
			elseif ($rule['host'] == $this->request['host']) {
				$rule['include_path'] = 0;
				$rule['RULE_NAME'] = 'ONLY_HOST_MATCHES';
				array_push($this->potentials, array('line'=>$line,'rule'=>$rule,'complete'=>0));
			}

			//if not wildcard rule, if the paths don't match then skip it.
			if (isset($rule['path']) && !strpos($rule['path'],'/*')===false) {
				//check if the path after the domain is matching, if not skip.
				//echo 'MATCH PATH:  '.$match['path'].PHP_EOL;
				//echo 'REQUEST PATH:'.$this->request['path'].PHP_EOL;
				if (isset($this->request['path']) && isset($rule['path'])) {
					if (!strpos(trim($this->request['path']), $rule['path'] > 0)) {
						continue;
					}
				}
			}
		}
		if ($this->debug) Performance::finish();
	}

	/**
	 * All of the rules are sorted now, only 1st rule matters as it is the best option.
	 *
	 * @return string
	 */
	public function determineRoute() {
		if ($this->debug) Performance::point('determine routes');

		if (isset($this->potentials[0])) {

			$route = $this->potentials[0];

			if ($route['rule']['include_path']!=false && $route['complete']==1) {
				if ($this->debug) $this->log[] = 'Include_path: true  Path: '.$route['rule']['path'].' Complete: true';
				if ($this->debug) Performance::finish();
				return $route['rule']['redirect'].$route['rule']['path'];
			}
			else {
				if ($route['complete']==1) {
					if ($this->debug) Performance::finish();
					return $route['rule']['redirect'];
				} else {
					$root = parse_url($route['rule']['redirect']);
					if ($this->debug) Performance::finish();
					return 'http://'.$root['host'];
				}
			}
		} else {
			if ($this->debug) Performance::finish();
			header('HTTP/1.1 404 Not Found');
			echo "Could not find redirect path for given domain.";
			die();
		}
	}

	/**
	 *  Partially or fully match the path, returning either the new subpath or true or false on
	 *	other conditions.
	 *
	 * @param $request
	 * @param $match
	 * @return bool|mixed
	 */
	public function subpathMatch($request, $match, $redirect) {
		if ($this->debug) Performance::point( 'match routes' );
		$match['path'] = (isset($match['path'])) ?  str_replace('*','',$match['path']) : '/';

		if ($this->debug) $this->log[] = $match['path'] .' should match '.$request['path'];
		if (0 === strpos($request['path'], $match['path'])) {
			if (strlen($match['path'])>1) {
				$request_subpath = str_replace($match['path'],'', $request['path']);
			} else {
				$request_subpath = $request['path'];
			}

			//if empty path matches exactly, return true.
			if (empty($request_subpath)) {
				if ($this->debug) Performance::finish();
				return true;
			}
			if (strpos($request_subpath, $match['path'])!==0) $request_subpath = '/'.$request_subpath;

			if ($this->debug) Performance::finish();
			return $request_subpath;
		} else {
			if ($this->debug) Performance::finish();
			return false;
		}
	}

	public function outputLog() {

		if ($this->debug) {
			Performance::point( 'logger' );
			echo '<pre>';
			echo implode("\n\n", $this->log);
			echo '</pre>';
		}
		else {
			$logger = new Katzgrau\KLogger\Logger(dirname(__DIR__).'/logs', LogLevel::INFO, array('extension'=>'log','prefix'=>'redirect_'));
			//log the results to KLogger class
			foreach($this->log as $l) {
				$logger->info($l);
			}
		}

		if ($this->debug) 	Performance::finish();
		if ($this->debug) 	echo '</pre>';
		return true;
	}


	public function redirect() {
		if ($this->debug)  Performance::point( 'redirect' );
		if ($this->debug)  $this->log[] = print_r($this->potentials,true);
		if ($this->debug)  $this->log[] =  'REDIRECT: '.$this->request_string.' TO: '.$this->redirectTo;
		if ($this->debug)  $this->outputLog();

		if (!$this->debug) {
			/**
			 * @todo - Cache control on the day of release
			 * If the redirect file is updated and the script runs then start sending out
			 * header('Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0');
			 *
			 */
		
			//header('HTTP/1.1 301 Moved Permanently');
			header('Cache-Control: no-store, no-cache, must-revalidate');
			header('Location:'.$this->redirectTo);
			exit;
		} else {
			Performance::finish();
			Performance::results();
		}
	}
}


/**
 * Pass $_SERVER to the class constructor, pass testmode as second arg and rules
 * file if not default filename as third.
 *
 *
$_SERVER = array();
$_SERVER['HTTP_HOST'] = 'oaais.ucsf.edu';
$_SERVER['HTTPS'] = true;
$_SERVER['REQUEST_URI']='/students/student_email/287-DSY/spam/g1/966-DSY.html';
 */
/* 
$url = "http://";
$url .= "makeagift.ucsf.edu/site/SPageServer?pagename=API_RD_CHFSGivingForm&Other=Creative+Arts+Fund+(B2681)&utm_source=creative_arts&utm_medium=sharelink&utm_campaign=childlife";
$_SERVER = array();

$_SERVER['HTTPS'] = false;
$_SERVER['HTTP_HOST'] = parse_url($url, PHP_URL_HOST);
$_SERVER['REQUEST_URI']= parse_url($url, PHP_URL_PATH);
 */
$redirect = new redirectToRule($_SERVER, false);
$redirect->redirect();