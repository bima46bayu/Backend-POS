<?php
namespace App\Console\Commands;
use App\Services\RewardReservationService;
use Illuminate\Console\Command;
class ExpireRewardReservations extends Command { protected $signature='rewards:expire-reservations'; protected $description='Expire pending reward reservations and refund points'; public function handle(RewardReservationService $service): int{$this->info($service->expireDue().' reservations expired.'); return self::SUCCESS;} }
