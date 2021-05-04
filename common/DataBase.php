<?php
/**
 * Created by PhpStorm.
 * User: slinkin
 * Date: 16.01.2019
 * Time: 10:58
 */
require_once __DIR__ . "/../settings.php";

class Database
{

    // specify your own database credentials
    const host = "localhost";
    const db_name = DB_NAME;
    const username = DB_USER;
    const password = DB_PASSWORD;
    private static $conn;

    private function __construct()
    {
    }

    // get the database connection
    public static function getConnection(): PDO
    {
        if (self::$conn == null) {
            self::$conn = null;

            self::$conn = new PDO("mysql:host=" . self::host . ";dbname=" . self::db_name, self::username, self::password);

            if (isset($_REQUEST["debug_mode"])) {
                self::$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            }
            self::$conn->exec("set names utf8;SET time_zone = 'Asia/Yekaterinburg'");

        }

        return self::$conn;
    }

    /**
     * @param $query
     * @param array $params
     * @param int $mode
     * @return array
     * @throws Exception
     */
    public static function fetchAll($query, $params = [], $mode = PDO::FETCH_ASSOC): array
    {
        $conn = self::getConnection();
        $stmt = $conn->prepare($query);

        if (!$stmt->execute($params)) {
            throw new Exception("Не удалось выполнить запрос $query");
        }

        return $stmt->fetchAll($mode);
    }

    /**
     * @param string|null $orderBy
     * @param string|null $where
     * @param array $params
     * @return array все поля из school_events,
     * deviation_days - разница между датой начала и текущим временем (д)
     * deviation_hours - разница между датой начала и текущим временем (ч)
     * deviation_minutes  - разница между датой начала и текущим временем (м)
     * deviation_total_minutes  - разница между датой начала и текущим временем (полных минут)
     * deviation_total_days - разница между датой начала и текущим временем (полных дней)
     * short_date - дата начала dd.MM
     * short_time - время начала HH:mm
     * short_time_end - время завершения HH:mm
     * date_time_end - дата и время завершения
     * @throws Exception
     */
    public static function fetchEvents(?string $orderBy, ?string $where = null, array $params = []): array
    {
        $query = "
                            select *, 
                                   FLOOR(deviation_total_minutes/1440) deviation_days, 
                                   FLOOR((deviation_total_minutes%1440)/60) deviation_hours, 
                                   FLOOR(deviation_total_minutes%60) deviation_minutes
                            from (
                                     select *,
                                            DATE_FORMAT(date, '%d.%m')                            as       short_date,
                                            TIME_FORMAT(time, '%H:%i')                            as       short_time,
                                            TIME_FORMAT(DATE_ADD(time, INTERVAL duration MINUTE), '%H:%i') short_time_end,
                                            TIMESTAMPDIFF(MINUTE, now(), CONCAT(date, ' ', time)) as       deviation_total_minutes,
                                            TIMESTAMPDIFF(DAY, now(), CONCAT(date, ' ', time)) as       deviation_total_days,
                                            DATE_ADD(CONCAT(date, ' ', time), INTERVAL duration MINUTE) as date_time_end
                                     from `school_events`                                     
                                 ) s
                                 ";
        if ($where != null) {
            $query .= " WHERE $where";
        }
        if ($orderBy != null) {
            $query .= " ORDER BY $orderBy";
        }

        return self::fetchAll($query, $params);
    }
}