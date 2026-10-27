<?php

require_once(__DIR__ . '/../vendor/autoload.php');

const DEPAGE_CACHE_PATH = 'cache';
const DEPAGE_BASE = 'base';

// prepareDatabase drops and rebuilds all tables; a second suite started
// while this one runs would delete its tables mid-test. The lock has to
// be held for the whole run — the handles stay in $GLOBALS (the
// bootstrap runs inside a scope that ends right after this file).
//
// A file lock alone is not enough: runs in different containers or on
// different hosts share one database server (e.g. through
// host.docker.internal) while their /tmp are different files, so they
// never see each other's lock. The authoritative mutex is therefore a
// database named lock (GET_LOCK) on a connection that is kept open
// until the process ends; the flock only makes duplicate local runs
// wait without occupying a database connection.
$GLOBALS['testLock'] = fopen(sys_get_temp_dir() . '/depage-xmldb-test.lock', 'c');
flock($GLOBALS['testLock'], LOCK_EX);

if (!preg_match('/dbname=([^;]+)/', $GLOBALS['DB_DSN'], $matches)) {
    fwrite(STDERR, "Could not determine the database name from DB_DSN for the test lock.\n");
    exit(1);
}

$lockConnection = new \PDO($GLOBALS['DB_DSN'], $GLOBALS['DB_USER'], $GLOBALS['DB_PASSWD']);
$lockConnection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$GLOBALS['testLockConnection'] = $lockConnection;

// named locks are server wide, so the database name is part of the
// lock name; runs against different databases must not block each other
// (the timeout per round is small so that the waiting gets announced)
$lockStatement = $lockConnection->prepare('SELECT GET_LOCK(?, 2)');
$lockName = 'depage-xmldb-test:' . $matches[1];

$announced = false;
while (true) {
    $lockStatement->execute([$lockName]);
    $acquired = $lockStatement->fetchColumn();
    $lockStatement->closeCursor();

    if ($acquired === null) {
        fwrite(STDERR, "Acquiring the test lock GET_LOCK('{$lockName}') failed.\n");
        exit(1);
    }
    if ($acquired == 1) {
        break;
    }

    if (!$announced) {
        echo "Waiting for another depage-xmldb test run on database {$matches[1]} to finish...\n";
        $announced = true;
    }
}

Depage\XmlDb\Tests\XmlDbTestCase::prepareDatabase();
