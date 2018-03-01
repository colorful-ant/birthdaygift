<?php
/**
 * Copyright (C) 2018 SLiCK-303
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 *
 * @package    birthdaygift
 * @author     SLiCK-303 <slick_303@hotmail.com>
 * @copyright  2018 SLiCK-303
 * @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
**/

if (!defined('_TB_VERSION_')) {
	exit;
}

/**
 * Class BirthdayGift
 */
class BirthdayGift extends Module
{
	public function __construct()
	{
		$this->name = 'birthdaygift';
		$this->version = '2.0.0';
		$this->author = 'SLiCK-303';
		$this->tab = 'pricing_promotion';
		$this->need_instance = 0;

		$this->conf_keys = [
			'BDAY_GIFT_ENABLE',
			'BDAY_GIFT_GROUP',
			'BDAY_GIFT_VOUCHER',
			'BDAY_GIFT_AMOUNT',
			'BDAY_GIFT_TYPE',
			'BDAY_GIFT_PREFIX',
			'BDAY_GIFT_MINIMAL',
			'BDAY_GIFT_DAYS',
			'BDAY_GIFT_ORDER',
			'BDAY_GIFT_CLEAN_DB'
		];

		$this->bootstrap = true;
		parent::__construct();

		$this->displayName = $this->l('Birthday Gift');
		$this->description = $this->l('Offer your clients a birthday gift automatically.');

		$this->confirmUninstall = $this->l('Are you sure you want to delete all settings and your logs?');

		$secure_key = Configuration::get('BDAY_GIFT_SECURE_KEY');
		if($secure_key === false)
			Configuration::updateValue('BDAY_GIFT_SECURE_KEY', Tools::strtoupper(Tools::passwdGen(16)));
	}

	public function install()
	{
		if (!parent::install() ||
			!Configuration::updateValue('BDAY_GIFT_ENABLE', 1) ||
			!Configuration::updateValue('BDAY_GIFT_GROUP', '3') ||
			!Configuration::updateValue('BDAY_GIFT_VOUCHER', 1) ||
			!Configuration::updateValue('BDAY_GIFT_AMOUNT', 5) ||
			!Configuration::updateValue('BDAY_GIFT_TYPE', 2) ||
			!Configuration::updateValue('BDAY_GIFT_PREFIX', 'BDAY') ||
			!Configuration::updateValue('BDAY_GIFT_MINIMAL', 5) ||
			!Configuration::updateValue('BDAY_GIFT_DAYS', 30) ||
			!Configuration::updateValue('BDAY_GIFT_ORDER', 1) ||
			!Configuration::updateValue('BDAY_GIFT_CLEAN_DB', 0) ||
			!Db::getInstance()->execute('
				CREATE TABLE '._DB_PREFIX_.'log_bday_email (
				`id_log_email` int(11) NOT NULL AUTO_INCREMENT,
				`id_customer` int(11) NOT NULL,
				`id_cart_rule` int(11) NOT NULL,
				`date_add` datetime NOT NULL,
				PRIMARY KEY (`id_log_email`),
				INDEX `id_cart_rule`(`id_cart_rule`),
				INDEX `date_add`(`date_add`)
			) ENGINE='._MYSQL_ENGINE_)
		) {
			return false;
		}
		return true;
	}

	public function uninstall()
	{
		foreach ($this->conf_keys as $key)
			Configuration::deleteByName($key);

		Configuration::deleteByName('BDAY_GIFT_SECURE_KEY');

		Db::getInstance()->execute('DROP TABLE '._DB_PREFIX_.'log_bday_email');

		return parent::uninstall();
	}

	public function getContent()
	{
		$html = '';
		/* Save settings */
		if (Tools::isSubmit('submitBirthdayGift'))
		{
			$ok = true;
			foreach ($this->conf_keys as $c)
				if(Tools::getValue($c) !== false) // Prevent saving when URL is wrong
					$ok &= Configuration::updateValue($c, Tools::getValue($c));
			if ($ok)
				$html .= $this->displayConfirmation($this->l('Settings updated successfully'));
			else
				$html .= $this->displayError($this->l('Error occurred during settings update'));
		}
		$html .= $this->renderForm();
		$html .= $this->renderStats();

		return $html;
	}

	/* Log each sent e-mail */
	private function logEmail($id_cart_rule, $id_customer = null)
	{
		$values = [
			'id_cart_rule' => (int)$id_cart_rule,
			'date_add' => date('Y-m-d H:i:s')
		];
		if (!empty($id_customer))
			$values['id_customer'] = (int)$id_customer;
		Db::getInstance()->insert('log_bday_email', $values);
	}

	private function getLogsEmail()
	{
		static $id_list = [];
		static $executed = false;

		if (!$executed)
		{
			$query = '
			SELECT id_cart_rule, id_customer FROM '._DB_PREFIX_.'log_bday_email
			WHERE date_add >= CURRENT_DATE';
			$results = Db::getInstance()->executeS($query);
			foreach ($results as $line)
			{
				$id_list[] = $line['id_customer'];
			}
			$executed = true;
		}

		return $id_list;
	}

	/* For all customers with a birthday today */
	/**
	 * bdayCustomer send mails to all customers with a birthday today,
	 * with at least one valid order in history
	 *
	 * @param boolean $count if set to true, will return number of customer (default : false, will send mails, no return value)
	 *
	 * @return void
	 */
	private function bdayCustomer($count = false)
	{
		$shop_email = (string) Configuration::get('PS_SHOP_EMAIL');
		$shop_name = (string) Configuration::get('PS_SHOP_NAME');

		$conf = Configuration::getMultiple([
			'BDAY_GIFT_GROUP',
			'BDAY_GIFT_AMOUNT',
			'BDAY_GIFT_TYPE',
			'BDAY_GIFT_DAYS',
			'BDAY_GIFT_VOUCHER',
			'BDAY_GIFT_ORDER'
		]);

		if ((int)$conf['BDAY_GIFT_TYPE'] == 1)
		{
			$amount = (float) $conf['BDAY_GIFT_AMOUNT'].'%';
		} else {
			$amount = '$'.(float) $conf['BDAY_GIFT_AMOUNT'];
		}

		$customer_group = implode(',', (array) $conf['BDAY_GIFT_GROUP']);

		$email_logs = $this->getLogsEmail();

		if ((int) $conf['BDAY_GIFT_ORDER'] == 1)
		{
			$sql = '
				SELECT DISTINCT c.id_customer, c.id_shop, c.id_lang, c.firstname, c.lastname, c.email
				FROM '._DB_PREFIX_.'customer c
				LEFT JOIN '._DB_PREFIX_.'customer_group cg ON (c.id_customer = cg.id_customer)
				LEFT JOIN '._DB_PREFIX_.'orders o ON (c.id_customer = o.id_customer)
				WHERE o.valid = 1
				AND cg.id_group IN ('.$customer_group.')
				AND c.birthday LIKE \'%'.date('-m-d').'\'';
		} else {
			$sql = '
				SELECT DISTINCT c.id_customer, c.id_shop, c.id_lang, c.firstname, c.lastname, c.email
				FROM '._DB_PREFIX_.'customer c
				LEFT JOIN '._DB_PREFIX_.'customer_group cg ON (c.id_customer = cg.id_customer)
				WHERE cg.id_group IN ('.$customer_group.')
				AND c.birthday LIKE \'%'.date('-m-d').'\'';
		}

		$sql .= Shop::addSqlRestriction(Shop::SHARE_CUSTOMER, 'c');

		if (!empty($email_logs))
			$sql .= ' AND c.id_customer NOT IN ('.join(',', $email_logs).') ';

		$emails = Db::getInstance()->executeS($sql);

		if ($count || !count($emails))
			return count($emails);

		foreach ($emails as $email)
		{
			if ($conf['BDAY_GIFT_VOUCHER'] == 1)
			{
				$voucher = $this->createVoucher((int)$email['id_customer']);
				$code = $voucher->code;
				$template = 2;
			} else {
				$voucher = false;
				$code = '';
				$template = 1;
			}

			$template_vars = [
				'{email}' => $email['email'],
				'{lastname}' => $email['lastname'],
				'{firstname}' => $email['firstname'],
				'{amount}' => $amount,
				'{days}' => (int)$conf['BDAY_GIFT_DAYS'],
				'{voucher_num}' => $code
			];

			Mail::Send(
				(int)$email['id_lang'],
				'birthday'.$template.'',
				Mail::l('Happy Birthday', (int)$email['id_lang']),
				$template_vars,
				$email['email'],
				$email['firstname'].' '.$email['lastname'],
				$shop_email,
				$shop_name,
				null,
				null,
				dirname(__FILE__).'/mails/');

			$this->logEmail((int)$voucher->id, (int)$email['id_customer']);
		}
	}

	private function createVoucher($id_customer)
	{
		$conf = Configuration::getMultiple([
			'BDAY_GIFT_AMOUNT',
			'BDAY_GIFT_TYPE',
			'BDAY_GIFT_PREFIX',
			'BDAY_GIFT_DAYS',
			'BDAY_GIFT_MINIMAL'
		]);

		$cart_rule = new CartRule();
		if ((int)$conf['BDAY_GIFT_TYPE'] == 1)
			$cart_rule->reduction_percent = (float) $conf['BDAY_GIFT_AMOUNT'];
		else
			$cart_rule->reduction_amount = (float) $conf['BDAY_GIFT_AMOUNT'];

		$cart_rule->id_customer = (int)$id_customer;
		$cart_rule->date_to = strftime('%Y-%m-%d', strtotime('+'.(int) $conf['BDAY_GIFT_DAYS'].' day'));
		$cart_rule->date_from = date('Y-m-d H:i:s');
		$cart_rule->quantity = 1;
		$cart_rule->quantity_per_user = 1;
		$cart_rule->highlight = 1;
		$cart_rule->cart_rule_restriction = 0;
		$cart_rule->minimum_amount = (float) $conf['BDAY_GIFT_MINIMAL'];

		$languages = Language::getLanguages(true);
		foreach ($languages as $language)
			$cart_rule->name[(int)$language['id_lang']] = $this->l('Birthday Gift');

		$code = (string)$conf['BDAY_GIFT_PREFIX'].'-'.Tools::strtoupper(Tools::passwdGen(10));
		$cart_rule->code = $code;
		$cart_rule->active = 1;
		if (!$cart_rule->add())
			return false;

		return $cart_rule;
	}

	public function cronTask()
	{
		Context::getContext()->link = new Link(); //when this is call by cron context is not init
		$conf = Configuration::getMultiple([
			'BDAY_GIFT_ENABLE',
			'BDAY_GIFT_PREFIX',
			'BDAY_GIFT_CLEAN_DB'
		]);

		if ((int)$conf['BDAY_GIFT_ENABLE'])
			$this->bdayCustomer();

		/* Clean-up database by deleting all outdated vouchers */
		if ($conf['BDAY_GIFT_CLEAN_DB'] == 1)
		{
			$outdated_vouchers = Db::getInstance()->executeS('
				SELECT id_cart_rule
				FROM '._DB_PREFIX_.'cart_rule
				WHERE date_to < NOW()
				AND code LIKE "'.$conf['BDAY_GIFT_ENABLE'].'-%"
			');

			foreach ($outdated_vouchers as $outdated_voucher)
			{
				$cart_rule = new CartRule((int)$outdated_voucher['id_cart_rule']);
				if (Validate::isLoadedObject($cart_rule))
					$cart_rule->delete();
			}
		}
	}

	public function renderStats()
	{
		$stats = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
			SELECT DATE_FORMAT(l.date_add, \'%Y-%m-%d\') date_stat, l.id_cart_rule, COUNT(l.id_log_email) nb,
			(SELECT COUNT(l2.id_cart_rule)
			FROM '._DB_PREFIX_.'log_bday_email l2
			LEFT JOIN '._DB_PREFIX_.'order_cart_rule ocr ON (ocr.id_cart_rule = l2.id_cart_rule)
			LEFT JOIN '._DB_PREFIX_.'orders o ON (o.id_order = ocr.id_order)
			WHERE l2.date_add = l.date_add AND ocr.id_order IS NOT NULL AND o.valid = 1) nb_used
			FROM '._DB_PREFIX_.'log_bday_email l
			WHERE l.date_add >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
			GROUP BY DATE_FORMAT(l.date_add, \'%Y-%m-%d\')');

			$stats_array = [];
		foreach ($stats as $stat)
		{
			$stats_array[$stat['date_stat']][4]['nb'] = (int)$stat['nb'];
			$stats_array[$stat['date_stat']][4]['nb_used'] = (int)$stat['nb_used'];
		}

		foreach ($stats_array as $date_stat => $array)
		{
			$rates = [];
			if (isset($stats_array[$date_stat][4]['nb']) && isset($stats_array[$date_stat][4]['nb_used']) && $stats_array[$date_stat][4]['nb_used'] > 0)
				$rates[4] = number_format(($stats_array[$date_stat][4]['nb_used'] / $stats_array[$date_stat][4]['nb']) * 100, 2, '.', '');
			$stats_array[$date_stat][4]['nb'] = isset($stats_array[$date_stat][4]['nb']) ? (int)$stats_array[$date_stat][4]['nb'] : 0;
			$stats_array[$date_stat][4]['nb_used'] = isset($stats_array[$date_stat][4]['nb_used']) ? (int)$stats_array[$date_stat][4]['nb_used'] : 0;
			$stats_array[$date_stat][4]['rate'] = isset($rates[4]) ? $rates[4] : '0.00';
			ksort($stats_array[$date_stat]);
		}

		$this->context->smarty->assign(['stats_array' => $stats_array]);

		return $this->display(__FILE__, 'stats.tpl');
	}

	public function renderForm()
	{
		$currency = new Currency((int)Configuration::get('PS_CURRENCY_DEFAULT'));

		$c1 = $this->bdayCustomer(true);

		$cron_info = '';
		if (Shop::getContext() === Shop::CONTEXT_SHOP)
			$cron_info = $this->l('Define the settings and paste the following URL in the crontab, or call it manually on a daily basis:').'<br />
								<b>'.$this->context->shop->getBaseURL().'modules/birthdaygift/cron.php?secure_key='.Configuration::get('BDAY_GIFT_SECURE_KEY').'</b></p>';

		$fields_form_1 = [
			'form' => [
				'legend' => [
					'title' => $this->l('Information'),
					'icon'  => 'icon-cogs',
				],
				'description' => $cron_info,
			],
		];

		$fields_form_2 = [
			'form' => [
				'legend' => [
					'title' => $this->l('E-Mails to send'),
					'icon'  => 'icon-cogs',
				],
				'input' => [
					[
						'type'    => 'switch',
						'label'   => $this->l('Enable'),
						'name'    => 'BDAY_GIFT_ENABLE',
						'hint'    => $this->l('Activate sending of birthday message'),
						'is_bool' => true,
						'values'  => [
							[
								'id'      => 'active_on',
								'value'   => 1,
								'label'   => $this->l('Enabled'),
							],
							[
								'id'      => 'active_off',
								'value'   => 0,
								'label'   => $this->l('Disabled'),
							],
						],
					],
					[
						'type'    => 'radio',
						'label'   => $this->l('Include voucher: '),
						'name'    => 'BDAY_GIFT_VOUCHER',
						'hint'    => $this->l('Activate creating a voucher'),
						'is_bool' => true,
						'values'  => [
							[
								'id'      => 'include_voucher_enable',
								'value'   => 1,
								'checked' => 'checked',
								'label'   => $this->l('Enabled'),
							],
							[
								'id'      => 'include_voucher_disable',
								'value'   => 0,
								'label'   => $this->l('Disabled'),
							],
						],
					],
					[
						'type'    => 'text',
						'label'   => $this->l('Voucher prefix: '),
						'name'    => 'BDAY_GIFT_PREFIX',
						'hint'    => $this->l('Prefix for the voucher code')
					],
					[
						'type'    => 'radio',
						'label'   => $this->l('Voucher type: '),
			                        'name'   => 'BDAY_GIFT_TYPE',
						'hint'    => $this->l('Pick a percentage or fixed amount for the voucher'),
						'values'  => [
							[
								'id'    => 'voucher_type1',
								'value'   => 1,
								'label'   => $this->l('Voucher offering a percentage'),
							],
							[
								'id'    => 'voucher_type2',
								'value'   => 2,
								'label'   => $this->l('Voucher offering a fixed amount'),
							],
						],
					],
					[
						'type'    => 'text',
						'label'   => $this->l('Voucher value: '),
						'name'    => 'BDAY_GIFT_AMOUNT',
						'hint'    => $this->l('The percentage or fixed amount the voucher is worth')
					],
					[
						'type'    => 'text',
						'label'   => $this->l('Voucher validity'),
						'name'    => 'BDAY_GIFT_DAYS',
						'hint'    => $this->l('How many days the voucher is good for'),
						'suffix'  => $this->l('day(s)'),
					],
					[
						'type'    => 'text',
						'label'   => $this->l('Minimal Order: '),
						'name'    => 'BDAY_GIFT_MINIMAL',
						'hint'    => $this->l('The minimum order amount needed to use the voucher'),
					],
					[
						'type'    => 'radio',
						'label'   => $this->l('Valid order needed: '),
						'name'    => 'BDAY_GIFT_ORDER',
						'hint'    => $this->l('Disabled equals send to all users'),
						'is_bool' => true,
						'values'  => [
							[
								'id'      => 'valid_order_enable',
								'value'   => 1,
								'checked' => 'checked',
								'label'   => $this->l('Enabled'),
							],
							[
								'id'      => 'valid_order_disable',
								'value'   => 0,
								'label'   => $this->l('Disabled'),
							],
						],
					],
					[
						'type'    => 'text',
						'label'   => $this->l('Group access: '),
						'name'    => 'BDAY_GIFT_GROUP',
						'hint'    => $this->l('Enter your group ids, separated by commas')
					],
					[
						'type'    => 'desc',
						'name'    => '',
						'text'    => sprintf($this->l('Next process will send: %d e-mail(s)'), $c1),
					],
				],
				'submit' => [
					'title' => $this->l('Save'),
					'class' => 'btn btn-default pull-right',
				],
			],
		];

		$fields_form_3 = [
			'form' => [
				'legend' => [
					'title' => $this->l('General'),
					'icon'  => 'icon-cogs'
				],
				'input'  => [
					[
						'type'    => 'switch',
						'is_bool' => true, //retro-compat
						'label'   => $this->l('Delete outdated vouchers during each launch to clean database'),
						'name'    => 'BDAY_GIFT_CLEAN_DB',
						'values'  => [
							[
								'id'    => 'active_on',
								'value' => 1,
								'label' => $this->l('Enabled'),
							],
							[
								'id'    => 'active_off',
								'value' => 0,
								'label' => $this->l('Disabled'),
							],
						],
					],
				],
				'submit' => [
					'title' => $this->l('Save'),
					'class' => 'btn btn-default pull-right',
				],
			],
		];

		$helper = new HelperForm();
		$helper->show_toolbar = false;
		$helper->table = $this->table;
		$lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
		$helper->default_form_language = $lang->id;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
		$helper->identifier = $this->identifier;
		$helper->override_folder = '/';
		$helper->module = $this;
		$helper->submit_action = 'submitBirthdayGift';
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->tpl_vars = [
			'fields_value' => $this->getConfigFieldsValues(),
			'languages'    => $this->context->controller->getLanguages(),
			'id_language'  => $this->context->language->id
		];

		return $helper->generateForm([
			$fields_form_1,
			$fields_form_2,
			$fields_form_3
		]);
	}

	public function getConfigFieldsValues()
	{
		return [
			'BDAY_GIFT_ENABLE'   => Tools::getValue('BDAY_GIFT_ENABLE', (int) Configuration::get('BDAY_GIFT_ENABLE')),
			'BDAY_GIFT_GROUP'    => Tools::getValue('BDAY_GIFT_GROUP', (string) Configuration::get('BDAY_GIFT_GROUP')),
			'BDAY_GIFT_VOUCHER'  => Tools::getValue('BDAY_GIFT_VOUCHER', (int) Configuration::get('BDAY_GIFT_VOUCHER')),
			'BDAY_GIFT_AMOUNT'   => Tools::getValue('BDAY_GIFT_AMOUNT', (float) Configuration::get('BDAY_GIFT_AMOUNT')),
			'BDAY_GIFT_TYPE'     => Tools::getValue('BDAY_GIFT_TYPE', (int) Configuration::get('BDAY_GIFT_TYPE')),
			'BDAY_GIFT_PREFIX'   => Tools::getValue('BDAY_GIFT_PREFIX', (string) Configuration::get('BDAY_GIFT_PREFIX')),
			'BDAY_GIFT_MINIMAL'  => Tools::getValue('BDAY_GIFT_MINIMAL', (float) Configuration::get('BDAY_GIFT_MINIMAL')),
			'BDAY_GIFT_DAYS'     => Tools::getValue('BDAY_GIFT_DAYS', (int) Configuration::get('BDAY_GIFT_DAYS')),
			'BDAY_GIFT_ORDER'    => Tools::getValue('BDAY_GIFT_ORDER', (int) Configuration::get('BDAY_GIFT_ORDER')),
			'BDAY_GIFT_CLEAN_DB' => Tools::getValue('BDAY_GIFT_CLEAN_DB', (int) Configuration::get('BDAY_GIFT_CLEAN_DB')),
		];
	}
}
