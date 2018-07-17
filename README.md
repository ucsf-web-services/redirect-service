# REDIRECT SERVER SCRIPT #
 
Purpose of this file is to manage redirection of traffic to the new host location.

## RULES ##
* Each line of the rules.csv file contains a redirect route.  The original path is on the left, seperated by a pipe and then followed by the redirect path.   The application tries exact matches before attempting wild cards etc.

* The original path SHOULD NOT contain http:// or https:// but just the domain name and any path information.

* You can redirect the same path from different servers by using parentheses and pipe to seperate:
 (domain1.com|domain2.com), this was logic we needed to support cause the old Apache redirect rules did the same thing.

* Condition required where old path needs to write to new path domain-a.com/PATH > domain-b.com/PATH you may use a astericks (\*) to have it route to the equivalent path on the new domain.
 
* More specific rules may need to live closer to the top of the file and less specific rules might need to go below.  Future versions of the script may address this by supporting SQLlite to organize and re-order rules.

* You should always trim the last forward slash off the request and match so that the paths are always equivalent for matching.

* All redirect requests are logged for review.

* QUERY STRINGS should be passed along to the new route when possible, however we are not doing that now.
	
@todo - there might be conditions that require re-writing of some of the query results, no support now.
 
@todo - need to ensure LOG files are writable, either setting them here or messaging there is a problem when not writable.
 
@todo - we need to add HTTPS/SSL support to the redirect.ucsf.edu server so we can handle redirects that originally came form a secure link.

__Help contribute to this project__

* https://github.com/ucsf-web-services/redirect-service
* https://github.com/ucsf-web-services/redirect-service.git
