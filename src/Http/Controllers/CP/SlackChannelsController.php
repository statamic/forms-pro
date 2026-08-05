<?php

namespace Statamic\FormsPro\Http\Controllers\CP;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Statamic\FormsPro\FormConnections\Slack\SlackClient;
use Statamic\FormsPro\FormConnections\Slack\SlackRequestException;
use Statamic\Http\Controllers\CP\CpController;

class SlackChannelsController extends CpController
{
    public function __invoke(SlackClient $slack): array|JsonResponse
    {
        try {
            $channels = Cache::remember('forms-pro.slack.channels', now()->addMinutes(5), fn () => $slack->channels()->all());
        } catch (SlackRequestException $e) {
            return response()->json(['message' => $e->errorMessage()], 422);
        }

        return ['channels' => $channels];
    }
}
