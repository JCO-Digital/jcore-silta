<?php
/**
 * Dev stack only: Gravity Forms content, when Gravity Forms is installed.
 *
 * Runs after the diverge scripts, so the post IDs the integration tests rely
 * on are the same with and without Gravity Forms.
 *
 * On staging it creates two forms and two posts that embed them:
 * - "Yhteydenotto" (contact): text, email, textarea and an HTML field with an
 *   absolute link, a confirmation that redirects to the Yhteystiedot page and
 *   a notification. Embedded in the page "Ota yhteyttä" with the
 *   `gravityforms/form` block.
 * - "Uutiskirje" (newsletter): an email field. Embedded in the post
 *   "Tilaa uutiskirje" with the `[gravityform]` shortcode.
 *
 * On production it creates "Tuotannon palaute", a form staging doesn't have,
 * so staging's forms get other IDs when they are synced there.
 *
 * IDs are stored in the `silta_dev_gf_seed` option.
 *
 *   docker compose run --rm cli-staging wp eval-file /seed/gravityforms.php staging --user=admin
 *   docker compose run --rm cli-production wp eval-file /seed/gravityforms.php production --user=admin
 *
 * @package Jcore\Silta\Dev
 */

// Run with `wp eval-file`, never loaded by the plugin: these are not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if ( ! class_exists( 'GFAPI' ) ) {
	WP_CLI::log( 'Gravity Forms is not active, skipping.' );
	return;
}
if ( get_option( 'silta_dev_gf_seed' ) ) {
	WP_CLI::log( 'Gravity Forms content already seeded, skipping.' );
	return;
}

require_once __DIR__ . '/helpers.php';

$site = $args[0] ?? 'staging';
$home = home_url();
$seed = array(
	'forms' => array(),
	'posts' => array(),
);

/**
 * Creates a form and fails loudly.
 *
 * @param array $form Form.
 * @return int Form ID.
 */
function silta_seed_form( array $form ): int {
	$id = GFAPI::add_form( $form );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( "Could not create form '{$form['title']}': " . $id->get_error_message() );
	}

	return (int) $id;
}

if ( 'production' === $site ) {
	$seed['forms']['feedback'] = silta_seed_form(
		array(
			'title'  => 'Tuotannon palaute',
			'fields' => array(
				array(
					'id'    => 1,
					'type'  => 'textarea',
					'label' => 'Palaute',
				),
			),
		)
	);
} else {
	$dev_seed = (array) get_option( 'silta_dev_seed', array() );
	$contact  = (int) ( $dev_seed['posts']['contact_fi'] ?? 0 );

	$seed['forms']['contact'] = silta_seed_form(
		array(
			'title'          => 'Yhteydenotto',
			'description'    => 'Kerro, miten voimme auttaa.',
			'labelPlacement' => 'top_label',
			'button'         => array(
				'type' => 'text',
				'text' => 'Lähetä',
			),
			'fields'         => array(
				array(
					'id'         => 1,
					'type'       => 'text',
					'label'      => 'Nimi',
					'isRequired' => true,
				),
				array(
					'id'         => 2,
					'type'       => 'email',
					'label'      => 'Sähköposti',
					'isRequired' => true,
				),
				array(
					'id'    => 3,
					'type'  => 'textarea',
					'label' => 'Viesti',
				),
				array(
					'id'      => 4,
					'type'    => 'html',
					'label'   => 'Tietosuoja',
					'content' => '<p>Lue <a href="' . esc_url( $home . '/tietosuoja/' ) . '">tietosuojaseloste</a>.</p>',
				),
			),
			'confirmations'  => array(
				'5f0000000001' => array(
					'id'        => '5f0000000001',
					'name'      => 'Oletusvahvistus',
					'isDefault' => true,
					'type'      => 'page',
					'pageId'    => $contact,
					'message'   => '',
				),
			),
			'notifications'  => array(
				'5f0000000002' => array(
					'id'       => '5f0000000002',
					'name'     => 'Ylläpitäjälle',
					'isActive' => true,
					'to'       => '{admin_email}',
					'subject'  => 'Uusi yhteydenotto',
					'message'  => '{all_fields}<p>Lähetetty sivustolta ' . esc_url( $home ) . '</p>',
					'event'    => 'form_submission',
				),
			),
		)
	);

	$seed['forms']['newsletter'] = silta_seed_form(
		array(
			'title'  => 'Uutiskirje',
			'fields' => array(
				array(
					'id'         => 1,
					'type'       => 'email',
					'label'      => 'Sähköposti',
					'isRequired' => true,
				),
			),
		)
	);

	$seed['posts']['form_page'] = silta_seed_post(
		array(
			'post_type'    => 'page',
			'post_title'   => 'Ota yhteyttä',
			'post_name'    => 'ota-yhteytta',
			'post_content' => silta_seed_blocks(
				silta_seed_paragraph( 'Jätä viesti, niin palaamme asiaan.' ),
				silta_seed_block(
					'gravityforms/form',
					array(
						'formId' => (string) $seed['forms']['contact'],
						'title'  => false,
					)
				)
			),
		)
	);

	$seed['posts']['form_post'] = silta_seed_post(
		array(
			'post_title'   => 'Tilaa uutiskirje',
			'post_name'    => 'tilaa-uutiskirje',
			'post_content' => silta_seed_blocks(
				silta_seed_paragraph( 'Saat uutiset suoraan sähköpostiisi.' ),
				"<!-- wp:shortcode -->\n[gravityform id=\"{$seed['forms']['newsletter']}\" title=\"false\" ajax=\"true\"]\n<!-- /wp:shortcode -->"
			),
		)
	);
}

update_option( 'silta_dev_gf_seed', $seed, false );

WP_CLI::success(
	'Gravity Forms: ' . implode( ', ', array_map( static fn( $k, $v ) => "form $k=$v", array_keys( $seed['forms'] ), $seed['forms'] ) )
	. ( empty( $seed['posts'] ) ? '' : '; ' . implode( ', ', array_map( static fn( $k, $v ) => "$k=$v", array_keys( $seed['posts'] ), $seed['posts'] ) ) )
);
