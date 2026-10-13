<?php
/**
 * Dev stack only: the browser reaches the sites at http://localhost:8090
 * (staging) and http://localhost:8091 (production), but those addresses mean
 * nothing inside the containers. Point Silta's server-to-server requests at
 * the Compose service names, and WP-Cron's loopback at Apache's real port.
 *
 * @package Jcore\Silta\Dev
 */

// Public URL => the same site inside the Docker network.
const JCORE_SILTA_DEV_INTERNAL_URLS = array(
	'http://localhost:8090' => 'http://staging',
	'http://localhost:8091' => 'http://production',
);

/*
 * Every URL Silta requests on a remote (pairing, handshake, export, media
 * chunks...) passes through this filter. The pairing string and connections
 * keep the public URL; only the request goes to the container.
 */
add_filter(
	'jcore_silta_remote_url',
	static function ( string $url ): string {
		return strtr( $url, JCORE_SILTA_DEV_INTERNAL_URLS );
	}
);

add_filter(
	'cron_request',
	static function ( array $request ): array {
		$request['url'] = str_replace( array_keys( JCORE_SILTA_DEV_INTERNAL_URLS ), 'http://localhost', $request['url'] );
		return $request;
	}
);
