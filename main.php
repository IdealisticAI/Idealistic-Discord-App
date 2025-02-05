<?php
require '/root/big_manage/utilities/utilities.php';
$token = get_keys_from_file(
    "discord_token"
);

if ($token === null) {
    exit("No Discord token found");
}
ini_set('memory_limit', '-1');
require '/root/vendor/autoload.php';

require '/root/big_manage/utilities/sql.php';
require '/root/big_manage/utilities/communication.php';
require '/root/big_manage/utilities/evaluator.php';

use Discord\Builders\CommandBuilder;
use Discord\Builders\Components\Option;
use Discord\Builders\Components\SelectMenu;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Helpers\Collection;
use Discord\Parts\Channel\Message;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\User\Member;
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

    if (!empty($discord->guilds->first())) {
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
    }

    // Separator

    $discord->getLoop()->addPeriodicTimer(1, function () use ($discord) {
        if (empty($discord->users->first())) {
            return;
        }
        $notifications = BigManageNotifications::retrieve(BigManageAccessPlatform::DISCORD);

        if (!empty($notifications)) {
            foreach ($notifications as $notification) {
                $identity = $notification->getUser()->getLastIdentity();

                if ($identity === null
                    || $identity->getPlatformID() !== BigManageAccessPlatform::DISCORD) {
                    continue;
                }
                foreach ($discord->users as $user) {
                    if ($user->id === $identity->getPlatformUserID()) {
                        if ($notification->process()) {
                            $user->getPrivateChannel()->done(function ($channel) use ($notification) {
                                $channel->sendMessage(
                                    MessageBuilder::new()->setContent($notification->getMessage())
                                );
                            });
                        }
                        break;
                    }
                }
            }
        }
    });

    // Separator

    $discord->on(Event::GUILD_MEMBER_ADD, function (Member $member, Discord $discord) {
        $member->setNickname(".");
    });

    // Separator

    $discord->on(Event::MESSAGE_CREATE, function (Message $message, Discord $discord) {
        if ($message->member !== null) {
            return;
        }
        $author = $message->author;

        if ($author === null
            || $author->id === $discord->id) {
            return;
        }
        if (true) {
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
        $account = new Account(Account::BIGMANAGE_APPLICATION_ID);
        $account = $account->getAccounts()->getAccountFromType(
            BigManageAccessPlatform::DISCORD,
            $author->username
        );

        if ($account === null) {
            $message->reply(
                MessageBuilder::new()->setContent(
                    BigManageGeneralMessage::NO_DISCORD_ACCOUNT_CORRELATION_FOUND
                )
            );
            return;
        }
        $team = new BigManageTeam($account);

        if (!$team->hasEstablishedAccess()) {
            if (empty($team->getAccesses())) {
                $message->reply(
                    MessageBuilder::new()->setContent(
                        BigManageGeneralMessage::NO_TEAM_ACCESSES_FOUND
                    )
                );
            } else {
                $message->reply(
                    MessageBuilder::new()->setContent(
                        BigManageGeneralMessage::DISCORD_SELECT_TEAM_ACCESSES
                    )
                );
            }
            return;
        }
        $user = $team->findUser($account);

        if ($user instanceof BigManageOutcome) {
            $message->reply(
                MessageBuilder::new()->setContent(
                    $user->getTranslatedMessage($team)
                )
            );
            return;
        }
        $attachments = array();

        if (!empty($message->attachments->first())) {
            foreach ($message->attachments as $attachment) {
                $contents = @file_get_contents($attachment->url);

                if ($contents === false) {
                    $message->reply(
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
                        $attachment->proxy_url,
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
        }
        $message->reply(
            MessageBuilder::new()->setContent(
                BigManageStrings::translateMessage(
                    BigManageGeneralMessage::PROMPT_WAIT_RESPONSE,
                    $user
                )
            )
        )->done(function (Message $newMessage) use ($discord, $team, $user, $author, $attachments, $message) {
            try {
                if ($message->referenced_message === null) {
                    $content = $message->content;
                } else {
                    $object = new stdClass();
                    $object->content = $message->content;
                    $object->referenced_message = $message->referenced_message;
                    $content = json_encode($object);
                }
                $prompt = $user->createPrompt(
                    BigManageAccessPlatform::DISCORD,
                    $author->id,
                    $message->id,
                    $author->username,
                    $author->displayname,
                    $content,
                    $attachments
                );

                if (!$prompt->getOutcome()->isPositiveOutcome()) {
                    $newMessage->edit(
                        MessageBuilder::new()->setContent(
                            $prompt->getOutcome()->getTranslatedMessage($user)
                        )
                    );
                    return;
                }
                $pieces = str_split(
                    $prompt->getReply()->getOutcome()->getTranslatedMessage($user),
                    2000
                );
                $builder = MessageBuilder::new()->setContent(array_shift($pieces));

                if (!empty($prompt->getReply()->getAttachments())) {
                    foreach ($prompt->getReply()->getAttachments() as $attachment) {
                        if (!($attachment instanceof BigManageAttachment)) {
                            continue;
                        }
                        if ($attachment->getName() !== null
                            && ($attachment->nameHasFormat()
                                || $attachment->getSimpleFormat() !== null)) {
                            $data = $attachment->getDecodedData();

                            if ($data !== null) {
                                $builder->addFileFromContent(
                                    $attachment->getName()
                                    . ($attachment->nameHasFormat()
                                        ? ""
                                        : "." . $attachment->getSimpleFormat()),
                                    $data
                                );
                            }
                        }
                    }
                }
                $newMessage->edit($builder);

                if (!empty($pieces)) {
                    foreach ($pieces as $split) {
                        $newMessage->reply(
                            MessageBuilder::new()->setContent($split)
                        );
                    }
                }
            } catch (Throwable $e) {
                BigManageError::storeThrowable(
                    $team,
                    $user,
                    $e
                );
                $newMessage->edit(MessageBuilder::new()->setContent(
                    BigManageStrings::translateMessage(
                        BigManageGeneralMessage::EXCEPTION_THROWN,
                        $user
                    )
                ));
            }
        });
    });

    // Separator

    $commandName = "bigmanage";
    $commandBuilder = CommandBuilder::new()
        ->setName($commandName)
        ->setDescription("Manage your access");

    $discord->application->commands->save(
        $discord->application->commands->create(
            $commandBuilder->toArray()
        )
    );
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
                                    "You already have established access to the team '"
                                    . $team->getTitle() . "' and have no other team accesses.",
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
                        function (Interaction $interaction, Collection $options) use ($team) {
                            $choice = $team->getAccesses()[$options[0]->getValue()];
                            $interaction->respondWithMessage(
                                MessageBuilder::new()->setContent(
                                    "You have selected the team '" . $choice->getTitle() . "'"
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
                    BigManageGeneralMessage::EXCEPTION_THROWN
                ));
            }
        }
    );

});

$discord->run();
