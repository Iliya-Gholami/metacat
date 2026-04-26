<?php declare(strict_types = 1);

namespace MetaCat;

use Amp\Mysql;
use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnection;
use Amp\Mysql\MysqlResult;

class Database
{
    protected const DB_HOST = 'localhost';
    protected const DB_PORT = 3306;
    protected const DB_USER = 'root';
    protected const DB_PASS = '';
    protected const DB_NAME = 'se';

    protected static ?MysqlConnection $connection = null;

    public static function getConnection(): MysqlConnection
    {
        if (self::$connection === null) {
            $config = new MysqlConfig(
                self::DB_HOST,
                self::DB_PORT,
                self::DB_USER,
                self::DB_PASS,
                self::DB_NAME
            );

            $config = $config->withCharset('utf8mb4', 'utf8mb4_general_ci');

            self::$connection = Mysql\connect($config);
            
        }

        return self::$connection;
    }

    public static function fetchAll(MysqlResult $result): array
    {
        $rows = [];

        foreach ($result as $row) {
            $rows[] = $row;
        }

        return $rows;
    }
}