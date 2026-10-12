<?php

namespace Tests\Feature;

use App\Events\WhatsAppMessageReceived;
use App\Jobs\SendWhatsAppActivationJob;
use App\Models\User;
use App\Models\WhatsAppMessageLog;
use App\Service\MuthobartaSmsService;
use App\Service\WhatsAppCloudService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsAppServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.enabled' => true,
            'services.whatsapp.access_token' => 'test-mock-token',
            'services.whatsapp.phone_number_id' => '1234567890',
            'services.whatsapp.business_account_id' => '9876543210',
            'services.whatsapp.api_version' => 'v22.0',
            'services.whatsapp.activation_template' => 'dropshipper_activation',
            'services.whatsapp.template_language' => 'bn',
            'services.whatsapp.webhook_verify_token' => 'meta_secret_verify_token',
            'services.whatsapp.login_url' => 'https://resellerbrain.com/login',
        ]);
    }

    private function createDropshipper(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Fahim Dropshipper',
            'email' => 'dropshipper_' . uniqid() . '@example.com',
            'password' => bcrypt('secret123'),
            'phone' => '01712345678',
            'user_type' => 'dropshipper',
            'role' => 'customer',
            'banned' => 1,
            'whatsapp_consent' => true,
            'whatsapp_consent_source' => 'dropshipper_registration',
            'whatsapp_consented_at' => now(),
        ], $attributes));
    }

    public function test_unbanning_dropshipper_dispatches_whatsapp_job(): void
    {
        Queue::fake();

        // Mock SMS service so it succeeds cleanly
        $this->mock(MuthobartaSmsService::class, function ($mock) {
            $mock->shouldReceive('send')->once()->andReturn(['success' => true]);
        });

        $dropshipper = $this->createDropshipper();

        $response = $this->patchJson("/api/users/ban/{$dropshipper->id}");

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $dropshipper->id,
            'banned' => 0,
        ]);

        Queue::assertPushed(SendWhatsAppActivationJob::class, function ($job) use ($dropshipper) {
            return $job->user->id === $dropshipper->id;
        });
    }

    public function test_whatsapp_job_sends_template_and_logs_provider_id(): void
    {
        Http::fake([
            'https://graph.facebook.com/v22.0/1234567890/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '8801712345678', 'wa_id' => '8801712345678']],
                'messages' => [['id' => 'wamid.HBgLMTIzNDU2Nzg5MA==']],
            ], 200),
        ]);

        $dropshipper = $this->createDropshipper(['banned' => 0]);

        $job = new SendWhatsAppActivationJob($dropshipper);
        app()->call([$job, 'handle']);

        $this->assertDatabaseHas('whatsapp_message_logs', [
            'user_id' => $dropshipper->id,
            'phone_number' => '8801712345678',
            'template_name' => 'dropshipper_activation',
            'template_language' => 'bn',
            'provider_message_id' => 'wamid.HBgLMTIzNDU2Nzg5MA==',
            'status' => 'sent',
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://graph.facebook.com/v22.0/1234567890/messages'
                && $request['to'] === '8801712345678'
                && $request['template']['name'] === 'dropshipper_activation'
                && $request['template']['components'][0]['parameters'][0]['text'] === 'Fahim Dropshipper';
        });
    }

    public function test_whatsapp_job_skips_when_service_is_disabled(): void
    {
        config(['services.whatsapp.enabled' => false]);
        Http::fake();

        $dropshipper = $this->createDropshipper(['banned' => 0]);

        $job = new SendWhatsAppActivationJob($dropshipper);
        app()->call([$job, 'handle']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('whatsapp_message_logs', 0);
    }

    public function test_whatsapp_job_skips_when_user_opted_out_or_no_consent(): void
    {
        Http::fake();

        $dropshipper = $this->createDropshipper([
            'banned' => 0,
            'whatsapp_consent' => false,
            'whatsapp_opted_out_at' => now(),
        ]);

        $job = new SendWhatsAppActivationJob($dropshipper);
        app()->call([$job, 'handle']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('whatsapp_message_logs', 0);
    }

    public function test_whatsapp_job_is_idempotent_and_prevents_duplicate_notifications(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.FIRST_SEND']],
            ], 200),
        ]);

        $dropshipper = $this->createDropshipper(['banned' => 0]);

        // First run
        $job = new SendWhatsAppActivationJob($dropshipper);
        app()->call([$job, 'handle']);
        $this->assertEquals(1, WhatsAppMessageLog::count());

        // Second run should skip due to existing 'sent' log
        app()->call([$job, 'handle']);
        $this->assertEquals(1, WhatsAppMessageLog::count());
        Http::assertSentCount(1);
    }

    public function test_permanent_meta_error_does_not_retry_indefinitely(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Template does not exist',
                    'type' => 'OAuthException',
                    'code' => 100,
                ],
            ], 400),
        ]);

        $dropshipper = $this->createDropshipper(['banned' => 0]);

        $job = new SendWhatsAppActivationJob($dropshipper);
        // Should not throw unhandled exception on 400 client errors
        app()->call([$job, 'handle']);

        $this->assertDatabaseHas('whatsapp_message_logs', [
            'user_id' => $dropshipper->id,
            'status' => 'failed',
            'error_code' => '100',
        ]);
    }

    public function test_service_can_send_direct_text_message(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.TEXT_REPLY_123']],
            ], 200),
        ]);

        /** @var WhatsAppCloudService $service */
        $service = app(WhatsAppCloudService::class);
        $result = $service->sendTextMessage(
            to: '01712345678',
            text: 'Hello, regarding your product inquiry, stock is available.',
            replyToMessageId: 'wamid.ORIGINAL_QUERY'
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('wamid.TEXT_REPLY_123', $result['message_id']);

        $this->assertDatabaseHas('whatsapp_message_logs', [
            'message_type' => 'text',
            'message_content' => 'Hello, regarding your product inquiry, stock is available.',
            'provider_message_id' => 'wamid.TEXT_REPLY_123',
            'status' => 'sent',
        ]);
    }

    public function test_webhook_handshake_verification(): void
    {
        // Valid token
        $response = $this->get('/api/webhooks/whatsapp?' . http_build_query([
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'meta_secret_verify_token',
            'hub_challenge' => '1158201444',
        ]));

        $response->assertStatus(200);
        $this->assertEquals('1158201444', $response->getContent());

        // Invalid token
        $badResponse = $this->get('/api/webhooks/whatsapp?' . http_build_query([
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'wrong_token',
            'hub_challenge' => '1158201444',
        ]));

        $badResponse->assertStatus(403);
    }

    public function test_webhook_status_delivery_receipt_updates_log(): void
    {
        $log = WhatsAppMessageLog::create([
            'direction' => 'outbound',
            'phone_number' => '8801712345678',
            'provider_message_id' => 'wamid.TRACKED_MESSAGE_456',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $payload = [
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'statuses' => [
                                    [
                                        'id' => 'wamid.TRACKED_MESSAGE_456',
                                        'status' => 'delivered',
                                        'timestamp' => '1730000000',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/api/webhooks/whatsapp', $payload);

        $response->assertStatus(200);
        $this->assertDatabaseHas('whatsapp_message_logs', [
            'id' => $log->id,
            'status' => 'delivered',
        ]);
    }

    public function test_webhook_inbound_dropshipper_message_stores_log_and_fires_event(): void
    {
        Event::fake([WhatsAppMessageReceived::class]);

        $dropshipper = $this->createDropshipper(['phone' => '01712345678']);

        $payload = [
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'messages' => [
                                    [
                                        'from' => '8801712345678',
                                        'id' => 'wamid.INCOMING_QUERY_789',
                                        'timestamp' => '1730000000',
                                        'type' => 'text',
                                        'text' => [
                                            'body' => 'Is product SKU-1002 in stock?',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/api/webhooks/whatsapp', $payload);

        $response->assertStatus(200);

        $this->assertDatabaseHas('whatsapp_message_logs', [
            'direction' => 'inbound',
            'user_id' => $dropshipper->id,
            'phone_number' => '8801712345678',
            'message_content' => 'Is product SKU-1002 in stock?',
            'provider_message_id' => 'wamid.INCOMING_QUERY_789',
            'status' => 'received',
        ]);

        Event::assertDispatched(WhatsAppMessageReceived::class, function ($event) use ($dropshipper) {
            return $event->senderPhone === '8801712345678'
                && $event->messageText === 'Is product SKU-1002 in stock?'
                && $event->user?->id === $dropshipper->id;
        });
    }
}
