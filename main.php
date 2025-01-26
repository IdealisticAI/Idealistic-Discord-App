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

use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Message;
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

    $discord->on(Event::MESSAGE_CREATE, function (Message $message, Discord $discord) {
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
                MessageBuilder::new()->setContent(
                    BigManageStrings::translateMessage(
                        BigManageGeneralMessage::NO_TEAM_ACCESSES_FOUND,
                        $team
                    )
                );
            } else {
                MessageBuilder::new()->setContent(
                    BigManageGeneralMessage::DISCORD_SELECT_TEAM_ACCESSES // Do not translate due to command instructions
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
        if (!($user instanceof BigManageUser)) {
            $message->reply(
                MessageBuilder::new()->setContent(
                    BigManageStrings::translateMessage(
                        BigManageGeneralMessage::NO_DISCORD_ACCOUNT_CORRELATION_FOUND,
                        $team
                    )
                )
            );
            return;
        }
        $prompt = $user->createPrompt(
            BigManageAccessPlatform::DISCORD,
            $author->id,
            $author->username,
            $author->displayname,
            $message->content,
            array(), // todo
            true // todo
        );

        if (!$prompt->getOutcome()->isPositiveOutcome()) {
            $message->reply(
                MessageBuilder::new()->setContent(
                    $prompt->getOutcome()->getTranslatedMessage($user)
                )
            );
            return;
        }
        $builder = MessageBuilder::new();
        $message->reply(
            MessageBuilder::new()->setContent(
                json_encode($account->getObject())
            )
        );
    });

});

$discord->run();
