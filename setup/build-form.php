<?php
/**
 * Build the target form the way the plugin's own AI / MCP form builder does.
 *
 * This calls the registered ability `everest-forms/create-form` through the
 * plugin's ability registry. That is the same code path the MCP HTTP endpoint
 * dispatches to, so the meta keys produced here are the ones a model building a
 * form over MCP would get. The README shows the equivalent HTTP call.
 *
 * Nothing here edits a meta key by hand.
 *
 * Usage: wp eval-file setup/build-form.php
 */

wp_set_current_user( 1 );

$registry = EVF_Abilities_Registry::instance();

$result = $registry->execute(
	'everest-forms/create-form',
	array(
		'title'  => 'Contact',
		'status' => 'publish',
		'fields' => array(
			array( 'type' => 'text', 'label' => 'Full Name' ),
			array( 'type' => 'text', 'label' => 'Signature' ),
		),
	)
);

if ( is_wp_error( $result ) ) {
	echo 'CREATE_FAILED ' . $result->get_error_code() . ' ' . $result->get_error_message() . "\n";
	exit( 1 );
}

$form_id = isset( $result['form_id'] ) ? (int) $result['form_id'] : ( isset( $result['id'] ) ? (int) $result['id'] : 0 );
if ( ! $form_id ) {
	echo "NO_FORM_ID\n";
	exit( 1 );
}

// The Active toggle. One AJAX call does this in the UI; `status: publish` on the
// ability above already sets it on current builds, so this is belt and braces.
$post = get_post( $form_id );
$data = json_decode( $post->post_content, true );
if ( empty( $data['form_enabled'] ) ) {
	$data['form_enabled'] = 1;
	$had_kses = ( false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
	if ( $had_kses ) {
		kses_remove_filters();
	}
	wp_update_post(
		array(
			'ID'           => $form_id,
			'post_content' => wp_slash( wp_json_encode( $data ) ),
		)
	);
	if ( $had_kses ) {
		kses_init_filters();
	}
}

$page_id = wp_insert_post(
	array(
		'post_title'   => 'Contact',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => '[everest_form id="' . $form_id . '"]',
	)
);

$content = evf()->form->get( $form_id, array( 'content_only' => true ) );

echo "FORM_ID=" . $form_id . "\n";
echo "PAGE_ID=" . $page_id . "\n";
foreach ( (array) $content['form_fields'] as $field_id => $field ) {
	$meta = isset( $field['meta-key'] ) ? $field['meta-key'] : '(none)';
	$hit  = preg_match( '/signature_/', $meta ) ? ' <-- matched by the delete sink' : '';
	echo "FIELD " . $field_id . " type=" . $field['type'] . " label=" . $field['label'] . " meta-key=" . $meta . $hit . "\n";
}
