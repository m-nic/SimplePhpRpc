<?php

require_once 'helpers.php';

require_once 'SimplePhpRpc.php';
require_once 'RemoteClass.php';

SimplePhpRpc::setConnection(
    SimplePhpRpcConnection::make()
        ->setHost('localhost', false)
        ->setAuth("test", "testing")
);

/** @var RemoteClass $object */
$object = SimplePhpRpc::for(RemoteClass::class);

$result = $object->returnValue(1, 2);
Test::assertEquals($result, 3);

ob_start();
$result = $object->stdOut("m", "nic");
$stdOut = ob_get_contents();
ob_end_clean();

Test::assertEquals($result, null);
Test::assertEquals($stdOut, "Hello\nYey m nic");

$err = '';
try {
    $object->throwErr('Cool error');
} catch (Throwable $e) {
    $err = $e->getMessage();
}
Test::assertEquals($err, 'Cool error');

echo "\nSuccess!\n";


