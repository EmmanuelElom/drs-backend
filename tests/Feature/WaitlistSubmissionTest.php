<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaitlistSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_waitlist_submissions_are_persisted_and_deduplicated(): void
    {
        $payload = [
            'email' => 'launch@example.com',
            'source_path' => '/',
        ];

        $this->postJson('/api/waitlist', $payload)
            ->assertCreated()
            ->assertJsonPath('message', 'You are on the wait list.')
            ->assertJsonPath('data.email', 'launch@example.com')
            ->assertJsonPath('data.sourcePath', '/');

        $this->assertDatabaseHas('waitlist_submissions', [
            'email' => 'launch@example.com',
            'source_path' => '/',
        ]);

        $this->postJson('/api/waitlist', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'You are already on the wait list.')
            ->assertJsonPath('data.email', 'launch@example.com');

        $this->assertDatabaseCount('waitlist_submissions', 1);
    }

    public function test_waitlist_submission_validates_email_addresses(): void
    {
        $this->postJson('/api/waitlist', [
            'email' => 'not-an-email',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }
}
