<?php /** @noinspection PhpComposerExtensionStubsInspection */
/** @noinspection HtmlDeprecatedAttribute */
/** @noinspection HtmlDeprecatedTag */
/** @noinspection PhpUndefinedClassInspection */
/** @noinspection SqlNoDataSourceInspection */

/** @noinspection SqlDialectInspection */

use Twig\Environment;
use Twig\Loader\ArrayLoader;

require_once "common/Logger.php";
require_once "common/Functions.php";
require_once "common/DataBase.php";
require_once "common/Steps.php";
require("api/ViberApi.php");

class ViberBot
{
    private const AUTH_TOKEN = "AUTH_TOKEN";
    private const NAME = "Школьный помощник";
    private const ADMIN_ID = "ADMIN_ID";
    private const HEADMAN_ID = "HEADMAN_ID";


    public function __construct()
    {
        date_default_timezone_set('Asia/Yekaterinburg');
    }

    function getWebHookResponse(): array
    {
        $api = new ViberApi(self::AUTH_TOKEN);
        return $api->getWebHookResponse();
    }

    function processMessage($data): array
    {
        Logger::logDebug("processMessage: " . json_encode($data));
        $senderId = $data["sender"]["id"];
        $message = $data["message"];

        if (isset($message["tracking_data"]))
            $data["message"]["tracking_data"] = json_decode($message["tracking_data"], true);
        else {
            $data["message"]["tracking_data"] = array();
        }

        if ($message["type"] == "text") {
            switch ($message["text"]) {
                case "generate_register_pwd":
                    $this->generatePassword($data);
                    break;
                case "events_manage":
                    $this->eventsManage($data);
                    break;
                case "events_list":
                    $this->eventsList($data);
                    break;
                case "main_menu":
                    $this->defaultMessage($senderId);
                    break;
                case "subscribe":
                    $this->subscribe($data);
                    break;
                case "unsubscribe":
                    $this->unsubscribe($senderId);
                    break;
                case "contacts":
                    $this->getContacts($senderId);
                    break;
                case "test":
                    $this->test($data);
                    break;
                default:
                    $command = (isset($data["message"]["tracking_data"]) && isset($data["message"]["tracking_data"]["command"])) ? $data["message"]["tracking_data"]["command"] : null;
                    switch ($command) {
//                        case "announcement_send":
//                            $this->announcementSend($data);
//                            break;
                        default:
                            $this->checkEvent($data);
                            break;
                    }
                    break;
            }
        }

        return self::createSeenResponse($data);
    }

    function notifySubscribers(): array
    {
        Logger::logDebug("notifySubscribers");

        $results = Database::fetchEvents("`date`,`time`", "deviation_total_minutes = 30 OR deviation_total_minutes = 10");

        if (sizeof($results) > 0) {
            $message = $this->render('hot_events', ['events' => $results]);
            $this->sendBroadcastToSubscribers($message);
            return [
                "message" => $message
            ];
        }
        return [
            "message" => "не о чем оповещать"
        ];
    }

    function notifyTodayEvents(): array
    {
        Logger::logDebug("notifyTodayEvents");

        $results = Database::fetchEvents("`date`,`time`", "CURDATE() = date and deviation_total_minutes > 0");

        if (sizeof($results) > 0) {
            $message = $this->render('today_events', ['events' => $results]);
            $this->sendBroadcastToSubscribers($message);
            return [
                "message" => $message
            ];
        }
        return [
            "message" => "не о чем оповещать"
        ];
    }

    function getUserDetails($viberId): string
    {
        Logger::logDebug("getUserDetails: " . $viberId);
        $api = new ViberApi(self::AUTH_TOKEN);
        $result = $api->getUserDetails($viberId);
        self::checkResponse($result);
        Logger::logDebug("response: $result");
        return $result;
    }

    function getWelcomeMessage(): array
    {
        $api = new ViberApi(self::AUTH_TOKEN);
        return $api->getWelcomeMessage(self::NAME, "Добро пожаловать в наш бот! Внимание, бот работает в тестовом режиме.");
    }

    function getAccountInfo(): string
    {
        $api = new ViberApi(self::AUTH_TOKEN);
        return $api->getAccountInfo();
    }

    private function getContacts($senderId)
    {
        Logger::logDebug("getContacts");

        $contacts = [
 
        ];

        $text = "";
        foreach ($contacts as $contact) {
            $text .= "\n" . $contact["name"];
            if (isset($contact["email"])) {
                foreach ($contact["email"] as $email) {
                    $text .= "\nemail: " . $email;
                }
            }
            if (isset($contact["phone"])) {
                $text .= "\nтел: " . $contact["phone"];
            }
            $text .= "\n";
        }

        $text .= "\n\nЕсли вы нашли неточность или у вас есть доп. информация, воспользуйтесь кнопкой \"Предложить объявление\"";
        $this->defaultMessage($senderId, $text);
    }

    private function eventsManage($data)
    {
        Logger::logDebug("eventsManage");

        $senderId = $data["sender"]["id"];
        $isAdmin = self::isAdmin($senderId);
        $isRegistered = self::isRegistered($senderId);


        if ($isAdmin || $isRegistered) {
            $buttons = [];
            $buttons[] = [
                "ActionBody" => "event_create",
                "Text" => "Создать",
                "Columns" => 3
            ];
            $buttons[] = [
                "ActionBody" => "event_edit",
                "Text" => "Редактировать",
                "Columns" => 3
            ];
            $buttons[] = [
                "ActionBody" => "event_copy",
                "Text" => "Копировать",
                "Columns" => 3
            ];
            $buttons[] = [
                "ActionBody" => "event_delete",
                "Text" => "Удалить",
                "Columns" => 3
            ];
            $buttons[] = [
                "ActionBody" => "main_menu",
                "Text" => "Главное меню"
            ];
            $keyboard = new Keyboard($buttons);
            $keyboard->setInputFieldState(Keyboard::INPUT_FIELD_STATE_HIDDEN);

            self::sendApiMessage($senderId, "Управление уроками: ", $keyboard);
        } else {
            $this->defaultMessage($senderId);
        }

    }

    private function checkEvent($data)
    {
        Logger::logDebug("checkEvent");
        $senderId = $data["sender"]["id"];
        $isAdmin = self::isAdmin($senderId);
        $event = (isset($data["message"]["tracking_data"]) && isset($data["message"]["tracking_data"]["event"])) ? $data["message"]["tracking_data"]["event"] : $data["message"]["text"];
        Logger::logDebug("event $event");
        switch ($event) {
            case "announcement":
                $this->nextStep($data, $event, Steps::getAnnouncementSteps(function ($text) {
                    $this->sendBroadcastToSubscribers($text);
                }));

                break;
            case "register":
                if (self::isRegistered($senderId)) {
                    $this->defaultMessage($senderId, "Вы уже зарегистрированы");
                    return;
                }
                $this->nextStep($data, $event, Steps::getRegisterSteps());
                break;
            case "event_create":
                if (!self::isRegistered($senderId) && !$isAdmin) {
                    $this->defaultMessage($senderId, "У вас нет прав на создание мероприятий");
                    return;
                }
                $this->nextStep($data, $event, Steps::getEventSteps($isAdmin));
                break;
            case "event_edit":
                if (!self::isRegistered($senderId) && !$isAdmin) {
                    $this->defaultMessage($senderId, "У вас нет прав на создание мероприятий");
                    return;
                }
                $stepName = (isset($data["message"]["tracking_data"]) && isset($data["message"]["tracking_data"]["step"])) ? $data["message"]["tracking_data"]["step"] : null;
                if ($stepName == null) {
                    $data["message"]["tracking_data"]["step"] = "event_id";
                }
                $steps = Steps::getEventSteps($isAdmin);

                foreach ($steps as &$step) {
                    if ($step["name"] != "save_edit")
                        $step["next"] = "edit";
                    if ($step["name"] != "edit" && $step["name"] != "event_id" && $step["name"] != "save_edit")
                        $step["prev"] = "edit";
                }
                $this->nextStep($data, $event, $steps);
                break;
            case "event_copy":
                if (!self::isRegistered($senderId) && !$isAdmin) {
                    $this->defaultMessage($senderId, "У вас нет прав на создание мероприятий");
                    return;
                }
                $this->nextStep($data, $event, Steps::copyEventSteps());
                break;
            case "event_delete":
                $this->nextStep($data, $event, Steps::deleteEventSteps());
                break;
            case "event_tracking_data":
                if (!self::isRegistered($senderId) && !$isAdmin) {
                    $this->defaultMessage($senderId, "У вас нет прав на создание мероприятий");
                    return;
                }

                $steps = Steps::getEventSteps($isAdmin);

                foreach ($steps as &$step) {

                    if ($step["name"] != "from_tracking_data" && $step["name"] != "done") {
                        $step["prev"] = "from_tracking_data";
                        $step["next"] = "from_tracking_data";
                    }
                }
                $this->nextStep($data, $event, $steps);
                break;
            case "event_offer_news":
                $this->nextStep($data, $event, Steps::getOfferNewsSteps(self::ADMIN_ID,
                    function ($receiverId, $text, $trackingData,
                              $buttons = null, $keyboardConfig = null) {
                        $this->sendStepMessage($receiverId, $text, $trackingData, $buttons, $keyboardConfig);
                    },
                    function ($text) {
                        $this->sendBroadcastToSubscribers($text);
                    }));

                break;
            default:
                if (!$this->checkIsZoomInvitation($data)) {
                    $this->defaultMessage($senderId);
                }
                break;
        }
    }

    private
    function nextStep($data, $event, $steps)
    {
        Logger::logDebug("nextStep");
        $senderId = $data["sender"]["id"];
        $isHeadMan = self::isHeadman($senderId);
        $isAdmin = self::isAdmin($senderId);
        $isRegistered = self::isRegistered($senderId);

        $stepName = (isset($data["message"]["tracking_data"]) && isset($data["message"]["tracking_data"]["step"])) ? $data["message"]["tracking_data"]["step"] : null;

        $param = trim($data["message"]["text"]);
        $tracking_data = $data["message"]["tracking_data"];

        $step = $stepName != null ? Steps::findStepByName($steps, $stepName) : $steps[0];
        if ($step == null) {
            Logger::logError("Не найден шаг $stepName");
            $this->defaultMessage($senderId, "Что-то пошло не так");
            return;
        }
        Steps::tryStepAction($step, $senderId, $param, $tracking_data);
        if ($data["message"]["text"] == "prev") {
            Logger::logDebug("предыдущий ищем для $stepName " . $step["prev"]);
            $stepName = $step["prev"];
            $step = Steps::findStepByName($steps, $stepName);
            unset($tracking_data["step_check"]);
        }
        if ($data["message"]["text"] == "next") {
            Logger::logDebug("следующий ищем для $stepName " . $step["next"]);
            $stepName = $step["next"];
            $step = Steps::findStepByName($steps, $stepName);
            unset($tracking_data["step_check"]);
        }
        if ($step == null) {
            Logger::logError("Не найден шаг $stepName");
            $this->defaultMessage($senderId, "Что-то пошло не так");
            return;
        }

        Steps::tryStepAction($step, $senderId, $param, $tracking_data);

        $stepName = $step["name"];
        $tracking_data["event"] = $event;
        Logger::logDebug("шаг $stepName");
        if (isset($tracking_data["step_check"]) && $tracking_data["step_check"]) {
            $rules = isset($step["rules"]) ? $step["rules"] : null;
            $error_message = null;
            if ($rules != null) {
                foreach ($rules as $rule) {
                    if ($error_message != null)
                        break;
                    switch ($rule["type"]) {
                        case "query":
                            $db = $this->dbCnnect();
                            $stmt = $db->prepare($rule["query"]);
                            $stmt->execute(["param" => $param]);

                            switch ($rule["operator"]) {
                                case "any":
                                    if ($stmt->rowCount() == 0) {
                                        $error_message = $rule["error_message"];
                                    }
                                    break;
                            }
                            break;
                        case "length":
                            $paramLength = strlen($param);
                            if ((isset($rule["min"]) && $paramLength < $rule["min"]) || (isset($rule["max"]) && $paramLength > $rule["max"]))
                                $error_message = $rule["error_message"];

                            break;
                        case "filter_var":
                            if (!filter_var($param, $rule["filter"]))
                                $error_message = $rule["error_message"];

                            break;
                        case "regex":
                            if (!preg_match($rule["pattern"], $param, $matches))
                                $error_message = $rule["error_message"];

                            break;
                        case "role":
                            $allowHeadMan = $isHeadMan && $rule["isHeadMan"] == true;
                            if (!$isAdmin && !$isRegistered && !$allowHeadMan)
                                $error_message = isset($rule["error_message"]) ? $rule["error_message"] : "Нет прав";

                            break;
                    }
                }
            }
            if ($error_message != null) {
                Steps::tryStepAction($step, $senderId, $param, $tracking_data);
                $buttons = Steps::getStepButtons($step);
                $this->sendStepMessage($senderId, $error_message, $tracking_data, $buttons,
                    isset($step["keyboard"]) ? $step["keyboard"] : null);
                return;
            }

            $tracking_data[$stepName] = $param;
            $stepName = $step["next"];
        }

        $step = Steps::findStepByName($steps, $stepName);
        if ($step == null) {
            Logger::logError("Не найден шаг $stepName");
            $this->defaultMessage($senderId, "Что-то пошло не так");
            return;
        }
        Logger::logDebug("шаг $stepName");
        Steps::tryStepAction($step, $senderId, $param, $tracking_data);

        $tracking_data["step"] = $step["name"];
        $tracking_data["step_check"] = true;
        if (!isset($step["message"]) || $step["message"] == "") {
            Logger::logWarning("не задано сообщение для шага " . $step["name"]);
        }
        $message = $step["message"];

        $buttons = Steps::getStepButtons($step);

        if (!isset($step["next"]) || !$step["next"]) {
            $this->defaultMessage($senderId, $message);
        } else {
            $this->sendStepMessage($senderId, $message, $tracking_data, $buttons,
                isset($step["keyboard"]) ? $step["keyboard"] : null);
        }
    }

    private
    function sendStepMessage($receiverId, $text, $trackingData,
                             $buttons = null, $keyboardConfig = null)
    {
        Logger::logDebug("sendStepMessage");

        $defaultButtons = array();
        if ($buttons != null && sizeof($buttons) > 0)
            $defaultButtons = $buttons;

        $defaultButtons[] =
            [
                "ActionBody" => "main_menu",
                "BgColor" => "#CA312A",
                "Text" => "<font color=\"#FFFFFF\">Отмена</font>"
            ];

        $keyboard = new Keyboard($defaultButtons);
        if ($keyboardConfig != null && isset($keyboardConfig["InputFieldState"]))
            $keyboard->setInputFieldState($keyboardConfig["InputFieldState"]);
        self::sendApiMessage($receiverId, $text, $keyboard, $trackingData);
    }

    private
    function eventsList($data)
    {
        Logger::logDebug("eventsList");
        $senderId = $data["sender"]["id"];


        $results = Database::fetchEvents("`date`,`time`", "date_time_end > now()");

        if (sizeof($results) > 0) {
            $message = $this->render('events_list', ['events' => $results]);
            $this->defaultMessage($senderId, $message);
        } else {
            $this->defaultMessage($senderId, "Нет запланированных мероприятий");
        }
    }

    private
    function checkIsZoomInvitation($data): bool
    {
        $senderId = $data["sender"]["id"];
        if (!self::isAdmin($senderId)) {
            return false;
        }
        $text = $data["message"]["text"];
        $pattern = '/Тема:\s*(.*)$[\s\S]*?Время:\s*(\d+)\s*([^\d\s]+)\s+(\d{4})\s+(\d+:\d+)\s+(AM|PM)?.*$[\s\S]*(https:\/\/.*)$[\s\S]*Идентификатор конференции:\s*(.*)$[\s\S]*Код доступа:\s*(.*)$/m';

        if (preg_match($pattern, $text, $matches)) {
            $tracking_data = isset($data["message"]["tracking_data"]) ? $data["message"]["tracking_data"] : [];
            $timeStr = $matches[5] . ($matches[6] == null ? "" : (" " . $matches[6]));

            $ru_month = array('янв', 'фев', 'март', 'апр', 'май', 'июнь', 'июль', 'авг', 'сент', 'окт', 'ноябрь', 'дек');
            $en_month = array('January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December');
            $dateStr = $matches[2] . " " . str_replace($ru_month, $en_month, $matches[3]) . " " . $matches[4];

            $tracking_data["name"] = $matches[1];
            $tracking_data["date"] = date("d.m", strtotime($dateStr));
            $tracking_data["time"] = date("H:i", strtotime($timeStr));
            $tracking_data["duration"] = 40;
            $tracking_data["url"] = $matches[7];
            $tracking_data["zoom_id"] = $matches[8];
            $tracking_data["zoom_code"] = $matches[9];
            $tracking_data["event"] = "event_tracking_data";
            $tracking_data["step"] = "from_tracking_data";
            $data["message"]["tracking_data"] = $tracking_data;
            $this->checkEvent($data);
            return true;
        }
        return false;
    }

    private
    function render($name, array $context = []): string
    {
        try {
            require_once 'vendor/autoload.php';
            $loader = new ArrayLoader([
                "event_deviation" =>
                    "{% set r = [] %}"
                    . "{% if event.deviation_days|abs > 0 %}{% set r = r | merge([event.deviation_days|abs~'д']) %}{% endif %}"
                    . "{% if event.deviation_hours|abs > 0 %}{% set r = r | merge([event.deviation_hours|abs~'ч']) %}{% endif %}"
                    . "{% if event.deviation_minutes|abs > 0 %}{% set r = r | merge([event.deviation_minutes|abs~'мин']) %}{% endif %}"
                    . "{{ r|join(' ') }}",
                'today_events' =>
                    "Уроки сегодня ({{ events|length }}):\n\n"
                    . "{% for event in events %}"
                    . "{{ include('today_event') }}\n\n"
                    . "{% endfor %}",
                "today_event" =>
                    "(checkmark) {{ event.subject }}"
                    . "\n(time) {{ event.short_time }} - {{ event.short_time_end }}"
                    . "{% if event.deviation_total_minutes > 0 %}"
                    . " (через {{ include('event_deviation') }})"
                    . "{% else %}"
                    . " (🔥идёт {{ include('event_deviation') }})"
                    . "{% endif %}"
                    . "{% if event.url is not empty %} \n🔗{{ event.url }}{% endif %}"
                    . "{% if event.zoom_id is not empty %} \n🔑 идентификатор: {{ event.zoom_id }}{% endif %}"
                    . "{% if event.zoom_code is not empty %} \n🔑 код доступа: {{ event.zoom_code }}{% endif %}",
                "future_event" =>
                    "(checkmark) {{ event.subject }}"
                    . "\n(time) {{ event.short_time }} - {{ event.short_time_end }}"
                    . " (через {{ include('event_deviation') }})"
                    . "{% if event.url is not empty or event.zoom_id is not empty or event.zoom_code is not empty %} \n"
                    . "{% if event.url is not empty %}🔗 {% endif %}"
                    . "{% if event.zoom_id is not empty %}🔑 {% endif %}"
                    . "{% if event.zoom_code is not empty %}🔑 {% endif %}"
                    . "{% endif %}",
                'hot_events' =>
                    "{% if events|length > 1 %}"
                    . "Скоро начнутся ({{ events|length }}):\n\n"
                    . "{% for event in events %}"
                    . "{{ include('hot_event') }}\n\n"
                    . "{% endfor %}"
                    . "{% elseif events|length == 1 %}"
                    . "{% set event = events.0 %}"
                    . "{{ include('hot_single_event') }}"
                    . "{% else %}"
                    . "{% endif %}",
                "hot_single_event" =>
                    "(checkmark) {{ event.subject }} (через {{ include('event_deviation') }})"
                    . "\n(time) {{ event.short_time }} - {{ event.short_time_end }}"
                    . "{% if event.url is not empty %} \n🔗{{ event.url }}{% endif %}"
                    . "{% if event.zoom_id is not empty %} \n🔑 идентификатор: {{ event.zoom_id }}{% endif %}"
                    . "{% if event.zoom_code is not empty %} \n🔑 код доступа: {{ event.zoom_code }}{% endif %}",
                "hot_event" =>
                    "(checkmark) {{ event.subject }}"
                    . "\n(time) {{ event.short_time }} - {{ event.short_time_end }}"
                    . " (через {{ include('event_deviation') }})"
                    . "{% if event.url is not empty %} \n🔗{{ event.url }}{% endif %}"
                    . "{% if event.zoom_id is not empty %} \n🔑 идентификатор: {{ event.zoom_id }}{% endif %}"
                    . "{% if event.zoom_code is not empty %} \n🔑 код доступа: {{ event.zoom_code }}{% endif %}",
                'events_list' =>
                    "{% set todayEvents = events|filter(e=>e.deviation_total_days==0) %}"
                    . "{% set hasEvents = false %}"
                    . "{% if todayEvents|length > 0 %}"
                    . "{% set hasEvents = true %}"
                    . "⬇️⬇️⬇️ СЕГОДНЯ ⬇️⬇️⬇️\n\n"
                    . "{% for event in todayEvents %}"
                    . "{{ include('today_event') }}\n\n"
                    . "{% endfor %}"
                    . "\n⬆️⬆️⬆️ СЕГОДНЯ ⬆️⬆️⬆️\n"
                    . "{% endif %}"
                    . "{% set tomorrowEvents = events|filter(e=>e.deviation_total_days==1) %}"
                    . "{% if tomorrowEvents|length > 0 %}"
                    . "{% if hasEvents %}\n\n{% endif %}"
                    . "{% set hasEvents = true %}"
                    . "\n⬇️⬇️⬇️ Завтра ⬇️⬇️⬇️\n\n"
                    . "{% for event in tomorrowEvents %}"
                    . "{{ include('future_event') }}\n\n"
                    . "{% endfor %}"
                    . "\n⬆️⬆️⬆️ Завтра ⬆️⬆️⬆️\n"
                    . "{% endif %}",

            ]);
            $twig = new Environment($loader);
            return $twig->render($name, $context);
        } catch (Throwable $ex) {
            Logger::logException($ex);
            return "render error";
        }
    }

    private
    function subscribe($data)
    {
        $senderId = $data["sender"]["id"];
        $viberName = $data["sender"]["name"];
        $db = $this->dbCnnect();
        $stmt = $db->prepare("INSERT INTO school_subscribers (viber_id, viber_name) VALUES(:viber_id,:viber_name)");

        // execute query
        if ($stmt->execute(["viber_id" => $senderId, "viber_name" => $viberName])) {
            $this->defaultMessage($senderId, "Вы успешно подписаны на оповещения о приближающихся уроках");
        } else {
            $this->defaultMessage($senderId, "что-то пошло не так");
        }
    }

    private
    function unsubscribe($receiverId)
    {
        $db = $this->dbCnnect();
        $stmt = $db->prepare("delete from school_subscribers where viber_id=:viber_id");

        // execute query
        if ($stmt->execute(["viber_id" => $receiverId])) {
            $this->defaultMessage($receiverId, "Вы успешно отписаны от оповещений о приближающихся мероприятиях");
        } else {
            $this->defaultMessage($receiverId, "что-то пошло не так");
        }
    }

    private
    static function getDefaultMenuKeyboard($receiverId): Keyboard
    {
        $isAdmin = self::isAdmin($receiverId);
        $isHeadman = self::isHeadman($receiverId);
        $isRegistered = self::isRegistered($receiverId);
        $isSubscriber = self::isSubscriber($receiverId);
        $buttons = [];
        if ($isRegistered || $isAdmin) {
            $buttons[] = [
                "ActionBody" => "events_manage",
                "Text" => "Управление уроками",
                "Columns" => 3
            ];
        }

        if ($isRegistered || $isAdmin || $isHeadman) {
            $buttons[] = [
                "ActionBody" => "announcement",
                "Text" => "Объявление",
                "Columns" => 3
            ];
        }

        $buttons[] = [
            "ActionBody" => "events_list",
            "Default" => true,
            "Text" => "Уроки"
        ];

        $buttons[] = [
            "ActionBody" => "contacts",
            "Text" => "Контакты учителей",
            "Columns" => 3
        ];

        $buttons[] = array(
            "ActionBody" => "event_offer_news",
            "Text" => "Предложить объявление",
            "Columns" => 3
        );

        if (!$isSubscriber) {
            $buttons[] = [
                "ActionBody" => "subscribe",
                "Text" => "Подписаться",
                "Columns" => 3
            ];
        } else {
            $buttons[] = [
                "ActionBody" => "unsubscribe",
                "Text" => "Отписаться",
                "Columns" => 3
            ];
        }

        if ($isRegistered != true) {
            $buttons[] = [
                "ActionBody" => "register",
                "Text" => "Регистрация учителя"
            ];
        }

        if ($isAdmin) {
            $buttons[] = array(
                "ActionBody" => "generate_register_pwd",
                "Text" => "Сгенерировать пароль",
                "Columns" => 3
            );
            $buttons[] = array(
                "ActionBody" => "test",
                "Text" => "Тест",
                "Columns" => 3
            );
        }

        return new Keyboard($buttons);
    }

    private
    function defaultMessage($receiverId, $text = "Выберите действие", ?array $trackingData = null)
    {
        Logger::logDebug("defaultMessage");
        $text = $text ? $text : "Выберите действие";
        self::sendApiMessage($receiverId, $text, self::getDefaultMenuKeyboard($receiverId), $trackingData);
    }

    private
    static function isAdmin($senderId): bool
    {
        return $senderId == self::ADMIN_ID;
    }

    private
    static function isHeadman($senderId): bool
    {
        return $senderId == self::HEADMAN_ID;
    }

    private
    static function isRegistered($senderId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM school_people WHERE viber_id=:viber_id");
        $stmt->execute(['viber_id' => $senderId]);
        $rowcount = $stmt->rowCount();
        return $rowcount > 0;
    }

    private
    static function isSubscriber($senderId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM school_subscribers WHERE viber_id=:viber_id");
        $stmt->execute(['viber_id' => $senderId]);
        $rowcount = $stmt->rowCount();
        return $rowcount > 0;
    }

    private
    function generatePassword($data)
    {
        Logger::logDebug("generatePassword");
        $senderId = $data["sender"]["id"];
        if (!self::isAdmin($senderId)) {
            $this->defaultMessage($senderId, "У вас нет прав на генерацию пароля для регистрации");
            return;
        }
        $password = Functions::generateRandomString(4);
        $db = $this->dbCnnect();
        $stmt = $db->prepare("INSERT INTO school_config (name, value) VALUES('register_pwd', '$password') ON DUPLICATE KEY UPDATE value ='$password'");

        // execute query
        $stmt->execute();

        $this->defaultMessage($senderId, $password);
    }

    private
    static function dbCnnect(): ?PDO
    {
        return Database::getConnection();
    }

    private
    static function createSeenResponse($data): array
    {
        $api = new ViberApi(self::AUTH_TOKEN);
        return $api->getSeenResponse($data);
    }

    private
    function sendBroadcastToSubscribers($message): string
    {
        $subscribers = Database::fetchAll("select * from school_subscribers");

        $subscribers = array_map(function ($r) {
            return $r["viber_id"];
        }, $subscribers);

        //$subscribers = [self::ADMIN_ID, self::OPERATOR_ID];
        if (sizeof($subscribers) > 0) {
            return $this->sendBroadcastToUsers($subscribers, $message);
        }
        return "{}";
    }

    private
    function sendBroadcastToUsers($receiverIds, $message): string
    {
        Logger::logDebug("sendBroadcastToUsers");
        return self::sendBroadcastApiMessage($receiverIds, $message, new Keyboard([
            [
                "ActionBody" => "main_menu",
                "Text" => "Главное меню"
            ]
        ]));
    }

    private static function prepareKeyboard(Keyboard $keyboard)
    {
        foreach ($keyboard->buttons as &$button) {
            if (isset($button["Default"])) {
                unset($button["Default"]);
                $button["BgColor"] = "#2E9430";
                $button["Text"] = "<font color=\"#FFFFFF\">" . $button["Text"] . "</font>";
            }
        }
    }

    private
    static function sendApiMessage(string $receiverId, string $text, ?Keyboard $keyboard = null, ?array $trackingData = null): string
    {
        Logger::logDebug("sendApiMessage");

        $message = new TextMessage($receiverId, new Sender(self::NAME), $text);
        if ($keyboard) {
            self::prepareKeyboard($keyboard);
            $message->setKeyboard($keyboard);
        }
        if ($trackingData)
            $message->setTrackingData(json_encode($trackingData));
        Logger::logDebug(json_encode($message->jsonSerialize()));

        $api = new ViberApi(self::AUTH_TOKEN);
        $result = $api->sendMessage($message);
        self::checkResponse($result);
        Logger::logDebug("response: $result");
        return $result;
    }

    private
    static function sendBroadcastApiMessage(array $receivers, string $text, ?Keyboard $keyboard): string
    {
        Logger::logDebug("sendBroadcastApiMessage");

        $message = new BroadcastMessage($receivers, new Sender(self::NAME), $text);
        if ($keyboard) {
            self::prepareKeyboard($keyboard);
            $message->setKeyboard($keyboard);
        }

        Logger::logDebug(json_encode($message->jsonSerialize()));

        $api = new ViberApi(self::AUTH_TOKEN);
        $result = $api->sendBroadcastMessage($message);
        self::checkResponse($result);
        Logger::logDebug("response: $result");
        return $result;
    }

    private
    static function checkResponse($response)
    {
        Logger::logDebug("checkResponse");
        try {
            $json = json_decode($response, true);
            if (isset($json["status"]) && $json["status"] != 0) {
                Logger::logError($response);
            }

        } catch (Throwable $ex) {
            Logger::logError($ex->getMessage());
        }
    }

    /** @noinspection PhpUnusedParameterInspection */
    private
    function test($data)
    {
        try {

            $this->eventsList2($data);

        } catch (Throwable $ex) {
            Logger::logException($ex);
        }
        Logger::logDebug("test");

    }
}