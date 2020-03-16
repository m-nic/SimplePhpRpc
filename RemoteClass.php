<?php

class RemoteClass
{
    public function stdOut($a, $b)
    {
        echo "Hello\n";
        echo "Yey {$a} {$b}";
    }

    public function returnValue($a, $b)
    {
        return $a + $b;
    }

    public function throwErr($errMessage)
    {
        throw new Error($errMessage);
    }
}