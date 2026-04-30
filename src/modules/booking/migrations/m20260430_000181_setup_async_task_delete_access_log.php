<?php

use App\modules\phpgwapi\services\Migration\Migration;

return new class extends Migration
{
    public string $description = 'Set up async task to delete anonymous frontend user access log entries';

    public function up(): void
    {
        $asyncservice = \CreateObject('phpgwapi.asyncservice');
        $asyncservice->delete('booking_async_task_delete_access_log');

        $asyncservice->set_timer(
            ['day' => '*/1'],
            'booking_async_task_delete_access_log',
            'booking.async_task.doRun',
            [
                'task_class' => 'booking.async_task_delete_access_log',
            ]
        );
    }
};
