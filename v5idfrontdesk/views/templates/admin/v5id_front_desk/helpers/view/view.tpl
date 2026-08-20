{if isset($hotels) && $hotels}
	<div id="v5idfrontdesk-app" class="v5idfd">
		<div class="v5idfd-boot">
			<i class="icon-spinner icon-spin"></i> {l s='Loading front desk…' mod='v5idfrontdesk'}
		</div>
	</div>
{else}
	<div class="panel">
		<div class="panel-heading">
			<i class="icon-warning"></i> {l s='No hotels available' mod='v5idfrontdesk'}
		</div>
		<div class="alert alert-warning">
			<p>{l s='No hotel is currently accessible to your account.' mod='v5idfrontdesk'}</p>
		</div>
	</div>
{/if}
