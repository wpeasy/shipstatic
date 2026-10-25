<?php
/**
 * Connector: the destination's web server refused a request as too large.
 *
 * @package WPEasy\ShipStatic
 * @since   2.3.0
 */

declare(strict_types=1);

namespace WPEasy\ShipStatic\Deploy;

defined('ABSPATH') || exit;

/**
 * HTTP 413 from the web server in front of the receiver (nginx's
 * client_max_body_size is 1 MB by default) — PHP never saw the request.
 * ConnectorClient::upload() retries with smaller chunks before giving up.
 */
final class ConnectorTooLargeException extends \RuntimeException {
}
