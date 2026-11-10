<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
	public string $description = 'Add created_by_user_id and created_by_account_id to bb_booking (ref #1327). They record who created an interntildeling, so an SMS sent from it can reach that person. created_by_user_id is the bb_user.id of the citizen who made it through the booking frontend; it is not the phpgw account, because every citizen request runs as the one shared bookingguest account (1022) and that would name nobody. created_by_account_id is the phpgw account of the staff member who made it through the admin form or the case officer create modal, kept for audit; it has no phone source, so it is never used to send anything. At most one of the two is set on a new row, and both are NULL on every pre-existing row and on a new row made by a citizen login that has no bb_user: NULL/NULL means the creator is not known. The foreign key on created_by_user_id is ON DELETE SET NULL, because bb_user rows are deleted (DELETE /booking/users/{id}) and a plain key would turn that into an error for anyone who created an interntildeling; a deleted citizen simply becomes an unknown creator. The foreign key on created_by_account_id is a plain one, like every other key into phpgw_accounts: the audit trail is kept, and deleting a staff account that created an interntildeling is refused until it is dealt with. No index: the SMS reads the creator by bb_booking.id, and deleting a citizen or an account is rare. The migration must run BEFORE the code that lists the two fields in booking_sobooking, or every bb_booking read and insert fails.';

	private const TABLE = 'bb_booking';

	public function up(): void
	{
		$this->assertTableExists(self::TABLE);

		$this->ensureColumn(self::TABLE, 'created_by_user_id', [
			'type' => 'int',
			'precision' => 4,
			'nullable' => true,
		]);

		$this->ensureColumn(self::TABLE, 'created_by_account_id', [
			'type' => 'int',
			'precision' => 4,
			'nullable' => true,
		]);

		if (!$this->constraintExists(self::TABLE, 'bb_booking_created_by_user_id_fkey')) {
			$this->sql("ALTER TABLE " . self::TABLE . " ADD CONSTRAINT bb_booking_created_by_user_id_fkey FOREIGN KEY (created_by_user_id) REFERENCES bb_user(id) ON DELETE SET NULL");
		}

		if (!$this->constraintExists(self::TABLE, 'bb_booking_created_by_account_id_fkey')) {
			$this->sql("ALTER TABLE " . self::TABLE . " ADD CONSTRAINT bb_booking_created_by_account_id_fkey FOREIGN KEY (created_by_account_id) REFERENCES phpgw_accounts(account_id)");
		}
	}
};
