<?php
	include_class('rental', 'contract', 'inc/model/');
	include_class('rental', 'price_item', 'inc/model/');

	/**
	 * Represents a price item in an invoice. The data is typically built from
	 * an instance of rental_contract_price_item.
	 *
	 */
	class rental_invoice_price_item extends rental_price_item
	{

		protected $decimals;
		protected $invoice_id;
		protected $is_area;
		protected $is_one_time;
		protected $price_per_type;
		protected $price_type_id;
		protected $area;
		protected $count;
		protected $total_price;
		protected $timestamp_start; // Start date for the given invoice
		protected $timestamp_end; // End date for the given invoice
		public static $so;

		public function __construct( int $decimals, int $id, int $invoice_id, string $title, string $agresso_id, bool $is_area, float $price_per_type, float $area, int $count, int $timestamp_start, int $timestamp_end, int $price_type_id )
		{
			parent::__construct($id);
			$this->decimals = (int)$decimals;
			$this->id = (int)$id;
			$this->invoice_id = (int)$invoice_id;
			$this->title = $title;
			$this->agresso_id = $agresso_id;
			$this->is_area = (bool)$is_area;
			$this->price_per_type = (float)$price_per_type;
			$this->area = (float)$area;
			$this->count = (int)$count;
			$this->timestamp_start = (int)$timestamp_start;
			$this->timestamp_end = (int)$timestamp_end;
			$this->total_price = null; // Needs to be re-calculated
			$this->price_type_id = $price_type_id ? $price_type_id : 1;
		}

		public function set_invoice_id( int $invoice_id )
		{
			$this->invoice_id = (int)$invoice_id;
		}

		public function get_invoice_id()
		{
			return $this->invoice_id;
		}

		public function set_is_area( bool $is_area )
		{
			$this->is_area = (bool)$is_area;
			$this->total_price = null; // Needs to be re-calculated
		}

		public function is_area()
		{
			return $this->is_area;
		}

		public function set_count( int $count )
		{
			$this->count = (int)$count;
			$this->total_price = null; // Needs to be re-calculated
		}

		public function set_price( $price_per_type )
		{
			$this->price_per_type = (float)$price_per_type;
			$this->total_price = null; // Needs to be re-calculated
		}

		public function get_price()
		{
			return $this->price_per_type;
		}
		public function set_price_type_id( $price_type_id = 1)
		{
			$this->price_type_id = (int)$price_type_id;
		}

		public function get_price_type_id()
		{
			return $this->price_type_id;
		}

		public function set_area( $area )
		{
			$this->area = (float)$area;
			$this->total_price = null; // Needs to be re-calculated
		}

		public function get_area()
		{
			return $this->area;
		}

		public function get_count()
		{
			return $this->count;
		}

		public function set_total_price( float $total_price )
		{
			$this->total_price = (float)$total_price;
		}

		/**
		 * This method calculated the total price of the invoice price item if it hasn't been done before
		 * 
		 * @return float	the total price of the price item
		 */
		public function get_total_price()
		{
			if ($this->total_price == null) // Needs to be calculated
			{
				// The calculation of the price for complete months (meaning the item applies for the whole month) ..
				$num_of_complete_months = 0;

				// ..is different than the calculation for incomplete months
				$incomplete_months = array();

				// Get the year, month and day from the startdate timestamp
				$date_start = array();
				$date_start['year'] = (int)date('Y', $this->get_timestamp_start());
				$date_start['month'] = (int)date('n', $this->get_timestamp_start());
				$date_start['day'] = (int)date('j', $this->get_timestamp_start());

				// Get the year, month and day from the enddate timestamp
				$date_end = array();
				$date_end['year'] = (int)date('Y', $this->get_timestamp_end());
				$date_end['month'] = (int)date('n', $this->get_timestamp_end());
				$date_end['day'] = (int)date('j', $this->get_timestamp_end());

				// Runs through all the years this price item goes for
				if($this->price_type_id != rental_price_item::PRICE_TYPE_DAY)
				{
					for ($current_year = $date_end['year']; $current_year >= $date_start['year']; $current_year--)
					{
						// Within each year: which months do the price item run for
						// First we set the defaults (whole year)
						$current_start_month = 1; // January
						$current_end_month = 12; // December
						// If we are at the start year, use the start month of this year as start month
						if ($current_year == $date_start['year'])
						{
							$current_start_month = $date_start['month'];
						}

						// If we are at the start year, use the end month of this year as end month
						if ($current_year == $date_end['year'])
						{
							$current_end_month = $date_end['month'];
						}

						// Runs through all of the months of the current year (we go backwards since we go backwards with the years)
						for ($current_month = $current_end_month; $current_month >= $current_start_month; $current_month--)
						{
							// Retrive the number of days in the current month
							$num_of_days_in_current_month = date('t', strtotime($current_year . '-' . $current_month . '-01'));

							// Set the defaults (whole month)
							$first_day = 1;
							$last_day = $num_of_days_in_current_month;

							// If we are at the start month in the start year, use day in this month as first day
							if ($current_year == $date_start['year'] && $current_month == $date_start['month'])
							{
								$first_day = $date_start['day'];
							}

							// If we are at the end month in the end year, use the day in this month as end day
							if ($current_year == $date_end['year'] && $current_month == $date_end['month'])
							{
								$last_day = $date_end['day']; // The end date's day is the item's end day
							}

							// Increase counter: complete months or incomplete months (number of days in this year and number of days )
							if ($first_day === 1 && $last_day == $num_of_days_in_current_month)
							{ // This is a whole month
								$num_of_complete_months++;
							}
							else // Incomplete month
							{
								// YYY: There must be a better day to do this!?
								if($this->price_type_id == rental_price_item::PRICE_TYPE_YEAR)
								{
									$num_of_days_in_current_year = (date('L', strtotime($current_year . '01-01')) == 0) ? 365 : 366;
									$num_of_days = $last_day - $first_day + 1;
									$incomplete_months[] = array($num_of_days_in_current_year, $num_of_days);
								}
								else if($this->price_type_id == rental_price_item::PRICE_TYPE_MONTH)
								{
									$num_of_days = $last_day - $first_day + 1;
									$incomplete_months[] = array($num_of_days_in_current_month, $num_of_days);
								}
								else
								{
									throw new Exception('Price type not supported');
								}
							}
						}
					}
				}
				// ---- Calculate complemete months
				// Retrieve the amount: rented area of contract or the number of items (depending on type of price element)
				$amount = $this->is_area() ? $this->get_area() : $this->get_count();

				// The total price of this price element for complete months
				
				if($this->price_type_id == rental_price_item::PRICE_TYPE_YEAR)
				{
					$this->total_price = (($this->get_price() * $num_of_complete_months) / 12.0) * $amount;
				}
				else if($this->price_type_id == rental_price_item::PRICE_TYPE_MONTH)
				{
					$this->total_price = ($this->get_price() * $num_of_complete_months) * $amount;
				}
				else if($this->price_type_id == rental_price_item::PRICE_TYPE_DAY)
				{
					$date1 = new DateTime(date('Y-m-d', $this->get_timestamp_start()));
					$date2 = new DateTime(date('Y-m-d', $this->get_timestamp_end()));
					$total_num_of_days = $date2->diff($date1)->format("%a");
					$this->total_price = ($this->get_price() * $total_num_of_days) * $amount;
					//block the year and month correction
					$incomplete_months = array();
				}
				else
				{
					throw new Exception('Price type not supported');
				}

				// ---- Calculate incomplete months

				$price_per_type = $this->get_price() * $amount;

				// Run through all the incomplete months ...blocked if rental_price_item::PRICE_TYPE_DAY
				foreach ($incomplete_months as $day_factors)
				{
					// ... and add the sum of each incomplete month to the total price of the price item
					// Calculation: Price per day (price per year divided with number of days in year) multiplied with number of days in incomplete month
					$this->total_price += ($price_per_type / $day_factors[0]) * $day_factors[1];
				}
				// We round the total price for each price item with the specified number of decimals precision
				$this->total_price = round($this->total_price, $this->decimals);
			}
			return $this->total_price;
		}

		public function set_timestamp_start( int $timestamp_start )
		{
			$this->timestamp_start = (int)$timestamp_start;
			$this->total_price = null; // Needs to be re-calculated
		}

		public function get_timestamp_start()
		{
			return $this->timestamp_start;
		}

		public function set_timestamp_end( int $timestamp_end )
		{
			$this->timestamp_end = (int)$timestamp_end;
			$this->total_price = null; // Needs to be re-calculated
		}

		public function get_timestamp_end()
		{
			return $this->timestamp_end;
		}

		public function set_is_one_time( $is_one_time )
		{
			$this->is_one_time = (bool)$is_one_time;
		}

		public function is_one_time()
		{
			return $this->is_one_time;
		}

		public function get_is_one_time()
		{
			return $this->is_one_time;
		}

		public function serialize()
		{
			$date_format = $this->userSettings['preferences']['common']['dateformat'];
			return array
				(
				'title' => $this->get_title(),
				'agresso_id' => $this->get_agresso_id(),
				'is_area' => $this->get_type_text(),
				'is_one_time' => $this->get_is_one_time(),
				'price' => $this->get_price(),
				'area' => $this->get_area(),
				'count' => $this->get_count(),
				'total_price' => $this->get_total_price(),
				'timestamp_start' => date($date_format, $this->get_timestamp_start()),
				'timestamp_end' => date($date_format, $this->get_timestamp_end()),
			);
		}
	}
