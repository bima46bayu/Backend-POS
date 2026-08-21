<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReservationResource;
use App\Models\LoyaltyReward;
use App\Models\RewardReservation;
use App\Models\StoreLocation;
use App\Services\RewardQrTokenService;
use App\Services\RewardReservationService;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function index(Request $request)
    {
        return ReservationResource::collection($request->user()->member->reservations()->with($this->relations())->latest()->paginate(20));
    }

    public function store(Request $request, RewardReservationService $service)
    {
        $data = $request->validate(['loyalty_reward_id' => ['required', 'integer', 'exists:loyalty_rewards,id']]);

        return $this->create($request, LoyaltyReward::findOrFail($data['loyalty_reward_id']), $service);
    }

    private function create(Request $request, LoyaltyReward $reward, RewardReservationService $service)
    {
        $data = $request->validate(['store_location_id' => ['nullable', 'integer', 'exists:store_locations,id'], 'idempotency_key' => ['nullable', 'string', 'min:8', 'max:100']]);
        $storeId = (int) ($data['store_location_id'] ?? $reward->store_location_id);
        if (! $storeId) {
            abort(422, 'Pilih cabang pengambilan reward.');
        }
        $key = $request->header('Idempotency-Key') ?: ($data['idempotency_key'] ?? null);
        if (! is_string($key) || strlen($key) < 8 || strlen($key) > 100) {
            abort(422, 'Idempotency-Key header (8-100 chars) is required.');
        }
        [$reservation, $pickup, $created] = $service->create($request->user()->member, $reward, $storeId, $key);
        $payload = (new ReservationResource($reservation->load($this->relations())))->resolve();
        if ($pickup) {
            $payload['pickup_code'] = $pickup;
        }

        return response()->json($payload, $created ? 201 : 200);
    }

    public function show(Request $request, RewardReservation $reservation)
    {
        $this->owned($request, $reservation);

        return new ReservationResource($reservation->load($this->relations()));
    }

    public function cancel(Request $request, RewardReservation $reservation, RewardReservationService $service)
    {
        $this->owned($request, $reservation);

        return new ReservationResource($service->cancel($reservation)->load($this->relations()));
    }

    public function qr(Request $request, RewardReservation $reservation, RewardQrTokenService $qr)
    {
        $this->owned($request, $reservation);
        abort_unless($reservation->status === RewardReservation::PENDING, 422, 'Only pending reservations have QR tokens.');

        return response()->json($qr->issue($reservation));
    }

    public function stores()
    {
        return response()->json(StoreLocation::query()->select('id', 'code', 'name', 'address', 'phone')->orderBy('name')->get());
    }

    private function owned(Request $request, RewardReservation $reservation): void
    {
        abort_unless($reservation->member_id === $request->user()->member_id, 404);
    }

    private function relations(): array
    {
        return ['member:id,code,name,points_balance', 'reward.category', 'reward.minimumTier', 'reward.product:id,name,sku,image_url,description', 'storeLocation:id,code,name,address'];
    }
}
