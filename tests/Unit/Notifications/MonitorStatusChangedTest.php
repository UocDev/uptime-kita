<?php

use App\Models\NotificationChannel;
use App\Models\User;
use App\Notifications\MonitorStatusChanged;
use App\Services\TelegramRateLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use NotificationChannels\Telegram\TelegramMessage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'John Doe']);
    $this->data = [
        'id' => 1,
        'url' => 'https://example.com',
        'status' => 'DOWN',
        'message' => 'Website https://example.com is DOWN',
    ];
    $this->notification = new MonitorStatusChanged($this->data);
});

describe('MonitorStatusChanged', function () {
    describe('constructor', function () {
        it('stores data correctly', function () {
            expect($this->notification->data)->toBe($this->data);
        });
    });

    describe('via', function () {
        it('returns channels based on user notification channels', function () {
            // Create notification channels for user
            NotificationChannel::factory()->create([
                'user_id' => $this->user->id,
                'type' => 'email',
                'is_enabled' => true,
            ]);
            
            NotificationChannel::factory()->create([
                'user_id' => $this->user->id,
                'type' => 'telegram',
                'is_enabled' => true,
                'destination' => '123456789',
            ]);
            
            $channels = $this->notification->via($this->user);
            
            expect($channels)->toContain('mail');
            expect($channels)->toContain('telegram');
        });

        it('only returns enabled channels', function () {
            NotificationChannel::factory()->create([
                'user_id' => $this->user->id,
                'type' => 'email',
                'is_enabled' => true,
            ]);
            
            NotificationChannel::factory()->create([
                'user_id' => $this->user->id,
                'type' => 'telegram',
                'is_enabled' => false,
                'destination' => '123456789',
            ]);
            
            $channels = $this->notification->via($this->user);
            
            expect($channels)->toContain('mail');
            expect($channels)->not->toContain('telegram');
        });

        it('returns empty array when no channels enabled', function () {
            $channels = $this->notification->via($this->user);
            
            expect($channels)->toBeEmpty();
        });

        it('maps channel types correctly', function () {
            NotificationChannel::factory()->create([
                'user_id' => $this->user->id,
                'type' => 'slack',
                'is_enabled' => true,
            ]);
            
            $channels = $this->notification->via($this->user);
            
            expect($channels)->toContain('slack');
        });
    });

    describe('toMail', function () {
        it('creates mail message with correct content', function () {
            $mailMessage = $this->notification->toMail($this->user);
            
            expect($mailMessage)->toBeInstanceOf(MailMessage::class);
            expect($mailMessage->subject)->toBe('Website Status: DOWN');
            expect($mailMessage->greeting)->toBe('Halo, John Doe');
            
            // Check that the message contains expected content
            $mailData = $mailMessage->data();
            expect($mailData['introLines'])->toContain('Website berikut mengalami perubahan status:');
            expect($mailData['introLines'])->toContain('🔗 URL: https://example.com');
            expect($mailData['introLines'])->toContain('⚠️ Status: DOWN');
        });

        it('includes action button with correct URL', function () {
            $mailMessage = $this->notification->toMail($this->user);
            
            expect($mailMessage->actionText)->toBe('Lihat Detail');
            expect($mailMessage->actionUrl)->toBe(url('/monitors/1'));
        });
    });

    describe('toTelegram', function () {
        it('returns null when no telegram channel exists', function () {
            $result = $this->notification->toTelegram($this->user);
            
            expect($result)->toBeNull();
        });

        it('returns null when telegram channel is disabled', function () {
            NotificationChannel::factory()->create([
                'user_id' => $this->user->id,
                'type' => 'telegram',
                'is_enabled' => false,
                'destination' => '123456789',
            ]);
            
            $result = $this->notification->toTelegram($this->user);
            
            expect($result)->toBeNull();
        });

        it('returns null when rate limited', function () {
            $telegramChannel = NotificationChannel::factory()->create([
                'user_id' => $this->user->id,
                'type' => 'telegram',
                'is_enabled' => true,
                'destination' => '123456789',
            ]);
            
            // Mock rate limit service to deny sending
            $rateLimitService = mock(TelegramRateLimitService::class);
            $rateLimitService->shouldReceive('shouldSendNotification')
                ->with($this->user, $telegramChannel)
                ->andReturn(false);
            
            $this->app->instance(TelegramRateLimitService::class, $rateLimitService);
            
            $result = $this->notification->toTelegram($this->user);
            
            expect($result)->toBeNull();
        });

        it('creates telegram message when conditions are met', function () {
            $telegramChannel = NotificationChannel::factory()->create([
                'user_id' => $this->user->id,
                'type' => 'telegram',
                'is_enabled' => true,
                'destination' => '123456789',
            ]);
            
            // Mock rate limit service to allow sending
            $rateLimitService = mock(TelegramRateLimitService::class);
            $rateLimitService->shouldReceive('shouldSendNotification')
                ->with($this->user, $telegramChannel)
                ->andReturn(true);
            $rateLimitService->shouldReceive('trackSuccessfulNotification')
                ->with($this->user, $telegramChannel)
                ->once();
            
            $this->app->instance(TelegramRateLimitService::class, $rateLimitService);
            
            $result = $this->notification->toTelegram($this->user);
            
            expect($result)->toBeInstanceOf(TelegramMessage::class);
        });

        it('formats DOWN status message correctly', function () {
            $telegramChannel = NotificationChannel::factory()->create([
                'user_id' => $this->user->id,
                'type' => 'telegram',
                'is_enabled' => true,
                'destination' => '123456789',
            ]);
            
            $rateLimitService = mock(TelegramRateLimitService::class);
            $rateLimitService->shouldReceive('shouldSendNotification')->andReturn(true);
            $rateLimitService->shouldReceive('trackSuccessfulNotification');
            
            $this->app->instance(TelegramRateLimitService::class, $rateLimitService);
            
            $result = $this->notification->toTelegram($this->user);
            
            // Check that message contains DOWN indicators
            $reflection = new ReflectionClass($result);
            $contentProperty = $reflection->getProperty('content');
            $contentProperty->setAccessible(true);
            $content = $contentProperty->getValue($result);
            
            expect($content)->toContain('🔴');
            expect($content)->toContain('Website DOWN');
            expect($content)->toContain('https://example.com');
            expect($content)->toContain('Status: *DOWN*');
        });

        it('formats UP status message correctly', function () {
            $this->data['status'] = 'UP';
            $notification = new MonitorStatusChanged($this->data);
            
            $telegramChannel = NotificationChannel::factory()->create([
                'user_id' => $this->user->id,
                'type' => 'telegram',
                'is_enabled' => true,
                'destination' => '123456789',
            ]);
            
            $rateLimitService = mock(TelegramRateLimitService::class);
            $rateLimitService->shouldReceive('shouldSendNotification')->andReturn(true);
            $rateLimitService->shouldReceive('trackSuccessfulNotification');
            
            $this->app->instance(TelegramRateLimitService::class, $rateLimitService);
            
            $result = $notification->toTelegram($this->user);
            
            $reflection = new ReflectionClass($result);
            $contentProperty = $reflection->getProperty('content');
            $contentProperty->setAccessible(true);
            $content = $contentProperty->getValue($result);
            
            expect($content)->toContain('🟢');
            expect($content)->toContain('Website UP');
            expect($content)->toContain('Status: *UP*');
        });
    });

    describe('toArray', function () {
        it('returns array representation', function () {
            $result = $this->notification->toArray($this->user);
            
            expect($result)->toBeArray();
        });
    });
});