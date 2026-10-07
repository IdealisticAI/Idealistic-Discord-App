<?php
require '/root/idealistic_discord/utilities/utilities.php';
$token = get_keys_from_file(
    "discord_token"
);

if ($token === null) {
    exit("No Discord token found");
}
ini_set('memory_limit', '-1');
require '/root/vendor/autoload.php';
require '/root/idealistic_discord/utilities/sql.php';
require '/root/idealistic_discord/utilities/communication.php';
require '/root/idealistic_discord/utilities/evaluator.php';

use Discord\Builders\CommandBuilder;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Http\Endpoint;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\Parts\Interactions\Command\Choice;
use Discord\Parts\Interactions\Command\Option;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\Thread\Thread;
use Discord\Parts\User\Member;
use Discord\Parts\User\User;
use Discord\WebSockets\Event;
use Discord\WebSockets\Intents;

$files = evaluator::run(
    array(
        "/var/www/.structure/library/idealistic_office/init.php"
    )
);

if (!empty($files)) {
    foreach ($files as $path) {
        require $path;
    }
}

function portal_scope_option(Discord $discord): Option
{
    return (new Option($discord))
        ->setName("scope")
        ->setDescription("Target this channel/thread or its parent. Defaults to the parent in forum posts.")
        ->setType(Option::STRING)
        ->setRequired(false)
        ->addChoice(Choice::new($discord, "This channel/thread", "current"))
        ->addChoice(Choice::new($discord, "Parent channel (all threads)", "parent"));
}

// Returns "channel", "thread" or "forum_post" based on the raw interaction payload, since
// the cached channel object misses threads that aren't in cache (archived, private, etc.)
function portal_context(Interaction $interaction): string
{
    $raw = $interaction->getRawAttributes()["channel"] ?? null;
    $isThread = $raw !== null
        && isset($raw->parent_id)
        && in_array($raw->type ?? null, array(
            Channel::TYPE_ANNOUNCEMENT_THREAD,
            Channel::TYPE_PUBLIC_THREAD,
            Channel::TYPE_PRIVATE_THREAD
        ));

    if (!$isThread) {
        return "channel";
    }
    $parent = $interaction->guild?->channels->get("id", $raw->parent_id);
    return in_array($parent?->type, array(
        Channel::TYPE_GUILD_FORUM,
        16 // Media channel, no constant in this DiscordPHP version
    ), true) ? "forum_post" : "thread";
}

// Returns [channel_id, thread_id]
function portal_location(Interaction $interaction): array
{
    $context = portal_context($interaction);

    if ($context === "channel") {
        return array($interaction->channel_id, null);
    }
    $parentId = $interaction->getRawAttributes()["channel"]->parent_id;
    $scope = $interaction->data->options?->get("name", "scope")?->value;

    if ($scope === null) {
        $scope = $context === "forum_post" ? "parent" : "thread";
    }

    if ($scope === "parent") {
        return array($parentId, null);
    }
    return array($parentId, $interaction->channel_id);
}

// Channels/threads where the bot shows "typing..." until the portal's reply is delivered
class PortalTyping
{
    public const
        REFRESH_SECONDS = 8, // Discord clears typing after ~10 seconds
        MAX_SECONDS = 60; // Matches the time replies remain deliverable

    public static array $targets = array();
}

// Uses the channel ID directly, since the cached channel object can be missing (uncached channels/threads)
function portal_typing(Discord $discord, int|string $channelId): void
{
    $discord->getHttpClient()->post(
        Endpoint::bind(Endpoint::CHANNEL_TYPING, $channelId)
    )->then(
        null,
        fn(Throwable $e) => IdealisticOfficeError::storeThrowable(null, null, $e)
    );
}

// Guild messages only reach the portal installed in their channel/thread when the bot is tagged, never the author's assistant
function portal_run(Message $message, Discord $discord): void
{
    $author = $message->author;

    if ($author === null
        || $author->bot
        || $message->mentions->get("id", $discord->id) === null) {
        return;
    }
    $content = trim(preg_replace("/<@!?" . $discord->id . ">/", "", $message->content ?? ""));

    if ($content === "") {
        return;
    }
    $channel = $message->channel;
    $isThread = $channel !== null
        && $channel->parent_id !== null
        && in_array($channel->type, array(
            Channel::TYPE_ANNOUNCEMENT_THREAD,
            Channel::TYPE_PUBLIC_THREAD,
            Channel::TYPE_PRIVATE_THREAD
        ));
    $outcome = IdealisticOfficePortalIndependent::runInstalledPortal(
        IdealisticOfficeAccessPlatform::DISCORD,
        $author->id,
        $author->username,
        $author->displayname,
        $message->guild_id,
        $isThread ? $channel->parent_id : $message->channel_id,
        $isThread ? $message->channel_id : null,
        $message->id,
        $content,
        null
    );

    if ($outcome->isPositiveOutcome()) {
        portal_typing($discord, $message->channel_id);
        PortalTyping::$targets[(string)$message->channel_id] = time() + PortalTyping::MAX_SECONDS;
    } else if ($outcome->getRawOutcome() === false) {
        $message->reply(
            MessageBuilder::new()->setContent(
                $outcome->getTranslatedMessage()
            )
        );
    }
}

// Sends the builders in order, the first as a reply to the triggering message when it still exists
function portal_send(Channel|Thread $target, int|string|null $replyToId, array $builders): void
{
    $builder = array_shift($builders);

    if ($builder === null) {
        return;
    }
    $send = $replyToId === null
        ? $target->sendMessage($builder)
        : $target->messages->fetch($replyToId)->then(
            fn(Message $message) => $message->reply($builder),
            fn() => $target->sendMessage($builder)
        );
    $send->then(fn() => portal_send($target, null, $builders));
}

function portal_deliver(Channel $channel, IdealisticOfficePortalMessage $portalMessage): void
{
    unset(PortalTyping::$targets[(string)($portalMessage->getChannelThreadId() ?? $portalMessage->getChannelId())]);
    $builders = array();

    if ($portalMessage->hasMessage()) {
        foreach (mb_str_split(
            $portalMessage->getMessage(),
            IdealisticOfficeLimit::MESSAGE_CHARACTER_LIMIT[IdealisticOfficeAccessPlatform::DISCORD]
        ) as $piece) {
            $builders[] = MessageBuilder::new()->setContent($piece);
        }
    }
    $attachment = $portalMessage->getAttachment();

    if ($attachment !== null
        && $attachment->getFullBytes() <= IdealisticOfficeLimit::ATTACHMENT_BYTES_LIMIT[IdealisticOfficeAccessPlatform::DISCORD]) {
        $data = $attachment->getDecodedData();

        if ($data !== null) {
            if (empty($builders)) {
                $builders[] = MessageBuilder::new();
            }
            $builders[sizeof($builders) - 1]->addFileFromContent(
                $attachment->getName(),
                $data
            );
        }
    }
    if (empty($builders)) {
        return;
    }
    $threadId = $portalMessage->getChannelThreadId();

    if ($threadId === null) {
        portal_send($channel, $portalMessage->getMessageId(), $builders);
        return;
    }
    $thread = $channel->threads?->get("id", $threadId);

    if ($thread !== null) {
        portal_send($thread, $portalMessage->getMessageId(), $builders);
    } else {
        // Archived or otherwise uncached threads
        $channel->threads?->fetch($threadId)->then(
            fn(Thread $thread) => portal_send($thread, $portalMessage->getMessageId(), $builders)
        );
    }
}

global $token;
$discord = new Discord([
    'token' => $token[0],
    'intents' => Intents::getDefaultIntents() | Intents::GUILD_MEMBERS | Intents::GUILD_PRESENCES | Intents::MESSAGE_CONTENT,
    'storeMessages' => true,
    'retrieveBans' => false,
    'loadAllMembers' => true,
    'disabledEvents' => [],
    'dnsConfig' => '1.1.1.1',
]);

$discord->on('ready', function (Discord $discord) {
    $queue = array();

    // Separator

    $discord->getLoop()->addPeriodicTimer(
        PortalTyping::REFRESH_SECONDS,
        function () use ($discord) {
            foreach (PortalTyping::$targets as $channelId => $expiration) {
                if (time() > $expiration) {
                    unset(PortalTyping::$targets[$channelId]);
                } else {
                    portal_typing($discord, $channelId);
                }
            }
        }
    );

    $discord->getLoop()->addPeriodicTimer(
        IdealisticOfficeLimit::EXTERNAL_APPLICATION_QUERY_SECONDS,
        function () use ($discord) {
            if (empty($discord->users->first())) {
                return;
            }
            $notifications = IdealisticOfficeNotifications::retrieve(IdealisticOfficeAccessPlatform::DISCORD);

            if (!empty($notifications)) {
                foreach ($notifications as $notification) {
                    $identity = $notification->getUser()?->getLastIdentity();

                    if ($identity === null
                        || $identity->getPlatformID() !== IdealisticOfficeAccessPlatform::DISCORD) {
                        continue;
                    }
                    foreach ($discord->users as $user) {
                        if (!($user instanceof User)) {
                            continue;
                        }
                        if ($user->id == $identity->getPlatformUserID()) {
                            if ($notification->process()
                                && ($notification->getAttachmentName() !== null
                                    && $notification->getAttachmentContent() !== null
                                    || $notification->getMessage() !== null)) {
                                $builder = MessageBuilder::new();

                                if ($notification->getMessage() !== null) {
                                    $builder->setContent($notification->getMessage());
                                }
                                if ($notification->getAttachmentName() !== null
                                    && $notification->getAttachmentContent() !== null) {
                                    $builder->addFileFromContent(
                                        $notification->getAttachmentName(),
                                        $notification->isBase64()
                                            ? base64_decode($notification->getAttachmentContent())
                                            : $notification->getAttachmentContent()
                                    );
                                }
                                $user->getPrivateChannel()->done(function ($channel) use ($notification, $builder) {
                                    $channel->sendMessage($builder);
                                });
                            }
                            break;
                        }
                    }
                }
            }
        }
    );

    $discord->getLoop()->addPeriodicTimer(
        IdealisticOfficeLimit::EXTERNAL_APPLICATION_QUERY_SECONDS,
        function () use ($discord) {
            $portalMessages = IdealisticOfficePortalIndependent::getInstalledPortalMessages(IdealisticOfficeAccessPlatform::DISCORD);

            if (!empty($portalMessages)) {
                foreach ($portalMessages as $portalMessage) {
                    $channel = $discord->getChannel($portalMessage->getChannelId());

                    if (!($channel instanceof Channel)
                        || !$portalMessage->process()) {
                        continue;
                    }
                    portal_deliver($channel, $portalMessage);
                }
            }
        }
    );

    $discord->getLoop()->addPeriodicTimer(
        IdealisticOfficeLimit::EXTERNAL_APPLICATION_QUERY_SECONDS,
        function () use (&$queue) {
            $updateSeconds = 4;

            foreach ($queue as $promptID => $details) {
                $user = $details[0];
                $message = $details[1];
                $time = $details[2];
                $updateCooldown = $details[3];

                if (!($user instanceof IdealisticOfficeUser)
                    || !($message instanceof Message)
                    || !is_int($time)
                    || !is_numeric($updateCooldown)) {
                    unset($queue[$promptID]);
                    if ($message instanceof Message) {
                        $message->edit(
                            MessageBuilder::new()->setContent(
                                IdealisticOfficeGeneralMessage::EXCEPTION_THROWN . " (#714820396)"
                            )
                        );
                    }
                    continue;
                }
                try {
                    $microtime = microtime(true);

                    if ($microtime < $updateCooldown) {
                        continue;
                    }
                    $prompt = $user->getPrompt($promptID);

                    if ($prompt === null) {
                        continue;
                    }
                    $processing = $prompt->isProcessing();
                    $replies = $prompt->getReplies();

                    if ($processing) {
                        $queue[$promptID][3] = $microtime + $updateSeconds;
                    } else {
                        unset($queue[$promptID]);
                    }
                    $byteCount = array();
                    $lastMessage = 0;
                    $pieces = array();
                    $canEdit = false;

                    if (!empty($replies)) {
                        foreach ($replies as $reply) {
                            $pieces = array_merge(
                                $pieces,
                                str_split(
                                    $reply->getAnswer(),
                                    IdealisticOfficeLimit::MESSAGE_CHARACTER_LIMIT[IdealisticOfficeAccessPlatform::DISCORD]
                                )
                            );
                        }

                        if (empty($pieces)) {
                            $byteCount[$lastMessage] = strlen($message->content);
                            $builder = MessageBuilder::new()->setContent($message->content);
                        } else {
                            foreach ($pieces as $key => $piece) {
                                $byteCount[$key] = strlen($piece);
                            }
                            $builder = MessageBuilder::new()->setContent(array_shift($pieces));
                            $canEdit = true;
                        }
                    } else {
                        $byteCount[$lastMessage] = strlen($message->content);
                        $builder = MessageBuilder::new()->setContent($message->content);
                    }
                    $attachments = $prompt->getCreatedAttachments();
                    $messageAttachments = array();

                    if (!empty($attachments)) {
                        $byteLimit = floor(IdealisticOfficeLimit::ATTACHMENT_BYTES_LIMIT[IdealisticOfficeAccessPlatform::DISCORD] * 0.99);

                        foreach ($attachments as $attachment) {
                            $fullBytes = $attachment->getFullBytes();

                            if ($attachment->getName() !== null
                                && $fullBytes <= $byteLimit
                                && $attachment->getSimpleFormat() !== null) {
                                $data = $attachment->getDecodedData();

                                if ($data !== null) {
                                    if (($byteCount[$lastMessage] ?? 0) + $fullBytes > $byteLimit) {
                                        $lastMessage++;
                                    }
                                    if (array_key_exists($lastMessage, $byteCount)) {
                                        $byteCount[$lastMessage] += $fullBytes;
                                    } else {
                                        $byteCount[$lastMessage] = $fullBytes;
                                    }
                                    if (array_key_exists($lastMessage, $messageAttachments)) {
                                        $messageAttachments[$lastMessage][] = $attachment;
                                    } else {
                                        $messageAttachments[$lastMessage] = array($attachment);
                                    }
                                }
                            }
                        }

                        if (!empty($messageAttachments)) {
                            $attachments = array_shift($messageAttachments);

                            if (!empty($attachments)) {
                                foreach ($attachments as $attachment) {
                                    if (!($attachment instanceof IdealisticOfficeAttachment)) {
                                        continue;
                                    }
                                    $format = $attachment->getSimpleFormat();
                                    $builder->addFileFromContent(
                                        $attachment->getName()
                                        . ($format === null
                                            ? ""
                                            : "." . $format),
                                        $attachment->getDecodedData()
                                    );
                                    $canEdit = true;
                                }
                            }
                        }
                    }

                    if ($canEdit) {
                        $message->edit($builder);
                    }

                    if (!$processing) {
                        if (!empty($pieces)) {
                            foreach ($pieces as $piece) {
                                $builder = MessageBuilder::new()->setContent($piece);
                                $attachments = array_shift($messageAttachments);

                                if (!empty($attachments)) {
                                    foreach ($attachments as $attachment) {
                                        if (!($attachment instanceof IdealisticOfficeAttachment)) {
                                            continue;
                                        }
                                        $format = $attachment->getSimpleFormat();
                                        $builder->addFileFromContent(
                                            $attachment->getName()
                                            . ($format === null
                                                ? ""
                                                : "." . $format),
                                            $attachment->getDecodedData()
                                        );
                                    }
                                }
                                $message->reply($builder);
                            }
                        }
                        if (!empty($messageAttachments)) {
                            foreach ($messageAttachments as $attachments) {
                                $builder = MessageBuilder::new();

                                foreach ($attachments as $attachment) {
                                    if (!($attachment instanceof IdealisticOfficeAttachment)) {
                                        continue;
                                    }
                                    $format = $attachment->getSimpleFormat();
                                    $builder->addFileFromContent(
                                        $attachment->getName()
                                        . ($format === null
                                            ? ""
                                            : "." . $format),
                                        $attachment->getDecodedData()
                                    );
                                }
                                $message->reply($builder);
                            }
                        }
                    }
                } catch (Throwable $e) {
                    IdealisticOfficeError::storeThrowable(
                        $user->getTeam(),
                        $user,
                        $e
                    );
                    $message->edit(MessageBuilder::new()->setContent(
                        IdealisticOfficeStrings::translateMessage(
                            IdealisticOfficeGeneralMessage::EXCEPTION_THROWN . " (#814203967)",
                            $user
                        )
                    ));
                }
            }
        }
    );

    // Separator

    $discord->application->commands->save(
        $discord->application->commands->create(
            CommandBuilder::new()
                ->setName("idealistic-setup")
                ->setDescription("Install a portal in this channel or thread.")
                ->setDefaultMemberPermissions(1 << 4) // Manage Channels
                ->setDmPermission(false)
                ->addOption(
                    (new Option($discord))
                        ->setName("portal")
                        ->setDescription("The ID of the portal to install.")
                        ->setType(Option::STRING)
                        ->setRequired(true)
                )
                ->addOption(portal_scope_option($discord))
                ->toArray()
        )
    );

    $discord->application->commands->save(
        $discord->application->commands->create(
            CommandBuilder::new()
                ->setName("idealistic-remove")
                ->setDescription("Uninstall the portal of this channel or thread.")
                ->setDefaultMemberPermissions(1 << 4) // Manage Channels
                ->setDmPermission(false)
                ->addOption(portal_scope_option($discord))
                ->toArray()
        )
    );

    $discord->listenCommand("idealistic-setup", function (Interaction $interaction) {
        if ($interaction->guild_id === null) {
            $interaction->respondWithMessage(
                MessageBuilder::new()->setContent("This command can only be used in groups."),
                true
            );
            return;
        }
        $interaction->acknowledgeWithResponse(true)->done(function () use ($interaction) {
            [$channelId, $threadId] = portal_location($interaction);
            $outcome = IdealisticOfficePortalIndependent::installPortal(
                IdealisticOfficeAccessPlatform::DISCORD,
                $interaction->data->options->get("name", "portal")?->value,
                $interaction->user?->id,
                $interaction->guild_id,
                $channelId,
                $threadId,
                null
            );
            $interaction->updateOriginalResponse(
                MessageBuilder::new()->setContent(
                    $outcome->getTranslatedMessage()
                )
            );
        });
    });

    $discord->listenCommand("idealistic-remove", function (Interaction $interaction) {
        if ($interaction->guild_id === null) {
            $interaction->respondWithMessage(
                MessageBuilder::new()->setContent("This command can only be used in groups."),
                true
            );
            return;
        }
        $interaction->acknowledgeWithResponse(true)->done(function () use ($interaction) {
            [$channelId, $threadId] = portal_location($interaction);
            $outcome = IdealisticOfficePortalIndependent::uninstallPortal(
                IdealisticOfficeAccessPlatform::DISCORD,
                $interaction->user?->id,
                $interaction->guild_id,
                $channelId,
                $threadId
            );
            $interaction->updateOriginalResponse(
                MessageBuilder::new()->setContent(
                    $outcome->getTranslatedMessage()
                )
            );
        });
    });

    // Separator

    $discord->on(Event::MESSAGE_CREATE, function (Message $message, Discord $discord) use (&$queue) {
        if ($message->member !== null) {
            portal_run($message, $discord);
            return;
        }
        $author = $message->author;

        if ($author === null) {
            $message->reply(
                MessageBuilder::new()->setContent(
                    "No Discord message author found."
                )
            );
            return;
        }
        if ($author->id === $discord->id) {
            return;
        }
        if (false) {
            $author->getPrivateChannel()->done(function ($channel) use ($discord, $author) {
                $channel->getMessageHistory([])->done(function ($messages) use ($discord, $author) {
                    foreach ($messages as $message) {
                        if ($message->author->id === $discord->id) {
                            $message->delete();
                        }
                    }
                });
            });
        }
        $user = IdealisticOfficeTeamInitiator::findUser(
            IdealisticOfficeAccessPlatform::DISCORD,
            $author->id
        );

        if ($user instanceof IdealisticOfficeOutcome) {
            $message->reply(
                MessageBuilder::new()->setContent(
                    $user->getTranslatedMessage()
                )
            );
            return;
        }
        $message->reply(
            MessageBuilder::new()->setContent(
                IdealisticOfficeStrings::translateMessage(
                    IdealisticOfficeGeneralMessage::PROMPT_WAIT_RESPONSE,
                    $user
                )
            )
        )->done(function (Message $newMessage) use ($discord, $user, $author, $message, &$queue) {
            $attachments = array();
            $timezone = $user->getTimezone(false);

            foreach ($message->attachments as $attachment) {
                $contents = @file_get_contents($attachment->url);

                if ($contents === false) {
                    $contents = @file_get_contents($attachment->proxy_url);
                }
                if ($contents === false) {
                    $newMessage->edit(
                        MessageBuilder::new()->setContent(
                            IdealisticOfficeStrings::translateMessage(
                                IdealisticOfficeGeneralMessage::ATTACHMENT_FAILED_PROCESSING,
                                $user
                            )
                        )
                    );
                    return;
                } else {
                    $attachments[] = new IdealisticOfficeAttachment(
                        null,
                        $attachment->filename,
                        $attachment->content_type,
                        $attachment->url ?? $attachment->proxy_url,
                        $attachment->size,
                        $attachment->width,
                        $attachment->height,
                        $attachment->description,
                        base64_encode($contents),
                        null,
                        true,
                        IdealisticOfficeReader::getCurrentDate($timezone),
                        $timezone->getTimeZone(),
                        $user
                    );
                }
            }
            if ($message->referenced_message === null) {
                $content = $message->content;
            } else {
                $object = new stdClass();
                $object->content = $message->content;
                $object->referenced_message = $message->referenced_message;
                $content = json_encode($object);
            }
            $prompt = IdealisticOfficeTeamInitiator::createPrompt(
                $user,
                IdealisticOfficeAccessPlatform::DISCORD,
                $author->id,
                $message->channel_id,
                null,
                $message->id,
                $author->username,
                $author->displayname,
                $content,
                $attachments
            );

            if (!$prompt->isPositiveOutcome()) {
                $newMessage->edit(
                    MessageBuilder::new()->setContent(
                        $prompt->getTranslatedMessage($user)
                    )
                );
                return;
            }
            $queue[$prompt->getRawMessage()] = array($user, $newMessage, time(), microtime(true));
        });
    });

});

$discord->run();