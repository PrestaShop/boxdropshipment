<?php
	/**
	 * NOTICE OF LICENSE
	 *
	 * This source file is subject to the Academic Free License (AFL 3.0)
	 * that is bundled with this package in the file LICENSE.txt.
	 * It is also available through the world-wide-web at this URL:
	 * http://opensource.org/licenses/afl-3.0.php
	 * If you did not receive a copy of the license and are unable to
	 * obtain it through the world-wide-web, please send an email
	 * to license@prestashop.com so we can send you a copy immediately.
	 *
	 * @author    boxdrop Group AG
	 * @copyright boxdrop Group AG
	 * @license   http://opensource.org/licenses/afl-3.0.php Academic Free License (AFL 3.0)
	 * International Registered Trademark & Property of boxdrop Group AG
	 */

	/**
	 * Handles various needed AJAX requests for our plugin, eg. API calls
	 * Validates rather strictly
	 *
	 * @author sweber <sw@boxdrop.com>
	 * @package BoxdropShipment
	 */
	class BoxdropAjaxRequest extends Module
	{
		private $action = null;
		private $sdk = null;
		private $valid_request = false;

		/**
		 * Constructor
		 *
		 * @see   Module::__construct()
		 * @param string  $name Module unique name
		 * @param Context $context
		 */
		public function __construct($name = null, Context $context = null)
		{
			parent::__construct($name, $context);
			$this->name = $name;
		}

		/**
		 * Runs a previously set up action, if valid
		 *
		 * @author sweber <sw@boxdrop.com>
		 * @return mixed
		 */
		public function run()
		{
			if ($this->valid_request)
			{
				$action = $this->action;
				if (strstr($this->action, 'admSetupPreset') === false)
				{
					try
					{
						$this->sdk = BoxdropHelper::getBoxdropSDK();
					}
					catch (BoxdropSDKException $e)
					{
						$this->smarty->assign(array(
							'message' => $e->toJSString(),
							'status' => 'error'
						));
						return $this->display(realpath(dirname(__FILE__).'/../').'/boxdropshipment.php', 'ajaxError.tpl');
					}
				}
				return $this->$action();
			}
		}

		/**
		 * Retrieves a shops details via the SDK and returns them JSON-encoded
		 *
		 * @author sweber <sw@boxdrop.com>
		 * @return string
		 */
		private function getShopDetails()
		{
			$shop_id = (int)Tools::getValue('shop_id');
			$response = $this->sdk->request('shops', 'show', array('shop_id' => $shop_id));
			$order = new BoxdropOrder($this->context->cookie->id_cart);
			$order->id_cart = $this->context->cookie->id_cart;
			$order->boxdrop_shop_id = $shop_id;
			$order->id_customer = 0;
			$order->id_order = 0;
			$order->created_at = date('Y-m-d H:i:s');
			$order->save();
			$this->smarty->assign(array('shop' => $response->shop));
			return $this->display(realpath(dirname(__FILE__).'/../').'/boxdropshipment.php', 'carrierListSelectedShop.tpl');
		}

		/**
		 * Creates a shipment for an order
		 *
		 * @author sweber <sw@boxdrop.com>
		 * @return string
		 */
		private function admCreateShipment()
		{
			$order_id = (int)Tools::getValue('order_id');
			$parceldata = array();
			parse_str(Tools::getValue('parceldata'), $parceldata);
			$insurance_amount = (isset($parceldata['insurance'])) ? $parceldata['insurance'] : 0;
			$parceldata = (isset($parceldata['product-parcel'])) ? $parceldata['product-parcel'] : array();
			$order = new Order($order_id);
			$boxdrop_order = BoxdropOrder::retrieveByCartId($order->id_cart);
			$employee_id = $this->context->cookie->__get('id_employee');
			$smarty_data = $boxdrop_order->createBoxdropShipment($order, $parceldata, $insurance_amount, $employee_id, $this->sdk);
			$this->smarty->assign($smarty_data);
			return $this->display(realpath(dirname(__FILE__).'/../').'/boxdropshipment.php', 'adminAjaxCreateConsignment.tpl');
		}

		/**
		 * Reloads the shipment table in the order admin backend
		 *
		 * @author sweber <sw@boxdrop.com>
		 * @return string
		 */
		private function admReloadShipmentTable()
		{
			$order_id = (int)Tools::getValue('order_id');
			$this->smarty->assign(array(
				'products' => BoxdropOrder::getProductsToBeShipped($order_id),
				'shipments' => BoxdropOrderShipment::getByOrderId($order_id)
			));
			return $this->display(realpath(dirname(__FILE__).'/../').'/boxdropshipment.php', 'adminReloadShipmentTable.tpl');
		}

		/**
		 * Sets up the default shipping price preset
		 *
		 * @author sweber <sw@boxdrop.com>
		 * @return string
		 */
		private function admSetupPresetDefault()
		{
			$id_lang = Configuration::get('PS_LANG_DEFAULT');
			$carrier_ids = BoxdropHelper::getCarrierIds();
			foreach ($carrier_ids as $mode => $carrier_id)
			{
				$carrier = new Carrier($carrier_id);
				$country_zone = BoxdropCarrier::createCountryZone($mode, $id_lang);
				BoxdropCarrier::createPricingPresetDefault($mode, $carrier, $country_zone);
			}
			return $this->display(realpath(dirname(__FILE__).'/../').'/boxdropshipment.php', 'adminUpdateShippingPreset.tpl');
		}

		/**
		 * Sets up the default shipping price preset
		 *
		 * @author sweber <sw@boxdrop.com>
		 * @return string
		 */
		private function admSetupPresetFree()
		{
			$id_lang = Configuration::get('PS_LANG_DEFAULT');
			$carrier_ids = BoxdropHelper::getCarrierIds();
			foreach ($carrier_ids as $mode => $carrier_id)
			{
				$carrier = new Carrier($carrier_id);
				$country_zone = BoxdropCarrier::createCountryZone($mode, $id_lang);
				BoxdropCarrier::createPricingPresetFree($mode, $carrier, $country_zone, (float)Tools::getValue('min_total'),
				(float)Tools::getValue('shp_price'));
			}
			return $this->display(realpath(dirname(__FILE__).'/../').'/boxdropshipment.php', 'adminUpdateShippingPreset.tpl');
		}

		/**
		 * Changes the id_carrier in 
		 * - cart
		 * - order
		 * 
		 * Will create an entry in boxdrop_order, if not yet existing
		 * We are NOT changing the shipping price, as the change was made by admin, not by user.
		 * 
		 * @author sweber <sw@boxdrop.com>
		 * @return string
		 */
		private function admChangeCarrier()
		{
			$carrier_id = (int)Tools::getValue('carrier_id');
			$carrier = new Carrier($carrier_id);
			$order_id = (int)Tools::getValue('order_id');
			$order = new Order($order_id);
			$cart = new Cart($order->id_cart);
			$changed = false;
			
			if ($carrier instanceof Carrier &&
			$cart instanceof Cart &&
			$order instanceof Order) {
				
				$order->id_carrier = $carrier_id;
				$order->save();
				$cart->id_carrier = $carrier_id;
				$cart->save();
				
				$boxdrop_order = new BoxdropOrder($order->id_cart);
				
				if ($boxdrop_order->id_cart == 0) {
					
					$boxdrop_order->id_cart = $order->id_cart;
					$boxdrop_order->id_customer = $order->id_customer;
					$boxdrop_order->id_order = $order->id;
					$boxdrop_order->save();
				}
				
				$changed = true;
			}


			$this->smarty->assign(array(
				'changed' => $changed,
			));

			return $this->display(realpath(dirname(__FILE__).'/../').'/boxdropshipment.php', 'adminUpdateOrderCarrier.tpl');
		}

		/**
		 * Validates a request:
		 *
		 * - Basic authentication is already done by PrestaShop core
		 * - Checks if the called action exists in our class
		 * - We have to find the request token in the database and be sure the action is allowed
		 *
		 * If all valid, well setup the private static class vars to simply process the request by calling run() later
		 *
		 * @author sweber <sw@boxdrop.com>
		 */
		public function isValidRequest()
		{
			$action = Tools::getValue('action');
			$valid = false;
			if ($this->isClassMethod('private', $action, 'BoxdropAjaxRequest'))
			{
				$this->action = Tools::getValue('action');
				$this->valid_request = true;
				$valid = true;
			}
			
			if (!$this->isPOSTRequest())
				$valid = false;

			return $valid;
		}

		/**
		 * Checks for a method, type-aware for a given class
		 *
		 * @author sweber <sw@boxdrop.com>
		 * @param  string $type
		 * @param  string $method
		 * @param  string $class
		 * @return boolean
		 */
		private function isClassMethod($type, $method, $class)
		{
			$refl = new ReflectionMethod($class, $method);
			switch ($type)
			{
				case 'static':
					return $refl->isStatic();
				case 'public':
					return $refl->isPublic();
				case 'private':
					return $refl->isPrivate();
			}
			return false;
		}

		/**
		 * Returns true wheter this request is a POST one
		 *
		 * @author sweber <sw@boxdrop.com>
		 * @return boolean
		 */
		private function isPOSTRequest()
		{
			return ($_SERVER['REQUEST_METHOD'] == 'POST');
		}
	}
