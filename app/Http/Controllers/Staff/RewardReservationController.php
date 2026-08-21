<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReservationResource;
use App\Models\RewardReservation;
use App\Services\RewardQrTokenService;
use App\Services\RewardReservationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class RewardReservationController extends Controller
{
    public function index(Request $request, RewardReservationService $service)
    {
        $service->expireDue();
        $query = RewardReservation::with($this->relations())->latest();
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->user()->allowedStoreIds() !== null) {
            $query->whereIn('store_location_id', $request->user()->allowedStoreIds());
        }

        return ReservationResource::collection($query->paginate(min(100, max(1, (int) $request->input('per_page', 20)))));
    }

    public function resolvePickupCode(Request $request, RewardReservationService $service)
    {
        $data = $request->validate(['pickup_code' => ['required', 'string', 'max:30']]);
        $service->expireDue();
        $reservation = RewardReservation::with($this->relations())->where('status', RewardReservation::PENDING)->latest()->get()->first(fn ($item) => Hash::check(trim($data['pickup_code']), $item->pickup_code_hash));
        if (! $reservation) {
            abort(404, 'Pickup code tidak ditemukan.');
        }
        $this->authorizeStore($request, $reservation);
        $payload = (new ReservationResource($reservation))->resolve();
        $payload['pickup_code'] = trim($data['pickup_code']);

        return response()->json($payload);
    }

    public function resolveQr(Request $request, RewardQrTokenService $qr)
    {
        $data = $request->validate(['token' => ['required', 'string']]);
        $reservation = $qr->resolve($data['token']);
        if (! $reservation) {
            abort(422, 'QR token is invalid or expired.');
        }
        $this->authorizeStore($request, $reservation);

        return new ReservationResource($reservation->load($this->relations()));
    }

    public function fulfill(Request $request, RewardReservation $reservation, RewardReservationService $service)
    {
        $data = $request->validate(['store_location_id' => ['required', 'integer', 'exists:store_locations,id']]);
        abort_unless((int) $data['store_location_id'] === (int) $reservation->store_location_id, 422, 'Cabang penyerahan harus sama dengan cabang reservasi.');
        $this->authorizeStore($request, $reservation);

        return new ReservationResource($service->fulfill($reservation, $request->user()->id)->load($this->relations()));
    }

    public function reject(Request $request, RewardReservation $reservation, RewardReservationService $service)
    {
        $this->authorizeStore($request, $reservation);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return new ReservationResource($service->cancel($reservation, $request->user()->id, RewardReservation::REJECTED, $data['reason'])->load($this->relations()));
    }

    private function authorizeStore(Request $request, RewardReservation $reservation): void
    {
        abort_unless($request->user()->canAccessStore($reservation->store_location_id), 403);
    }

    private function relations(): array
    {
        return ['member:id,code,name,points_balance', 'reward.category', 'reward.minimumTier', 'reward.product:id,name,sku,image_url,description', 'storeLocation:id,code,name,address'];
    }
}
