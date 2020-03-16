<?php

function dd(...$args)
{
    print_r($args);
    exit;
}

class Test
{
    public static function assertEquals($actual, $expected, $strict = true)
    {
        $notEqual = $strict
            ? $actual !== $expected
            : $actual != $expected;

        if ($notEqual) {
            $actual = var_export($actual, true);
            $expected = var_export($expected, true);

            echo sprintf("Assertion failed\n Actual:\n \t %s \n Expected:\n\t %s\n\n", $actual, $expected);

            throw new Exception('Assertion failed');
        }
    }
}