<?php
/**
 * Documentation page template.
 *
 * Hosts the Svelte docs app. The `.bs` root container scopes the base framework
 * tokens/styles; the app mounts into #bs-docs.
 *
 * @package WPEasy\ShipStatic
 * @since   0.0.1
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<div class="wrap">
	<div class="bs">
		<div id="bs-docs">
			<?php
			$shipstatic_noscript = __('ShipStatic documentation requires JavaScript.', 'shipstatic');
			$shipstatic_entry = 'docs';
			require __DIR__ . '/loading.php';
			?>
		</div>
	</div>
</div>
