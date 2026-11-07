<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('chat:recover')->everyMinute()->withoutOverlapping();
