<?php
require_once "Logger.php";
require_once "DataBase.php";

class Steps
{
    public static function getStepButtons($step)
    {
        $buttons = isset($step["buttons"]) ? $step["buttons"] : [];
        $hasPrev = isset($step["prev"]) && $step["prev"];
        $hasSkip = isset($step["allowSkip"]) && $step["allowSkip"];
        $count = ($hasPrev ? 1 : 0) + ($hasSkip ? 1 : 0);
        $columns = max(floor(6 / $count), 1);
        if ($hasPrev) {
            $buttons[] = [
                "ActionBody" => "prev",
                "Text" => "Предыдущий шаг",
                "Columns" => $columns
            ];
        }
        if ($hasSkip) {
            $buttons[] = [
                "ActionBody" => "next",
                "Text" => "Пропустить",
                "Columns" => $columns
            ];
        }
        return $buttons;
    }

    public static function tryStepAction(&$step, $senderId, $param, &$tracking_data)
    {
        Logger::logDebug("tryStepAction");
        if (isset($step["action"])) {
            $action = $step["action"];
            try {
                $action($step, $senderId, $param, $tracking_data);
            } catch (Throwable $ex) {
                Logger::logException($ex);
            }
        }
    }

    public static function findStepByName($steps, $name)
    {
        $filtered = array_values(array_filter($steps, function ($s) use ($name) {
            return $s["name"] == $name;
        }));
        if (sizeof($filtered) == 0) return null;
        return $filtered[0];
    }

    /** @noinspection PhpUnusedParameterInspection
     * @noinspection PhpParameterByRefIsNotUsedAsReferenceInspection
     */
    public static function getRegisterSteps(): array
    {
        return [
            [
                "name" => "password",
                "message" => "Введите пароль. Пароль можно получить у классного руководителя или администратора бота",
                "rules" => [
                    [
                        "type" => "query",
                        "operator" => "any",
                        "query" => "SELECT * FROM school_config WHERE NAME='register_pwd' AND VALUE=:param",
                        "error_message" => "Неверный пароль. Повторите ввод"
                    ]
                ],
                "next" => "name"
            ],
            [
                "name" => "name",
                "message" => "Введите ваше имя",
                "rules" => [
                    [
                        "type" => "length",
                        "min" => 10,
                        "max" => 255,
                        "error_message" => "Неверная длина имени (10-255). Повторите ввод"
                    ]
                ],
                "next" => "subject"
            ],
            [
                "name" => "subject",
                "message" => "Введите предмет по умолчанию",
                "rules" => [
                    [
                        "type" => "length",
                        "min" => 3,
                        "max" => 50,
                        "error_message" => "Неверная длина имени (10-255). Повторите ввод"
                    ]
                ],
                "prev" => "name",
                "next" => "email"
            ],
            [
                "name" => "email",
                "message" => "Введите email для связи",
                "rules" => [
                    [
                        "type" => "filter_var",
                        "filter" => FILTER_VALIDATE_EMAIL,
                        "error_message" => "Email указан неверно. Повторите ввод"
                    ]
                ],
                "prev" => "subject",
                "next" => "prepare"
            ],
            [
                "name" => "prepare",
                "action" => function (&$step, $senderId, $param, &$tracking_data) {
                    Logger::logDebug("prepare action");

                    $name = $tracking_data["name"];
                    $subject = $tracking_data["subject"];
                    $email = $tracking_data["email"];

                    $message = "Подтвердите данные регистрации:\n";
                    $message .= "Имя: $name\n";
                    $message .= "Предмет: $subject\n";
                    $message .= "Email: $email\n";
                    $step["message"] = $message;
                },
                "prev" => "email",
                "next" => "done",
                "buttons" => [
                    [
                        "ActionBody" => "done",
                        "Text" => "Подтвердить",
                        "Default" => true
                    ]
                ]
            ],
            [
                "name" => "done",
                "action" => function (&$step, $senderId, $param, &$tracking_data) {
                    Logger::logDebug("done action");

                    $name = $tracking_data["name"];
                    $email = $tracking_data["email"];
                    $subject = $tracking_data["subject"];

                    $db = Database::getConnection();
                    $stmt = $db->prepare("INSERT INTO school_people (name, viber_id, subject, email) VALUES(:name,:viber_id,:subject,:email)");

                    // execute query
                    $stmt->execute(["name" => $name, "viber_id" => $senderId, "subject" => $subject, "email" => $email]);
                    $step["message"] = "Регистрация завершена! Теперь вы можете создавать мероприятия";
                },
                "prev" => "email"
            ]
        ];
    }

    /** @noinspection PhpUnusedParameterInspection
     * @noinspection PhpParameterByRefIsNotUsedAsReferenceInspection
     */
    public static function getEventSteps(bool $isAdmin): array
    {
        return [
            [
                "name" => "name",
                "message" => "Введите название мероприятия или выберите из списка",
                "rules" => [
                    [
                        "type" => "length",
                        "min" => 3,
                        "max" => 255,
                        "error_message" => "Неверная длина имени (10-255). Повторите ввод"
                    ]
                ],
                "action" => function (&$step, $senderId, $param, &$tracking_data) {
                    $query = "
                        SELECT DISTINCT * from (
                            SELECT now() as create_date, `subject`, 0 as gr FROM `school_people`  
                            WHERE `viber_id`=:viber_id
                            UNION
                            (
                                select create_date, `subject`, 1 as gr from `school_events`
                                WHERE `viber_id`=:viber_id                                
                            )
                            UNION
                            (
                                select create_date, `subject`, 2 as gr from `school_events`
                                WHERE `viber_id`<>:viber_id
                            )
                        ) s
                        WHERE subject is not null
                        GROUP by subject
                        ORDER BY gr, create_date desc 
                        LIMIT 20";
                    $results = Database::fetchAll($query, ['viber_id' => $senderId]);
                    $buttons = null;

                    $count = sizeof($results);
                    if ($count > 0) {
                        $columns = max(floor(6 / $count), 3);
                        $step["buttons"] = array_map(
                            function ($r) use ($columns) {
                                return array(
                                    "ActionBody" => $r["subject"],
                                    "Text" => $r["subject"],
                                    "Columns" => $columns
                                );
                            },
                            $results
                        );
                    }
                },
                "next" => "date"
            ],
            [
                "name" => "date",
                "message" => "Введите дату начала в формате ДД.ММ",
                "rules" => [
                    [
                        "type" => "regex",
                        "pattern" => '/^(0[1-9]|[1-2][0-9]|3[0-1])\.(0[1-9]|1[0-2])$/',
                        "error_message" => "Неверный формат. Повторите ввод"
                    ]
                ],
                "action" => function (&$step, $senderId, &$tracking_data) {
                    Logger::logDebug("date action");
                    $dayOfWeek = date("N") - 1;
                    $weekDays = ["пн", "вт", "ср", "чт", "пт", "сб", "вс"];
                    $dates = [];
                    for ($i = 0; $i < 6; $i++) {
                        $dates[] = [
                            "date" => date("d.m", mktime(0, 0, 0, date("m"), date("d") + $i, date("Y"))),
                            "week" => $weekDays[($dayOfWeek + $i) % sizeof($weekDays)],
                            "humanity" => ($i == 0) ? "сегодня" : (($i == 1) ? "завтра" : null)
                        ];
                    }
                    $columns = max(floor(6 / sizeof($dates)), 1);
                    $step["buttons"] = array_map(
                        function ($d) use ($columns) {
                            $date = $d["date"];
                            $weekDay = $d["week"];
                            // $humanity = $d["humanity"];
                            $text = "$date ($weekDay)";
                            return array(
                                "ActionBody" => $date,
                                "Text" => $text,
                                "Columns" => $columns
                            );
                        },
                        $dates
                    );

                },
                "next" => "time",
                "prev" => "name"
            ],
            [
                "name" => "time",
                "message" => "Введите время начала",
                "rules" => [
                    [
                        "type" => "regex",
                        "pattern" => '/^([0-1][0-9]|[2][0-3]):([0-5][0-9])$/',
                        "error_message" => "Неверный формат. Повторите ввод"
                    ]
                ],
                "next" => "duration",
                "prev" => "date",
                "action" => function (&$step, $senderId, $param, &$tracking_data) {
                    $lessonTimes = self::getLessonTimes();

                    $count = sizeof($lessonTimes);
                    $columns = max(floor(6 / $count), 1);
                    $step["buttons"] = array_map(
                        function ($r, $i) use ($columns) {
                            $time = $r["time"];
                            $ind = $r["index"];
                            return array(
                                "ActionBody" => $r["time"],
                                "Text" => "$time ($ind)",
                                "Columns" => $columns
                            );
                        },
                        $lessonTimes, array_keys($lessonTimes)
                    );
                },
            ],
            [
                "name" => "duration",
                "message" => "Введите длительность, мин",
                "rules" => [
                    [
                        "type" => "regex",
                        "pattern" => '/^\d+$/',
                        "error_message" => "Неверный формат. Повторите ввод"
                    ]
                ],
                "action" => function (&$step, $senderId, $param, &$tracking_data) {
                    $query = "
                        SELECT DISTINCT * from (                           
                            (
                                select `duration`, 0 as gr from `school_events`
                                WHERE `viber_id`=:viber_id
                                ORDER BY create_date desc
                            )
                            UNION
                            (
                                select `duration`, 1 as gr from `school_events`
                                WHERE `viber_id`<>:viber_id
                                ORDER BY create_date desc
                            )
                        ) s
                        WHERE duration is not null
                        group by duration
                        order by gr, duration
                        
                        LIMIT 6";

                    $results = Database::fetchAll($query, ['viber_id' => $senderId]);
                    $buttons = null;

                    $count = sizeof($results);
                    if ($count > 0) {
                        $columns = max(floor(6 / $count), 1);
                        $step["buttons"] = array_map(
                            function ($r) use ($columns) {
                                return array(
                                    "ActionBody" => $r["duration"],
                                    "Text" => $r["duration"] . " мин",
                                    "Columns" => $columns
                                );
                            },
                            $results
                        );
                    }
                },
                "next" => "url",
                "prev" => "time"
            ],
            [
                "name" => "url",
                "message" => "Введите ссылку на конференцию",
                "rules" => [
                    [
                        "type" => "filter_var",
                        "filter" => FILTER_VALIDATE_URL,
                        "error_message" => "Неверный формат ссылки. Повторите ввод"
                    ]
                ],
                "allowSkip" => true,
                "next" => "zoom_id",
                "prev" => "time"
            ],
            [
                "name" => "zoom_id",
                "message" => "Введите идентификатор конференции",
                "rules" => [
                    [
                        "type" => "regex",
                        "pattern" => '/^([\d\s]+)$/',
                        "error_message" => "Неверный формат. Повторите ввод"
                    ]
                ],
                "allowSkip" => true,
                "next" => "zoom_code",
                "prev" => "url"
            ],
            [
                "name" => "zoom_code",
                "message" => "Введите код доступа",
                "rules" => [
                    [
                        "type" => "length",
                        "min" => 3,
                        "max" => 20,
                        "error_message" => "Неверный формат. Повторите ввод"
                    ]
                ],
                "allowSkip" => true,
                "next" => "prepare",
                "prev" => "zoom_id"
            ],
            [
                "name" => "prepare",
                "action" => function (&$step, $senderId, $param, &$tracking_data) {
                    Logger::logDebug("prepare action");

                    $name = $tracking_data["name"];
                    $date = $tracking_data["date"];
                    $time = $tracking_data["time"];
                    $duration = $tracking_data["duration"];
                    $url = isset($tracking_data["url"]) ? $tracking_data["url"] : null;
                    $zoom_id = isset($tracking_data["zoom_id"]) ? $tracking_data["zoom_id"] : null;
                    $zoom_code = isset($tracking_data["zoom_code"]) ? $tracking_data["zoom_code"] : null;

                    $message = "Запланированное мероприятие:\n"
                        . "$name\n"
                        . "$date $time\n"
                        . "длительность $duration мин\n";
                    if ($url != null) {
                        $message .= "$url";
                    } else {
                        $message .= "Идентификатор конференции: $zoom_id\n";
                        $message .= "Код доступа: $zoom_code";
                    }
                    $step["message"] = $message;
                },
                "prev" => "access_type",
                "next" => "done",
                "buttons" => [
                    [
                        "ActionBody" => "done",
                        "Text" => "Создать",
                        "Default" => true
                    ]
                ]
            ],
            [
                "name" => "done",
                "action" => function (&$step, $senderId, $param, &$trackingData) {
                    Logger::logDebug("done action");

                    $name = $trackingData["name"];
                    $date = $trackingData["date"];
                    $time = $trackingData["time"];
                    $duration = $trackingData["duration"];
                    $url = isset($trackingData["url"]) ? $trackingData["url"] : null;
                    $zoom_id = isset($trackingData["zoom_id"]) ? $trackingData["zoom_id"] : null;
                    $zoom_code = isset($trackingData["zoom_code"]) ? $trackingData["zoom_code"] : null;

                    $message = "Запланированное мероприятие создано:\n"
                        . "$name\n"
                        . "$date $time\n"
                        . "длительность $duration мин\n";
                    if ($url != null) {
                        $message .= "$url";
                    } else {
                        $message .= "Идентификатор конференции: $zoom_id\n";
                        $message .= "Код доступа: $zoom_code";
                    }
                    $db = Database::getConnection();

                    $stmt = $db->prepare("INSERT INTO school_events (subject, `date`, `time`, duration, url, zoom_id, zoom_code, viber_id) VALUES (:subject,:date, :time,:duration,:url,:zoom_id, :zoom_code, :viber_id)");

                    $dt = DateTime::createFromFormat("d.m.Y", "$date." . date("Y"));

                    $stmt->execute([
                        "subject" => $name,
                        "date" => $dt->format('Y-m-d'),
                        "time" => $time,
                        "duration" => $duration,
                        "url" => $url,
                        "zoom_id" => $zoom_id,
                        "zoom_code" => $zoom_code,
                        "viber_id" => $senderId
                    ]);

                    $step["message"] = $message;
                },
                "prev" => "email"
            ],
            [
                "name" => "event_id",
                "message" => "Выберите мероприятие",
                "action" => function (&$step, $senderId, $param, &$tracking_data) use ($isAdmin) {
                    $query = "
                        SELECT * from (                            
                            (
                                select *, 0 as gr from `school_events`
                                WHERE `viber_id`=:viber_id
                            )
                            UNION
                            (
                                select *, 0 as gr from `school_events`
                                WHERE `viber_id`<>:viber_id AND 1=:is_admin
                            )
                        ) s
                        WHERE subject is not null AND date>=CURDATE()                            
                        ORDER BY gr, date, time 
                        LIMIT 20";

                    $results = Database::fetchAll($query, ['viber_id' => $senderId, "is_admin" => $isAdmin]);
                    $buttons = null;

                    $count = sizeof($results);
                    if ($count > 0) {
                        $columns = max(floor(6 / $count), 3);
                        $step["buttons"] = array_map(
                            function ($r) use ($columns) {
                                $subject = $r["subject"];
                                $date = date("d.m", strtotime($r["date"]));
                                $time = date("H:i", strtotime($r["time"]));

                                $text = "$subject\n$date\n$time";
                                return array(
                                    "ActionBody" => $r["id"],
                                    "Text" => $text,
                                    "Columns" => $columns
                                );
                            },
                            $results
                        );
                    }
                },
                "keyboard" => [
                    "InputFieldState" => "hidden"
                ],
                "next" => "edit"
            ],
            [
                "name" => "edit",
                "message" => "Выберите параметр для редактирования",
                "action" => function (&$step, $senderId, $param, &$tracking_data) {
                    Logger::logDebug(json_encode($tracking_data));

                    $id = $tracking_data["event_id"];
                    $results = DataBase::fetchAll("select * from `school_events` where id=:id", ['id' => $id]);
                    $event = $results[0];

                    $name = isset($tracking_data["name"]) ? $tracking_data["name"] : ($event["subject"] ? $event["subject"] : null);
                    $date = isset($tracking_data["date"]) ? $tracking_data["date"] : ($event["date"] ? date("d.m", strtotime($event["date"])) : null);
                    $time = isset($tracking_data["time"]) ? $tracking_data["time"] : ($event["time"] ? date("H:i", strtotime($event["time"])) : null);
                    $duration = isset($tracking_data["duration"]) ? $tracking_data["duration"] : ($event["duration"] ? $event["duration"] : null);
                    $url = isset($tracking_data["url"]) ? $tracking_data["url"] : ($event["url"] ? $event["url"] : null);
                    $zoom_id = isset($tracking_data["zoom_id"]) ? $tracking_data["zoom_id"] : ($event["zoom_id"] ? $event["zoom_id"] : null);
                    $zoom_code = isset($tracking_data["zoom_code"]) ? $tracking_data["zoom_code"] : ($event["zoom_code"] ? $event["zoom_code"] : null);

                    $tracking_data["name"] = $name;
                    $tracking_data["date"] = $date;
                    $tracking_data["time"] = $time;
                    $tracking_data["duration"] = $duration;
                    $tracking_data["url"] = $url;
                    $tracking_data["zoom_id"] = $zoom_id;
                    $tracking_data["zoom_code"] = $zoom_code;
                    $buttons = [
                        [
                            "ActionBody" => "name",
                            "Text" => $name ? $name : "Название",
                            "Columns" => 6
                        ],
                        [
                            "ActionBody" => "date",
                            "Text" => $date ? $date : "Дата",
                            "Columns" => 2
                        ],
                        [
                            "ActionBody" => "time",
                            "Text" => $time ? $time : "Время",
                            "Columns" => 2
                        ],
                        [
                            "ActionBody" => "duration",
                            "Text" => $duration ? $duration : "Длительность",
                            "Columns" => 2
                        ],
                        [
                            "ActionBody" => "url",
                            "Text" => $url ? $url : "Ссылка",
                            "Columns" => 2
                        ],
                        [
                            "ActionBody" => "zoom_id",
                            "Text" => $zoom_id ? $zoom_id : "Идентификатор",
                            "Columns" => 2
                        ],
                        [
                            "ActionBody" => "zoom_code",
                            "Text" => $zoom_code ? $zoom_code : "Код",
                            "Columns" => 2
                        ],
                        [
                            "ActionBody" => "save_edit",
                            "Default" => true,
                            "Text" => "Сохранить изменения"
                        ]
                    ];

                    $step["next"] = $param;
                    $step["buttons"] = $buttons;
                },
                "keyboard" => [
                    "InputFieldState" => "hidden"
                ],
                "next" => "in_action_generate"
            ],
            [
                "name" => "save_edit",
                "action" => function (&$step, $senderId, $param, &$trackingData) {
                    Logger::logDebug("done action");

                    $event_id = $trackingData["event_id"];
                    $name = $trackingData["name"];
                    $date = $trackingData["date"];
                    $time = $trackingData["time"];
                    $duration = $trackingData["duration"];
                    $url = isset($trackingData["url"]) ? $trackingData["url"] : null;
                    $zoom_id = isset($trackingData["zoom_id"]) ? $trackingData["zoom_id"] : null;
                    $zoom_code = isset($trackingData["zoom_code"]) ? $trackingData["zoom_code"] : null;

                    $message = "Запланированное мероприятие отредактировано:\n"
                        . "$name\n"
                        . "$date $time\n"
                        . "длительность $duration мин\n";
                    if ($url != null) {
                        $message .= "$url";
                    } else {
                        $message .= "Идентификатор конференции: $zoom_id\n";
                        $message .= "Код доступа: $zoom_code";
                    }
                    $db = Database::getConnection();

                    $stmt = $db->prepare("UPDATE `school_events` 
SET `subject`=:subject, `date`=:date,`time`=:time,`duration`=:duration, `url`=:url, `zoom_id`=:zoom_id, `zoom_code`=:zoom_code,`editor_viber_id`=:viber_id
WHERE `id` = :event_id");

                    $dt = DateTime::createFromFormat("d.m.Y", "$date." . date("Y"));

                    $stmt->execute([
                        "event_id" => $event_id,
                        "subject" => $name,
                        "date" => $dt->format('Y-m-d'),
                        "time" => $time,
                        "duration" => $duration,
                        "url" => $url,
                        "zoom_id" => $zoom_id,
                        "zoom_code" => $zoom_code,
                        "viber_id" => $senderId
                    ]);

                    $step["message"] = $message;
                }
            ],
            [
                "name" => "from_tracking_data",
                "message" => "Выберите параметр для редактирования",
                "action" => function (&$step, $senderId, $param, &$tracking_data) {
                    Logger::logDebug(json_encode($tracking_data));

                    $name = isset($tracking_data["name"]) ? $tracking_data["name"] : "Название";
                    $date = isset($tracking_data["date"]) ? $tracking_data["date"] : "Дата";
                    $time = isset($tracking_data["time"]) ? $tracking_data["time"] : "Время";
                    $duration = isset($tracking_data["duration"]) ? $tracking_data["duration"] : "Длительность";
                    $url = isset($tracking_data["url"]) ? $tracking_data["url"] : "Ссылка";
                    $zoom_id = isset($tracking_data["zoom_id"]) ? $tracking_data["zoom_id"] : "Идентификатор";
                    $zoom_code = isset($tracking_data["zoom_code"]) ? $tracking_data["zoom_code"] : "Код";

                    $tracking_data["name"] = $name;
                    $tracking_data["date"] = $date;
                    $tracking_data["time"] = $time;
                    $tracking_data["duration"] = $duration;
                    $tracking_data["url"] = $url;
                    $tracking_data["zoom_id"] = $zoom_id;
                    $tracking_data["zoom_code"] = $zoom_code;
                    $buttons = [
                        [
                            "ActionBody" => "name",
                            "Text" => $name,
                            "Columns" => 6
                        ],
                        [
                            "ActionBody" => "date",
                            "Text" => $date,
                            "Columns" => 2
                        ],
                        [
                            "ActionBody" => "time",
                            "Text" => $time,
                            "Columns" => 2
                        ],
                        [
                            "ActionBody" => "duration",
                            "Text" => $duration,
                            "Columns" => 2
                        ],
                        [
                            "ActionBody" => "url",
                            "Text" => $url,
                            "Columns" => 2
                        ],
                        [
                            "ActionBody" => "zoom_id",
                            "Text" => $zoom_id,
                            "Columns" => 2
                        ],
                        [
                            "ActionBody" => "zoom_code",
                            "Text" => $zoom_code,
                            "Columns" => 2
                        ],
                        [
                            "ActionBody" => "done",
                            "Text" => "Создать",
                            "Default" => true
                        ]
                    ];

                    $step["next"] = $param;
                    $step["buttons"] = $buttons;
                },
                "keyboard" => [
                    "InputFieldState" => "hidden"
                ],
                "next" => "in_action_generate"
            ],
        ];
    }

    private static function getLessonTimes()
    {
        $times = ["13:15", "14:00", "14:50", "15:45", "16:40", "17:30", "18:15"];

        return array_map(
            function ($r, $i) {
                $time = $r;
                return array(
                    "time" => $time,
                    "index" => $i
                );
            },
            $times, array_keys($times)
        );
    }

    /** @noinspection PhpUnusedParameterInspection
     * @noinspection PhpParameterByRefIsNotUsedAsReferenceInspection
     */
    public static function copyEventSteps(): array
    {
        return [
            [
                "name" => "event_id",
                "message" => "Выберите мероприятие",
                "action" => function (&$step, $senderId, $param, &$tracking_data) {
                    $db = Database::getConnection();
                    $stmt = $db->prepare("
                        SELECT * from (                            
                            (
                                select *, 0 as gr from `school_events`
                                WHERE `viber_id`=:viber_id
                                ORDER BY create_date desc
                            )
                            UNION
                            (
                                select *, 1 as gr from `school_events`
                                WHERE `viber_id`<>:viber_id
                                ORDER BY create_date desc
                            )
                        ) s
                        WHERE subject is not null
                        GROUP by subject, url, zoom_id, zoom_code
                        ORDER BY gr, create_date desc 
                        LIMIT 20");
                    $stmt->execute(['viber_id' => $senderId]);

                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $buttons = null;

                    $count = sizeof($results);
                    if ($count > 0) {
                        $columns = max(floor(6 / $count), 3);
                        $step["buttons"] = array_map(
                            function ($r) use ($columns) {
                                $subject = $r["subject"];

                                $zoomId = $r["zoom_id"];
                                $zoomCode = $r["zoom_code"];
                                $text = "$subject\n$zoomId\n$zoomCode";
                                return array(
                                    "ActionBody" => $r["id"],
                                    "Text" => $text,
                                    "Columns" => $columns
                                );
                            },
                            $results
                        );
                    }
                },
                "keyboard" => [
                    "InputFieldState" => "hidden"
                ],
                "next" => "date"
            ],
            [
                "name" => "date",
                "message" => "Введите дату начала в формате ДД.ММ",
                "rules" => [
                    [
                        "type" => "regex",
                        "pattern" => '/^(0[1-9]|[1-2][0-9]|3[0-1])\.(0[1-9]|1[0-2])$/',
                        "error_message" => "Неверный формат. Повторите ввод"
                    ]
                ],
                "action" => function (&$step, $senderId, &$tracking_data) {
                    Logger::logDebug("date action");
                    $dayOfWeek = date("N") - 1;
                    $weekDays = ["пн", "вт", "ср", "чт", "пт", "сб", "вс"];
                    $dates = [];
                    for ($i = 0; $i < 6; $i++) {
                        $dates[] = [
                            "date" => date("d.m", mktime(0, 0, 0, date("m"), date("d") + $i, date("Y"))),
                            "week" => $weekDays[($dayOfWeek + $i) % sizeof($weekDays)],
                            "humanity" => ($i == 0) ? "сегодня" : (($i == 1) ? "завтра" : null)
                        ];
                    }
                    $columns = max(floor(6 / sizeof($dates)), 1);
                    $step["buttons"] = array_map(
                        function ($d) use ($columns) {
                            $date = $d["date"];
                            $weekDay = $d["week"];
                            // $humanity = $d["humanity"];
                            $text = "$date ($weekDay)";
                            return array(
                                "ActionBody" => $date,
                                "Text" => $text,
                                "Columns" => $columns
                            );
                        },
                        $dates
                    );

                },
                "next" => "time",
                "prev" => "event_id"
            ],
            [
                "name" => "time",
                "message" => "Введите время начала",
                "rules" => [
                    [
                        "type" => "regex",
                        "pattern" => '/^([0-1][0-9]|[2][0-3]):([0-5][0-9])$/',
                        "error_message" => "Неверный формат. Повторите ввод"
                    ]
                ],
                "next" => "prepare",
                "prev" => "date",
                "action" => function (&$step, $senderId, $param, &$tracking_data) {
                    $lessonTimes = self::getLessonTimes();

                    $count = sizeof($lessonTimes);
                    $columns = max(floor(6 / $count), 1);
                    $step["buttons"] = array_map(
                        function ($r, $i) use ($columns) {
                            $time = $r["time"];
                            $ind = $r["index"];
                            return array(
                                "ActionBody" => $r["time"],
                                "Text" => "$time ($ind)",
                                "Columns" => $columns
                            );
                        },
                        $lessonTimes, array_keys($lessonTimes)
                    );
                },
            ],
            [
                "name" => "prepare",
                "action" => function (&$step, $senderId, $param, &$trackingData) {
                    Logger::logDebug("prepare action");

                    $id = $trackingData["event_id"];
                    $results = DataBase::fetchAll("select * from `school_events` where id=:id", ['id' => $id]);
                    $buttons = null;

                    $count = sizeof($results);
                    if ($count == 1) {
                        $row = $results[0];
                        $name = $row["subject"];
                        $duration = $row["duration"];
                        $url = $row["url"];
                        $zoom_id = $row["zoom_id"];
                        $zoom_code = $row["zoom_code"];
                        $trackingData["name"] = $name;
                        $trackingData["duration"] = $duration;
                        $trackingData["url"] = $url;
                        $trackingData["zoom_id"] = $zoom_id;
                        $trackingData["zoom_code"] = $zoom_code;
                    }

                    $name = $trackingData["name"];
                    $date = $trackingData["date"];
                    $time = $trackingData["time"];
                    $duration = $trackingData["duration"];
                    $url = isset($trackingData["url"]) ? $trackingData["url"] : null;
                    $zoom_id = isset($trackingData["zoom_id"]) ? $trackingData["zoom_id"] : null;
                    $zoom_code = isset($trackingData["zoom_code"]) ? $trackingData["zoom_code"] : null;

                    $message = "Запланированное мероприятие:\n"
                        . "$name\n"
                        . "$date $time\n"
                        . "длительность $duration мин\n";
                    if ($url != null) {
                        $message .= "$url";
                    } else {
                        $message .= "Идентификатор конференции: $zoom_id\n";
                        $message .= "Код доступа: $zoom_code";
                    }
                    $step["message"] = $message;
                },
                "prev" => "time",
                "next" => "done",
                "buttons" => [
                    [
                        "ActionBody" => "done",
                        "Text" => "Создать",
                        "Default" => true
                    ]
                ]
            ],
            [
                "name" => "done",
                "action" => function (&$step, $senderId, $param, &$trackingData) {
                    Logger::logDebug("done action");

                    $name = $trackingData["name"];
                    $date = $trackingData["date"];
                    $time = $trackingData["time"];
                    $duration = $trackingData["duration"];
                    $url = isset($trackingData["url"]) ? $trackingData["url"] : null;
                    $zoom_id = isset($trackingData["zoom_id"]) ? $trackingData["zoom_id"] : null;
                    $zoom_code = isset($trackingData["zoom_code"]) ? $trackingData["zoom_code"] : null;

                    $message = "Запланированное мероприятие создано:\n"
                        . "$name\n"
                        . "$date $time\n"
                        . "длительность $duration мин\n";
                    if ($url != null) {
                        $message .= "$url";
                    } else {
                        $message .= "Идентификатор конференции: $zoom_id\n";
                        $message .= "Код доступа: $zoom_code";
                    }
                    $db = Database::getConnection();

                    $stmt = $db->prepare("INSERT INTO school_events (subject, `date`, `time`, duration, url, zoom_id, zoom_code, viber_id) VALUES (:subject,:date, :time,:duration,:url,:zoom_id, :zoom_code, :viber_id)");

                    $dt = DateTime::createFromFormat("d.m.Y", "$date." . date("Y"));

                    $stmt->execute([
                        "subject" => $name,
                        "date" => $dt->format('Y-m-d'),
                        "time" => $time,
                        "duration" => $duration,
                        "url" => $url,
                        "zoom_id" => $zoom_id,
                        "zoom_code" => $zoom_code,
                        "viber_id" => $senderId
                    ]);

                    $step["message"] = $message;
                }
            ]
        ];
    }

    public static function deleteEventSteps()
    {
        return [
            [
                "name" => "event_id",
                "message" => "Выберите мероприятие",
                "rules" => [
                    [
                        "type" => "role"
                    ]
                ],
                "action" => function (&$step, $senderId, $param, &$tracking_data) {
                    $results = Database::fetchEvents("date, time", "CURDATE() <= date");
                    $buttons = null;

                    $count = sizeof($results);
                    if ($count > 0) {
                        $columns = max(floor(6 / $count), 3);
                        $step["buttons"] = array_map(
                            function ($r) use ($columns) {
                                $subject = $r["subject"];
                                $date = $r["short_date"];
                                $time = $r["short_time"];
                                $zoomId = $r["zoom_id"];
                                $zoomCode = $r["zoom_code"];
                                $text = "$subject\n$date в $time\n$zoomId\n$zoomCode";
                                return array(
                                    "ActionBody" => $r["id"],
                                    "Text" => $text,
                                    "Columns" => $columns
                                );
                            },
                            $results
                        );
                    }
                },
                "keyboard" => [
                    "InputFieldState" => "hidden"
                ],
                "next" => "prepare"
            ],
            [
                "name" => "prepare",
                "message" => "Подтвердите удаление",
                "rules" => [
                    [
                        "type" => "role"
                    ]
                ],
                "buttons" => [
                    [
                        "ActionBody" => "delete",
                        "Text" => "Удалить",
                        "Default" => true
                    ]
                ],
                "keyboard" => [
                    "InputFieldState" => "hidden"
                ],
                "next" => "delete",
                "prev" => "event_id"
            ],
            [
                "name" => "delete",
                "message" => "Урок удалён",
                "rules" => [
                    [
                        "type" => "role"
                    ]
                ],
                "action" => function (&$step, $senderId, $param, &$trackingData) {
                    Logger::logDebug("delete action");
                    $db = Database::getConnection();
                    $event_id = $trackingData["event_id"];
                    $stmt = $db->prepare("DELETE from school_events WHERE `id` = :event_id");

                    $stmt->execute([
                        "event_id" => $event_id
                    ]);
                },
                "buttons" => [
                    [
                        "ActionBody" => "delete",
                        "Text" => "Удалить"
                    ]
                ]
            ],
        ];
    }

    public static function getAnnouncementSteps($sendBroadcastToSubscribersFunc)
    {
        return [
            [
                "name" => "announcement",
                "message" => "Введите текст объявления:",
                "rules" => [
                    [
                        "type" => "role",
                        "isHeadMan" => true
                    ]
                ],
                "next" => "done"
            ],
            [
                "name" => "done",
                "rules" => [
                    [
                        "type" => "role",
                        "isHeadMan" => true
                    ]
                ],
                "action" => function (&$step, $senderId, $param, &$trackingData) use ($sendBroadcastToSubscribersFunc) {
                    Logger::logDebug("Announcement done");


                    $db = Database::getConnection();

                    $stmt = $db->prepare("INSERT INTO school_announcements (`viber_id`, `text`) VALUES (:viber_id, :text)");
                    $stmt->execute(["viber_id" => $senderId, "text" => $param]);

                    $sendBroadcastToSubscribersFunc($param);
                }
            ]
        ];
    }

    public static function getOfferNewsSteps($adminId, $sendStepMessageFunc, $sendBroadcastToSubscribersFunc)
    {
        return [
            [
                "name" => "offer",
                "message" => "Введите текст объявления:",
                "next" => "offer_send"
            ],
            [
                "name" => "offer_send",
                "message" => "Ваше объявление отправлено на рассмотрение",
                "action" => function (&$step, $senderId, $param, &$trackingData) use ($adminId, $sendStepMessageFunc) {
                    Logger::logDebug("offer_send action");


                    $messageTrackingData = $trackingData;
                    $messageTrackingData["senderId"] = $senderId;


                    $messageTrackingData["step"] = "receive_offer";
                    $messageTrackingData["step_check"] = true;

                    $keyboard = [
                        "InputFieldState" => "hidden"
                    ];
                    $buttons = [
                        [
                            "ActionBody" => "accept_offer",
                            "Text" => "Разослать объявление",
                            "Default" => true
                        ]
                    ];
                    $sendStepMessageFunc($adminId, "Пользователь $senderId предлагает объявление: " . $messageTrackingData["offer"],
                        $messageTrackingData, $buttons, $keyboard);
                }
            ],
            [
                "name" => "receive_offer",
                "rules" => [
                    [
                        "type" => "role"
                    ]
                ],
                "action" => function (&$step, $senderId, $param, &$trackingData) use ($adminId, $sendStepMessageFunc) {
                    Logger::logDebug("receive_offer action" . $param);

                    $offerSender = isset($trackingData["senderId"]) ? $trackingData["senderId"] : "unknown";
                    $step["message"] = "Пользователь $offerSender предлагает объявление: " . $trackingData["offer"];
                    $step["next"] = $param;
                }
            ],
            [
                "name" => "accept_offer",
                "message" => "Объявление отправлено",
                "rules" => [
                    [
                        "type" => "role"
                    ]
                ],
                "action" => function (&$step, $senderId, $param, &$trackingData) use ($sendBroadcastToSubscribersFunc) {
                    Logger::logDebug("!!1accept_offer!!!" . $trackingData["offer"]);

                    $sendBroadcastToSubscribersFunc($trackingData["offer"]);
                }
            ]
        ];
    }
}