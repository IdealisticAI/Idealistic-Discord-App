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
use Discord\Builders\Components\Option;
use Discord\Builders\Components\SelectMenu;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Helpers\Collection;
use Discord\Parts\Channel\Message;
use Discord\Parts\Interactions\Interaction;
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
    load_sql_database();
    $queue = array();

    foreach ($discord->guilds as $guild) {
        if (!empty($guild->members->first())) {
            foreach ($guild->members as $member) {
                if ($member->id !== $discord->id
                    && !$member->getPermissions()?->administrator
                    && $member->displayname !== "."
                    && !str_starts_with($member->displayname, ".#")) {
                    $member->setNickname(".");
                }
            }
        }
    }

    // Separator

    $discord->getLoop()->addPeriodicTimer(
        IdealisticOfficeLimit::EXTERNAL_APPLICATION_QUERY_SECONDS,
        function () use ($discord) {
            if (empty($discord->users->first())) {
                return;
            }
            $notifications = IdealisticOfficeNotifications::retrieve(IdealisticOfficeAccessPlatform::DISCORD);

            if (!empty($notifications)) {
                foreach ($notifications as $notification) {
                    $identity = $notification->getUser()->getLastIdentity();

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
        function () use (&$queue) {
            $updateSeconds = 3;

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
                    if ($prompt->isQueued()) {
                        $tryQueue = $prompt->tryQueue();

                        if ($tryQueue === null) {
                            unset($queue[$promptID]);
                            $message->edit(
                                MessageBuilder::new()->setContent(
                                    IdealisticOfficeGeneralMessage::EXCEPTION_THROWN . " (#579847912)"
                                )
                            );
                            continue;
                        } else if (!$tryQueue) {
                            $reply = $prompt->getFailedReplies()[0] ?? null;

                            if ($reply !== null
                                && $reply->getAnswer() !== $message->content) {
                                $message->edit(
                                    MessageBuilder::new()->setContent($reply->getAnswer())
                                );
                                $queue[$promptID][3] = $microtime + $updateSeconds;
                            }
                            continue;
                        }
                    }
                    $processing = $prompt->isProcessing();
                    $replies = $prompt->getReplies();

                    if ($processing) {
                        $queue[$promptID][3] = $microtime + $updateSeconds;
                    } else {
                        unset($queue[$promptID]);

                        if (empty($replies)) {
                            $failedReply = $prompt->addFinalFailedReply();

                            if ($failedReply !== null) {
                                $message->edit(
                                    MessageBuilder::new()->setContent($failedReply)
                                );
                            }
                            continue;
                        }
                    }
                    $byteCount = array();
                    $lastMessage = 0;
                    $pieces = array();
                    $canEdit = false;

                    if (!empty($replies)) {
                        foreach ($replies as $reply) {
                            if ($reply->sentNotification()) {
                                continue;
                            }
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
                            if (!($attachment instanceof IdealisticOfficeAttachment)) {
                                continue;
                            }
                            $fullBytes = $attachment->getFullBytes();

                            if ($attachment->getName() !== null
                                && $fullBytes <= $byteLimit
                                && ($attachment->nameHasFormat()
                                    || $attachment->getSimpleFormat() !== null)) {
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
                                    $builder->addFileFromContent(
                                        $attachment->getName()
                                        . ($attachment->nameHasFormat()
                                            ? ""
                                            : "." . $attachment->getSimpleFormat()),
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
                                        $builder->addFileFromContent(
                                            $attachment->getName()
                                            . ($attachment->nameHasFormat()
                                                ? ""
                                                : "." . $attachment->getSimpleFormat()),
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
                                    $builder->addFileFromContent(
                                        $attachment->getName()
                                        . ($attachment->nameHasFormat()
                                            ? ""
                                            : "." . $attachment->getSimpleFormat()),
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

    $discord->on(Event::GUILD_MEMBER_ADD, function (Member $member, Discord $discord) {
        $member->setNickname(".");
    });

    // Separator

    $discord->on(Event::MESSAGE_CREATE, function (Message $message, Discord $discord) use (&$queue) {
        if ($message->member !== null) {
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
            $author->id,
            $author->username
        );

        if (!($user instanceof IdealisticOfficeUser)) {
            $user = null;
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
                        $attachment->description,
                        $attachment->content_type,
                        $attachment->url ?? $attachment->proxy_url,
                        $attachment->size,
                        $attachment->width,
                        $attachment->height,
                        null,
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

    // Separator

    $commandName = strtolower(IdealisticOfficeVariable::APPLICATION_COMMAND);
    $commandBuilder = CommandBuilder::new()
        ->setName($commandName)
        ->setDescription("Manage your access");

    try {
        $discord->application->commands->save(
            $discord->application->commands->create(
                $commandBuilder->toArray()
            )
        );
    } catch (Throwable $e) {
        exit();
    }
    $discord->listenCommand(
        $commandName,
        function (Interaction $interaction) use ($discord) {
            try {
                if ($interaction->member !== null) {
                    $interaction->respondWithMessage(
                        MessageBuilder::new()->setContent("This command can only be used in private messages."),
                        true
                    );
                    return;
                }
                $author = $interaction->user;

                if ($author === null
                    || $author->id === $discord->id) {
                    return;
                }
                $user = IdealisticOfficeTeamInitiator::findUser(
                    IdealisticOfficeAccessPlatform::DISCORD,
                    $author->id,
                    $author->username
                );

                if ($user instanceof IdealisticOfficeOutcome) {
                    $interaction->respondWithMessage(
                        MessageBuilder::new()->setContent(
                            $user->getTranslatedMessage()
                        ),
                        true
                    );
                    return;
                }
                $team = $user->getEvolvedTeam(false);
                $buildMenu = false;

                if ($team->hasEstablishedAccess()) {
                    if (empty($team->getAccesses())) {
                        $interaction->respondWithMessage(
                            MessageBuilder::new()->setContent(
                                IdealisticOfficeGeneralMessage::EXCEPTION_THROWN . " (#102945725)"
                            ),
                            true
                        );
                    } else if (sizeof($team->getAccesses()) === 1) {
                        $interaction->respondWithMessage(
                            MessageBuilder::new()->setContent(
                                IdealisticOfficeStrings::translateMessage(
                                    str_replace(
                                        "{name}",
                                        $team->getName(),
                                        IdealisticOfficeGeneralMessage::ALREADY_ESTABLISHED_ACCESS_AND_NO_EXTRA
                                    ),
                                    $team
                                )
                            ),
                            true
                        );
                    } else {
                        $buildMenu = true;
                    }
                } else {
                    if (empty($team->getAccesses())) {
                        $interaction->respondWithMessage(
                            MessageBuilder::new()->setContent(
                                IdealisticOfficeGeneralMessage::NO_ACCESS_TO_ESTABLISH
                            ),
                            true
                        );
                    } else {
                        $buildMenu = true;
                    }
                }

                if ($buildMenu) {
                    $selectMenu = SelectMenu::new()->setPlaceholder(
                        "Please select a team to access."
                    )->setMinValues(
                        1
                    )->setMaxValues(
                        1
                    );

                    foreach ($team->getAccesses() as $index => $teamAccess) {
                        $selectMenu->addOption(
                            Option::new(
                                $teamAccess->getName(),
                                $index
                            )
                        );
                    }
                    $selectMenu->setListener(
                        function (Interaction $interaction, Collection $options) use ($team, $user) {
                            $choice = $team->selectAccess(
                                $options[0]->getValue(),
                                $user->getAccount(),
                                false
                            );
                            $interaction->respondWithMessage(
                                MessageBuilder::new()->setContent(
                                    $choice->getTranslatedMessage($user)
                                ),
                                true
                            );
                        },
                        $discord
                    );
                    $interaction->respondWithMessage(
                        MessageBuilder::new()->addComponent($selectMenu),
                        true
                    );
                }
            } catch (Throwable $e) {
                IdealisticOfficeError::storeThrowable(
                    null,
                    null,
                    $e
                );
                $interaction->respondWithMessage(
                    MessageBuilder::new()->setContent(
                        IdealisticOfficeGeneralMessage::EXCEPTION_THROWN . " (#692847130)"
                    ),
                    true
                );
            }
        }
    );

});

$discord->run();
