<?php

namespace Database\Seeders;

use App\Models\Document;
use App\Models\DocumentAssignment;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'username' => 'admin',
                'password' => 'admin123',
                'role' => 'admin',
            ]
        );

        $user = User::query()->updateOrCreate(
            ['email' => 'user@example.com'],
            [
                'username' => 'user',
                'password' => 'user123',
                'role' => 'user',
            ]
        );

        Document::query()->updateOrCreate(
            ['document_uuid' => 'doc1'],
            [
                'owner_id' => $admin->id,
                'created_by_id' => $admin->id,
                'user_id' => $user->id,
                'assigned_by_id' => $admin->id,
                'title' => 'Confidential Business Agreement',
                'content' => "CONFIDENTIAL BUSINESS AGREEMENT\n\nThis Confidential Business Agreement is entered into as of March 27, 2026.\n\nPlease review the document carefully and provide your feedback.",
                'days_allowed' => 7,
                'assigned_at' => Carbon::parse('2026-03-27 00:00:00'),
                'sent_at' => Carbon::parse('2026-03-27 00:00:00'),
                'expires_at' => Carbon::parse('2026-03-27 00:00:00')->addDays(7),
                'status' => 'in-review',
                'review_acknowledged' => false,
                'signature_invited' => false,
                'signature_completed' => false,
                'storage_mode' => 'base64',
            ]
        );

        $assignedDocument = Document::query()->where('document_uuid', 'doc1')->firstOrFail();

        DocumentAssignment::query()->updateOrCreate(
            [
                'document_id' => $assignedDocument->id,
                'user_id' => $user->id,
            ],
            [
                'assigned_by' => $admin->id,
                'assigned_at' => Carbon::parse('2026-03-27 00:00:00'),
                'expires_at' => Carbon::parse('2026-03-27 00:00:00')->addDays(7),
                'days_allowed' => 7,
                'status' => 'in-review',
            ]
        );

        Document::query()->updateOrCreate(
            ['document_uuid' => 'doc-library-1'],
            [
                'owner_id' => $user->id,
                'created_by_id' => $user->id,
                'user_id' => null,
                'assigned_by_id' => null,
                'title' => 'Saved Draft Policy',
                'content' => 'This is a saved library document.',
                'days_allowed' => null,
                'assigned_at' => null,
                'sent_at' => null,
                'expires_at' => null,
                'status' => 'saved',
                'review_acknowledged' => false,
                'signature_invited' => false,
                'signature_completed' => false,
                'storage_mode' => 'base64',
            ]
        );

        AppSetting::query()->updateOrCreate(
            ['key' => 'document_storage_mode'],
            ['value' => 'auto']
        );
    }
}
