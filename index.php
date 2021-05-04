<?php
header('Content-Type: application/json');
require_once "ViberBot.php";
require_once "common/Logger.php";

function receiveRequest()
{
    try {
        $request = file_get_contents("php://input");
        Logger::logDebug("request: " . $request);
        $input = json_decode($request, true);

        $event = isset($input['event']) ? $input['event'] : "unknown_event";

        switch ($event) {
            case "webhook":
                Logger::logSystem("webhook");
                $bot = new ViberBot();
                $webhook_response = $bot->getWebHookResponse();
                echo json_encode($webhook_response);
                die;
            case "conversation_started":
                Logger::logSystem("conversation_started: " . json_encode($input));
                $bot = new ViberBot();
                $response = $bot->getWelcomeMessage();
                echo json_encode($response);
                die;
            case "message":
                $bot = new ViberBot();
                $response = $bot->processMessage($input);
                echo json_encode($response);
                die;
            case "subscribed":
                Logger::logSystem("subscribed: " . json_encode($input));
                die;

            default:
                if (isset($_REQUEST["event"]) && isset($_REQUEST["password"]) && $_REQUEST["password"] == "PASSWORD") {
                    switch ($_REQUEST["event"]) {
                        case "notify_subscribers":
                            $bot = new ViberBot();
                            $response = $bot->notifySubscribers();
                            echo json_encode($response);
                            die;
                        case "notify_today_events":
                            $bot = new ViberBot();
                            $response = $bot->notifyTodayEvents();
                            echo json_encode($response);
                            die;
                        case "user_details":
                            if (isset($_REQUEST["id"])) {
                                $bot = new ViberBot();
                                $response = $bot->getUserDetails($_REQUEST["id"]);
                                echo $response;
                            }
                            die;
                        case "account_info":
                            $bot = new ViberBot();
                            echo $bot->getAccountInfo();
                            die;
                        case "raw_message":
                            $bot = new ViberApi("empty");
                            echo $bot->postJsonData("https://chatapi.viber.com/pa/send_message", $request);
                            die;
                    }
                }
                break;
        }
    } catch (Throwable $ex) {
        Logger::logError($ex->getMessage());
    }
}

receiveRequest();
