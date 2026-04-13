<?php
/**
 *	REDIRECT.UCSF.EDU SCRIPT
 *
 */

require '../vendor/autoload.php';
// use Performance\Performance;
include_once('../redirectRule.php');


// https://tableau-snd.ucsf.edu/#/site/QA/views/ServiceNowMetricsTestURL/OpenTickets


if (isset($_POST['url_form'])) {
    //echo $_POST['url_form'];
	$redirect = new redirectToRule($_POST['url_form'], true);
	$response = $redirect->redirect();
    $data = json_decode($response, true);
    //print_r($data);
    if ($data['url']) {
        $url = $data['url'];
    }
}
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
		width: 100em;
		height: 333px;
		border: 0px solid grey;
		text-align: left;
		font-size: 1.0em;
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
<div class="waitblock"><p>
	<form action="testscript.php" method="post">
    <label for="name">URL:</label>
    <input type="text" id="url" name="url_form" size="155" />
    <button type="submit">Test your Results</button>
  </p>
    </form>
    <?php if (!empty($url)) {
        echo $url;
		echo $redirect->outputLog();
    } 
	
	?>
</div>
<script>
	/*
    async function submitHash() {
		const hashForm = new FormData();
		hashForm.append("url", );
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
    */
</script>
</body>
</html>