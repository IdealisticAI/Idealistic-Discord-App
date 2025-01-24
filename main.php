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
require '/root/big_manage/utilities/AbstractMethodReply.php';

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
        $user = $message->author;

        if ($user === null
            || $user->id === $discord->id) {
            return;
        }
        $account = new Account(Account::BIGMANAGE_APPLICATION_ID);
        $account = $account->getAccounts()->getAccountFromType(
            BigManageAccessPlatform::DISCORD,
            $user->username
        );

        if ($account === null) {
            $message->reply(
                MessageBuilder::new()->setContent(
                    BigManageGeneralMessage::NO_DISCORD_ACCOUNT_CORRELATION_FOUND
                )
            );
            return;
        }
        $message->reply(
            MessageBuilder::new()->setContent(
                json_encode($account->getObject())
            )
        );
    });

});

$discord->run();
