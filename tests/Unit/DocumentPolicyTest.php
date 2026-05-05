<?php

namespace Tests\Unit;

use App\Models\Comment;
use App\Models\Document;
use App\Models\User;
use App\Policies\DocumentPolicy;
use PHPUnit\Framework\TestCase;

class DocumentPolicyTest extends TestCase
{
    public function test_assigned_reviewer_can_view_review_and_manage_the_document(): void
    {
        $policy = new DocumentPolicy();

        $user = new User();
        $user->id = 7;
        $user->role = 'user';

        $document = new Document();
        $document->user_id = '7';
        $document->signature_invited = true;

        $comment = new Comment();
        $comment->user_id = '7';

        $this->assertTrue($policy->view($user, $document));
        $this->assertTrue($policy->comment($user, $document));
        $this->assertTrue($policy->acknowledge($user, $document));
        $this->assertTrue($policy->sign($user, $document));
        $this->assertTrue($policy->deleteComment($user, $document, $comment));
    }

    public function test_unrelated_user_is_denied_access_to_the_review_flow(): void
    {
        $policy = new DocumentPolicy();

        $user = new User();
        $user->id = 8;
        $user->role = 'user';

        $document = new Document();
        $document->user_id = '7';
        $document->signature_invited = true;

        $comment = new Comment();
        $comment->user_id = '7';

        $this->assertFalse($policy->view($user, $document));
        $this->assertFalse($policy->comment($user, $document));
        $this->assertFalse($policy->acknowledge($user, $document));
        $this->assertFalse($policy->sign($user, $document));
        $this->assertFalse($policy->deleteComment($user, $document, $comment));
    }
}
