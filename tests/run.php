<?php
/**
 * Standalone tests for the pure string/regex logic in class-page-builders.php.
 *
 * No WordPress and no PHPUnit required: the handful of WordPress functions the
 * tested helpers call are stubbed below, and private methods are reached with
 * reflection. Run from the repo root with:
 *
 *   php tests/run.php
 */

error_reporting( E_ALL & ~E_DEPRECATED );

// Minimal WordPress stubs used by the helpers under test.
define( 'ABSPATH', __DIR__ );
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; }
function wp_slash( $s ) { return $s; }
function wp_json_encode( $d ) { return json_encode( $d ); }

class WP_Post {
	public $ID = 1;
	public $post_content = '';
	public function __construct( $content = '' ) { $this->post_content = $content; }
}

require __DIR__ . '/../linkerflow/includes/class-page-builders.php';

// --- Tiny test harness --------------------------------------------------------

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function check( $name, $got, $want ) {
	if ( $got === $want ) {
		$GLOBALS['pass']++;
		echo "PASS  $name\n";
		return;
	}
	$GLOBALS['fail']++;
	echo "FAIL  $name\n";
	echo "  got:  " . var_export( $got, true ) . "\n";
	echo "  want: " . var_export( $want, true ) . "\n";
}

// Calls a private/protected method by reference-safe reflection.
function call_method( $obj, $method, array $args ) {
	$ref = new ReflectionMethod( $obj, $method );
	$ref->setAccessible( true );
	return $ref->invokeArgs( $obj, $args );
}

function anchor( $tag, $text, $href ) {
	return array(
		'tag'  => $tag,
		'text' => $text,
		'href' => $href,
		'key'  => strtolower( $text ) . "\0" . $href,
	);
}

$pb = new LinkerFlow_Page_Builders();

// --- inject_anchor ------------------------------------------------------------

check(
	'inject into plain text',
	call_method( $pb, 'inject_anchor', array( '<p>Buy our shoes today</p>', anchor( '<a href="/shoes">shoes</a>', 'shoes', '/shoes' ) ) ),
	'<p>Buy our <a href="/shoes">shoes</a> today</p>'
);

check(
	'inject skips text already inside an existing link',
	call_method( $pb, 'inject_anchor', array( '<p>See <a href="/x">shoes</a> and shoes</p>', anchor( '<a href="/shoes">shoes</a>', 'shoes', '/shoes' ) ) ),
	'<p>See <a href="/x">shoes</a> and <a href="/shoes">shoes</a></p>'
);

check(
	'inject returns null when text not present in body',
	call_method( $pb, 'inject_anchor', array( '<p>nothing here</p>', anchor( '<a href="/shoes">shoes</a>', 'shoes', '/shoes' ) ) ),
	null
);

check(
	'inject preserves the incoming tag attributes (rel/target)',
	call_method( $pb, 'inject_anchor', array( '<p>buy shoes now</p>', anchor( '<a href="/shoes" rel="nofollow" target="_blank">shoes</a>', 'shoes', '/shoes' ) ) ),
	'<p>buy <a href="/shoes" rel="nofollow" target="_blank">shoes</a> now</p>'
);

// --- replace_text_outside_tags ------------------------------------------------

check(
	'replace ignores matches inside a tag attribute',
	call_method( $pb, 'replace_text_outside_tags', array( '<p class="shoes">buy shoes</p>', 'shoes', 'X' ) ),
	'<p class="shoes">buy X</p>'
);

// --- unwrap_anchor ------------------------------------------------------------

check(
	'unwrap removes the matching anchor back to plain text',
	call_method( $pb, 'unwrap_anchor', array( '<p>buy <a href="/shoes">shoes</a> now</p>', anchor( '', 'shoes', '/shoes' ) ) ),
	'<p>buy shoes now</p>'
);

check(
	'unwrap leaves a non-matching anchor untouched',
	call_method( $pb, 'unwrap_anchor', array( '<p>buy <a href="/other">shoes</a> now</p>', anchor( '', 'shoes', '/shoes' ) ) ),
	'<p>buy <a href="/other">shoes</a> now</p>'
);

// --- extract_anchors ----------------------------------------------------------

$anchors = call_method( $pb, 'extract_anchors', array( '<p><a href="/a" rel="nofollow">Foo Bar</a></p>' ) );
check( 'extract_anchors count', count( $anchors ), 1 );
check( 'extract_anchors text', $anchors[0]['text'], 'Foo Bar' );
check( 'extract_anchors href', $anchors[0]['href'], '/a' );
check( 'extract_anchors key', $anchors[0]['key'], "foo bar\0/a" );

// --- anchors_added / anchors_removed ------------------------------------------

$added = call_method( $pb, 'anchors_added', array( '<p>shoes</p>', '<p><a href="/s">shoes</a></p>' ) );
check( 'anchors_added detects a new link', count( $added ), 1 );
check( 'anchors_added carries the full tag', $added[0]['tag'], '<a href="/s">shoes</a>' );

$removed = call_method( $pb, 'anchors_removed', array( '<p><a href="/s">shoes</a></p>', '<p>shoes</p>' ) );
check( 'anchors_removed detects a dropped link', count( $removed ), 1 );

$none = call_method( $pb, 'anchors_added', array( '<p><a href="/s">shoes</a></p>', '<p><a href="/s">shoes</a></p>' ) );
check( 'anchors_added is empty when unchanged', count( $none ), 0 );

// --- elementor_collect --------------------------------------------------------

$elements = array(
	array( 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => array( 'editor' => '<p>Hello</p>' ) ),
	array( 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Title' ) ),
	array( 'elType' => 'widget', 'widgetType' => 'button', 'settings' => array( 'text' => 'Click', 'link' => array( 'url' => '/go' ) ) ),
);
$blocks = array();
call_method( $pb, 'elementor_collect', array( $elements, &$blocks ) );
check(
	'elementor_collect flattens text, heading and linked button',
	$blocks,
	array( '<p>Hello</p>', '<h2>Title</h2>', '<a href="/go">Click</a>' )
);

// --- elementor_apply (injects into text-editor only, recursively) -------------

$tree = array(
	array(
		'elType'   => 'section',
		'elements' => array(
			array( 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => array( 'editor' => '<p>shoes here</p>' ) ),
			array( 'elType' => 'widget', 'widgetType' => 'button', 'settings' => array( 'text' => 'shoes', 'link' => array( 'url' => '/b' ) ) ),
		),
	),
);
$add     = array( anchor( '<a href="/shoes">shoes</a>', 'shoes', '/shoes' ) );
$changed = false;
call_method( $pb, 'elementor_apply', array( &$tree, &$add, array(), &$changed ) );
check( 'elementor_apply set changed flag', $changed, true );
check(
	'elementor_apply injected into the text-editor widget',
	$tree[0]['elements'][0]['settings']['editor'],
	'<p><a href="/shoes">shoes</a> here</p>'
);
check(
	'elementor_apply left the button widget untouched',
	$tree[0]['elements'][1]['settings']['text'],
	'shoes'
);

// --- Divi: divi_attr and divi_read -------------------------------------------

check(
	'divi_attr reads a shortcode attribute',
	call_method( $pb, 'divi_attr', array( ' button_url="/x" button_text="Go" ', 'button_url' ) ),
	'/x'
);

$divi_post = new WP_Post( '[et_pb_section][et_pb_text]<p>Read about shoes</p>[/et_pb_text][et_pb_button button_url="/go" button_text="Go"][/et_pb_section]' );
check(
	'divi_read flattens et_pb_text inner and surfaces button links',
	call_method( $pb, 'divi_read', array( $divi_post ) ),
	"<p>Read about shoes</p>\n<a href=\"/go\">Go</a>"
);

// --- Summary ------------------------------------------------------------------

echo "\n" . $GLOBALS['pass'] . " passed, " . $GLOBALS['fail'] . " failed\n";
exit( $GLOBALS['fail'] > 0 ? 1 : 0 );
