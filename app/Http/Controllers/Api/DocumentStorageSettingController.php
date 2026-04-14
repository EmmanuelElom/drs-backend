<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class DocumentStorageSettingController extends Controller
{
    private const SETTING_KEY = 'document_storage_mode';

    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function show(Request $request)
    {
        $this->authorize('viewAny', AppSetting::class);

        return response()->json([
            'data' => $this->serializeSetting($this->getSetting()),
        ]);
    }

    public function update(Request $request)
    {
        if (! Schema::hasTable('app_settings')) {
            abort(503, 'Document storage settings are not initialized yet. Please run the database migrations.');
        }

        $setting = $this->getSetting();
        $this->authorize('update', $setting);

        $data = $request->validate([
            'storage_mode' => ['required', 'in:base64,upload,auto'],
        ]);

        $setting->forceFill([
            'value' => $data['storage_mode'],
        ])->save();

        $this->auditLogger->fromRequest(
            action: 'settings_updated',
            request: $request,
            details: sprintf('Updated document storage mode to %s.', $setting->value)
        );

        return response()->json([
            'message' => 'Document storage mode updated successfully.',
            'data' => $this->serializeSetting($setting),
        ]);
    }

    private function getSetting(): AppSetting
    {
        if (! Schema::hasTable('app_settings')) {
            return new AppSetting([
                'key' => self::SETTING_KEY,
                'value' => 'auto',
            ]);
        }

        return AppSetting::query()->firstOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => 'auto']
        );
    }

    private function serializeSetting(AppSetting $setting): array
    {
        return [
            'key' => $setting->key,
            'storageMode' => $setting->value,
        ];
    }
}
