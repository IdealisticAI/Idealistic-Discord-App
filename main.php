<?php
require '/root/discord_bot/utilities/utilities.php';
$token = get_keys_from_file(
    "discord_token"
);

if ($token === null) {
    exit("No Discord token found");
}
ini_set('memory_limit', '-1');
require '/root/vendor/autoload.php';

require '/root/discord_bot/utilities/sql.php';
require '/root/discord_bot/utilities/communication.php';
require '/root/discord_bot/utilities/evaluator.php';
require '/root/discord_bot/utilities/AbstractMethodReply.php';

use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\WebSockets\Event;
use Discord\WebSockets\Intents;

$files = evaluator::run();

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

    });
});

$discord->run();
