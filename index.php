<?php
/**
 *	REDIRECT.UCSF.EDU SCRIPT
 *
 *	Purpose of this file is to manage redirection of traffic to the new host location.
 *
 *	RULES
 *	- each line of the rules.csv file contains a possible redirect route
 *	- in some cases the redirect route might work for 2 or more domains - implemented
 *	- QUERY STRINGS should be passed along to the new route when possible
 *	- condition required where old path needs to write to new path domain-a.com/PATH > domain-b.com/PATH
 *	- more specific rules may need to live closer to the top of the file and less specific rules might need to go below
 *	- $request is the original given URL requested, $match is the first segment of the current line in the CSV file
 * @todo - need to verify $redirect includes http:// or https:// if not add it so redirect is successful.
 * @todo - need to trim last forward slash off the request and match so that the paths are always equivalent for matching
 * @todo - simplify the code, remove redundancy and start refactoring.
 * @todo - condition where anything underneath a subpath like /realestate/{*} is rewritten on the new domain.
 * @todo - there might be conditions that require re-writing of some of the query results, no support now.
 */

require 'vendor/autoload.php';
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
	public $rulesFile		= 'rules.csv';

	//if enabled show log and errors on screen, don't redirect to destination
	public $testMode		= false;
	public $log				= array();

	//final destination from rules
	public $redirectTo		= null;

	//pop or push potential routes here based on rule precident
	public $potentials		= array();

	public function __construct($request, $testmode, $rulesFile=null)
	{
		$protocol		= (isset($request['HTTPS'])) ? 'https://' : 'http://';
		$this->request 	= parse_url($protocol.$request['HTTP_HOST'].$request['REQUEST_URI']);
		$this->testMode = $testmode;

		if ($rulesFile!=null) {
			$this->rulesFile = $rulesFile;
		}

		if ($testmode) {
			$this->enableDebugging();
		}

		$this->log[]		= 'BEGIN';
		$this->log[]		= 'INCOMING URL REQUEST: '. print_r($request,true);

		$this->redirectTo 	= $this->sortRulesFile();


	}

	public function enableDebugging() {
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
				$domains = explode('|',$domains);

				$pair = substr($rule, $end+1);
				list($path, $redirect) = explode('|', $pair);

				//we should never reach here, cause technically we check above.
				if (!in_array($this->request['host'], $domains)) {
					continue;
				}
				//we know the host exists, in the line, so just use the host and forget the possible domain options.
				$match = parse_url('http://'.$this->request['host'].$path);
			} else {
				list($path, $redirect) = explode('|', $rule);
				$match = parse_url('http://'.$path);
			}

			$match['path'] = (!isset($match['path'])) ?  '/' : $match['path'];


			if ($this->request['path'] == $match['path']) {
				$this->log[] = 'Claims path matches: ' .$this->request['path'] . ' == ' .$match['path'];
				array_unshift($this->potentials, array('rule' => $rule, 'match' => $match));
			} elseif (strpos($match['path'],'*')) {
				$returnedPath = $this->subpathMatch($this->request, $match, $redirect);
				if ($returnedPath) {
					$this->log[] = 'Return path found with * rule: ' .$this->request['path'] . ' == ' .$match['path'];
					array_unshift($this->potentials, array('rule'=>$rule,'match'=>$match));
				} else {
					$this->log[] = 'No return path match with * rule: ' .$this->request['path'] . ' == ' .$match['path'];
					array_push($this->potentials, array('rule'=>$rule,'match'=>$match));
				}
			} elseif ($match['host'] == $this->request['host'] && $this->request['path'] != $match['path']) {
				$this->log[] = 'Only the host is matching: ' .$this->request['host'] . ' == ' .$match['host'];
				array_push($this->potentials, array('rule'=>$rule,'match'=>$match));
			}
		}

	}


	public function determineRoute() {

		if ($match['host'] == $this->request['host']) {
			$this->log[] 			= 'Host '.$this->request['host'].' found in this rule.';
			$this->defaultHost 		= parse_url($redirect);

			$match['query'] = (isset($this->request['query'])) ? '?'.$this->request['query'] : '';

			$this->log[] = 'PARTIAL MATCH: ' . $rule;

			$this->log[] = "Request: \n\n".print_r($this->request, true);
			$this->log[] = "Match: \n\n".print_r($match, true);

			/* NEED TO MAKE THIS WORK SOMEHOW BETTER.*/
			if (strpos($match['path'],'*')) {
				$this->log[] = 'REMAP EXISTING PATH TO NEW ONE: '.strpos($match['path'],'*');
				$this->pathRemap 	= true;

				$response = $this->getPathRemap($this->request, $match, $redirect);

				if ($response==false) {

				} else {

				}
			}

			$match['path'] = (!isset($match['path'])) ?  '/' : $match['path'];

			if (isset($this->request['path']) && ($this->request['path'] == $match['path'])) {
				$this->log[] =  'http://'.$this->request['host'].$this->request['path'] . '  ROUTES TO  ' . $redirect.$match['query'];
				$this->log[] =  'REDIRECT TO: '.$redirect.$match['query'];

				//return $redirect.$match['query'];

			} elseif ($this->pathRemap) {

			} else {
				//return $this->noExactRule();
			}



		}

	}

	/**
	 * If not exact rule is found, there some alternate routes we could try.
	 *
	 * @return string
	 */
	public function noExactRule()
	{

		if ($this->pathRemap == true) {
			$this->log[] = 'Remapping rule was found, but could not find exact match, let us redirect host and old path.';
			$this->log[] = 'http://'.$this->defaultHost['host'].$this->path_remapping_url;

			return 'http://'.$this->defaultHost['host'].$this->path_remapping_url;
		}
		elseif ($this->defaultHost !== null) {
			//either URL had no paths or we couldn't find a matching path so just redirect to root of site
			$this->log[] = 'Redirect to base path, no distinct rule was found: '. $this->defaultHost['host'];

			return 'http://'.$this->defaultHost['host'];

		} else {
			//everything else fails, maybe just go to UCSF.edu
			$this->log[] = 'No rules found, goto www.ucsf.edu'; //should probably be 404 page.

			return 'https://www.ucsf.edu';
		}

	}

	/**
	 * This function still needs work, not exactly sure how to deal with this yet.
	 *
	 * @param $request
	 * @param $match
	 * @return bool|mixed
	 */
	public function subpathMatch($request, $match, $redirect) {

		$match['path'] = (!isset($match['path'])) ?  '/' : str_replace('/*','',$match['path']);
		$this->log[] = 'Subpath match: '.$match['path'];

		if (0 === strpos($request['path'], $match['path'])) {
			// It starts with 'http'

			$request_subpath = str_replace($match['path'],'', $request['path']);

			return $request_subpath;
		} else {
			return false;
		}


	}

	public function outputLog() {
		if ($this->testMode) {
			echo '<pre>';
			echo implode("\n\n", $this->log);
			echo '</pre>';
		} else {
			$logger = new Katzgrau\KLogger\Logger(__DIR__.'/logs', Psr\Log\LogLevel::INFO, array('extension'=>'log','prefix'=>'redirect_'));

			//log the results to KLogger class
			foreach($this->log as $l) {
				$logger->info($l);
			}
		}
		return true;
	}


	public function redirect() {
		$this->log[] = 	print_r($this->potentials,true);
		$this->log[] =  'REDIRECT INITIATED: '.$this->redirectTo;
		$this->log[] =  'END';

		$this->outputLog();

		if ($this->testMode==false) {
			header('HTTP/1.1 301 Moved Permanently');
			header('Location:'.$this->redirectTo);
			exit;
		}
	}

}


/**
 * Pass $_SERVER to the class constructor, pass testmode as second arg and rules file if not default filename as third.
 *
 */
$redirect = new redirectToRule($_SERVER, true);
$redirect->redirect();