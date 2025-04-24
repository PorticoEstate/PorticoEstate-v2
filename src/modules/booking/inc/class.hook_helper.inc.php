<?php

/**
 * property - Hook helper
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2015 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package booking
 * @version $Id: class.hook_helper.inc.php 13774 2015-08-25 13:29:40Z sigurdne $
 */
/*
	  This program is free software: you can redistribute it and/or modify
	  it under the terms of the GNU General Public License as published by
	  the Free Software Foundation, either version 2 of the License, or
	  (at your option) any later version.

	  This program is distributed in the hope that it will be useful,
	  but WITHOUT ANY WARRANTY; without even the implied warranty of
	  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	  GNU General Public License for more details.

	  You should have received a copy of the GNU General Public License
	  along with this program.  If not, see <http://www.gnu.org/licenses/>.
	 */

use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\controllers\Locations;
use App\Database\Db;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Applications;

phpgw::import_class('phpgwapi.datetime');

/**
 * Hook helper
 *
 * @package booking
 */
class booking_hook_helper
{
	private $location_obj, $db, $userSettings, $app_id, $navbar_bg, $flags, $portal_order, $phpgwapi_common;

	function __construct()
	{
		$this->location_obj		 = new Locations();
		$this->db				 = Db::getInstance();
		$theme = Settings::getInstance()->get('theme');
		$this->navbar_bg = isset($theme['navbar_bg']) ? $theme['navbar_bg'] : '';
		$this->phpgwapi_common = new \phpgwapi_common();
		$this->userSettings = Settings::getInstance()->get('user');
		$this->flags = Settings::getInstance()->get('flags');
		$this->app_id = (new Applications())->name2id('booking');
		$this->portal_order = (array)Cache::session_get('phpgwapi', 'portal_order');
	}
	/*
		  $args = array
		  (
		  'id'		=> $category['id'],
		  'location'	=> $function_name,
		  );

		  $hooks->single($args, 'booking');
		 */

	/**
	 * Handle a new activity being added, create location to hold ACL-data
	 */
	function activity_add($data)
	{
		$this->location_obj->add(".application.{$data['id']}", $data['name'], 'booking', false, null, false, true);
		$this->location_obj->add(".resource.{$data['id']}", $data['name'], 'booking', false, null, false, true);
	}

	/**
	 * Handle a activity being deleted, remove the location
	 */
	function activity_delete($data)
	{
		$this->location_obj->delete('booking', ".application.{$data['id']}", false);
		$this->location_obj->delete('booking', ".resource.{$data['id']}", false);
	}

	/**
	 * Handle a activity being edited, update the location info
	 */
	function activity_edit($data)
	{
		$this->location_obj->update_description(".application.{$data['id']}", $data['name'], 'booking');
		$this->location_obj->update_description(".resource.{$data['id']}", $data['name'], 'booking');
	}

	/**
	 * Alert user if participation registration is set globally
	 */
	function after_navbar()
	{
		$config = CreateObject('phpgwapi.config', 'booking')->read();

		if (!empty($config['participant_limit']) && (int)$config['participant_limit'])
		{
			$message = lang('global participant limit is set to %1', $config['participant_limit']);
			echo '<div class="msg_good">';
			echo $message;
			echo '</div>';
		}
	}

	function resource_add()
	{
		$this->db->query('SELECT id FROM fm_ecomva WHERE id > 0 ORDER BY id', __LINE__, __FILE__);
		$this->db->next_record();
		$tax_code	 = $this->db->f('id');

		$sql = "SELECT bb_resource.id FROM bb_resource"
			. " LEFT JOIN bb_article_mapping ON (bb_resource.id = bb_article_mapping.article_id AND bb_article_mapping.article_cat_id = 1)"
			. " WHERE bb_article_mapping.id IS NULL";

		$this->db->query($sql, __LINE__, __FILE__);
		$resources = array();
		while ($this->db->next_record())
		{
			$resources[] =  $this->db->f('id');
		}

		$add_sql = "INSERT INTO bb_article_mapping ("
			. " article_cat_id, article_id, article_code, unit, tax_code)"
			. " VALUES (?, ?, ?, ?, ?)";

		$article_cat_id	 = 1;

		$insert_update	 = array();

		foreach ($resources as $resource_id)
		{
			$article_code	 = "resource_{$resource_id}";

			$insert_update[] = array(
				1	 => array(
					'value'	 => $article_cat_id,
					'type'	 => PDO::PARAM_INT
				),
				2	 => array(
					'value'	 => $resource_id,
					'type'	 => PDO::PARAM_INT
				),
				3	 => array(
					'value'	 => $article_code,
					'type'	 => PDO::PARAM_STR
				),
				4	 => array(
					'value'	 => 'hour',
					'type'	 => PDO::PARAM_STR
				),
				5	 => array(
					'value'	 => $tax_code,
					'type'	 => PDO::PARAM_INT
				),
			);
		}

		if ($insert_update)
		{
			$this->db->insert($add_sql, $insert_update, __LINE__, __FILE__);
			Cache::message_set(lang('%1 resources mapped as articles', count($insert_update)));
		}
	}

	function home()
	{
		//Just a test for now
		if($this->userSettings['lid'] == 'hc483')
		{
			$this->home_vipps_transactions();
		}
	}
	private function get_controls($app_id)
	{
		$var = array(
			'up'	 => array('url' => '/set_box.php', 'app' => $app_id),
			'down'	 => array('url' => '/set_box.php', 'app' => $app_id),
		);
		return $var;
	}
	/**
	 * Show Vipps pending transactions for dashboard
	 *
	 * @return void
	 */
	public function home_vipps_transactions()
	{
		$accound_id = $this->userSettings['account_id'];
		$save_app = $this->flags['currentapp'];
		$this->flags['currentapp'] = 'booking';
		Settings::getInstance()->set('flags', $this->flags);

		$maxmatches = $this->userSettings['preferences']['common']['maxmatchs'];
		$this->userSettings['preferences']['common']['maxmatchs'] = 10;
		Settings::getInstance()->set('user', $this->userSettings);

		$prefs = $this->userSettings['preferences'];

		// Create a header box for the transactions
		$portalbox = CreateObject('phpgwapi.listbox', array(
			'title'                  => lang('Pending Vipps Transactions'),
			'primary'                => $this->navbar_bg,
			'secondary'              => $this->navbar_bg,
			'tertiary'               => $this->navbar_bg,
			'width'                  => '100%',
			'outerborderwidth'       => '0',
			'header_background_image' => $this->phpgwapi_common->image('phpgwapi', 'bg_filler', '.png', False)
		));

		$app_id = $this->app_id;
		if (!isset($this->portal_order) || !in_array($app_id, $this->portal_order))
		{
			$this->portal_order[] = $app_id;
			Cache::session_set('phpgwapi', 'portal_order', $this->portal_order);
		}

		$var = $this->get_controls($app_id);

		foreach ($var as $key => $value)
		{
			// $portalbox->set_controls($key, $value);
		}

		$portalbox->data = array();

		$grid_element = 'row mt-4';

		echo "\n<div class='container'>";
		echo "\n" . '<!-- BEGIN vipps transaction info -->' . "\n<div class='{$grid_element}' style='padding-left: 10px;'>" . $portalbox->draw() . "</div>\n" . '<!-- END vipps transaction info -->' . "\n";

		echo '<div id="vipps_transaction_container"></div>';

		$lang = js_lang('Remote Order ID', 'Amount', 'Date', 'Description', 'Status', 'Payment Method');

		$js = <<<JS
        <script type="text/javascript">
            // Define filter options
            var filterOptions = {
                label: 'Filter',
                placeholder: 'Alle transaksjoner',
                paramName: 'status',
                options: [
                    { value: 'completed', text: 'Kun fullførte' }
                ]
            };

            var lang = $lang;
            var transactions_url = phpGWLink('booking/getpendingtransactions/vipps', {
                order:'date',
                sort:'desc',
                result:10
            }, true);

            var rTransactions = [{n: 'ResultSet'},{n: 'Result'}];

            var colDefsTransactions = [
                {key: 'remote_order_id', label: lang['Remote Order ID']},
                {key: 'amount', label: lang['Amount'], formatter: function(o) { return o.value + ' NOK'; }},
                {key: 'date', label: lang['Date']},
                {key: 'description', label: lang['Description']},
                {key: 'remote_state', label: lang['Status']},
                {key: 'payment_method', label: lang['Payment Method']}
            ];

            var paginatorTableTransactions = new Array();
            paginatorTableTransactions.limit = 10;
            createPaginatorTable('vipps_transaction_container', paginatorTableTransactions);

            let afterTableLoad = null;
            
            createTableWithFilter(
                'vipps_transaction_container', 
                transactions_url, 
                colDefsTransactions, 
                rTransactions, 
                'pure-table pure-table-bordered',
                paginatorTableTransactions, 
                filterOptions,
                afterTableLoad
            );
        </script>
    JS;

		echo $js;
		echo "\n</div>";

		$this->flags['currentapp'] = $save_app;
		Settings::getInstance()->set('flags', $this->flags);

		$this->userSettings['preferences']['common']['maxmatchs'] = $maxmatches;
		Settings::getInstance()->set('user', $this->userSettings);
	}
}
