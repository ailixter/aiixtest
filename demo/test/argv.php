<?php


global $argv;
reset($argv);
while (($arg = next($argv)) !== false) {
    $array[] = $arg;
}
