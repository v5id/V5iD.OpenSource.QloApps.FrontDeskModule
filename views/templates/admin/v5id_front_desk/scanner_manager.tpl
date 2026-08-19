<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{l s='V5iD Scanner Manager' mod='v5idfrontdesk'}</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="{$cssUrl}">
</head>
<body>
<div class="v5sm-shell">
	<header class="v5sm-header">
		<h1>{l s='V5iD Scanner Manager' mod='v5idfrontdesk'}</h1>
		<p class="v5sm-hint">{l s='Keep this tab open for the shift. It holds the connection to your scanner(s) and forwards every scan to your Front Desk tabs, even while you navigate around in them.' mod='v5idfrontdesk'}</p>
	</header>

	<div id="v5idfrontdesk-scanner-manager" class="v5sm-list"></div>
</div>

<script src="{$registryJsUrl}"></script>
{foreach from=$adapterJsUrls item=adapterJsUrl}
	<script src="{$adapterJsUrl}"></script>
{/foreach}
<script src="{$channelJsUrl}"></script>
<script src="{$managerAppJsUrl}"></script>
</body>
</html>
