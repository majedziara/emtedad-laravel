<?php

use App\Models\EmailVerificationCode;
use Illuminate\Support\Facades\Schedule;

Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('auth:clear-resets')->daily();
Schedule::call(fn() => EmailVerificationCode::where('expires_at', '<', now())->delete())->daily();
