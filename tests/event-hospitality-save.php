<?php

namespace App\modules\booking\repositories {
	class HospitalityOrderRepository
	{
		public static array $orders = [];
		public function getOrdersWithLinesByApplication(int $id): array { return self::$orders; }
	}
}

namespace {
	use App\modules\booking\repositories\HospitalityOrderRepository;
	use App\modules\booking\services\HospitalityOrderDiff;

	require __DIR__ . '/../src/modules/booking/services/HospitalityOrderDiff.php';
	class_alias(HospitalityOrderRepository::class, 'HospitalityOrderRepository');
	class_alias(HospitalityOrderDiff::class, 'HospitalityOrderDiff');

	function lang($key) { return $key; }

	class Sanitizer
	{
		public static array $values = [];
		public static function get_var($key) { return self::$values[$key] ?? null; }
	}

	class PurchaseOrderStub
	{
		public function add_purchase_order($order) { return 1; }
		public function get_single_purchase_order($id) { return ['sum' => 8937.50]; }
	}

	class EventStub
	{
		public array $saved = [];
		public function update($event) { $this->saved = $event; }
	}

	class EventSaveHarness
	{
		public $sopurchase_order;
		public $bo;
		public array $history = [];
		public function __construct()
		{
			$this->sopurchase_order = new PurchaseOrderStub();
			$this->bo = new EventStub();
		}
		public function add_cost_history(&$event, $comment, $cost) { $this->history[] = $cost; }

		public function run(array $orders, bool $articles, array $selected, float $posted): array
		{
			$source = file_get_contents(__DIR__ . '/../src/modules/booking/inc/class.uievent.inc.php');
			$calculationStart = strpos($source, "\t\t\$hospitality_cost_ore = 0;");
			$calculationEnd = strpos($source, "\t\tif (\$event['description'])", $calculationStart);
			$saveStart = strpos($source, "\t\t\t\t\t\$purchase_order = array(", $calculationEnd);
			$saveEnd = strpos($source, "\t\t\t\t\t\$receipt = \$this->bo->update(\$event);", $saveStart);
			if ($calculationStart === false || $calculationEnd === false || $saveStart === false || $saveEnd === false)
			{
				throw new \RuntimeException('Event cost blocks not found');
			}
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_POST['cost_orig'] = '8937.50';
			$config = ['activate_application_articles' => $articles];
			$event = ['application_id' => 1, 'cost' => $posted];
			$errors = [];
			$id = 1;
			Sanitizer::$values = ['selected_articles' => $selected, 'cost_comment' => ''];
			HospitalityOrderRepository::$orders = $orders;
			eval(substr($source, $calculationStart, $calculationEnd - $calculationStart));
			if (empty($errors))
			{
				eval(substr($source, $saveStart, $saveEnd - $saveStart) . '$this->bo->update($event);');
			}
			return [$this->bo->saved, $errors, $this->history];
		}
	}

	$orders = [
		['status' => 'pending', 'lines' => [['amount' => '360.00', 'tax_percent' => 25], ['amount' => '1600.00', 'tax_percent' => 25]]],
		['status' => 'cancelled', 'lines' => [['amount' => '500.00', 'tax_percent' => null]]],
	];
	[$event, $errors, $history] = (new EventSaveHarness())->run($orders, true, ['1_1_75_1'], 11387.50);
	if ($errors !== [] || $event['cost'] !== 11387.50 || $history !== [11387.50])
	{
		throw new \RuntimeException('Combined cost was not saved');
	}

	[$event, $errors] = (new EventSaveHarness())->run($orders, false, [], 7000.00);
	if ($errors !== [] || $event['cost'] !== 7000.00)
	{
		throw new \RuntimeException('Manual cost was not preserved');
	}

	$orders[0]['lines'][0]['tax_percent'] = null;
	[$event, $errors] = (new EventSaveHarness())->run($orders, true, ['1_1_75_1'], 11387.50);
	if (!isset($errors['cost']) || $event !== [])
	{
		throw new \RuntimeException('Invalid hospitality VAT did not block saving');
	}
	echo "Event hospitality save: passed\n";
}
