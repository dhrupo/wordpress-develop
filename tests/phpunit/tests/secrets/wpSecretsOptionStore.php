<?php
/**
 * Tests for WP_Secrets_Option_Store.
 *
 * @group secrets
 */
class Tests_Secrets_WPSecretsOptionStore extends WP_UnitTestCase {

	/**
	 * A well-formed single-slot record.
	 *
	 * @return array{v: int, current: array<string, int|string>}
	 */
	private function sample_record(): array {
		return array(
			'v'       => 1,
			'current' => array(
				'dk'          => 'x',
				'dk_nonce'    => 'x',
				'ct'          => 'x',
				'nonce'       => 'x',
				'fingerprint' => 'x',
				'created'     => 12345,
			),
		);
	}

	public function test_implements_the_store_interface(): void {
		$this->assertInstanceOf( WP_Secrets_Store::class, new WP_Secrets_Option_Store() ); // @phpstan-ignore method.alreadyNarrowedType (Pins the interface the class declares, so removing it fails a test.)
	}

	public function test_get_returns_null_for_an_absent_secret(): void {
		$store = new WP_Secrets_Option_Store();

		$this->assertNull( $store->get( 'myplugin/absent' ) );
	}

	public function test_set_then_get_round_trips_a_record_site_scope(): void {
		$store  = new WP_Secrets_Option_Store();
		$record = $this->sample_record();

		$this->assertTrue( $store->set( 'myplugin/key', $record ) );
		$this->assertSame( $record, $store->get( 'myplugin/key' ) );
	}

	public function test_set_then_get_round_trips_a_record_network_scope(): void {
		$store  = new WP_Secrets_Option_Store();
		$record = $this->sample_record();

		$this->assertTrue( $store->set( 'myplugin/key', $record, true ) );
		$this->assertSame( $record, $store->get( 'myplugin/key', true ) );
	}

	public function test_site_and_network_scope_do_not_collide(): void {
		$store          = new WP_Secrets_Option_Store();
		$site_record    = $this->sample_record();
		$network_record = array_merge( $this->sample_record(), array( 'marker' => 'network' ) );

		$store->set( 'myplugin/key', $site_record, false );
		$store->set( 'myplugin/key', $network_record, true );

		$this->assertSame( $site_record, $store->get( 'myplugin/key', false ) );
		$this->assertSame( $network_record, $store->get( 'myplugin/key', true ) );
	}

	public function test_get_returns_an_error_when_the_stored_value_is_not_an_array(): void {
		update_option( '_wp_secret_myplugin/key', 'not an array', false );

		$store = new WP_Secrets_Option_Store();

		$result = $store->get( 'myplugin/key' );

		$this->assertWPError( $result );
		$this->assertSame( WP_SECRETS_ERROR_RECORD_MALFORMED, $result->get_error_code() );
	}

	/**
	 * @ticket 66424
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	public function test_get_returns_an_error_when_the_record_read_fails(): void {
		global $wpdb;

		$store = new WP_Secrets_Option_Store();
		$store->set( 'myplugin/key', $this->sample_record() );
		wp_cache_delete( '_wp_secret_myplugin/key', 'options' );

		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', array( $this, 'fail_option_reads' ) );

		$result = $store->get( 'myplugin/key' );

		remove_filter( 'query', array( $this, 'fail_option_reads' ) );
		$wpdb->suppress_errors( $suppress );

		$this->assertNotNull( $result, 'A failed read must not read as a missing secret.' );
		$this->assertWPError( $result );
		$this->assertSame( WP_SECRETS_ERROR_STORE_UNAVAILABLE, $result->get_error_code() );
	}

	/**
	 * @ticket 66424
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	public function test_get_returns_an_error_when_a_network_record_read_fails(): void {
		global $wpdb;

		$store = new WP_Secrets_Option_Store();
		$store->set( 'myplugin/key', $this->sample_record(), true );
		wp_cache_flush();

		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', array( $this, 'fail_option_reads' ) );

		$result = $store->get( 'myplugin/key', true );

		remove_filter( 'query', array( $this, 'fail_option_reads' ) );
		$wpdb->suppress_errors( $suppress );

		$this->assertNotNull( $result, 'A failed read must not read as a missing secret.' );
		$this->assertWPError( $result );
		$this->assertSame( WP_SECRETS_ERROR_STORE_UNAVAILABLE, $result->get_error_code() );
	}

	/**
	 * A read that never reaches the database returns from wpdb::query() before
	 * wpdb::$last_error is set, so failure has to be read from the query result.
	 *
	 * @ticket 66424
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	public function test_get_returns_an_error_when_the_read_never_reaches_the_database(): void {
		global $wpdb;

		$store = new WP_Secrets_Option_Store();
		$store->set( 'myplugin/key', $this->sample_record() );
		wp_cache_delete( '_wp_secret_myplugin/key', 'options' );

		$wpdb->last_error = '';
		$wpdb->ready      = false;

		$result = $store->get( 'myplugin/key' );

		$wpdb->ready = true;

		$this->assertNotNull( $result, 'A read that never reached the database must not read as a missing secret.' );
		$this->assertWPError( $result );
		$this->assertSame( WP_SECRETS_ERROR_STORE_UNAVAILABLE, $result->get_error_code() );
	}

	/**
	 * wp_sitemeta.meta_value is nullable, unlike wp_options.option_value, so a row
	 * can exist while holding no record. That is a malformed record, not absence.
	 *
	 * @ticket 66424
	 *
	 * @group ms-required
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	public function test_get_returns_an_error_for_a_network_row_with_no_value(): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->sitemeta,
			array(
				'site_id'    => get_current_network_id(),
				'meta_key'   => '_wp_network_secret_myplugin/key',
				'meta_value' => null,
			)
		);
		wp_cache_flush();

		$store = new WP_Secrets_Option_Store();

		$result = $store->get( 'myplugin/key', true );

		$this->assertNotNull( $result, 'A row that exists must not read as a missing secret.' );
		$this->assertWPError( $result );
		$this->assertSame( WP_SECRETS_ERROR_RECORD_MALFORMED, $result->get_error_code() );
	}

	/**
	 * Makes every read of a stored option or network option fail.
	 *
	 * @param string $query The query about to run.
	 * @return string The query to run instead.
	 */
	public function fail_option_reads( $query ) {
		if ( 0 === stripos( $query, 'SELECT' ) && ( false !== stripos( $query, 'option_value' ) || false !== stripos( $query, 'meta_value' ) ) ) {
			return 'SELECT this is not valid SQL';
		}

		return $query;
	}

	public function test_delete_removes_an_existing_record(): void {
		$store = new WP_Secrets_Option_Store();
		$store->set( 'myplugin/key', $this->sample_record() );

		$this->assertTrue( $store->delete( 'myplugin/key' ) );
		$this->assertNull( $store->get( 'myplugin/key' ) );
	}

	public function test_delete_on_an_absent_record_is_not_an_error(): void {
		$store = new WP_Secrets_Option_Store();

		$this->assertTrue( $store->delete( 'myplugin/never-existed' ) );
	}

	/**
	 * @global wpdb $wpdb WordPress database abstraction object.
	 */
	public function test_site_scope_records_are_not_autoloaded(): void {
		global $wpdb;

		$store = new WP_Secrets_Option_Store();
		$store->set( 'myplugin/key', $this->sample_record() );

		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				'_wp_secret_myplugin/key'
			)
		);

		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_list_names_returns_stored_names_without_the_prefix(): void {
		$store = new WP_Secrets_Option_Store();
		$store->set( 'myplugin/one', $this->sample_record() );
		$store->set( 'myplugin/two', $this->sample_record() );

		$names = $store->list_names();

		$this->assertIsArray( $names );
		$this->assertContains( 'myplugin/one', $names );
		$this->assertContains( 'myplugin/two', $names );
	}

	public function test_list_names_does_not_include_unrelated_options(): void {
		update_option( 'completely_unrelated_option', 'value' );

		$store = new WP_Secrets_Option_Store();
		$store->set( 'myplugin/one', $this->sample_record() );

		$names = $store->list_names();

		$this->assertIsArray( $names );
		$this->assertNotContains( 'completely_unrelated_option', $names );
	}

	public function test_list_names_does_not_include_network_scope_names(): void {
		$store = new WP_Secrets_Option_Store();
		$store->set( 'myplugin/site-only', $this->sample_record(), false );
		$store->set( 'myplugin/network-only', $this->sample_record(), true );

		$site_names    = $store->list_names( false );
		$network_names = $store->list_names( true );

		$this->assertIsArray( $site_names );
		$this->assertIsArray( $network_names );
		$this->assertContains( 'myplugin/site-only', $site_names );
		$this->assertNotContains( 'myplugin/network-only', $site_names );
		$this->assertContains( 'myplugin/network-only', $network_names );
		$this->assertNotContains( 'myplugin/site-only', $network_names );
	}

	/**
	 * '_' is a single-character wildcard in SQL LIKE. An option name with any other
	 * character where the prefix's underscore falls must not match unless the prefix
	 * is properly escaped before the query is built.
	 */
	public function test_list_names_escapes_the_underscore_wildcard(): void {
		// Would match "_wp_secret_%" under an *unescaped* LIKE, since '_' matches
		// any single character – proving esc_like() is actually applied.
		update_option( '_wpXsecret_decoy/name', 'irrelevant value', false );

		$store = new WP_Secrets_Option_Store();
		$store->set( 'myplugin/real', $this->sample_record() );

		$names = $store->list_names();

		$this->assertIsArray( $names );
		$this->assertContains( 'myplugin/real', $names );
		$this->assertNotContains( 'decoy/name', $names );
	}
}
