<?php
require '/root/big_manage_discord/utilities/utilities.php';
$token = get_keys_from_file(
    "discord_token"
);

if ($token === null) {
    exit("No Discord token found");
}
ini_set('memory_limit', '-1');
require '/root/vendor/autoload.php';

require '/root/big_manage_discord/utilities/sql.php';
require '/root/big_manage_discord/utilities/communication.php';
require '/root/big_manage_discord/utilities/evaluator.php';

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
        "/var/www/.structure/library/bigmanage/init.php"
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
                    && !starts_with($member->displayname, ".#")) {
                    $member->setNickname(".");
                }
            }
        }
    }

    // Separator

    $discord->getLoop()->addPeriodicTimer(
        BigManageLimit::EXTERNAL_APPLICATION_QUERY_SECONDS,
        function () use ($discord) {
            if (empty($discord->users->first())) {
                return;
            }
            $notifications = BigManageNotifications::retrieve(BigManageAccessPlatform::DISCORD);

            if (!empty($notifications)) {
                foreach ($notifications as $notification) {
                    if (!($notification instanceof BigManageNotification)) {
                        continue;
                    }
                    $identity = $notification->getUser()->getLastIdentity();

                    if ($identity === null
                        || $identity->getPlatformID() !== BigManageAccessPlatform::DISCORD) {
                        continue;
                    }
                    foreach ($discord->users as $user) {
                        if (!($user instanceof User)) {
                            continue;
                        }
                        if ($user->id === $identity->getPlatformUserID()) {
                            if ($notification->process()) {
                                if ($notification->getAttachmentName() !== null
                                    && $notification->getAttachmentContent() !== null
                                    || $notification->getMessage() !== null) {
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
                            }
                            break;
                        }
                    }
                }
            }
        }
    );

    $discord->getLoop()->addPeriodicTimer(
        BigManageLimit::EXTERNAL_APPLICATION_QUERY_SECONDS,
        function () use (&$queue) {
            foreach ($queue as $promptID => $details) {
                $user = $details[0];
                $message = $details[1];
                $time = $details[2];
                $updateCooldown = $details[3];

                if (!($user instanceof BigManageUser)
                    || !($message instanceof Message)
                    || !is_int($time)
                    || !is_numeric($updateCooldown)) {
                    unset($queue[$promptID]);
                    $message->edit(
                        MessageBuilder::new()->setContent(
                            BigManageGeneralMessage::EXCEPTION_THROWN . " (#714820396)"
                        )
                    );
                    continue;
                }
                try {
                    $prompt = $user->getPrompt($promptID);

                    if ($prompt === null) {
                        continue;
                    }
                    $processing = $prompt->isProcessing();
                    $replies = $prompt->getReplies();

                    if (empty($replies)) {
                        if (!$processing) {
                            unset($queue[$promptID]);
                            $message->edit(
                                MessageBuilder::new()->setContent(
                                    BigManageStrings::translateMessage(
                                        BigManageGeneralMessage::EXCEPTION_THROWN . " (#930182745)",
                                        $user
                                    )
                                )
                            );
                        }
                        continue;
                    }
                    if ($processing) {
                        if (microtime(true) < $updateCooldown) {
                            continue;
                        }
                        $queue[$promptID][3] = microtime(true) + 0.5;
                    } else {
                        unset($queue[$promptID]);
                    }
                    $byteCount = array();
                    $messageAttachments = array();
                    $lastMessage = 0;
                    $pieces = array();

                    foreach ($replies as $reply) {
                        if (!($reply instanceof BigManageHistoryReply)) {
                            continue;
                        }
                        $pieces = array_merge(
                            $pieces,
                            str_split(
                                $reply->getAnswer(),
                                BigManageLimit::MESSAGE_CHARACTER_LIMIT[BigManageAccessPlatform::DISCORD]
                            )
                        );
                    }
                    foreach ($pieces as $key => $piece) {
                        $byteCount[$key] = strlen($piece);
                    }
                    $builder = MessageBuilder::new()->setContent(array_shift($pieces));
                    $attachments = array_merge(
                        $prompt->getCreatedAttachments(),
                        $prompt->getRequestedAttachments(false)
                    );

                    if (!empty($attachments)) {
                        $byteLimit = floor(BigManageLimit::ATTACHMENT_BYTES_LIMIT[BigManageAccessPlatform::DISCORD] * 0.99);

                        foreach ($attachments as $attachment) {
                            if (!($attachment instanceof BigManageAttachment)) {
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
                    }
                    $attachments = array_shift($messageAttachments);

                    if (!empty($attachments)) {
                        foreach ($attachments as $attachment) {
                            if (!($attachment instanceof BigManageAttachment)) {
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
                    $message->edit($builder);

                    if (!empty($pieces)) {
                        foreach ($pieces as $piece) {
                            $builder = MessageBuilder::new()->setContent($piece);
                            $attachments = array_shift($messageAttachments);

                            if (!empty($attachments)) {
                                foreach ($attachments as $attachment) {
                                    if (!($attachment instanceof BigManageAttachment)) {
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
                                if (!($attachment instanceof BigManageAttachment)) {
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
                } catch (Throwable $e) {
                    BigManageError::storeThrowable(
                        $user->getTeam(),
                        $user,
                        $e
                    );
                    $message->edit(MessageBuilder::new()->setContent(
                        BigManageStrings::translateMessage(
                            BigManageGeneralMessage::EXCEPTION_THROWN . " (#814203967)",
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
        $user = BigManageTeamInitiator::findUser(
            BigManageAccessPlatform::DISCORD,
            $author->id,
            $author->username
        );

        if (!($user instanceof BigManageUser)) {
            $user = null;
        }
        $message->reply(
            MessageBuilder::new()->setContent(
                BigManageStrings::translateMessage(
                    BigManageGeneralMessage::PROMPT_WAIT_RESPONSE,
                    $user
                )
            )
        )->done(function (Message $newMessage) use ($discord, $user, $author, $message, &$queue) {
            $attachments = array();

            foreach ($message->attachments as $attachment) {
                $contents = @file_get_contents($attachment->url);

                if ($contents === false) {
                    $contents = @file_get_contents($attachment->proxy_url);
                }
                if ($contents === false) {
                    $newMessage->edit(
                        MessageBuilder::new()->setContent(
                            BigManageStrings::translateMessage(
                                BigManageGeneralMessage::ATTACHMENT_FAILED_PROCESSING,
                                $user
                            )
                        )
                    );
                    return;
                } else {
                    $attachments[] = new BigManageAttachment(
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
                        true
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
            $prompt = BigManageTeamInitiator::createPrompt(
                $user,
                BigManageAccessPlatform::DISCORD,
                $author->id,
                $message->channel_id,
                $message->id,
                $author->username,
                $author->displayname,
                $content,
                $attachments
            );

            if (!$prompt->isPositiveOutcome()) {
                $newMessage->edit(
                    MessageBuilder::new()->setContent(
                        BigManageStrings::translateMessage(
                            BigManageGeneralMessage::EXCEPTION_THROWN . " (#530184729)",
                            $user
                        )
                    )
                );
                return;
            }
            $queue[$prompt->getRawMessage()] = array($user, $newMessage, time(), microtime(true));
        });
    });

    // Separator

    $commandName = strtolower(BigManageVariable::APPLICATION_NAME);
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
                $account = new Account(Account::BIGMANAGE_APPLICATION_ID);
                $account = $account->getAccounts()->getAccountFromType(
                    BigManageAccessPlatform::DISCORD,
                    $author->username
                );

                if ($account === null) {
                    $interaction->respondWithMessage(
                        MessageBuilder::new()->setContent(
                            BigManageGeneralMessage::NO_DISCORD_ACCOUNT_CORRELATION_FOUND
                        )
                    );
                    return;
                }
                $team = new BigManageTeam($account);
                $user = $team->findUser($account);

                if ($user instanceof BigManageOutcome) {
                    $interaction->respondWithMessage(
                        MessageBuilder::new()->setContent(
                            $user->getTranslatedMessage($team)
                        )
                    );
                    return;
                }
                $buildMenu = false;

                if ($team->hasEstablishedAccess()) {
                    if (empty($team->getAccesses())) {
                        $interaction->respondWithMessage(
                            MessageBuilder::new()->setContent(
                                BigManageGeneralMessage::NO_TEAM_ACCESSES_FOUND
                            ),
                            true
                        );
                    } else if (sizeof($team->getAccesses()) === 1) {
                        $interaction->respondWithMessage(
                            MessageBuilder::new()->setContent(
                                BigManageStrings::translateMessage(
                                    str_replace(
                                        "{title}",
                                        $team->getTitle(),
                                        BigManageGeneralMessage::ALREADY_ESTABLISHED_ACCESS_AND_NO_EXTRA
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
                                BigManageGeneralMessage::NO_TEAM_ACCESSES_FOUND
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
                        $description = $teamAccess->getDescription();
                        $selectMenu->addOption(
                            Option::new(
                                $teamAccess->getTitle(),
                                $index
                            )->setDescription(
                                $description === null
                                    ? null
                                    : substr($description, 0, 100)
                            )
                        );
                    }
                    $selectMenu->setListener(
                        function (Interaction $interaction, Collection $options) use ($team, $user, $account) {
                            $choice = $team->selectAccess(
                                $options[0]->getValue(),
                                $account
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
                    );
                }
            } catch (Throwable $e) {
                BigManageError::storeThrowable(
                    null,
                    null,
                    $e
                );
                $interaction->respondWithMessage(MessageBuilder::new()->setContent(
                    BigManageGeneralMessage::EXCEPTION_THROWN . " (#692847130)"
                ));
            }
        }
    );

});

$discord->run();
