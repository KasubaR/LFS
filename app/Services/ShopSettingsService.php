<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shop-wide settings (single row, id = 1). The online sell-out threshold is
 * per size: once a size's stock is at or below it, the website treats that
 * size as sold out and the remaining units are kept for offline sales.
 */
class ShopSettingsService
{
    public const DEFAULT_THRESHOLD = 20;

    private ?int $threshold = null;

    public function onlineSelloutThreshold(): int
    {
        if ($this->threshold === null) {
            $value = Schema::hasTable('shop_settings')
                ? DB::table('shop_settings')->where('id', 1)->value('online_sellout_threshold')
                : null;
            $this->threshold = $value !== null ? max(0, (int) $value) : self::DEFAULT_THRESHOLD;
        }

        return $this->threshold;
    }

    public function setOnlineSelloutThreshold(int $threshold, ?int $adminId = null): void
    {
        DB::table('shop_settings')->updateOrInsert(
            ['id' => 1],
            [
                'online_sellout_threshold' => max(0, $threshold),
                'updated_by' => $adminId,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $this->threshold = null;
    }

    /**
     * How many units of a size the website may sell: stock above the threshold.
     */
    public function onlineStock(int $stock): int
    {
        return max(0, $stock - $this->onlineSelloutThreshold());
    }
}
