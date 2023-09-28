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

	public function __construct($request, $debug=false, $rulesFile=null)
	{
		if ($debug) 	Performance::point( 'contructor' );
		if ($debug) 	$this->enableDebugging();

		$protocol 				= (isset($request['HTTPS'])) ? 'https://' : 'http://';
		$this->request_string 	= $protocol.$request['HTTP_HOST'].$request['REQUEST_URI'];
		$this->request  		= parse_url($this->request_string);

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
		//should only read the external file if we know we have a valid redirect...
		foreach ($rules as $rule) {
			//here we handle if the URL can come from 2 or more domains as in the httpd.conf example
			//this just checks whether or not the string is within the line
			$has 	= strpos(trim($rule), $this->request['host']);
			$com	= strpos(trim($rule), '#');
			$rule 	= trim($rule);

			if ($has === false || $com===0) { //skip comment lines.
				//speed up the process by skipping all the tasks we don't need to check
				continue;
			}

			if ($rule[0]=='(') {
				if ($this->debug) $this->log[] = "Line contains multiple domains (domain1|domain2)".PHP_EOL;
				$start 	= 1;
				$end 	= strrpos($rule,')');
				$domains = substr($rule,$start,$end-1);
				$domains = explode("|",$domains);
				//this should be the path after the (domain|domain) part
				$pair = substr($rule, $end+1);

				list($path, $redirect) = explode("\t", $pair);

				//we need to check if the path is within the domain list
				if (!in_array($this->request['host'], $domains)) {
					continue;
				}

				//we know the host exists, in the line, so just use the host and forget the possible domain options.
				//echo 'http://'.$this->request['host'].$path.PHP_EOL;
				$match = parse_url('http://'.$this->request['host'].$path);
				//if ($this->debug) echo  'match: '.print_r($match, true).PHP_EOL;

			} else {
				list($path, $redirect) = explode("\t", $rule);

				$match = parse_url('http://'.$path);

				//need to check if the domain is in the $path
				if (strrpos($this->request['host'], $match['host'])===false) {
					if ($this->debug) $this->log[] = $this->request['host'].' !== '.$path.PHP_EOL;
					continue;
				}
			}

			//if not wildcard rule, if the paths don't match then skip it.
			if (isset($match['path']) && !strpos($match['path'],'/*')===false) {
				//check if the path after the domain is matching, if not skip.
				//echo 'MATCH PATH:  '.$match['path'].PHP_EOL;
				//echo 'REQUEST PATH:'.$this->request['path'].PHP_EOL;

				if (isset($this->request['path']) && isset($match['path'])) {
					if (!strpos(trim($this->request['path']), $match['path'] > 0)) {
						continue;
					}
				}
			}


			$match['path'] 			= (!isset($match['path'])) ?  '/' : $match['path'];
			$match['redirect']		= $redirect;

			if ($this->debug) $this->log[] =  "RULE: {$rule}";

			//match both the path and query
			if ((isset($this->request['query']) && isset($match['query']) ) && strtolower($this->request['path'].'?'.$this->request['query']) == strtolower($match['path'].'?'.$match['query'])) {
				if ($this->debug) $this->log[] = 'Path matches: ' .$this->request['path'].'?'.$this->request['query'] . ' == ' .$match['path'].'?'.$match['query'];
				$match['include_path'] = 0;
				$querymatch = true;
				array_unshift($this->potentials, array('rule' => $rule, 'match' => $match,'complete'=>1));
			}

			//match just the path
			elseif (isset($this->request['path']) && strtolower(trim($this->request['path'],' /')) == strtolower(trim($match['path'],' /'))) {
				if ($this->debug) $this->log[] = 'Path matches: ' .$this->request['path'] . ' == ' .$match['path'];
				$match['include_path'] = 0;
				$function =  ($querymatch) ? 'array_push' : 'array_unshift';
				$function($this->potentials, array('rule' => $rule, 'match' => $match,'complete'=>1));
			}

			//really match anything after the slash and redirect to equivalent path
			elseif (strpos($match['path'],'*')) {
				$returnedPath = $this->subpathMatch($this->request, $match, $redirect);
				if ($returnedPath) {
					if ($returnedPath===true) {
						//just empty the path since it matched exact and we don't want to route to it.
						if ($this->debug) $this->log[] = '(returnedPath==true) Delete the matching subpath, then append remaining path.';
						$match['path'] = ''; //str_replace('*','',$match['path']);
					} else {
						if ($this->debug) $this->log[] = 'Append the returned path.';
						$match['path'] = $returnedPath;
					}
					$match['include_path'] 	= 1;
					$function =  ($querymatch) ? 'array_push' : 'array_unshift';
					//this should get lower priority
					$function($this->potentials, array('rule'=>$rule,'match'=>$match,'complete'=>1));
				} else {
					if ($this->debug) $this->log[] = 'No return path with * rule: ' .$this->request['path'] . ' == ' .$match['path'];
					$match['include_path'] = 0;
					array_push($this->potentials, array('rule'=>$rule,'match'=>$match,'complete'=>0));
				}
			}

			//match just the host
			elseif ($match['host'] == $this->request['host']) {
				if ($this->debug) $this->log[] = 'Found only host: ' .$this->request['host'] . ' == ' .$match['host'];
				$match['include_path'] = 0;
				array_push($this->potentials, array('rule'=>$rule,'match'=>$match,'complete'=>0));
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

			if ($route['match']['include_path']!=false && $route['complete']==1) {
				if ($this->debug) $this->log[] = 'Include_path: true  Path: '.$route['match']['path'].' Complete: true';
				if ($this->debug) Performance::finish();
				return $route['match']['redirect'].$route['match']['path'];
			}
			else {
				if ($route['complete']==1) {
					if ($this->debug) Performance::finish();
					return $route['match']['redirect'];
				} else {
					$root = parse_url($route['match']['redirect']);
					if ($this->debug) Performance::finish();
					return 'http://'.$root['host'];
				}
			}
		} else {
			if ($this->debug) Performance::finish();
			return 'https://www.ucsf.edu/404';
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
			header('HTTP/1.1 301 Moved Permanently');
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


$redirect = new redirectToRule($_SERVER, false);
$redirect->redirect();
