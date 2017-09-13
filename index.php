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
 */

require 'vendor/autoload.php';
use Performance\Performance;
use Psr\Log\LogLevel;


class redirectToRule {

	//should be the original server request array
	public $request 		= null;

	//if no exact rule found we can route to defaultHost
	public $defaultHost     = null; //host line found so use this line if all else fails.

	//the rule line includes path mapping, may redirect here eventually
	public $pathRemap	 	= false;
	public $pathRemapUrl	= null;
	//the file that contains the rule set
	public $rulesFile		= 'rules.tsv';

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
		$this->log[]	= 'REQUEST: '. $this->request;
		$protocol		= (isset($request['HTTPS'])) ? 'https://' : 'http://';
		$this->request 	= $protocol.$request['HTTP_HOST'].$request['REQUEST_URI'];
		$this->request  = parse_url($this->request);

		//do nothing with these sophos scanner requests, hundreds of these are happening per second?
		if (stristr($this->request['path'],'/sophos/update/')) {
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

		//should only read the external file if we know we have a valid redirect...
		foreach ($rules as $rule) {
			//here we handle if the URL can come from 2 or more domains as in the httpd.conf example
			$has 	= strpos(trim($rule), $this->request['host']);
			$com	= strpos(trim($rule), '#');
			$rule 	= trim($rule);

			if ($has === false || $com===0) { //skip comment lines.
				//speed up the process by skipping all the tasks we don't need to check
				continue;
			}

			if ($rule[0]=='(') {
				$start 	= 1;
				$end 	= strrpos($rule,')');
				$domains = substr($rule,$start,$end-1);
				$domains = explode("|",$domains);
				//this should be the path after the (domain|domain) part
				$pair = substr($rule, $end+1);  
				//print_r($domains);
				list($path, $redirect) = explode("\t", $pair);

				//we should never reach here, cause technically we check above.
				if (!in_array($this->request['host'], $domains)) {
					continue;
				}
				//we know the host exists, in the line, so just use the host and forget the possible domain options.
				$match = parse_url('http://'.$this->request['host'].$path);
				//echo 'match:'.print_r($match, true).PHP_EOL;

			} else {
				list($path, $redirect) = explode("\t", $rule);
				$match = parse_url('http://'.$path);
			}

			if (isset($this->request['path']) && isset($match['path'])) {
				//anything thats a partial match but not at beginning of the rule is not a match
				if ((strpos(trim($this->request['path']), $match['path']) > 0)) {
					continue;
				}
			}

			$match['path'] 			= (!isset($match['path'])) ?  '/' : $match['path'];
			$match['redirect']		= $redirect;

			if (strpos($match['path'],'*')) {
				$returnedPath = $this->subpathMatch($this->request, $match, $redirect);
				if ($returnedPath) {

					if ($returnedPath===true) {
						//just empty the path since it matched exact and we don't want to route to it.
						//$this->log[] = '(returnedPath==true) Delete the matching subpath, then append remaining path.';
						$match['path'] = ''; //str_replace('*','',$match['path']);
					} else {
						//$this->log[] = 'Append the returned path.';
						$match['path'] = $returnedPath;
					}
					$match['include_path'] 	= true;

					array_unshift($this->potentials, array('rule'=>$rule,'match'=>$match,'complete'=>1));
				} else {
					//$this->log[] = 'No return path with * rule: ' .$this->request['path'] . ' == ' .$match['path'];
					$match['include_path'] = false;
					array_push($this->potentials, array('rule'=>$rule,'match'=>$match,'complete'=>0));
				}
			}
			elseif (strtolower(trim($this->request['path'],' /')) == strtolower(trim($match['path'],' /'))) {
				//$this->log[] = 'Path matches: ' .$this->request['path'] . ' == ' .$match['path'];
				$match['include_path'] = false;
				array_unshift($this->potentials, array('rule' => $rule, 'match' => $match,'complete'=>1));
			}
			elseif ($match['host'] == $this->request['host']) {
				//$this->log[] = 'Found only host: ' .$this->request['host'] . ' == ' .$match['host'];
				$match['include_path'] = false;
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

			if ($route['match']['include_path']!==false && $route['complete']==1) {
				//$this->log[] 		= 'Include_path: true   Path: '.$route['match']['path'].' Complete: true';
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
		$match['path'] 		= (isset($match['path'])) ?  str_replace('*','',$match['path']) : '/';

		//$this->log[]		= $match['path'] .' should match '.$request['path'];
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

		$logger = new Katzgrau\KLogger\Logger(__DIR__.'/logs', Psr\Log\LogLevel::INFO, array('extension'=>'log','prefix'=>'redirect_'));
		//log the results to KLogger class
		foreach($this->log as $l) {
			$logger->info($l);
		}

		if ($this->debug) 	Performance::finish();
		return true;
	}


	public function redirect() {
		if ($this->debug) 	Performance::point( 'redirect' );
		if ($this->debug)	$this->log[] = print_r($this->potentials,true);
		$this->log[] =  'REDIRECT: '.$this->redirectTo;
		$this->outputLog();

		if (!$this->debug) {
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
 */
$redirect = new redirectToRule($_SERVER, false);
$redirect->redirect();
