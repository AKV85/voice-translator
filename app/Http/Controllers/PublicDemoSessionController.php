<?php

namespace App\Http\Controllers;

use App\Enums\Language;
use App\Services\PublicDemo\PublicDemoTokenStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class PublicDemoSessionController extends Controller
{
    private const HOURLY_DECAY_SECONDS =
        3600;

    private const DAILY_DECAY_SECONDS =
        86400;

    public function __invoke(
        Request $request,
        PublicDemoTokenStore $tokenStore,
    ): JsonResponse {
        if (! config('public_demo.enabled')) {
            return $this->unavailable();
        }

        $validated =
            $request->validate([
                'source_language' => [
                    'required',

                    Rule::enum(
                        Language::class,
                    ),
                ],
            ]);

        $sourceLanguage =
            Language::from(
                $validated['source_language'],
            );

        $profile =
            config(
                'public_demo.profiles.'
                    .$sourceLanguage->value,
            );

        $pipelineProfiles =
            config(
                'benchmarks.translation.pipeline.providers',
                [],
            );

        if (
            ! is_string($profile)
            || ! is_array($pipelineProfiles)
            || ! array_key_exists(
                $profile,
                $pipelineProfiles,
            )
        ) {
            return $this->unavailable();
        }

        $tokenTtlSeconds =
            (int) config(
                'public_demo.token_ttl_seconds',
            );

        $maxAudioSeconds =
            (int) config(
                'public_demo.max_audio_seconds',
            );

        $maxAudioBytes =
            (int) config(
                'public_demo.max_audio_bytes',
            );

        $hourlyLimit =
            (int) config(
                'public_demo.hourly_limit',
            );

        $dailyLimit =
            (int) config(
                'public_demo.daily_limit',
            );

        $ipDailyLimit =
            (int) config(
                'public_demo.ip_daily_limit',
            );

        $globalDailyLimit =
            (int) config(
                'public_demo.global_daily_limit',
            );

        if (
            min(
                $tokenTtlSeconds,
                $maxAudioSeconds,
                $maxAudioBytes,
                $hourlyLimit,
                $dailyLimit,
                $ipDailyLimit,
                $globalDailyLimit,
            ) < 1
        ) {
            return $this->unavailable();
        }

        $ip =
            $request->ip()
            ?? 'unknown';

        $visitorId =
            $request->session()->get(
                'public_demo_visitor_id',
            );

        if (
            ! is_string($visitorId)
            || $visitorId === ''
        ) {
            $visitorId =
                (string) Str::uuid();

            $request->session()->put(
                'public_demo_visitor_id',
                $visitorId,
            );
        }

        $visitorKey =
            hash(
                'sha256',
                $ip.'|'.$visitorId,
            );

        $ipKey =
            hash(
                'sha256',
                $ip,
            );

        $visitorHourlyKey =
            "public-demo:visitor:{$visitorKey}:hour";

        $visitorDailyKey =
            "public-demo:visitor:{$visitorKey}:day";

        $ipDailyKey =
            "public-demo:ip:{$ipKey}:day";

        $globalDailyKey =
            'public-demo:global:day';

        $limited =
            $this->firstExceededLimit([
                [
                    'key' => $visitorHourlyKey,
                    'limit' => $hourlyLimit,
                ],

                [
                    'key' => $visitorDailyKey,
                    'limit' => $dailyLimit,
                ],

                [
                    'key' => $ipDailyKey,
                    'limit' => $ipDailyLimit,
                ],

                [
                    'key' => $globalDailyKey,
                    'limit' => $globalDailyLimit,
                ],
            ]);

        if ($limited !== null) {
            return $this->rateLimited(
                $limited,
            );
        }

        if (
            $tokenStore->hasActive(
                $visitorKey,
            )
        ) {
            return response()->json(
                [
                    'message' => 'A demo translation is already active.',
                ],
                409,
            );
        }

        $token =
            $tokenStore->issue(
                visitorKey: $visitorKey,
                sourceLanguage: $sourceLanguage,
                profile: $profile,
                ttlSeconds: $tokenTtlSeconds,
                maxAudioSeconds: $maxAudioSeconds,
                maxAudioBytes: $maxAudioBytes,
            );

        RateLimiter::hit(
            $visitorHourlyKey,
            self::HOURLY_DECAY_SECONDS,
        );

        RateLimiter::hit(
            $visitorDailyKey,
            self::DAILY_DECAY_SECONDS,
        );

        RateLimiter::hit(
            $ipDailyKey,
            self::DAILY_DECAY_SECONDS,
        );

        RateLimiter::hit(
            $globalDailyKey,
            self::DAILY_DECAY_SECONDS,
        );

        return response()->json(
            [
                'token' => $token,

                'expires_in' => $tokenTtlSeconds,

                'source_language' => $sourceLanguage->value,

                'target_language' => match (
                    $sourceLanguage
                ) {
                    Language::Russian => Language::English->value,

                    Language::English => Language::Russian->value,
                },

                'max_audio_seconds' => $maxAudioSeconds,

                'usage' => [
                    'hourly_remaining' => RateLimiter::remaining(
                        $visitorHourlyKey,
                        $hourlyLimit,
                    ),

                    'daily_remaining' => RateLimiter::remaining(
                        $visitorDailyKey,
                        $dailyLimit,
                    ),
                ],
            ],
            201,
        );
    }

    /**
     * @param  array<int, array{
     *     key: string,
     *     limit: int
     * }>  $limits
     * @return array{
     *     key: string,
     *     limit: int
     * }|null
     */
    private function firstExceededLimit(
        array $limits,
    ): ?array {
        foreach ($limits as $limit) {
            if (
                RateLimiter::tooManyAttempts(
                    $limit['key'],
                    $limit['limit'],
                )
            ) {
                return $limit;
            }
        }

        return null;
    }

    /**
     * @param  array{
     *     key: string,
     *     limit: int
     * }  $limit
     */
    private function rateLimited(
        array $limit,
    ): JsonResponse {
        $retryAfter =
            RateLimiter::availableIn(
                $limit['key'],
            );

        return response()
            ->json(
                [
                    'message' => 'Public demo usage limit reached.',

                    'retry_after' => $retryAfter,
                ],
                429,
            )
            ->header(
                'Retry-After',
                (string) $retryAfter,
            );
    }

    private function unavailable(): JsonResponse
    {
        return response()->json(
            [
                'message' => 'Public voice demo is temporarily unavailable.',
            ],
            503,
        );
    }
}
