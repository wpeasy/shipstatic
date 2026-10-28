<?php
/**
 * Admin page template.
 *
 * Hosts the Svelte dashboard. The `.bs` root container scopes the base
 * framework tokens/styles; the Svelte app mounts into #bs-dashboard.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<div class="wrap">
	<div class="bs">
		<div id="bs-dashboard">
			<?php
			$shipstatic_noscript = __('ShipStatic requires JavaScript.', 'shipstatic');
			$shipstatic_entry = 'dashboard';
			require __DIR__ . '/loading.php';
			?>
		</div>
	</div>
</div>
