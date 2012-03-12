<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/*
 * CI-Merchant Library
 *
 * Copyright (c) 2011-2012 Crescendo Multimedia Ltd
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:

 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.

 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

// Support legacy drivers which extend the CI_Driver class
// All drivers should be updated to extend Merchant_driver instead
// This will be removed in a future version!
if ( ! class_exists('CI_Driver')) get_instance()->load->library('driver');

define('MERCHANT_VENDOR_PATH', realpath(dirname(__FILE__).'/../vendor'));
define('MERCHANT_DRIVER_PATH', realpath(dirname(__FILE__).'/merchant'));

/**
 * Merchant Class
 *
 * Payment processing for CodeIgniter
 */
class Merchant
{
	public static $CURRENCIES_WITHOUT_DECIMALS = array('JPY');

	private $_driver;

	public function __construct($driver = NULL)
	{
		if ( ! empty($driver))
		{
			$this->load($driver);
		}
	}

	public function __call($function, $arguments)
	{
		if ( ! empty($this->_driver))
		{
			return call_user_func_array(array($this->_driver, $function), $arguments);
		}
	}

	/**
	 * Load the specified driver
	 */
	public function load($driver)
	{
		$this->_driver = $this->_create_instance($driver);
		return $this->_driver !== FALSE;
	}

	/**
	 * Returns the name of the currently loaded driver
	 */
	public function active_driver()
	{
		$class_name = get_class($this->_driver);
		if ($class_name === FALSE) return FALSE;
		return str_replace('Merchant_', '', $class_name);
	}

	/**
	 * Load and create a new instance of a driver.
	 * $driver can be specified either as a class name (Merchant_paypal) or a short name (paypal)
	 */
	private function _create_instance($driver)
	{
		if (stripos($driver, 'merchant_') === 0)
		{
			$driver_class = ucfirst(strtolower($driver));
		}
		else
		{
			$driver_class = 'Merchant_'.strtolower($driver);
		}

		if ( ! class_exists($driver_class))
		{
			// attempt to load driver file
			$driver_path = MERCHANT_DRIVER_PATH.'/'.strtolower($driver_class).'.php';
			if ( ! file_exists($driver_path)) return FALSE;
			require_once($driver_path);

			// did the driver file implement the class?
			if ( ! class_exists($driver_class)) return FALSE;
		}

		// ensure class is not abstract
		$reflection_class = new ReflectionClass($driver_class);
		if ($reflection_class->isAbstract()) return FALSE;

		return new $driver_class();
	}

	public function valid_drivers()
	{
		static $valid_drivers = array();

		if (empty($valid_drivers))
		{
			foreach (scandir(MERCHANT_DRIVER_PATH) as $file_name)
			{
				$driver_path = MERCHANT_DRIVER_PATH.'/'.$file_name;
				if (stripos($file_name, 'merchant_') === 0 AND is_file($driver_path))
				{
					require_once($driver_path);

					// does the file implement an appropriately named class?
					$driver_class = ucfirst(str_replace('.php', '', $file_name));
					if ( ! class_exists($driver_class)) continue;

					// ensure class is not abstract
					$reflection_class = new ReflectionClass($driver_class);
					if ($reflection_class->isAbstract()) continue;

					$valid_drivers[] = str_replace('Merchant_', '', $driver_class);
				}
			}
		}

		return $valid_drivers;
	}

	public function authorize($params)
	{
		return $this->_do_authorize_or_purchase('authorize', $params);
	}

	public function authorize_return($params)
	{
		return $this->_do_authorize_or_purchase('authorize_return', $params);
	}

	public function capture($params)
	{
		return $this->_do_capture_or_refund('capture', $params);
	}

	public function purchase($params)
	{
		return $this->_do_authorize_or_purchase('purchase', $params);
	}

	public function purchase_return($params)
	{
		return $this->_do_authorize_or_purchase('purchase_return', $params);
	}

	public function refund($params)
	{
		return $this->_do_capture_or_refund('refund', $params);
	}

	private function _do_authorize_or_purchase($method, $params)
	{
		$this->_normalize_card_params($params);
		$this->_normalize_currency_params($params);

		try
		{
			// load driver params
			$this->_driver->set_params($params);

			// all payments require amount and currency
			$this->_driver->require_params('amount', 'currency');

			// support drivers using deprecated required_fields array
			if (($method == 'authorize' OR $method == 'purchase') AND
				! empty($this->_driver->required_fields))
			{
				$this->_driver->require_params($this->_driver->required_fields);
			}

			// validate card_no
			$this->_driver->validate_card();

			// begin the actual processing
			return $this->_driver->$method();
		}
		catch (Merchant_exception $e)
		{
			return new Merchant_response(Merchant_response::FAILED, $e->getMessage());
		}
	}

	private function _do_capture_or_refund($method, $params)
	{
		$this->_normalize_currency_params($params);

		try
		{
			// load driver params
			$this->_driver->set_params($params);

			// begin the actual processing
			return $this->_driver->$method();
		}
		catch (Merchant_exception $e)
		{
			return new Merchant_response(Merchant_response::FAILED, $e->getMessage());
		}
	}

	private function _normalize_card_params(&$params)
	{
		// normalize months to 2 digits and years to 4
		if ( ! empty($params['exp_month'])) $params['exp_month'] = sprintf('%02d', (int)$params['exp_month']);
		if ( ! empty($params['exp_year'])) $params['exp_year'] = sprintf('%04d', (int)$params['exp_year']);
		if ( ! empty($params['start_month'])) $params['start_month'] = sprintf('%02d', (int)$params['start_month']);
		if ( ! empty($params['start_year'])) $params['start_year'] = sprintf('%04d', (int)$params['start_year']);

		// normalize card_type to lowercase
		if (isset($params['card_type'])) $params['card_type'] = strtolower($params['card_type']);
	}

	private function _normalize_currency_params(&$params)
	{
		// support deprecated currency_code parameter
		if (isset($params['currency_code']))
		{
			$params['currency'] =& $params['currency_code'];
		}
		elseif (isset($params['currency']))
		{
			$params['currency_code'] =& $params['currency'];
		}
	}

	/**
	 * Support deprecated curl_helper() static method.
	 */
	public static function curl_helper($url, $post_data = NULL, $username = NULL, $password = NULL)
	{
		// errors are now thrown as Merchant_exception()
		$response = array('error' => NULL);

		if (is_null($post_data))
		{
			$response['data'] = $this->_driver->get_request($url, $username, $password);
		}
		else
		{
			$response['data'] = $this->_driver->post_request($url, $post_data, $username, $password);
		}

		return $response;
	}

	/**
	 * Redirect Post function
	 *
	 * Automatically redirect the user to payment pages which require POST data
	 */
	public static function redirect_post($post_url, $data, $message = 'Please wait while we redirect you to the payment page...')
	{
		?>
<!DOCTYPE html>
<html>
<head><title>Redirecting...</title></head>
<body onload="document.forms[0].submit();">
	<p><?php echo htmlspecialchars($message); ?></p>
	<form name="payment" action="<?php echo htmlspecialchars($post_url); ?>" method="post">
		<p>
			<?php if (is_array($data)): ?>
				<?php foreach ($data as $key => $value): ?>
					<input type="hidden" name="<?php echo $key; ?>" value="<?php echo htmlspecialchars($value); ?>" />
				<?php endforeach ?>
			<?php else: ?>
				<?php echo $data; ?>
			<?php endif; ?>
			<input type="submit" value="Continue" />
		</p>
	</form>
</body>
</html>
	<?php
		exit();
	}
}

abstract class Merchant_driver
{
	protected $CI;
	protected $settings = array();
	protected $params = array();

	public function __construct()
	{
		$this->CI =& get_instance();

		// initialize default settings
		foreach ($this->default_settings() as $key => $default)
		{
			if (is_array($default))
			{
				$this->settings[$key] = isset($default['default']) ? $default['default'] : NULL;
			}
			else
			{
				$this->settings[$key] = $default;
			}
		}
	}

	/**
	 * Default settings. This should be overridden by the driver.
	 */
	public abstract function default_settings();

	/**
	 * Initialize the driver settings
	 */
	public function initialize($settings)
	{
		foreach ($this->default_settings() as $key => $default)
		{
			if (isset($settings[$key]))
			{
				// boolean settings must remain booleans
				$this->settings[$key] = is_bool($default) ? (bool)$settings[$key] : $settings[$key];
			}
		}
	}

	/**
	 * All driver settings
	 *
	 * @return array
	 */
	public function settings()
	{
		return $this->settings;
	}

	/**
	 * Setting
	 *
	 * @return  mixed
	 */
	public function setting($key)
	{
		return isset($this->settings[$key]) ? $this->settings[$key] : FALSE;
	}

	public function can_authorize()
	{
		$method = new ReflectionMethod($this, 'authorize');
		return $method->getDeclaringClass()->name !== __CLASS__;
	}

	public function can_capture()
	{
		$method = new ReflectionMethod($this, 'capture');
		return $method->getDeclaringClass()->name !== __CLASS__;
	}

	public function can_refund()
	{
		$method = new ReflectionMethod($this, 'refund');
		return $method->getDeclaringClass()->name !== __CLASS__;
	}

	public function can_return()
	{
		$method = new ReflectionMethod($this, 'purchase_return');
		if ($method->getDeclaringClass()->name !== __CLASS__) return TRUE;

		// try calling deprecated process_return() method instead
		if (method_exists($this, 'process_return')) return TRUE;
		if (method_exists($this, '_process_return')) return TRUE;

		return FALSE;
	}

	public function authorize()
	{
		throw new BadMethodCallException("Method not supported by this gateway.");
	}

	public function authorize_return()
	{
		throw new BadMethodCallException("Method not supported by this gateway.");
	}

	public function capture()
	{
		throw new BadMethodCallException("Method not supported by this gateway.");
	}

	public function purchase()
	{
		// try calling deprecated process() method instead
		if (method_exists($this, 'process'))
		{
			return $this->process($this->params);
		}

		if (method_exists($this, '_process'))
		{
			return $this->_process($this->params);
		}

		throw new BadMethodCallException("Method not supported by this gateway.");
	}

	public function purchase_return()
	{
		// try calling deprecated process_return() method instead
		if (method_exists($this, 'process_return'))
		{
			return $this->process_return($this->params);
		}

		if (method_exists($this, '_process_return'))
		{
			return $this->_process_return($this->params);
		}

		throw new BadMethodCallException("Method not supported by this gateway.");
	}

	public function refund()
	{
		throw new BadMethodCallException("Method not supported by this gateway.");
	}

	public function param($name)
	{
		return isset($this->params[$name]) ? $this->params[$name] : FALSE;
	}

	public function set_params($params)
	{
		$this->params = array_merge($this->params, $params);
	}

	public function require_params()
	{
		$args = func_get_args();
		if (empty($args)) return;

		// also accept an array instead of multiple parameters
		if (count($args) == 1 AND is_array($args[0])) $args = $args[0];

		foreach ($args as $name)
		{
			if (empty($this->params[$name]))
			{
				throw new Merchant_exception(str_replace('%s', $name, "The %s field is required."));
			}
		}
	}

	public function validate_card()
	{
		// skip validation if card_no is empty
		if (empty($this->params['card_no'])) return;

		if ( ! $this->secure_request())
		{
			throw new Merchant_exception('Card details must be submitted over a secure connection.');
		}

		// strip any non-digits from card_no
		$this->params['card_no'] = preg_replace('/\D/', '', $this->params['card_no']);

		if ($this->validate_luhn($this->params['card_no']) == FALSE)
		{
			throw new Merchant_exception('Invalid card number.');
		}

		if ($this->param('exp_month') AND $this->param('exp_year') AND
			$this->validate_expiry($this->param('exp_month'), $this->param('exp_year')) == FALSE)
		{
			throw new Merchant_exception('Credit card has expired.');
		}
	}

	/**
	 * Luhn algorithm number checker - (c) 2005-2008 shaman - www.planzero.org
	 * This code has been released into the public domain, however please
	 * give credit to the original author where possible.
	 *
	 * @return boolean TRUE if the number is valid
	 */
	protected function validate_luhn($number)
	{
		// Set the string length and parity
		$number_length = strlen($number);
		$parity = $number_length % 2;

		// Loop through each digit and do the maths
		$total = 0;
		for ($i = 0; $i < $number_length; $i++)
		{
			$digit = $number[$i];
			// Multiply alternate digits by two
			if ($i % 2 == $parity)
			{
				$digit *= 2;
				// If the sum is two digits, add them together (in effect)
				if ($digit > 9)
				{
					$digit -= 9;
				}
			}
			// Total up the digits
			$total += $digit;
		}

		// If the total mod 10 equals 0, the number is valid
		return ($total % 10 == 0) ? TRUE : FALSE;
	}

	/**
	 * Check whether an expiry date has already passed
	 *
	 * @return bool TRUE if the expiry date is valid
	 */
	protected function validate_expiry($month, $year)
	{
		// subtract 12 hours from current GMT time to avoid potential timezone issues
		// in this rare case we will leave it up to the payment gateway to decide
		$date = getdate(gmmktime() - 43200); // 12*60*60

		if ($year < $date['year'])
		{
			return FALSE;
		}

		if ($year == $date['year'] AND $month < $date['mon'])
		{
			return FALSE;
		}

		return TRUE;
	}

	/**
	 * Returns TRUE if the current request was made using HTTPS
	 */
	protected function secure_request()
	{
		if (empty($_SERVER['HTTPS']) OR strtolower($_SERVER['HTTPS']) == 'off')
		{
			return FALSE;
		}

		return TRUE;
	}

	protected function amount_dollars()
	{
		if (in_array($this->param('currency'), Merchant::$CURRENCIES_WITHOUT_DECIMALS))
		{
			return round($this->param('amount'));
		}

		return sprintf('%01.2f', $this->param('amount'));
	}

	protected function amount_cents()
	{
		if (in_array($this->param('currency'), Merchant::$CURRENCIES_WITHOUT_DECIMALS))
		{
			return round($this->param('amount'));
		}

		return round($this->param('amount') * 100);
	}

	/**
	 * Make a standard HTTP GET request.
	 * This method is only public to support the deprecated Merchant::curl_helper() method,
	 * it should not be called from outside a driver class and will be set to protected in future.
	 *
	 * @param string $url The URL to request
	 * @param string $username
	 * @param string $password
	 */
	public function get_request($url, $username = NULL, $password = NULL)
	{
		$ch = curl_init($url);
		return $this->_do_curl_request($ch, $username, $password);
	}

	/**
	 * Make a standard HTTP POST request.
	 * This method is only public to support the deprecated Merchant::curl_helper() method,
	 * it should not be called from outside a driver class and will be set to protected in future.
	 *
	 * @param string $url The URL to request
	 * @param mixed $data An optional string or array of form data which will be appended to the URL
	 * @param string $username
	 * @param string $password
	 */
	public function post_request($url, $data = NULL, $username = NULL, $password = NULL)
	{
		$ch = curl_init($url);

		if (is_array($data))
		{
			$data = http_build_query($data);
		}

		curl_setopt($ch, CURLOPT_POST, TRUE);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

		return $this->_do_curl_request($ch, $username, $password);
	}

	private function _do_curl_request($ch, $username, $password)
	{
		curl_setopt($ch, CURLOPT_HEADER, FALSE);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); // don't check client certificate

		if ($username !== NULL)
		{
			curl_setopt($ch, CURLOPT_USERPWD, $username.':'.$password);
		}

		$response = curl_exec($ch);
		$error = curl_error($ch);

		if ( ! empty($error))
		{
			throw new Merchant_exception($error);
		}

		curl_close($ch);
		return $response;
	}

	/**
	 * Redirect the user to a given URL
	 */
	protected function redirect($url)
	{
		$this->CI->load->helper('url');
		redirect($url);
	}
}

class Merchant_exception extends Exception {}

class Merchant_response
{
	const AUTHORIZED = 'authorized';
	const COMPLETED = 'completed';
	const FAILED = 'failed';
	const REFUNDED = 'refunded';

	/**
	 * @var string one of 'authorized', 'completed', 'failed', or 'refunded'
	 */
	protected $_status;

	/**
	 * @var string a message returned by the payment gateway
	 */
	protected $_message;

	/**
	 * @var string a transaction id returned by the payment gateway
	 */
	protected $_transaction_id;

	public function __construct($status, $message = NULL, $transaction_id = NULL)
	{
		// support deprecated 'declined' status
		if ($status == 'declined') $status = self::FAILED;

		// always require a valid status
		if ( ! in_array($status, array(self::AUTHORIZED, self::COMPLETED, self::FAILED, self::REFUNDED)))
		{
			throw new InvalidArgumentException('Invalid payment status');
		}

		$this->_status = $status;
		$this->_message = $message;
		$this->_transaction_id = $transaction_id;
	}

	public function status()
	{
		return $this->_status;
	}

	public function success()
	{
		return $this->_status !== self::FAILED;
	}

	public function message()
	{
		return $this->_message;
	}

	public function transaction_id()
	{
		return $this->_transaction_id;
	}
}

/* End of file ./libraries/merchant/merchant.php */