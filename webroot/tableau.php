<?php
/**
 *	REDIRECT.UCSF.EDU SCRIPT
 *
 */

require '../vendor/autoload.php';
// use Performance\Performance;
include_once('../redirectRule.php');

if (isset($_POST['url'])) {
	$redirect = new redirectToRule($_POST['url'], false);
	$redirect->redirect();
} else {
?>
<!DOCTYPE html>
<html>
<head>
<style>
	* {
	margin: 0;
	}
	html, body { 
		height: 100%;
		font-size: 14px;
	}
	.waitblock {
		position: relative;
		top: 33%;
		margin: 0 auto;
		display:  block;
		width: 20em;
		height: 250px;
		border: 0px solid grey;
		text-align: center;
		font-size: 1.3em;
		color: #a8a8a8d8;
		
		font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
	}
	.waitblock p {
		margin-top: 0.5em;
	}
	.hidden {
		display: none;
	}
	.error {
		color: #993333;
	}
</style>
</head>
<body>
<div class="waitblock">
	<img src="./images/wait.gif" width="175" height="175" alt="Waiting for process to complete." id="waiting" />
	<p id="processtext" class="">Processing Tableau redirect...</p>
</div>
<script>
	async function submitHash() {
		const hashForm = new FormData();
		hashForm.append("url", window.location.href);
		const response = await fetch("/", {
			method: "POST",
			body: hashForm
		});
		return response;
	}

	// Call start
	(async() => {
		const response = await submitHash();
		const data = await response.json();
		console.log(data);
		const hasURL = data.hasOwnProperty('url');
		if (hasURL) {
			console.log('Would redirect to: ' + data.url);
			window.location.assign(data.url);
		} else {
			const hasError = data.hasOwnProperty('error');
			const img = document.getElementById('waiting');
			const text = document.getElementById('processtext');
			img.classList.add('hidden');
			text.classList.add('error');
			//handle the error;
			text.innerHTML = data.error;
			console.log(data.error);
		}
	})();
</script>
</body>
</html>
<?php } 