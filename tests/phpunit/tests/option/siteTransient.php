<?php

/**
 * @group option
 */
class Tests_Option_SiteTransient extends WP_UnitTestCase {

	/**
	 * Previous `$wpdb->suppress_errors()` state, restored after a test that forces a database error.
	 *
	 * @var bool|null
	 */
	private $suppress_errors = null;

	/**
	 * Previous external object cache flag, restored after a test that simulates one.
	 *
	 * @var bool|null
	 */
	private $had_external_object_cache = null;

	public function set_up() {
		parent::set_up();

		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This test requires that an external object cache is not in use.' );
		}
	}

	public function tear_down() {
		if ( null !== $this->suppress_errors ) {
			$GLOBALS['wpdb']->suppress_errors( $this->suppress_errors );
			$this->suppress_errors = null;
		}

		if ( null !== $this->had_external_object_cache ) {
			wp_using_ext_object_cache( $this->had_external_object_cache );
			$this->had_external_object_cache = null;
		}

		parent::tear_down();
	}

	/**
	 * @covers ::get_site_transient
	 * @covers ::set_site_transient
	 * @covers ::delete_site_transient
	 */
	public function test_the_basics() {
		$key    = 'key1';
		$value  = 'value1';
		$value2 = 'value2';

		$this->assertFalse( get_site_transient( 'doesnotexist' ) );
		$this->assertTrue( set_site_transient( $key, $value ) );
		$this->assertSame( $value, get_site_transient( $key ) );
		$this->assertFalse( set_site_transient( $key, $value ) );
		$this->assertTrue( set_site_transient( $key, $value2 ) );
		$this->assertSame( $value2, get_site_transient( $key ) );
		$this->assertTrue( delete_site_transient( $key ) );
		$this->assertFalse( get_site_transient( $key ) );
		$this->assertFalse( delete_site_transient( $key ) );
	}

	/**
	 * @covers ::get_site_transient
	 * @covers ::set_site_transient
	 * @covers ::delete_site_transient
	 */
	public function test_serialized_data() {
		$key   = __FUNCTION__;
		$value = array(
			'foo' => true,
			'bar' => true,
		);

		$this->assertTrue( set_site_transient( $key, $value ) );
		$this->assertSame( $value, get_site_transient( $key ) );

		$value = (object) $value;
		$this->assertTrue( set_site_transient( $key, $value ) );
		// Keep assertEquals() because the objects are intentionally compared by value.
		$this->assertEquals( $value, get_site_transient( $key ) );
		$this->assertTrue( delete_site_transient( $key ) );
	}

	/**
	 * @ticket 22846
	 * @group ms-excluded
	 *
	 * @covers ::set_site_transient
	 * @covers ::wp_load_alloptions
	 */
	public function test_set_site_transient_is_not_stored_as_autoload_option() {
		$key = 'not_autoloaded';

		set_site_transient( $key, 'Not an autoload option' );

		$options = wp_load_alloptions();

		$this->assertArrayNotHasKey( '_site_transient_' . $key, $options );
	}

	/**
	 * Ensure site transients are stored in the options table on single site installations.
	 *
	 * @group ms-excluded
	 *
	 * @covers ::set_site_transient
	 */
	public function test_site_transient_stored_in_options_on_single_site() {
		global $wpdb;
		$key   = 'test_site_transient_stored_in_options_on_single_site';
		$value = 'Test Site Transient Value';

		set_site_transient( $key, $value );

		$option = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_name, option_value from {$wpdb->options} WHERE option_name = %s",
				'_site_transient_' . $key
			)
		);
		// Keep assertEquals() because the database row object is compared by value.
		$this->assertEquals(
			(object) array(
				'option_name'  => '_site_transient_' . $key,
				'option_value' => $value,
			),
			$option,
			'Site transient should be stored in the options table on single site installations.'
		);
	}

	/**
	 * Ensure site transients are stored in the sitemeta table on multisite.
	 *
	 * @group ms-required
	 *
	 * @covers ::set_site_transient
	 */
	public function test_site_transients_stored_in_site_meta_on_ms() {
		global $wpdb;
		$key   = 'test_site_transient_stored_in_site_meta_on_ms';
		$value = 'Test Site Transient Value';

		set_site_transient( $key, $value );

		$option = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT meta_key, meta_value from {$wpdb->sitemeta} WHERE meta_key = %s",
				'_site_transient_' . $key
			)
		);
		// Keep assertEquals() because the database row object is compared by value.
		$this->assertEquals(
			(object) array(
				'meta_key'   => '_site_transient_' . $key,
				'meta_value' => $value,
			),
			$option,
			'Site transient should be stored in sitemeta table on multisite.'
		);
	}

	/**
	 * Ensure site transients are not stored in the options table on multisite.
	 *
	 * @group ms-required
	 *
	 * @covers ::set_site_transient
	 */
	public function test_site_transients_not_stored_in_options_table_on_ms() {
		global $wpdb;
		$key   = 'test_site_transients_not_stored_in_options_table_on_ms';
		$value = 'Test Site Transient Value';

		set_site_transient( $key, $value );

		$option = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_name, option_value from {$wpdb->options} WHERE option_name = %s",
				'_site_transient_' . $key
			)
		);

		$this->assertNull( $option, 'Querying option table should not return transient on multisite.' );
	}

	/**
	 * Tests that a new site transient is not stored when its timeout cannot be saved.
	 *
	 * @ticket 52345
	 *
	 * @covers ::set_site_transient
	 */
	public function test_set_site_transient_should_not_store_value_when_timeout_cannot_be_added() {
		$key     = 'site_transient_timeout_fails';
		$timeout = "_site_transient_timeout_{$key}";
		$action  = new MockAction();
		add_action( "set_site_transient_{$key}", array( $action, 'action' ) );
		$this->fail_insert_of_site_option( $timeout );

		$this->assertFalse( set_site_transient( $key, 'value', 60 ), 'set_site_transient() should report the failure.' );
		$this->assertFalse( get_site_transient( $key ), 'The site transient should not be readable without a timeout.' );
		$this->assertFalse( get_site_option( "_site_transient_{$key}" ), 'The value should not be stored.' );
		$this->assertSame( 0, $action->get_call_count(), 'The set_site_transient_{$transient} action should not fire.' );
	}

	/**
	 * Tests that a site transient can still be set when an orphaned timeout is left behind.
	 *
	 * @ticket 52345
	 *
	 * @covers ::set_site_transient
	 */
	public function test_set_site_transient_should_store_value_when_orphaned_timeout_exists() {
		$key = 'site_transient_orphaned_timeout';
		set_site_transient( $key, 'old', 60 );
		delete_site_option( "_site_transient_{$key}" );

		$this->assertTrue( set_site_transient( $key, 'new', 60 ), 'set_site_transient() should succeed.' );
		$this->assertSame( 'new', get_site_transient( $key ), 'The new value should be returned.' );
	}

	/**
	 * Tests that a new site transient is stored when another request adds the same timeout first.
	 *
	 * @ticket 52345
	 *
	 * @covers ::set_site_transient
	 */
	public function test_set_site_transient_should_store_value_when_timeout_was_added_concurrently() {
		global $wpdb;

		$key     = 'site_transient_timeout_race';
		$timeout = "_site_transient_timeout_{$key}";
		$filter  = static function ( $query ) use ( $timeout, $wpdb, &$filter ) {
			if ( 0 === stripos( ltrim( $query ), 'INSERT' ) && str_contains( $query, "'{$timeout}'" ) ) {
				remove_filter( 'query', $filter );
				$wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Replays core's already prepared INSERT.
			}
			return $query;
		};
		add_filter( 'query', $filter );

		$this->assertTrue( set_site_transient( $key, 'value', 60 ), 'set_site_transient() should succeed.' );
		$this->assertSame( 'value', get_site_transient( $key ), 'The value should be returned.' );
	}

	/**
	 * Makes the INSERT query for the given site option fail with a database error.
	 *
	 * @param string $option_name Site option name whose INSERT should fail.
	 */
	private function fail_insert_of_site_option( $option_name ) {
		global $wpdb;

		$this->suppress_errors = $wpdb->suppress_errors();
		add_filter(
			'query',
			static function ( $query ) use ( $option_name, $wpdb ) {
				if ( 0 === stripos( ltrim( $query ), 'INSERT' ) && str_contains( $query, "'{$option_name}'" ) ) {
					return str_replace(
						array( "`{$wpdb->options}`", "`{$wpdb->sitemeta}`" ),
						array( "`{$wpdb->options}_missing`", "`{$wpdb->sitemeta}_missing`" ),
						$query
					);
				}
				return $query;
			}
		);
	}

	/**
	 * Tests that an expiring site transient writes a timeout row and a value row on the database path.
	 *
	 * @ticket 52345
	 *
	 * @covers ::set_site_transient
	 */
	public function test_set_site_transient_should_write_timeout_and_value_rows() {
		$key = 'site_transient_rows_with_expiry';

		$this->assertTrue( set_site_transient( $key, 'value', 60 ), 'set_site_transient() should succeed.' );
		$this->assertSame( 'value', get_site_transient( $key ), 'The value should be returned.' );
		$this->assertSame( 2, $this->count_site_option_rows( "_site_transient%{$key}" ), 'Both the value and timeout rows should exist.' );
		$this->assertNotFalse( get_site_option( "_site_transient_timeout_{$key}" ), 'The timeout row should be readable.' );
	}

	/**
	 * Tests that a site transient without an expiry writes no timeout row.
	 *
	 * @ticket 52345
	 *
	 * @covers ::set_site_transient
	 */
	public function test_set_site_transient_without_expiration_should_not_write_a_timeout_row() {
		$key = 'site_transient_rows_without_expiry';

		$this->assertTrue( set_site_transient( $key, 'value' ), 'set_site_transient() should succeed.' );
		$this->assertSame( 'value', get_site_transient( $key ), 'The value should be returned.' );
		$this->assertSame( 1, $this->count_site_option_rows( "_site_transient%{$key}" ), 'Only the value row should exist.' );
		$this->assertFalse( get_site_option( "_site_transient_timeout_{$key}" ), 'No timeout row should be written.' );
	}

	/**
	 * Tests that a failure to store the value row is still reported once the timeout row was written.
	 *
	 * @ticket 52345
	 *
	 * @covers ::set_site_transient
	 */
	public function test_set_site_transient_should_return_false_when_value_cannot_be_added() {
		$key    = 'site_transient_value_fails';
		$action = new MockAction();
		add_action( "set_site_transient_{$key}", array( $action, 'action' ) );
		$this->fail_insert_of_site_option( "_site_transient_{$key}" );

		$this->assertFalse( set_site_transient( $key, 'value', 60 ), 'set_site_transient() should report the failure.' );
		$this->assertFalse( get_site_transient( $key ), 'No value should be readable.' );
		$this->assertSame( 0, $action->get_call_count(), 'The set_site_transient_{$transient} action should not fire.' );
	}

	/**
	 * Tests that an earlier, unrelated database error does not stop a site transient being set over an orphaned timeout.
	 *
	 * @ticket 52345
	 *
	 * @covers ::set_site_transient
	 */
	public function test_set_site_transient_should_store_value_over_orphaned_timeout_after_an_unrelated_database_error() {
		global $wpdb;

		$key = 'site_transient_orphan_stale_error';
		set_site_transient( $key, 'old', 60 );
		delete_site_option( "_site_transient_{$key}" );

		$this->suppress_errors = $wpdb->suppress_errors( true );
		$wpdb->query( "SELECT 1 FROM {$wpdb->options}_missing" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Deliberately invalid query.
		$this->assertNotSame( '', $wpdb->last_error, 'The unrelated query should have left an error behind.' );

		$this->assertTrue( set_site_transient( $key, 'new', 60 ), 'set_site_transient() should succeed.' );
		$this->assertSame( 'new', get_site_transient( $key ), 'The new value should be returned.' );
	}

	/**
	 * Tests that, with an external object cache, site transients use the cache only and write no rows.
	 *
	 * The external cache is simulated by flagging the default in-memory cache as external. It is not a real
	 * Redis or Memcached drop-in.
	 *
	 * @ticket 52345
	 *
	 * @dataProvider data_expirations
	 *
	 * @covers ::set_site_transient
	 * @covers ::get_site_transient
	 * @covers ::delete_site_transient
	 *
	 * @param int $expiration Expiration in seconds.
	 */
	public function test_site_transients_should_use_only_the_object_cache_with_an_external_object_cache( $expiration ) {
		$key                             = 'site_transient_external_cache';
		$this->had_external_object_cache = wp_using_ext_object_cache( true );
		wp_cache_flush();

		// A failing timeout insert must be irrelevant, as no timeout row is ever written.
		$this->fail_insert_of_site_option( "_site_transient_timeout_{$key}" );
		$queries = array();
		add_filter(
			'query',
			static function ( $query ) use ( &$queries ) {
				$queries[] = $query;
				return $query;
			}
		);

		$this->assertTrue( set_site_transient( $key, 'first', $expiration ), 'The first set should succeed.' );
		$this->assertTrue( set_site_transient( $key, 'second', $expiration ), 'Setting again should succeed.' );
		$this->assertSame( 'second', get_site_transient( $key ), 'The value should come from the object cache.' );
		$this->assertSame( 'second', wp_cache_get( $key, 'site-transient' ), 'The value should be in the site transient cache group.' );

		$this->assertTrue( delete_site_transient( $key ), 'The site transient should be deleted.' );
		$this->assertFalse( get_site_transient( $key ), 'The site transient should be gone.' );

		// Check the recorded queries before this test runs any of its own.
		$this->assertSame( array(), preg_grep( '/' . preg_quote( $key, '/' ) . '/', $queries ), 'No query should mention the site transient.' );
		$this->assertSame( 0, $this->count_site_option_rows( "%{$key}%" ), 'Neither setting nor deleting should write rows.' );
	}

	/**
	 * Data provider for expiry values.
	 *
	 * @return array[]
	 */
	public function data_expirations() {
		return array(
			'with an expiry'    => array( 60 ),
			'without an expiry' => array( 0 ),
		);
	}

	/**
	 * Counts rows holding site options whose name matches a LIKE pattern.
	 *
	 * Site options live in the sitemeta table on multisite and in the options table otherwise.
	 *
	 * @param string $like LIKE pattern.
	 * @return int Row count.
	 */
	private function count_site_option_rows( $like ) {
		global $wpdb;

		if ( is_multisite() ) {
			$query = "SELECT COUNT(*) FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s";
		} else {
			$query = "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s";
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $query, $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table name only, value is prepared.
	}
}
