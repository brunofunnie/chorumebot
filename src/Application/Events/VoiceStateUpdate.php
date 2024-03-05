<?php

namespace Chorume\Application\Events;

use Chorume\Repository\User;
use Discord\Discord;
use Discord\Parts\WebSockets\VoiceStateUpdate as WebSocketsVoiceStateUpdate;

class VoiceStateUpdate
{
    public function __invoke(WebSocketsVoiceStateUpdate $data, Discord $discord): void
    {
        $this->handle($data, $discord);
    }

    public function __construct(
        private Discord $discord,
        private $redis,
        private User $userRepository
    ) {
    }

    public function handle(
        WebSocketsVoiceStateUpdate $data,
        Discord $discord
    ): void {
        $channel = $data->channel;
        $user = $data->user;
        $isUserDeafened = $data->self_deaf || $data->deaf;
        $userVoiceCache = $this->redis->get("voice_cache:" . $user->id);

        // Joined a channel
        if ($channel) {
            // If there is previous data, delete it to prevent some nasty earnings.
            if ($userVoiceCache) {
                $this->redis->del("voice_cache:" . $user->id);
            }

            $this->redis->set("voice_cache:" . $user->id, json_encode([
                "entry_time" => time(),
                "id" => $user->id
            ]));
        }

        // if the user left a channel or is deaf, we remove it from the cache and pay their coins if he was there for more than 1 minute
        if ($channel == null || $isUserDeafened) {
            $userVoiceCache = $this->redis->get("voice_cache:" . $user->id);

            // If the user is not in the cache, we return
            if (!$userVoiceCache) {
                return;
            }

            $userVoiceCache = json_decode($userVoiceCache, true);

            $entry_time = $userVoiceCache["entry_time"];
            $elapsedSeconds = (time() - $entry_time);

            // Limit the accumulated gains to 1 hour
            if ($elapsedSeconds > 3600) {
                $elapsedSeconds = 3600;
            }

            // If the user was in the channel for more than 1 minute, we give him 5% of the time in coins
            if ($elapsedSeconds >= 60) {
                $coinPercentage = 5 / 100; // 5 percent
                $accumulatedAmount = $elapsedSeconds * $coinPercentage;
                $this->userRepository->giveCoins($user->id, $accumulatedAmount, 'Presence', "Coins por ficar " . $elapsedSeconds . " segundos em call");
                $discord->getLogger()->debug(
                    sprintf(
                        "Presence: username: %s - received: %s coins - elapsed seconds: %s",
                        $user->username,
                        $accumulatedAmount,
                        $elapsedSeconds
                    )
                );
            }

            $this->redis->del("voice_cache:" . $user->id);
            $discord->getLogger()->debug(
                sprintf(
                    "Presence: username: %s - left the channel",
                    $user->username
                )
            );
            return;
        }
    }
}
