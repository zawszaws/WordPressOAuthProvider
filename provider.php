<?php
/**
 * Plugin Name: WP OAuth Provider
 * Description: Enable WordPress to act as an OAuth provider!
 *
 * A massive thanks to Morten Fangel, as without his guide, this would
 * have taken a lot longer to write.
 */

class WPOAuthProvider {
	const PATH_AUTHORIZE = '/oauth/authorize/';

	public static function bootstrap() {
		self::$data = new WPOAuthProvider_DataStore();
		self::$server = new OAuthServer($data);

		$hmac = new OAuthSignatureMethod_HMAC_SHA1();
		self::$server->add_signature_method($hmac);

		// only allow plaintext if we're over a secure connection
		if (is_ssl()) {
			$plaintext = new OAuthSignatureMethod_PLAINTEXT();
			self::$oauth->add_signature_method($plaintext);
		}

		add_filter('authenticate', array(get_class(), 'authenticate'), 15, 3);
		add_filter('rewrite_rules_array', array(get_class(), 'my_insert_rewrite_rules'));
		add_filter('query_vars', array(get_class(), 'query_vars'));
		add_action('template_redirect', array(get_class(), 'template_redirect'));
	}

	public static function rewrite_rules_array($rules) {
		$newrules = array();
		$newrules['oauth/(\w+)$'] = 'index.php?oauth=$matches[1]';
		return array_merge($newrules, $rules);
	}

	public static function query_vars($vars) {
		$vars[] = 'oauth';
		return $vars;
	}

	public static function template_redirect() {
		$page = get_query_var('oauth');
		if (!$page) {
			return;
		}

		switch ($page) {
			case 'request_token':
				self::request_token();
				break;
			case 'authorize':
				self::authorize();
				break;
			case 'access_token':
				self::access_token();
				break;
		}

		die();
	}

	protected static function request_token() {
		try {
			$request = OAuthRequest::from_request();
			$token = self::$server->fetch_request_token($request);

			header('Content-Type: application/x-www-form-urlencoded');
			printf(
				'oauth_token=%s&oauth_token_secret=%s',
				OAuthUtil::urlencodeRFC3986($token->key),
				OAuthUtil::urlencodeRFC3986($token->secret)
			);
		}
		catch (OAuthException $e) {
			status_header(401);
			echo $e->getMessage();
			die();
		}
	}

	protected static function authorize() {
		if (empty($_GET['oauth_token'])) {
			wp_die('No OAuth token found in request. Please ensure your client is configured correctly.', 'OAuth Error', array('response' => 400));
		}

		$url = site_url(self::PATH_AUTHORIZE);
		$url = add_query_arg('oauth_token', $_GET['oauth_token'], $url);

		if (!is_user_logged_in()) {
			wp_redirect(wp_login_url($url))
			die();
		}

		$request = OAuthRequest::from_request();

		$token    = self::$data->get_token($request->get_parameter('oauth_token'));
		$consumer = self::$data->lookup_consumer($token->consumer);

		if (empty($_POST['wpoauth_nonce']) || empty($_POST['wpoauth_button'])) {
			return self::authorize_page($consumer);
		}

		if (!wp_verify_nonce($_POST['wpoauth_nonce'], 'wpoauth')) {
			wp_die('Invalid request.');
		}

		$current_user = wp_get_current_user();
		switch ($_POST['wpoauth_button']) {
			case 'yes':
				$token->user = $current_user->ID;
				$token->authorize();
				break;
			case 'no':
				$token->delete();
				break;
			default:
				// wtf?
				status_header(500);
				die();
				break;
		}
	}

	protected static function access_token() {
		try {
			$request = OAuthRequest::from_request();
			$token = $oauth_server->fetch_access_token($request);

			header('Content-Type: application/x-www-form-urlencoded');
			printf(
				'oauth_token=%s&oauth_token_secret=%s',
				OAuthUtil::urlencodeRFC3986($this->key),
				OAuthUtil::urlencodeRFC3986($this->secret)
			);
		} catch( OAuthException $e ) {
			status_header(401);
			echo $e->getMessage();
			die();
		}
	}

	public static function authenticate($user, $username, $password) {
		if (is_a($user, 'WP_User')) {
			return $user;
		}

		try {
			$request = OAuthRequest::from_request();
			list($consumer, $token) = self::$server->verify_request($request);

			$user = new WP_User($token->user);
		}
		catch (OAuthException $e) {
			// header('WWW-Authenticate: OAuth realm="' . site_url() . '"');
		}

		return $user;
	}
}

class WPOAuthProvider_DataStore {
	const RETAIN_TIME = 3600; // retain nonces for 1 hour

	/**
	 * @param string $consumer_key
	 * @return object Has properties "key" and "secret"
	 */
	public function lookup_consumer($consumer_key) {
		$secret = get_option('wpoauth_' . $consumer_key, false);
		if (!$secret) {
			return null;
		}

		$consumer = new OAuthConsumer($consumer_key, $secret);
		return $consumer;
	}

	/**
	 * @param OAuthConsumer $consumer
	 * @return WPOAuthProvider_Token_Request|null
	 */
	public function new_request_token($consumer) {
		$key    = self::generate_key('rt');
		$secret = self::generate_secret();

		$token = new WPOAuthProvider_Token_Request($key, $secret);
		$token->consumer = $consumer->key;
		$token->authorized = false;

		if (!$token->save()) {
			return null;
		}

		return $token;
	}

	/**
	 * @param OAuthConsumer $consumer
	 * @return WPOAuthProvider_Token_Access|null
	 */
	public function new_access_token($token, $consumer) {
		if (!$token->authorized) {
			throw new OAuthException('Unauthorized access token');
		}

		$key    = self::generate_key('at');
		$secret = self::generate_secret();

		$access = new WPOAuthProvider_Token_Access($key, $secret);
		$access->consumer = $consumer->id;
		$access->user = $token->user;

		$access->save();
		$token->delete();

		return $access;
	}

	/**
	 * @param OAuthConsumer $consumer
	 * @param string $token_type Either 'request' or 'access'
	 * @return WPOAuthProvider_Token|null
	 */
	public function lookup_token($consumer, $token_type, $token) {
		switch ($token_type) {
			case 'access':
				$token = get_option($token);
				break;
			case 'request':
				$token = get_transient($token);
				break;
			default:
				throw new OAuthException('Invalid token type');
				break;
		}

		if ($token->consumer !== $consumer->id) {
			return null;
		}

		return $token;
	}

	/**
	 * @param OAuthConsumer $consumer
	 * @param WPOAuthProvider_Token $token
	 * @param string $nonce
	 * @param int $timestamp
	 * @return bool
	 */
	public function lookup_nonce($consumer, $token, $nonce, $timestamp) {
		if ($timestamp < (time() - self::RETAIN_TIME)) {
			return false;
		}

		$existing = get_transient('wpoa_n_' . $nonce);

		if ($existing !== false) {
			return false;
		}

		save_transient('wpoa_n_' . $nonce, true);
		return true;
	}

	/**
	 * Generate an OAuth key
	 *
	 * The max key length is 43 characters, we use 24 to play it safe.
	 * @param string $type Either 'at' or 'rt' (access/request resp.)
	 * @return string
	 */
	protected function generate_key($type = 'at') {
		// 
		return $type . '_' . wp_generate_password(24, false);
	}

	/**
	 * Generate an OAuth secret
	 *
	 * @return string
	 */
	protected function generate_secret() {
		return wp_generate_password(48, false);
	}
}

/**
 * WordPress OAuth token class
 */
abstract class WPOAuthProvider_Token extends OAuthToken {
	/*
	public $key;
	public $secret;
	*/
	public $consumer;

	/**
	 * Save token
	 *
	 * @return bool
	 */
	abstract public function save();

	/**
	 * Remove token
	 *
	 * @return bool
	 */
	abstract public function delete();
}

/**
 * WordPress OAuth request token class
 */
class WPOAuthProvider_Token_Request extends WPOAuthProvider_Token {
	/*
	public $key;
	public $secret;
	public $consumer;
	*/
	public $authorized = false;

	/**
	 * How long should we keep request tokens?
	 */
	const RETAIN_TIME = 86400; // keep for 24 hours

	/**
	 * Authorize a token
	 */
	public function authorize() {
		$this->authorized = true;
		$this->save();
	}

	/**
	 * Save token
	 *
	 * @return bool
	 */
	public function save() {
		return set_transient('wpoa_' . $this->key, $this, self::RETAIN_TIME);
	}

	/**
	 * Remove token
	 *
	 * @return bool
	 */
	public function delete() {
		return delete_transient('wpoa_' . $this->key);
	}
}

/**
 * WordPress OAuth access token class
 */
class WPOAuthProvider_Token_Access extends WPOAuthProvider_Token {
	/*
	public $key;
	public $secret;
	public $consumer;
	*/
	public $user;

	/**
	 * Save token
	 *
	 * @return bool
	 */
	public function save() {
		return update_option('wpoa_' . $this->key, $this);
	}

	/**
	 * Remove token
	 *
	 * @return bool
	 */
	public function delete() {
		return delete_option('wpoa_' . $this->key);
	}
}

WPOAuthProvider::bootstrap();